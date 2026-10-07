<?php

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Support\MobileApps;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * The launch checks both apps make: GET /api/v1/app/update and
 * GET /api/v1/app/maintenance, both set per app from Admin → Settings → Mobile apps.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::Admin->value]);
});

function appSettings(array $overrides = []): array
{
    $blank = ['latest' => null, 'min' => null, 'store_url' => null];
    $live = ['update_message' => null, 'maintenance' => false, 'maintenance_message' => null, 'maintenance_until' => null];

    return array_replace_recursive([
        'versions' => [
            'worker' => ['android' => $blank, 'ios' => $blank],
            'employer' => ['android' => $blank, 'ios' => $blank],
        ],
        'settings' => ['worker' => $live, 'employer' => $live],
    ], $overrides);
}

it('tells an app whether to update, and when it must', function () {
    $this->actingAs($this->admin)->patch('/admin/settings/apps', appSettings([
        'versions' => ['worker' => ['android' => [
            'latest' => '1.4.0', 'min' => '1.2.0', 'store_url' => 'https://play.google.com/store/apps/details?id=com.superkarigar.worker',
        ]]],
        'settings' => ['worker' => ['update_message' => 'Faster job feed.']],
    ]))->assertSessionHasNoErrors();

    $check = fn (string $version) => $this->getJson("/api/v1/app/update?app=worker&platform=android&version={$version}");

    $check('1.4.0')->assertOk()->assertJson(['update_available' => false, 'force_update' => false]);
    $check('1.3.5+41')->assertJson([
        'installed_version' => '1.3.5', 'update_available' => true, 'force_update' => false,
        'latest_version' => '1.4.0', 'message' => 'Faster job feed.',
        'store_url' => 'https://play.google.com/store/apps/details?id=com.superkarigar.worker',
    ]);
    $check('1.1.9')->assertJson(['update_available' => true, 'force_update' => true]);

    // Nothing set for iOS: no update, no force.
    $this->getJson('/api/v1/app/update?app=worker&platform=ios&version=0.1.0')
        ->assertJson(['update_available' => false, 'force_update' => false, 'latest_version' => null]);

    $this->getJson('/api/v1/app/update?app=other&platform=android')->assertUnprocessable();

    // The message is the worker app's own.
    $this->getJson('/api/v1/app/update?app=employer&platform=android&version=1.0.0')->assertJson(['message' => null]);
});

it('refuses a minimum above the latest version', function () {
    $this->actingAs($this->admin)->patch('/admin/settings/apps', appSettings([
        'versions' => ['employer' => ['ios' => ['latest' => '1.0.0', 'min' => '2.0.0']]],
    ]))->assertSessionHasErrors('versions.employer.ios.min');
});

it('puts one app into maintenance, leaving the other app, the launch checks, help and legal open', function () {
    $worker = User::factory()->create(['role' => UserRole::Worker->value]);
    $employer = User::factory()->create(['role' => UserRole::Employer->value]);

    $this->getJson('/api/v1/app/maintenance?app=worker')->assertOk()->assertJson(['maintenance' => false, 'message' => null]);
    $this->getJson('/api/v1/app/maintenance')->assertUnprocessable();

    $this->actingAs($this->admin)->patch('/admin/settings/apps', appSettings([
        'settings' => ['worker' => [
            'maintenance' => true, 'maintenance_message' => 'Upgrading servers.', 'maintenance_until' => '2026-10-08T06:00',
        ]],
    ]))->assertSessionHasNoErrors();

    $this->getJson('/api/v1/app/maintenance?app=worker')->assertOk()->assertJson([
        'app' => 'worker', 'maintenance' => true, 'message' => 'Upgrading servers.', 'until' => '2026-10-08T00:30:00+00:00', // 6 AM in India
    ]);
    $this->getJson('/api/v1/app/maintenance?app=employer')->assertJson(['maintenance' => false]);

    // The worker app is told by its signed-in user, its X-App header or the role it signs in with.
    $this->actingAs($worker, 'sanctum')->getJson('/api/v1/worker/dashboard')
        ->assertStatus(503)->assertJson(['code' => 'maintenance', 'app' => 'worker', 'message' => 'Upgrading servers.']);
    $this->postJson('/api/v1/auth/otp/send', ['phone' => '9000000002'], ['X-App' => 'worker'])->assertStatus(503);
    $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9000000002', 'otp' => '0000', 'role' => 'worker'])->assertStatus(503);

    // The employer app keeps working; a guest call naming no app gets through.
    $this->actingAs($employer, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();
    $this->postJson('/api/v1/auth/otp/send', ['phone' => '9000000001'], ['X-App' => 'employer'])->assertStatus(200);
    $this->getJson('/api/v1/reference')->assertOk();

    $this->getJson('/api/v1/app/update?app=worker&platform=android')->assertOk();
    $this->getJson('/api/v1/legal')->assertOk();
    $this->getJson('/api/v1/support')->assertOk();

    // The website is not affected.
    $this->actingAs($this->admin)->get('/admin/settings')->assertOk();

    // Both down: a guest call naming no app is refused too.
    MobileApps::saveAppSettings(['worker' => ['maintenance' => true], 'employer' => ['maintenance' => true]]);
    $this->getJson('/api/v1/reference')->assertStatus(503);

    MobileApps::saveAppSettings(['worker' => ['maintenance' => false], 'employer' => ['maintenance' => false]]);
    $this->actingAs($worker, 'sanctum')->getJson('/api/v1/worker/dashboard')->assertOk();
});

it('keeps the old both-apps maintenance switch until the per-app settings are saved', function () {
    Setting::set('mobile_maintenance_enabled', '1');
    Setting::set('mobile_maintenance_message', 'Old switch.');

    $this->getJson('/api/v1/app/maintenance?app=worker')->assertJson(['maintenance' => true, 'message' => 'Old switch.']);
    $this->getJson('/api/v1/app/maintenance?app=employer')->assertJson(['maintenance' => true, 'message' => 'Old switch.']);

    MobileApps::saveAppSettings(['worker' => ['maintenance' => false], 'employer' => ['maintenance' => true]]);

    $this->getJson('/api/v1/app/maintenance?app=worker')->assertJson(['maintenance' => false]);
    $this->getJson('/api/v1/app/maintenance?app=employer')->assertJson(['maintenance' => true]);
});
