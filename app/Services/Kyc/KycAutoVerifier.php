<?php

namespace App\Services\Kyc;

use App\Enums\KycStatus;
use App\Models\KycCheck;
use App\Models\KycDocument;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\KycReviewedNotification;
use App\Support\KycRequirements;
use Illuminate\Support\Str;

/**
 * Verification without waiting for an admin. PAN and GSTIN numbers are looked
 * up in the government registry, Aadhaar comes verified out of the user's
 * DigiLocker; when every document asked for checks out and the names on them
 * agree, the submission is approved on the spot.
 *
 * Anything short of that — a document swapped for an alternate ID, Aadhaar
 * typed in by hand, a name that doesn't match, Decentro not answering —
 * leaves the submission pending, with what the checks found shown to the
 * admin. A number the registry has never heard of is turned back to the user
 * straight away instead (see KycSubmission).
 */
class KycAutoVerifier
{
    /** Admin → Settings: "Auto-verify with Decentro". */
    public const ENABLED_KEY = 'kyc_auto_verify_enabled';

    /** A passed lookup of the same number is reused for this long instead of paying again. */
    private const REUSE_DAYS = 30;

    /** Registry document type for each of our documents. */
    private const REGISTRY_TYPES = ['pan' => 'PAN', 'gst' => 'GSTIN'];

    public function __construct(private DecentroClient $client) {}

    public static function enabled(): bool
    {
        return DecentroClient::configured() && Setting::bool(self::ENABLED_KEY, true);
    }

    /**
     * Whether this document can be checked automatically at all.
     */
    public static function checksDocument(string $doc): bool
    {
        return $doc === 'aadhaar' || isset(self::REGISTRY_TYPES[$doc]);
    }

    /**
     * Look a PAN or GSTIN up in the registry.
     */
    public function checkNumber(User $account, string $doc, string $number): KycCheck
    {
        $hash = KycCheck::hashNumber($doc, $number);

        $recent = KycCheck::where('user_id', $account->id)
            ->where('document', $doc)
            ->where('number_hash', $hash)
            ->where('status', KycCheck::PASSED)
            ->where('created_at', '>=', now()->subDays(self::REUSE_DAYS))
            ->latest('id')
            ->first();

        if ($recent !== null) {
            return $recent;
        }

        $reference = self::newReference();
        $result = $this->client->validate(self::REGISTRY_TYPES[$doc], $number, $reference, self::purpose($account));

        [$status, $name, $details, $message] = match (true) {
            $result->isOk() => $this->readRegistry($doc, $result->data),
            $result->isNotFound() => [KycCheck::INVALID, null, null, $doc === 'pan'
                ? __('This PAN was not found in Income Tax records. Check the number.')
                : __('This GSTIN was not found in GST records. Check the number.')],
            default => [KycCheck::ERROR, null, null, $result->message],
        };

        return KycCheck::create([
            'user_id' => $account->id,
            'document' => $doc,
            'method' => KycCheck::METHOD_REGISTRY,
            'reference' => $reference,
            'provider_txn_id' => $result->txnId,
            'number_hash' => $hash,
            'status' => $status,
            'name' => $name,
            'details' => $details,
            'message' => $message ? Str::limit($message, 480) : null,
            'completed_at' => now(),
        ]);
    }

    /**
     * Open a DigiLocker session. The check stays `initiated` until the user
     * comes back to {@see completeDigilocker()}; its details hold the link.
     */
    public function startDigilocker(User $account): KycCheck
    {
        $reference = self::newReference();

        $check = KycCheck::create([
            'user_id' => $account->id,
            'document' => 'aadhaar',
            'method' => KycCheck::METHOD_DIGILOCKER,
            'reference' => $reference,
            'status' => KycCheck::INITIATED,
        ]);

        $result = $this->client->initiateDigilocker(
            $reference,
            route('kyc.digilocker.return', $reference),
            self::purpose($account),
        );

        $url = $result->data['authorizationUrl'] ?? null;

        if (! $result->isOk() || ! is_string($url)) {
            $check->update([
                'status' => KycCheck::ERROR,
                'provider_txn_id' => $result->txnId,
                'message' => $result->message ?: __('Could not open DigiLocker. Try again in a minute.'),
                'completed_at' => now(),
            ]);

            return $check;
        }

        $check->update([
            'provider_txn_id' => $result->txnId,
            'details' => ['authorization_url' => $url],
        ]);

        return $check;
    }

