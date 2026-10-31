<?php

namespace App\Http\Controllers;

use App\Models\KycCheck;
use App\Services\Kyc\KycAutoVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aadhaar through DigiLocker. The website sends the user off to sign in;
 * DigiLocker (via Decentro) sends them back to {@see return()}, which is
 * also where the apps' in-app browser lands — so it works without a session,
 * finding the attempt by the reference in its URL.
 */
class KycDigilockerController extends Controller
{
    public function start(Request $request, KycAutoVerifier $verifier): Response
    {
        abort_unless(KycAutoVerifier::enabled(), 404);

        $user = $request->user();
        $check = $verifier->startDigilocker($user->isEmployer() ? $user->employerAccount() : $user);
        $url = $check->details['authorization_url'] ?? null;

        if ($check->status === KycCheck::ERROR || $url === null) {
            return back()->with('toast', ['type' => 'error', 'message' => $check->message]);
        }

        return Inertia::location($url);
    }

    /**
     * Where DigiLocker sends the user back from our own sign-in (the Call
     * Back URL registered on API Setu), `state` being our reference. The
     * sign-in is finished here; the user then goes on to {@see return()},
     * the URL the apps' in-app browser watches for.
     */
    public function callback(Request $request, KycAutoVerifier $verifier): RedirectResponse
    {
        $check = KycCheck::where('reference', $request->string('state')->toString())
            ->where('method', KycCheck::METHOD_DIGILOCKER)
            ->firstOrFail();

        $verifier->completeDigilocker($check, $this->digilockerError($request), $request->string('code')->toString() ?: null);

        return to_route('kyc.digilocker.return', $check->reference);
    }

    public function return(Request $request, string $reference, KycAutoVerifier $verifier): RedirectResponse|HttpResponse
    {
        $check = KycCheck::where('reference', $reference)
            ->where('method', KycCheck::METHOD_DIGILOCKER)
            ->firstOrFail();

        $check = $verifier->completeDigilocker($check, $this->digilockerError($request));
        $passed = $check->passed();
        $message = $passed
            ? __('Aadhaar verified through DigiLocker.')
            : ($check->message ?: __('DigiLocker verification did not finish. Try again.'));

        $user = $request->user();
        $account = $user?->isEmployer() ? $user->employerAccount() : $user;

        // Signed in on the website: straight back to the KYC page.
        if ($account !== null && $account->id === $check->user_id) {
            return to_route('kyc.show')->with('toast', ['type' => $passed ? 'success' : 'error', 'message' => $message]);
        }

        // The apps' in-app browser: a page saying to go back to the app,
        // which then reads the outcome from the API.
        return response()->view('kyc.digilocker-return', [
            'passed' => $passed,
            'message' => $message,
        ]);
    }

    /**
     * Closing DigiLocker or refusing to share still comes back, with the
     * OAuth `error` (passed through by Decentro on its own route).
     */
    private function digilockerError(Request $request): ?string
    {
        return $request->filled('error')
            ? trim($request->string('error').': '.$request->string('error_description'), ': ')
            : null;
    }
}
