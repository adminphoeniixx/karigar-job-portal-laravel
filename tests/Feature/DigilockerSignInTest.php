<?php

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Models\KycCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Our own DigiLocker sign-in (MeriPehchaan via API Setu): the token endpoint
 * answers with an id_token, the e-Aadhaar endpoint with signed XML.
 *
 * @param  array<string, mixed>  $claims
 */
function fakeMeriPehchaan(array $claims = [], ?string $xml = null, bool $badHmac = false, ?array $eAadhaarError = null): void
{
    $claims += ['given_name' => 'Ramesh Kumar', 'masked_aadhaar' => 'xxxxxxx4321', 'pan_number' => 'ABCPK1234E'];
    $xml ??= '<?xml version="1.0" encoding="UTF-8"?><Certificate><CertificateData><KycRes ret="Y">'
        .'<UidData uid="xxxxxxxx4321"><Poi name="RAMESH KUMAR" dob="01-01-1990" gender="M"/>'
        .'<Poa house="12" street="MG Road" dist="Jaipur" state="Rajasthan" pc="302001"/></UidData>'
        .'</KycRes></CertificateData></Certificate>';
    $jwt = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

    Http::fake([
        '*/public/oauth2/2/token' => Http::response([
            'access_token' => 'dl-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'id_token' => $jwt(['alg' => 'RS256']).'.'.$jwt($claims).'.signature',
        ]),
        '*/public/oauth2/3/xml/eaadhaar' => $eAadhaarError !== null
            ? Http::response($eAadhaarError, 404)
            : Http::response($xml, 200, [
                'Content-Type' => 'application/xml',
                'hmac' => base64_encode(hash_hmac('sha256', $badHmac ? 'tampered' : $xml, 'dl-secret', true)),
            ]),
    ]);
}

beforeEach(function () {
    Storage::fake('private');
    config([
        'services.digilocker.client_id' => 'DLCLIENT1',
        'services.digilocker.client_secret' => 'dl-secret',
        'services.digilocker.redirect_uri' => 'https://superkarigar.com/kyc/digilocker/callback',
        // Decentro gone: DigiLocker alone.
        'services.decentro.client_id' => null,
        'services.decentro.client_secret' => null,
        'scout.driver' => 'null',
    ]);

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value, 'name' => 'Ramesh']);
});

function startSignIn(User $worker): array
{
    $start = test()->actingAs($worker, 'sanctum')->postJson('/api/v1/kyc/digilocker')->assertCreated();
    parse_str((string) parse_url($start->json('authorization_url'), PHP_URL_QUERY), $query);

    return [$start->json('reference'), $start->json('authorization_url'), $query];
}

it('sends the karigar to DigiLocker with our client, callback and a PKCE challenge', function () {
    [$reference, $url, $query] = startSignIn($this->worker);

    expect($url)->toStartWith('https://digilocker.meripehchaan.gov.in/public/oauth2/1/authorize?')
        ->and($query)->toMatchArray([
            'response_type' => 'code',
            'client_id' => 'DLCLIENT1',
            'redirect_uri' => 'https://superkarigar.com/kyc/digilocker/callback',
            'state' => $reference,
            'code_challenge_method' => 'S256',
            'scope' => 'openid',
        ])
        ->and($query['code_challenge'])->toMatch('/^[A-Za-z0-9_-]{43}$/');

    Http::assertNothingSent();
});

it('verifies the Aadhaar when DigiLocker sends the karigar back with a code', function () {
    fakeMeriPehchaan();
    [$reference, , $query] = startSignIn($this->worker);

    auth()->forgetGuards();
    $this->get("/kyc/digilocker/callback?code=AUTH-CODE&state={$reference}")
        ->assertRedirect("/kyc/digilocker/return/{$reference}");

    // The token request proves it was us: the verifier hashes to the challenge.
    Http::assertSent(function (HttpRequest $request) use ($query) {
        return str_ends_with($request->url(), '/public/oauth2/2/token')
            && $request['code'] === 'AUTH-CODE'
            && $request['client_secret'] === 'dl-secret'
            && rtrim(strtr(base64_encode(hash('sha256', $request['code_verifier'], true)), '+/', '-_'), '=') === $query['code_challenge'];
    });

    $this->get("/kyc/digilocker/return/{$reference}")->assertOk()->assertSee('Aadhaar verified');

    $this->actingAs($this->worker, 'sanctum')
        ->getJson("/api/v1/kyc/digilocker/{$reference}")
        ->assertJsonPath('status', 'passed')
        ->assertJsonPath('name', 'RAMESH KUMAR')
        ->assertJsonPath('masked_aadhaar', 'XXXX XXXX 4321');

    $check = KycCheck::where('reference', $reference)->first();
    expect($check->details)->toMatchArray(['last4' => '4321', 'district' => 'Jaipur', 'pincode' => '302001', 'pan' => 'ABCPK1234E'])
        ->and($check->details)->not->toHaveKeys(['code_verifier', 'house', 'street']);
});

