<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\LegalDocuments;
use Illuminate\Http\JsonResponse;

/**
 * "Terms & Privacy" for the apps. Public on purpose — someone deciding whether
 * to sign up has to be able to read both before handing anything over, and the
 * OTP screen links to them before an account exists.
 */
class LegalController extends Controller
{
    /**
     * Both documents without their bodies, for the settings row.
     */
    public function index(): JsonResponse
    {
        return response()->json(['documents' => LegalDocuments::index()]);
    }

    /**
     * One document with its full body.
     */
    public function show(string $document): JsonResponse
    {
        abort_unless(in_array($document, LegalDocuments::KEYS, true), 404);

        return response()->json(['document' => LegalDocuments::get($document)]);
    }
}
