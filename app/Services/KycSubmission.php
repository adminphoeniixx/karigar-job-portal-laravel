<?php

namespace App\Services;

use App\Enums\KycStatus;
use App\Http\Requests\KycSubmitRequest;
use App\Models\KycDocument;
use App\Models\User;
use App\Support\KycRequirements;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Saves a verification submission, from the web or either app. Every document
 * the user is asked for ends up either given (number + photo) or missing (an
 * alternate ID, its photo and a reason); documents no longer asked for — say
 * an employer switched business type — are cleared. Any submission goes back
 * to pending review.
 *
 * Proof files stay on the local disk, never the CDN.
 */
class KycSubmission
{
    private const DISK = 'local';

    public static function save(KycSubmitRequest $request, User $account): KycDocument
    {
        $docs = $request->documents();
        $businessType = $request->businessType();

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
            self::setNumber($kyc, $doc, $request->validated(KycRequirements::DOCUMENTS[$doc]['number_field']));
            $kyc->{$column} = self::store($request->file($doc.'_doc')) ?? $kyc->{$column};
        }

        $kyc->business_type = $businessType;
        $kyc->missing_documents = $missing ?: null;
        $kyc->has_missing_documents = $missing !== [];
        $kyc->status = KycStatus::Pending;
        $kyc->reviewed_by = null;
        $kyc->reviewed_at = null;
        $kyc->remarks = null;
        $kyc->save();

        // Files the submission replaced or dropped. A path can sit in two
        // columns (older employer rows had their GST certificate copied), so
        // only what nothing points at any more is deleted.
        foreach (array_diff($before, self::storedPaths($kyc)) as $path) {
            Storage::disk(self::DISK)->delete($path);
        }

        return $kyc;
    }

    private static function setNumber(KycDocument $kyc, string $doc, ?string $number): void
    {
        match ($doc) {
            'aadhaar' => [
                $kyc->aadhaar_number = $number,
                $kyc->aadhaar_hash = $number !== null ? hash('sha256', $number) : null,
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
