<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Support\MobileApps;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * The launch checks both apps make: GET /api/v1/app/update and
 * GET /api/v1/app/maintenance, both set from Admin → Settings → Mobile apps.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::Admin->value]);
});

function appSettings(array $overrides = []): array
{
    $blank = ['latest' => null, 'min' => null, 'store_url' => null];

    return array_replace_recursive([
        'versions' => [
            'worker' => ['android' => $blank, 'ios' => $blank],
            'employer' => ['android' => $blank, 'ios' => $blank],
        ],
        'update_message' => null,
        'maintenance' => false,
        'maintenance_message' => null,
        'maintenance_until' => null,
    ], $overrides);
}

it('tells an app whether to update, and when it must', function () {
    $this->actingAs($this->admin)->patch('/admin/settings/apps', appSettings([
        'versions' => ['worker' => ['android' => [
            'latest' => '1.4.0', 'min' => '1.2.0', 'store_url' => 'https://play.google.com/store/apps/details?id=com.superkarigar.worker',
        ]]],
        'update_message' => 'Faster job feed.',
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
});

it('refuses a minimum above the latest version', function () {
    $this->actingAs($this->admin)->patch('/admin/settings/apps', appSettings([
        'versions' => ['employer' => ['ios' => ['latest' => '1.0.0', 'min' => '2.0.0']]],
    ]))->assertSessionHasErrors('versions.employer.ios.min');
});

it('puts the app API into maintenance, leaving the launch checks, help and legal open', function () {
    $worker = User::factory()->create(['role' => UserRole::Worker->value]);

    $this->getJson('/api/v1/app/maintenance')->assertOk()->assertJson(['maintenance' => false, 'message' => null]);

    $this->actingAs($this->admin)->patch('/admin/settings/apps', appSettings([
        'maintenance' => true, 'maintenance_message' => 'Upgrading servers.', 'maintenance_until' => '2026-10-08T06:00',
    ]))->assertSessionHasNoErrors();

    $this->getJson('/api/v1/app/maintenance')->assertOk()->assertJson([
        'maintenance' => true, 'message' => 'Upgrading servers.', 'until' => '2026-10-08T00:30:00+00:00', // 6 AM in India
    ]);

    $this->actingAs($worker, 'sanctum')->getJson('/api/v1/worker/dashboard')
        ->assertStatus(503)->assertJson(['code' => 'maintenance', 'message' => 'Upgrading servers.']);
    $this->postJson('/api/v1/auth/otp/send', ['phone' => '9000000002'])->assertStatus(503);

    $this->getJson('/api/v1/app/update?app=worker&platform=android')->assertOk();
    $this->getJson('/api/v1/legal')->assertOk();
    $this->getJson('/api/v1/support')->assertOk();

    // The website is not affected.
    $this->actingAs($this->admin)->get('/admin/settings')->assertOk();

    MobileApps::saveMaintenance(false, null, null);
    $this->actingAs($worker, 'sanctum')->getJson('/api/v1/worker/dashboard')->assertOk();
});
