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
