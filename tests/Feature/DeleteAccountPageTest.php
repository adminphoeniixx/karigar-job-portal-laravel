<?php

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('is readable without an account', function () {
    // The point of the page: someone who cannot sign in — lost phone, dead
    // number — still has to be able to find out how to have the account removed.
    $this->get('/delete-account')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('DeleteAccount'));
});

it('drops the identity-document promise when verification is switched off', function () {
    Setting::set('kyc_verification_enabled', '0');

    $this->get('/delete-account')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('kycEnabled', false));

    Setting::set('kyc_verification_enabled', '1');

    $this->get('/delete-account')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('kycEnabled', true));
});
