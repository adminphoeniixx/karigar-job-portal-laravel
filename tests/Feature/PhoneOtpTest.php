<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// MSG91 keys are absent in testing, so the service runs in dev-fallback mode:
// the OTP is cached and no SMS API call is made.

it('shows the OTP login page for workers and employers', function () {
    $this->get('/worker/otp-login')->assertOk();
    $this->get('/employer/otp-login')->assertOk();
});

it('sends an OTP and registers a new worker on verify', function () {
    $this->post('/otp/send', ['phone' => '9876543210'])->assertRedirect()->assertSessionHasNoErrors();

    $otp = Cache::get('phone_otp.9876543210');
    expect($otp)->not->toBeNull();

    $this->post('/worker/otp/verify', ['phone' => '9876543210', 'otp' => $otp])
        ->assertRedirect('/worker/profile');

    $user = User::where('phone', '9876543210')->first();
    expect($user)->not->toBeNull()
        ->and($user->role)->toBe(UserRole::Worker)
        ->and($user->email_verified_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
});

it('logs in an existing user by phone without creating a duplicate', function () {
    $user = User::factory()->create(['role' => UserRole::Employer->value, 'phone' => '9812345678']);

    $this->post('/otp/send', ['phone' => '9812345678'])->assertRedirect();
    $otp = Cache::get('phone_otp.9812345678');

    $this->post('/employer/otp/verify', ['phone' => '9812345678', 'otp' => $otp])
        ->assertRedirect('/dashboard');

    expect(User::where('phone', '9812345678')->count())->toBe(1);
    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong or reused OTP', function () {
    $this->post('/otp/send', ['phone' => '9876543210'])->assertRedirect();
    $otp = Cache::get('phone_otp.9876543210');

    $this->post('/worker/otp/verify', ['phone' => '9876543210', 'otp' => $otp === '1111' ? '2222' : '1111'])
        ->assertSessionHasErrors('otp');
    $this->assertGuest();

    // Correct OTP works once…
    $this->post('/worker/otp/verify', ['phone' => '9876543210', 'otp' => $otp])->assertRedirect();
    auth()->logout();

    // …but cannot be replayed.
    $this->post('/worker/otp/verify', ['phone' => '9876543210', 'otp' => $otp])
        ->assertSessionHasErrors('otp');
});

it('logs the configured test number in with its fixed OTP, without an SMS', function () {
    config(['services.msg91.test_phone' => '9000000001', 'services.msg91.test_otp' => '1234']);

    // "Sending" the OTP must not cache a random one over the fixed value.
    $this->post('/otp/send', ['phone' => '9000000001'])->assertRedirect()->assertSessionHasNoErrors();
    expect(Cache::get('phone_otp.9000000001'))->toBeNull();

    $this->post('/employer/otp/verify', ['phone' => '9000000001', 'otp' => '9999'])
        ->assertSessionHasErrors('otp');

    // The fixed OTP works, and keeps working (it is never consumed).
    $this->post('/employer/otp/verify', ['phone' => '9000000001', 'otp' => '1234'])->assertRedirect();
    auth()->logout();
    $this->post('/employer/otp/verify', ['phone' => '9000000001', 'otp' => '1234'])->assertRedirect();

    expect(User::where('phone', '9000000001')->count())->toBe(1);
});

it('exempts every number in a comma-separated test list', function () {
    // The worker and employer apps each need their own test login.
    config(['services.msg91.test_phone' => '9000000001, 9000000002', 'services.msg91.test_otp' => '1234']);

    $this->post('/worker/otp/verify', ['phone' => '9000000002', 'otp' => '1234'])->assertRedirect();
    expect(User::where('phone', '9000000002')->sole()->role)->toBe(UserRole::Worker);

    auth()->logout();

    $this->post('/employer/otp/verify', ['phone' => '9000000001', 'otp' => '1234'])->assertRedirect();

    // A number outside the list still goes through the real OTP flow.
    auth()->logout();
    $this->post('/worker/otp/verify', ['phone' => '9000000003', 'otp' => '1234'])
        ->assertSessionHasErrors('otp');
});

it('does not let a stray comma exempt every number', function () {
    // '9000000001,' splits to ['9000000001', ''] — an empty candidate would
    // match nothing sane, but must never match a real number either.
    config(['services.msg91.test_phone' => '9000000001,', 'services.msg91.test_otp' => '1234']);

    $this->post('/worker/otp/verify', ['phone' => '9876543210', 'otp' => '1234'])
        ->assertSessionHasErrors('otp');

    expect(User::where('phone', '9876543210')->count())->toBe(0);
});

it('leaves normal OTP login alone when the test number is not configured', function () {
    config(['services.msg91.test_phone' => null, 'services.msg91.test_otp' => null]);

    $this->post('/otp/send', ['phone' => '9000000001'])->assertRedirect();
    $otp = Cache::get('phone_otp.9000000001');
    expect($otp)->not->toBeNull();

    $this->post('/worker/otp/verify', ['phone' => '9000000001', 'otp' => $otp === '1234' ? '4321' : '1234'])
        ->assertSessionHasErrors('otp');
});

it('rejects invalid phone numbers', function () {
    $this->post('/otp/send', ['phone' => '12345'])->assertSessionHasErrors('phone');
    $this->post('/otp/send', ['phone' => '1234567890'])->assertSessionHasErrors('phone'); // must start 6-9
});

it('uploads a company logo via the method-spoofed profile update', function () {
    Storage::fake('public');
    $employer = User::factory()->create(['role' => UserRole::Employer->value]);

    $this->actingAs($employer)->post('/employer/profile', [
        '_method' => 'PATCH',
        'company_name' => 'Sharma Constructions',
        'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
    ])->assertRedirect();

    $profile = $employer->employerProfile()->first();
    expect($profile->logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($profile->logo_path);
});
