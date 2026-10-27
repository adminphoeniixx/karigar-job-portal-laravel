<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Billing\Gst;
use App\Support\SiteInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $this->valid = [
        'legal_name' => 'Karigar Crafts Private Limited', 'address' => '12 MI Road, Jaipur, Rajasthan 302001',
        'gstin' => '08aafcp6967r1zf', 'email' => 'hello@example.com', 'phone' => '',
        'support_email' => 'help@example.com', 'support_phone' => '', 'support_whatsapp' => '919000000000',
        'support_hours' => 'Every day, 9 to 9', 'grievance_email' => 'grievance@example.com', 'jurisdiction' => 'Delhi',
    ];
});

it('falls back to the config until the admin saves', function () {
    expect(SiteInfo::get('legal_name'))->toBe(config('company.legal_name'))
        ->and(SiteInfo::get('support_email'))->toBe(config('support.email'));
});

it('lets an admin change the company and contact details everywhere they are printed', function () {
    $this->actingAs($this->admin)->patch('/admin/settings/website', $this->valid)->assertSessionHasNoErrors();

    // Footers and the privacy page, through the shared prop.
    $this->get('/privacy')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('company.legal_name', 'Karigar Crafts Private Limited')
        ->where('company.gstin', '08AAFCP6967R1ZF')
        ->where('company.phone', ''));

    // The apps' Help & Support: an emptied channel is left out.
    $channels = $this->getJson('/api/v1/support')->assertOk()->json('channels');
    expect($channels)->toMatchArray(['email' => 'help@example.com', 'whatsapp' => '919000000000', 'hours' => 'Every day, 9 to 9'])
        ->and($channels)->not->toHaveKey('phone');

    // The legal documents.
    $terms = json_encode($this->getJson('/api/v1/legal/terms')->json('document'));
    expect($terms)->toContain('grievance@example.com')->toContain('courts of Delhi');

    // Invoices, while Billing names no seller of its own.
    expect(Gst::seller())->toMatchArray([
        'name' => 'Karigar Crafts Private Limited', 'gstin' => '08AAFCP6967R1ZF', 'state_code' => '08', 'email' => 'hello@example.com',
    ]);
});

it('rejects a bad GSTIN, WhatsApp number or a missing legal name', function () {
    $this->actingAs($this->admin)
        ->patch('/admin/settings/website', [...$this->valid, 'gstin' => 'NOTAGSTIN', 'support_whatsapp' => '+91 90000', 'legal_name' => ''])
        ->assertSessionHasErrors(['gstin', 'support_whatsapp', 'legal_name']);

    expect(SiteInfo::get('legal_name'))->toBe(config('company.legal_name'));
});

it('is admin only', function () {
    $employer = User::factory()->create(['role' => UserRole::Employer->value]);

    $this->actingAs($employer)->patch('/admin/settings/website', $this->valid)->assertForbidden();
});
