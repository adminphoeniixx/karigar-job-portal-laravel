<?php

use App\Enums\JobStatus;
use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Models\KycCheck;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\KycReviewedNotification;
use App\Services\Kyc\KycAutoVerifier;
use App\Services\Kyc\NameMatcher;
use App\Support\WorkerVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * A stand-in for Decentro: PANs and GSTINs it knows, and one Aadhaar in DigiLocker.
 *
 * @param  array<string, array<string, mixed>>  $registry  number => kycResult
 */
function fakeDecentro(array $registry = [], array $aadhaar = ['name' => 'RAMESH KUMAR', 'uid' => 'xxxxxxxx4321']): void
{
    Http::fake([
        '*/kyc/public_registry/validate' => function (HttpRequest $request) use ($registry) {
            $result = $registry[$request['id_number']] ?? null;

            return $result === null
                ? Http::response([
                    'status' => 'SUCCESS', 'kycStatus' => 'FAILURE',
                    'error' => ['message' => 'No records found for the given ID.', 'responseCode' => 'E00022'],
                    'responseKey' => 'error_no_record_found', 'decentroTxnId' => 'TXN-NF',
                ])
                : Http::response([
                    'status' => 'SUCCESS', 'kycStatus' => 'SUCCESS', 'kycResult' => $result,
                    'responseKey' => 'success', 'decentroTxnId' => 'TXN-'.$request['id_number'],
                ]);
        },
        '*/v2/kyc/digilocker/initiate_session' => Http::response([
            'status' => 'SUCCESS', 'decentroTxnId' => 'DL-SESSION-1',
            'data' => ['authorizationUrl' => 'https://digilocker.example/authorize?state=DL-SESSION-1'],
        ]),
        '*/v2/kyc/digilocker/eaadhaar' => Http::response([
            'status' => 'SUCCESS', 'decentroTxnId' => 'DL-EAADHAAR-1',
            'data' => [
                'aadhaarUid' => $aadhaar['uid'],
                'proofOfIdentity' => ['name' => $aadhaar['name'], 'dob' => '01-01-1990', 'gender' => 'M'],
                'proofOfAddress' => ['district' => 'Jaipur', 'state' => 'Rajasthan', 'pincode' => '302001'],
            ],
        ]),
    ]);
}

beforeEach(function () {
    Storage::fake('local');
    config([
        'services.decentro.client_id' => 'test-client',
        'services.decentro.client_secret' => 'test-secret',
        'scout.driver' => 'null',
    ]);

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value, 'name' => 'Ramesh']);
    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->employer->employerProfile()->create(['company_name' => 'Phoeniixx']);
});

function companyKyc(array $overrides = []): array
{
    return $overrides + [
        'business_type' => 'private_limited',
        'legal_name' => 'Phoeniixx Designs Pvt Ltd',
        'registered_address' => 'Udyog Vihar Phase IV, Gurugram',
        'pan_number' => 'AAFCP6967R',
        'gstin' => '06AAFCP6967R1ZF',
    ];
}

const COMPANY_REGISTRY = [
    'AAFCP6967R' => ['idNumber' => 'AAFCP6967R', 'idStatus' => 'VALID', 'category' => 'COMPANY', 'name' => 'PHOENIIXX DESIGNS PRIVATE LIMITED'],
    '06AAFCP6967R1ZF' => ['gstin' => '06AAFCP6967R1ZF', 'legalName' => 'PHOENIIXX DESIGNS PRIVATE LIMITED', 'tradeName' => 'PHOENIIXX DESIGNS PRIVATE LIMITED', 'gstnStatus' => 'Active'],
];

// ───────────────────────── Employers ─────────────────────────

it('verifies a company on the spot when PAN and GSTIN check out, no photos needed', function () {
    Notification::fake();
    fakeDecentro(COMPANY_REGISTRY);

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/kyc', companyKyc())
        ->assertCreated()
        ->assertJsonPath('kyc.status', 'verified')
        ->assertJsonPath('kyc.auto_verified', true)
        ->assertJsonPath('kyc.documents.0.check.verified', true)
        ->assertJsonPath('kyc.documents.1.check.verified', true)
        ->assertJsonPath('verification.can_post_jobs', true);

    Notification::assertSentTo($this->employer, KycReviewedNotification::class);
    expect(KycCheck::where('user_id', $this->employer->id)->where('status', 'passed')->count())->toBe(2);
});

it('compares against the legal name just submitted, not one loaded earlier', function () {
    fakeDecentro(COMPANY_REGISTRY);
    $this->employer->employerProfile->update(['legal_name' => 'Old Name Traders']);
    // Loaded with the old name, as anything earlier in the request might have.
    $this->employer->load('employerProfile');

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/kyc', companyKyc())
        ->assertJsonPath('kyc.status', 'verified');
});

