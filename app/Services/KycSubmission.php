<?php

namespace App\Services;

use App\Enums\KycStatus;
use App\Http\Requests\KycSubmitRequest;
use App\Models\KycCheck;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Kyc\KycAutoVerifier;
use App\Support\KycRequirements;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Saves a verification submission, from the web or either app. Every document
 * the user is asked for ends up either given (number + photo) or missing (an
 * alternate ID, its photo and a reason); documents no longer asked for — say
 * an employer switched business type — are cleared.
 *
 * PAN and GSTIN are looked up through Decentro and an Aadhaar may come from
 * DigiLocker; when all of it checks out the submission is verified on the
 * spot, otherwise it waits for an admin (see KycAutoVerifier).
 *
 * Proof files go on the 'private' disk (BunnyCDN storage under private/,
 * read only through the admin controller), never a public CDN link.
 */
class KycSubmission
{
    public const DISK = 'private';

    public static function save(KycSubmitRequest $request, User $account): KycDocument
    {
        $docs = $request->documents();
        $businessType = $request->businessType();

        // Registry lookups first: a number nobody has heard of goes back to
        // the user before anything is saved.
        $checks = self::checkNumbers($request, $account, $docs);
        $digilocker = in_array('aadhaar', $docs, true) && ! $request->isMissing('aadhaar') && blank($request->validated('aadhaar_number'))
            ? $request->digilockerAadhaar()
            : null;

        if ($digilocker !== null) {
            $checks['aadhaar'] = KycAutoVerifier::snapshot($digilocker);
        }

        if ($account->isEmployer()) {
            $account->employerProfile()->firstOrCreate([])->update([
                'business_type' => $businessType,
                'legal_name' => $request->validated('legal_name'),
                'registered_address' => $request->validated('registered_address'),
                'gstin' => in_array('gst', $docs, true) && ! $request->isMissing('gst')
                    ? $request->validated('gstin')
                    : null,
            ]);
        }

        $kyc = $account->kyc()->firstOrNew([]);
        $before = self::storedPaths($kyc);
        $missing = $kyc->missing_documents ?? [];

        foreach (array_keys(KycRequirements::DOCUMENTS) as $doc) {
            $column = KycDocument::pathColumn($doc);

            if (! in_array($doc, $docs, true)) {
                $kyc->{$column} = null;
                unset($missing[$doc]);
                self::setNumber($kyc, $doc, null);

                continue;
            }

            if ($request->isMissing($doc)) {
                $kyc->{$column} = null;
                self::setNumber($kyc, $doc, null);

                $missing[$doc] = [
                    'alt_type' => $request->validated($doc.'_alt_type'),
                    'alt_number' => $request->validated($doc.'_alt_number'),
                    'doc_path' => self::store($request->file($doc.'_alt_doc')) ?? ($missing[$doc]['doc_path'] ?? null),
                    'reason' => $request->validated($doc.'_reason'),
                ];

                continue;
            }

            unset($missing[$doc]);
            $kyc->{$column} = self::store($request->file($doc.'_doc')) ?? $kyc->{$column};

            if ($doc === 'aadhaar' && $digilocker !== null) {
                self::setNumber($kyc, 'aadhaar', null);
                $kyc->aadhaar_last4 = $digilocker->details['last4'] ?? null;

                continue;
            }

            self::setNumber($kyc, $doc, $request->validated(KycRequirements::DOCUMENTS[$doc]['number_field']));
        }

        $kyc->business_type = $businessType;
        $kyc->missing_documents = $missing ?: null;
        $kyc->has_missing_documents = $missing !== [];
        $kyc->status = KycStatus::Pending;
        $kyc->reviewed_by = null;
        $kyc->reviewed_at = null;
        $kyc->remarks = null;
        $kyc->checks = $checks ?: null;
        // Saves it: verified on the spot when every check passed, pending otherwise.
        app(KycAutoVerifier::class)->decide($kyc, $account);

        // Files the submission replaced or dropped. A path can sit in two
        // columns (older employer rows had their GST certificate copied), so
        // only what nothing points at any more is deleted.
        foreach (array_diff($before, self::storedPaths($kyc)) as $path) {
            Storage::disk(self::DISK)->delete($path);
        }

        return $kyc;
    }

    /**
     * Each PAN / GSTIN given, looked up in the registry (when automatic
     * verification is on). Throws for a number the registry does not know.
     *
     * @param  list<string>  $docs
     * @return array<string, array<string, mixed>>
     */
    private static function checkNumbers(KycSubmitRequest $request, User $account, array $docs): array
    {
        if (! KycAutoVerifier::enabled()) {
            return [];
        }

        $verifier = app(KycAutoVerifier::class);
        $checks = [];
        $errors = [];

        foreach ($docs as $doc) {
            $field = KycRequirements::DOCUMENTS[$doc]['number_field'];
            $number = $request->validated($field);

            if ($doc === 'aadhaar' || $request->isMissing($doc) || blank($number)) {
                continue;
            }

            $check = $verifier->checkNumber($account, $doc, $number);

            if ($check->status === KycCheck::INVALID) {
                $errors[$field] = $check->message;

                continue;
            }

            $checks[$doc] = KycAutoVerifier::snapshot($check);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $checks;
    }

    private static function setNumber(KycDocument $kyc, string $doc, ?string $number): void
    {
        match ($doc) {
            'aadhaar' => [
                $kyc->aadhaar_number = $number,
                $kyc->aadhaar_hash = $number !== null ? hash('sha256', $number) : null,
                $kyc->aadhaar_last4 = $number !== null ? substr($number, -4) : null,
            ],
            'pan' => $kyc->pan_number = $number,
            // An employer's GSTIN lives on its profile.
            'gst' => null,
        };
    }

    private static function store(?UploadedFile $file): ?string
    {
        return $file?->store('kyc', self::DISK) ?: null;
    }

    /**
     * @return list<string>
     */
    private static function storedPaths(KycDocument $kyc): array
    {
        $alternates = array_column($kyc->missing_documents ?? [], 'doc_path');

        return array_values(array_filter([
            $kyc->aadhaar_doc_path, $kyc->pan_doc_path, $kyc->gst_doc_path, ...$alternates,
        ]));
    }
}