it('verifies a karigar on the spot when the PAN typed is the one DigiLocker holds', function () {
    Notification::fake();
    fakeMeriPehchaan();
    [$reference] = startSignIn($this->worker);
    $this->get("/kyc/digilocker/callback?code=AUTH-CODE&state={$reference}");

    $this->actingAs($this->worker, 'sanctum')
        ->postJson('/api/v1/kyc', ['pan_number' => 'ABCPK1234E'])
        ->assertCreated()
        ->assertJsonPath('kyc.status', 'verified')
        ->assertJsonPath('kyc.masked_aadhaar', 'XXXX XXXX 4321');

    expect($this->worker->fresh()->kyc->auto_verified)->toBeTrue();
});

it('leaves a different PAN for the team when there is no registry to ask', function () {
    fakeMeriPehchaan();
    [$reference] = startSignIn($this->worker);
    $this->get("/kyc/digilocker/callback?code=AUTH-CODE&state={$reference}");

    $this->actingAs($this->worker, 'sanctum')
        ->postJson('/api/v1/kyc', ['pan_number' => 'ZZZPK9999Z'])
        ->assertCreated()
        ->assertJsonPath('kyc.status', 'pending');

    expect($this->worker->fresh()->kyc->checks['pan']['message'])->toBe('Our team will check this number.');
});

it('falls back on the id_token when the e-Aadhaar signature does not match', function () {
    fakeMeriPehchaan(badHmac: true);
    [$reference] = startSignIn($this->worker);
    $this->get("/kyc/digilocker/callback?code=AUTH-CODE&state={$reference}");

    $check = KycCheck::where('reference', $reference)->first();
    expect($check->status)->toBe(KycCheck::PASSED)
        ->and($check->name)->toBe('Ramesh Kumar')
        ->and($check->details['last4'])->toBe('4321')
        ->and($check->details['eaadhaar_error'])->toBe('hmac_mismatch');
});

it('tells the karigar to link Aadhaar when their DigiLocker has none', function () {
    fakeMeriPehchaan(['masked_aadhaar' => null], eAadhaarError: ['error' => 'aadhaar_not_linked', 'error_description' => 'Aadhaar is not linked to the account']);
    [$reference] = startSignIn($this->worker);
    $this->get("/kyc/digilocker/callback?code=AUTH-CODE&state={$reference}");

    $this->actingAs($this->worker, 'sanctum')
        ->getJson("/api/v1/kyc/digilocker/{$reference}")
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Your DigiLocker has no Aadhaar linked yet. Link it in the DigiLocker app, or type your Aadhaar number instead.');
});

it('asks nothing of DigiLocker when the karigar backs out of sharing', function () {
    fakeMeriPehchaan();
    [$reference] = startSignIn($this->worker);

    $this->get("/kyc/digilocker/callback?error=access_denied&error_description=User+denied&state={$reference}")
        ->assertRedirect("/kyc/digilocker/return/{$reference}");

    Http::assertNothingSent();
    $this->actingAs($this->worker, 'sanctum')
        ->getJson("/api/v1/kyc/digilocker/{$reference}")
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'DigiLocker did not share your Aadhaar. Try again and tap "Allow" at the end, or type your Aadhaar number instead.');
});

it('takes the signed-in website user back to the KYC page', function () {
    fakeMeriPehchaan();

    $this->actingAs($this->worker)->post('/kyc/digilocker')
        ->assertRedirectContains('https://digilocker.meripehchaan.gov.in/public/oauth2/1/authorize?');
    $reference = KycCheck::where('user_id', $this->worker->id)->value('reference');

    $this->actingAs($this->worker)->get("/kyc/digilocker/callback?code=AUTH-CODE&state={$reference}")
        ->assertRedirect("/kyc/digilocker/return/{$reference}");
    $this->actingAs($this->worker)->get("/kyc/digilocker/return/{$reference}")
        ->assertRedirect('/kyc')
        ->assertSessionHas('toast.type', 'success');
});

it('approves a waiting submission once the karigar signs in to DigiLocker', function () {
    Notification::fake();
    fakeMeriPehchaan();

    // Aadhaar typed by hand + DigiLocker's PAN: waits for the Aadhaar.
    $this->worker->kyc()->create([
        'aadhaar_number' => '123456789012',
        'aadhaar_hash' => hash('sha256', '123456789012'),
        'pan_number' => 'ABCPK1234E',
        'status' => KycStatus::Pending,
        'checks' => ['pan' => ['status' => KycCheck::PASSED, 'name' => 'RAMESH KUMAR']],
    ]);

    [$reference] = startSignIn($this->worker);
    $this->get("/kyc/digilocker/callback?code=AUTH-CODE&state={$reference}");

    expect($this->worker->fresh()->kyc->status)->toBe(KycStatus::Verified);
});

it('404s a callback for an attempt it never started', function () {
    $this->get('/kyc/digilocker/callback?code=AUTH-CODE&state=KRG-NOPE')->assertNotFound();
});