    /**
     * The user is back from DigiLocker: fetch the Aadhaar they shared. A
     * submission already waiting on the account picks it up and may be
     * approved there and then.
     */
    public function completeDigilocker(KycCheck $check): KycCheck
    {
        if ($check->status !== KycCheck::INITIATED) {
            return $check;
        }

        if ($check->created_at?->lt(now()->subMinutes(KycCheck::DIGILOCKER_TTL_MINUTES)) || $check->provider_txn_id === null) {
            $check->update([
                'status' => KycCheck::ERROR,
                'message' => __('This DigiLocker session expired. Start again.'),
                'completed_at' => now(),
            ]);

            return $check;
        }

        $result = $this->client->eAadhaar($check->provider_txn_id, self::newReference(), self::purpose($check->user));

        if (! $result->isOk()) {
            $check->update([
                'status' => $result->isNotFound() ? KycCheck::INVALID : KycCheck::ERROR,
                'message' => $result->message ?: __('DigiLocker did not share an Aadhaar.'),
                'completed_at' => now(),
            ]);

            return $check;
        }

        $identity = $result->data['proofOfIdentity'] ?? [];
        $address = $result->data['proofOfAddress'] ?? [];
        $uid = preg_replace('/\D+/', '', (string) ($result->data['aadhaarUid'] ?? ''));

        $check->update([
            'status' => KycCheck::PASSED,
            'name' => $identity['name'] ?? null,
            'details' => [
                'last4' => $uid !== '' ? substr($uid, -4) : null,
                'dob' => $identity['dob'] ?? null,
                'gender' => $identity['gender'] ?? null,
                'district' => $address['district'] ?? null,
                'state' => $address['state'] ?? null,
                'pincode' => $address['pincode'] ?? null,
            ],
            'message' => null,
            'completed_at' => now(),
        ]);

        $this->applyDigilockerToWaitingSubmission($check);

        return $check;
    }

    /**
     * The account's newest Aadhaar that came through DigiLocker, if any.
     */
    public static function digilockerAadhaar(User $account): ?KycCheck
    {
        return KycCheck::where('user_id', $account->id)
            ->where('document', 'aadhaar')
            ->where('method', KycCheck::METHOD_DIGILOCKER)
            ->where('status', KycCheck::PASSED)
            ->latest('id')
            ->first();
    }

    /**
     * What the verification screens show about DigiLocker: whether it is on
     * offer, and the Aadhaar already verified through it.
     *
     * @return array{available: bool, verified: bool, name: ?string, masked_aadhaar: ?string, verified_at: ?string}
     */
    public static function digilockerSummary(User $account): array
    {
        $check = self::digilockerAadhaar($account);
        $last4 = $check?->details['last4'] ?? null;

        return [
            'available' => self::enabled(),
            'verified' => $check !== null,
            'name' => $check?->name,
            'masked_aadhaar' => $last4 ? 'XXXX XXXX '.$last4 : null,
            'verified_at' => $check?->completed_at?->toIso8601String(),
        ];
    }

    /**
     * The snapshot a submission keeps of one checked document.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(KycCheck $check): array
    {
        return [
            'status' => $check->status,
            'method' => $check->method,
            'name' => $check->name,
            'alt_name' => $check->details['trade_name'] ?? null,
            'detail' => $check->details['registry_status'] ?? null,
            'message' => $check->message,
            'checked_at' => $check->completed_at?->toIso8601String(),
            'check_id' => $check->id,
        ];
    }

    /**
     * Approve a submission whose every document checked out and whose names
     * agree; otherwise leave it pending for an admin. Fills in each
     * document's `name_matches` on the way.
     */
    public function decide(KycDocument $kyc, User $account): void
    {
        // Name agreement is worked out afresh every time.
        $checks = collect($kyc->checks ?? [])
            ->map(fn (array $check) => array_diff_key($check, ['name_matches' => true]))
            ->all();
        $role = $account->isEmployer() ? 'employer' : 'worker';
        $docs = KycRequirements::documentsFor($role, $kyc->business_type);
        // Read fresh: the submission has only just saved it, and a profile
        // loaded earlier in the request would still carry the old name.
        $legalName = $account->isEmployer() ? $account->employerProfile()->value('legal_name') : null;

        foreach ($this->namePairs($role, $kyc->business_type) as [$left, $right]) {
            $leftNames = $this->namesOf($checks, $left, $legalName);
            $rightNames = $this->namesOf($checks, $right, $legalName);

            if ($leftNames === [] || $rightNames === []) {
                continue;
            }

            $agree = collect($leftNames)->contains(
                fn (string $a) => collect($rightNames)->contains(fn (string $b) => NameMatcher::matches($a, $b)),
            );

            foreach ([$left, $right] as $doc) {
                if (isset($checks[$doc])) {
                    // A document is only as good as its worst comparison.
                    $checks[$doc]['name_matches'] = ($checks[$doc]['name_matches'] ?? true) && $agree;
                }
            }
        }

        $allPassed = $docs !== [] && collect($docs)->every(fn (string $doc) => ($checks[$doc]['status'] ?? null) === KycCheck::PASSED
            && ($checks[$doc]['name_matches'] ?? true) === true
            && ! isset(($kyc->missing_documents ?? [])[$doc]));

        $kyc->checks = $checks ?: null;

        if (! $allPassed) {
            $kyc->status = KycStatus::Pending;
            $kyc->auto_verified = false;
            $kyc->save();

            return;
        }

        $kyc->status = KycStatus::Verified;
        $kyc->auto_verified = true;
        $kyc->reviewed_by = null;
        $kyc->reviewed_at = now();
        $kyc->remarks = null;
        $kyc->save();

        $account->workerProfile?->searchable();
        $account->notify(new KycReviewedNotification($kyc));
    }

