<?php

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->worker = User::factory()->create(['role' => UserRole::Worker->value]);

    $this->worker->kyc()->create([
        'pan_number' => 'ABCDE1234F',
        'aadhaar_number' => '123456789012',
        'aadhaar_hash' => hash('sha256', '123456789012'),
        'status' => KycStatus::Verified,
    ]);
});

it('keeps KYC available while verification is on', function () {
    Setting::set('kyc_verification_enabled', '1');

    $this->actingAs($this->worker)->get('/kyc')->assertOk();
    expect($this->worker->fresh()->isKycVerified())->toBeTrue();
});

it('404s the web KYC route while verification is off', function () {
    Setting::set('kyc_verification_enabled', '0');

    $this->actingAs($this->worker)->get('/kyc')->assertNotFound();
});

it('404s the API KYC route while verification is off', function () {
    Setting::set('kyc_verification_enabled', '0');

    $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/kyc')->assertNotFound();
});

it('drops the verified badge while verification is off', function () {
    Setting::set('kyc_verification_enabled', '0');

    expect($this->worker->fresh()->isKycVerified())->toBeFalse();
});

it('reports the flag to the worker app and nulls the KYC status', function () {
    Setting::set('kyc_verification_enabled', '0');

    $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/worker/dashboard')
        ->assertOk()
        ->assertJsonPath('features.verification_enabled', false)
        ->assertJsonPath('stats.kyc_status', null);
});

it('restores everything when verification is switched back on', function () {
    Setting::set('kyc_verification_enabled', '0');
    Setting::set('kyc_verification_enabled', '1');

    $this->actingAs($this->worker)->get('/kyc')->assertOk();
    expect($this->worker->fresh()->isKycVerified())->toBeTrue();
});

it('lets an admin toggle verification from settings', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)->patch('/admin/settings', [
        'first_post_free_enabled' => true,
        'kyc_verification_enabled' => false,
        'ai_auto_shortlist_enabled' => false,
        'ai_auto_shortlist_threshold' => 80,
        'ai_auto_reject_enabled' => false,
        'ai_auto_reject_below' => 30,
        'ai_screening_call_enabled' => false,
    ])->assertRedirect();

    expect(Setting::bool('kyc_verification_enabled', true))->toBeFalse();
});
