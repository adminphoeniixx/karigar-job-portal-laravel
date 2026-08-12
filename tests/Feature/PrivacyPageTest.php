<?php

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('is readable without an account', function () {
    // The whole point: someone deciding whether to sign up must be able to read
    // this before handing anything over.
    $this->get('/privacy')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Privacy'));
});

it('drops the identity-document section when verification is switched off', function () {
    Setting::set('kyc_verification_enabled', '0');

    $this->get('/privacy')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('kycEnabled', false));

    Setting::set('kyc_verification_enabled', '1');

    $this->get('/privacy')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('kycEnabled', true));
});