    /**
     * Which names have to agree, by who is verifying: the person's two IDs
     * with each other, and the business's records with the name it gave.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function namePairs(string $role, ?string $businessType): array
    {
        if ($role === 'worker') {
            return [['aadhaar', 'pan']];
        }

        return match ($businessType) {
            'individual' => [['aadhaar', 'pan'], ['pan', 'legal_name']],
            'proprietorship' => [['aadhaar', 'pan'], ['gst', 'legal_name']],
            default => [['pan', 'legal_name'], ['gst', 'legal_name']],
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $checks
     * @return list<string>
     */
    private function namesOf(array $checks, string $side, ?string $legalName): array
    {
        if ($side === 'legal_name') {
            return filled($legalName) ? [$legalName] : [];
        }

        $check = $checks[$side] ?? null;

        if (($check['status'] ?? null) !== KycCheck::PASSED) {
            return [];
        }

        // A GST registration carries both the legal name and the trade (shop) name.
        return array_values(array_filter([$check['name'] ?? null, $check['alt_name'] ?? null]));
    }

    /**
     * A submission waiting for review gets the DigiLocker Aadhaar in place of
     * whatever Aadhaar it had, then is decided again.
     */
    private function applyDigilockerToWaitingSubmission(KycCheck $check): void
    {
        $account = $check->user;
        $kyc = $account?->kyc;

        if ($kyc === null || $kyc->status !== KycStatus::Pending) {
            return;
        }

        $role = $account->isEmployer() ? 'employer' : 'worker';

        if (! in_array('aadhaar', KycRequirements::documentsFor($role, $kyc->business_type), true)
            || isset(($kyc->missing_documents ?? [])['aadhaar'])) {
            return;
        }

        $kyc->aadhaar_number = null;
        $kyc->aadhaar_hash = null;
        $kyc->aadhaar_last4 = $check->details['last4'] ?? null;
        $kyc->checks = ['aadhaar' => self::snapshot($check)] + ($kyc->checks ?? []);

        $this->decide($kyc, $account);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: ?string, 2: ?array<string, mixed>, 3: ?string}
     */
    private function readRegistry(string $doc, array $data): array
    {
        if ($doc === 'pan') {
            $status = strtoupper((string) ($data['idStatus'] ?? ''));
            $valid = str_contains($status, 'VALID') && ! str_contains($status, 'INVALID');

            return [
                $valid ? KycCheck::PASSED : KycCheck::INVALID,
                $data['name'] ?? null,
                ['registry_status' => $data['idStatus'] ?? null, 'category' => $data['category'] ?? null],
                $valid ? null : __('The Income Tax department lists this PAN as :status.', ['status' => strtolower($status ?: 'not valid')]),
            ];
        }

        $status = (string) ($data['gstnStatus'] ?? '');
        $active = strcasecmp($status, 'Active') === 0;

        return [
            $active ? KycCheck::PASSED : KycCheck::INVALID,
            $data['legalName'] ?? null,
            [
                'registry_status' => $status ?: null,
                'trade_name' => $data['tradeName'] ?? null,
                'constitution' => $data['constitutionOfBusiness'] ?? null,
                'address' => $data['principalPlaceOfBusiness'] ?? null,
                'registered_on' => $data['registrationDate'] ?? null,
            ],
            $active ? null : __('This GSTIN is not active (status: :status).', ['status' => $status ?: 'unknown']),
        ];
    }

    private static function purpose(?User $account): string
    {
        // Decentro wants 20–50 characters.
        return $account?->isEmployer()
            ? 'Employer business verification for job posting'
            : 'Karigar identity verification to apply for jobs';
    }

    private static function newReference(): string
    {
        return 'KRG-'.Str::ulid();
    }
}