it('turns back a PAN the registry has never heard of, saving nothing', function () {
    fakeDecentro([]);

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/kyc', companyKyc())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pan_number', 'gstin']);

    expect($this->employer->fresh()->kyc)->toBeNull();
});

it('refuses a cancelled GSTIN', function () {
    $registry = COMPANY_REGISTRY;
    $registry['06AAFCP6967R1ZF']['gstnStatus'] = 'Cancelled';
    fakeDecentro($registry);

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/kyc', companyKyc())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['gstin']);
});

it('sends a name mismatch to the admin instead of approving it', function () {
    fakeDecentro(COMPANY_REGISTRY);

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/kyc', companyKyc(['legal_name' => 'Sharma Traders']))
        ->assertCreated()
        ->assertJsonPath('kyc.status', 'pending')
        ->assertJsonPath('kyc.auto_verified', false)
        ->assertJsonPath('kyc.documents.0.check.name_matches', false);
});

it('leaves the submission pending when Decentro is down', function () {
    Http::fake(['*' => Http::response(['status' => 'FAILURE', 'message' => 'Internal error'], 500)]);

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/kyc', companyKyc())
        ->assertCreated()
        ->assertJsonPath('kyc.status', 'pending')
        ->assertJsonPath('kyc.documents.0.check.status', 'error');
});

it('checks nothing while the admin has auto-verify off', function () {
    Http::fake();
    Setting::set(KycAutoVerifier::ENABLED_KEY, '0');

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/kyc', companyKyc())
        ->assertCreated()
        ->assertJsonPath('kyc.status', 'pending')
        ->assertJsonPath('kyc.documents.0.check', null);

    Http::assertNothingSent();
});

// ───────────────────────── Karigars + DigiLocker ─────────────────────────

it('verifies a karigar through DigiLocker and a PAN whose names agree', function () {
    Notification::fake();
    fakeDecentro(['ABCPK1234E' => ['idStatus' => 'VALID', 'category' => 'INDIVIDUAL', 'name' => 'RAMESH KUMAR']]);

    $start = $this->actingAs($this->worker, 'sanctum')
        ->postJson('/api/v1/kyc/digilocker')
        ->assertCreated()
        ->assertJsonPath('authorization_url', 'https://digilocker.example/authorize?state=DL-SESSION-1');

    $reference = $start->json('reference');
    expect($start->json('return_url_prefix'))->toEndWith('/kyc/digilocker/return/');

    // The in-app browser comes back without a session.
    auth()->forgetGuards();
    $this->get("/kyc/digilocker/return/{$reference}")->assertOk()->assertSee('Aadhaar verified');

    $this->actingAs($this->worker, 'sanctum')
        ->getJson("/api/v1/kyc/digilocker/{$reference}")
        ->assertJsonPath('status', 'passed')
        ->assertJsonPath('masked_aadhaar', 'XXXX XXXX 4321');

    // Aadhaar needs no number any more; only the PAN is typed.
    $this->actingAs($this->worker, 'sanctum')
        ->postJson('/api/v1/kyc', ['pan_number' => 'ABCPK1234E'])
        ->assertCreated()
        ->assertJsonPath('kyc.status', 'verified')
        ->assertJsonPath('kyc.masked_aadhaar', 'XXXX XXXX 4321')
        ->assertJsonPath('kyc.documents.0.check.via', 'digilocker')
        ->assertJsonPath('verification.can_apply', true);
});

it('approves a waiting submission once the karigar finishes DigiLocker', function () {
    Notification::fake();
    fakeDecentro(['ABCPK1234E' => ['idStatus' => 'VALID', 'name' => 'RAMESH KUMAR']]);

    // Aadhaar typed by hand: PAN checks out, but the submission waits.
    $this->actingAs($this->worker, 'sanctum')
        ->postJson('/api/v1/kyc', ['aadhaar_number' => '123456789012', 'pan_number' => 'ABCPK1234E'])
        ->assertJsonPath('kyc.status', 'pending');

    $reference = $this->postJson('/api/v1/kyc/digilocker')->json('reference');
    // The pending KYC is already loaded on the account when DigiLocker finishes.
    $this->worker->load('kyc');
    $this->get("/kyc/digilocker/return/{$reference}");

    $this->actingAs($this->worker, 'sanctum')
        ->getJson("/api/v1/kyc/digilocker/{$reference}")
        ->assertJsonPath('status', 'passed')
        ->assertJsonPath('kyc.status', 'verified');

    $kyc = $this->worker->fresh()->kyc;
    expect($kyc->status)->toBe(KycStatus::Verified)
        ->and($kyc->auto_verified)->toBeTrue()
        ->and($kyc->aadhaar_number)->toBeNull()
        ->and($kyc->masked_aadhaar)->toBe('XXXX XXXX 4321');
});

