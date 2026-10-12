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

    public function return(Request $request, string $reference, KycAutoVerifier $verifier): RedirectResponse|HttpResponse
    {
        $check = KycCheck::where('reference', $reference)
            ->where('method', KycCheck::METHOD_DIGILOCKER)
            ->firstOrFail();

        $check = $verifier->completeDigilocker($check);
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
}