it('keeps a karigar pending when the PAN and Aadhaar names differ', function () {
    fakeDecentro(['ABCPK1234E' => ['idStatus' => 'VALID', 'name' => 'SURESH PATEL']]);

    $reference = $this->actingAs($this->worker, 'sanctum')->postJson('/api/v1/kyc/digilocker')->json('reference');
    $this->get("/kyc/digilocker/return/{$reference}");

    $this->actingAs($this->worker, 'sanctum')
        ->postJson('/api/v1/kyc', ['pan_number' => 'ABCPK1234E'])
        ->assertJsonPath('kyc.status', 'pending');
});

it('lets a signed-in web user back to the KYC page after DigiLocker', function () {
    fakeDecentro();

    $this->actingAs($this->worker)->post('/kyc/digilocker')->assertRedirect('https://digilocker.example/authorize?state=DL-SESSION-1');

    $reference = KycCheck::where('user_id', $this->worker->id)->value('reference');

    $this->actingAs($this->worker)->get("/kyc/digilocker/return/{$reference}")->assertRedirect('/kyc');
});

// ───────────────────────── Applying needs a verified KYC ─────────────────────────

function openJob(User $employer)
{
    giveJobPlan($employer);

    return $employer->jobListings()->create([
        'title' => 'Plumber needed', 'description' => 'Fix pipes', 'category' => 'Plumbing',
        'status' => JobStatus::Active->value, 'vacancies' => 2, 'city' => 'Jaipur', 'state' => 'Rajasthan',
    ]);
}

it('keeps an unverified karigar from applying while the rule is on', function () {
    Setting::set(WorkerVerification::KEY, '1');
    $job = openJob($this->employer);

    $this->actingAs($this->worker, 'sanctum')
        ->postJson("/api/v1/jobs/{$job->id}/apply")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'verification_required')
        ->assertJsonPath('verification.status', 'not_submitted');

    $this->actingAs($this->worker)->post("/jobs/{$job->id}/apply")->assertRedirect('/kyc');

    expect($job->applications()->count())->toBe(0);
});

it('lets a verified karigar apply', function () {
    Setting::set(WorkerVerification::KEY, '1');
    $job = openJob($this->employer);
    $this->worker->kyc()->create(['status' => KycStatus::Verified]);

    $this->actingAs($this->worker, 'sanctum')
        ->postJson("/api/v1/jobs/{$job->id}/apply")
        ->assertCreated();
});

it('lets anyone apply while the admin has the rule off', function () {
    Setting::set(WorkerVerification::KEY, '0');
    $job = openJob($this->employer);

    $this->actingAs($this->worker, 'sanctum')->postJson("/api/v1/jobs/{$job->id}/apply")->assertCreated();
});

it('tells the worker app whether the karigar can apply', function () {
    Setting::set(WorkerVerification::KEY, '1');
    $job = openJob($this->employer);
    $this->worker->kyc()->create(['status' => KycStatus::Pending]);

    $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/worker/dashboard')
        ->assertJsonPath('features.verification_required_to_apply', true)
        ->assertJsonPath('verification.can_apply', false)
        ->assertJsonPath('verification.status', 'pending');

    $this->actingAs($this->worker, 'sanctum')->getJson("/api/v1/jobs/{$job->id}")
        ->assertJsonPath('meta.verification.can_apply', false);
});

// ───────────────────────── Names ─────────────────────────

it('matches names the way registries spell them', function (string $a, string $b, bool $same) {
    expect(NameMatcher::matches($a, $b))->toBe($same);
})->with([
    ['RAMESH KUMAR', 'Ramesh Kumar Sharma', true],
    ['R K SHARMA', 'RAMESH KUMAR SHARMA', true],
    ['MOHAMMAD ASLAM', 'MOHAMMED ASLAM', true],
    ['Phoeniixx Designs Pvt Ltd', 'PHOENIIXX DESIGNS PRIVATE LIMITED', true],
    ['M/s Sharma Traders', 'SHARMA TRADERS', true],
    ['RAMKUMAR', 'RAM KUMAR', true],
    ['RAMESH KUMAR', 'SURESH PATEL', false],
    ['R K', 'R K', false],
    ['Sharma Traders', 'PHOENIIXX DESIGNS PRIVATE LIMITED', false],
]);
