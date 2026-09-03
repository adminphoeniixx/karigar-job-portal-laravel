<?php

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists both legal documents without their bodies', function () {
    $this->getJson('/api/v1/legal')
        ->assertOk()
        ->assertJsonCount(2, 'documents')
        ->assertJsonPath('documents.0.key', 'terms')
        ->assertJsonPath('documents.1.key', 'privacy')
        ->assertJsonStructure(['documents' => [['key', 'title', 'summary', 'updated_at', 'updated_label', 'web_url']]])
        // The index is the row, not the document — bodies stay out of it.
        ->assertJsonMissingPath('documents.0.sections');
});

it('serves each document as sections of renderable blocks', function (string $key) {
    $response = $this->getJson("/api/v1/legal/{$key}")
        ->assertOk()
        ->assertJsonPath('document.key', $key)
        ->assertJsonStructure(['document' => ['key', 'title', 'intro', 'updated_at', 'sections' => [['id', 'title', 'blocks']]]]);

    // Three block types, nothing else — that is the whole contract for a client.
    $types = collect($response->json('document.sections'))
        ->flatMap(fn (array $section) => collect($section['blocks'])->pluck('type'))
        ->unique()
        ->values();

    expect($types->diff(['paragraph', 'heading', 'list']))->toBeEmpty()
        ->and($types)->not->toBeEmpty();

    // Every block carries the field its type promises.
    foreach ($response->json('document.sections') as $section) {
        foreach ($section['blocks'] as $block) {
            $block['type'] === 'list'
                ? expect($block['items'])->toBeArray()->not->toBeEmpty()
                : expect($block['text'])->toBeString()->not->toBeEmpty();
        }
    }
})->with(['terms', 'privacy']);

it('does not advertise a web page the terms do not have yet', function () {
    // A client renders "open in browser" off this — a dead /terms URL would
    // send someone to a 404.
    $this->getJson('/api/v1/legal')
        ->assertOk()
        ->assertJsonPath('documents.0.web_url', null)
        ->assertJsonPath('documents.1.web_url', url('/privacy'));
});

it('404s an unknown legal document', function () {
    $this->getJson('/api/v1/legal/refund-policy')->assertNotFound();
});

it('drops the identity-document section when verification is switched off', function () {
    Setting::set('kyc_verification_enabled', '1');

    $withKyc = json_encode($this->getJson('/api/v1/legal/privacy')->json());
    expect($withKyc)->toContain('If you get verified');

    Setting::set('kyc_verification_enabled', '0');

    $withoutKyc = json_encode($this->getJson('/api/v1/legal/privacy')->json());
    expect($withoutKyc)->not->toContain('If you get verified');
});

it('reads legal and support without signing in', function () {
    // Both are linked from the OTP screen, before an account exists.
    $this->getJson('/api/v1/legal')->assertOk();
    $this->getJson('/api/v1/legal/terms')->assertOk();
    $this->getJson('/api/v1/support')->assertOk();
});

it('returns support channels and every faq by default', function () {
    config(['support.email' => 'support@example.com', 'support.whatsapp' => '919000000000']);

    $response = $this->getJson('/api/v1/support')
        ->assertOk()
        ->assertJsonPath('channels.email', 'support@example.com')
        ->assertJsonPath('channels.whatsapp', '919000000000')
        ->assertJsonStructure(['faqs' => [['id', 'audience', 'question', 'answer']]]);

    expect(collect($response->json('faqs'))->pluck('audience')->unique()->sort()->values()->all())
        ->toBe(['all', 'employer', 'worker']);
});

it('omits a support channel that is not configured', function () {
    config(['support.whatsapp' => '', 'support.phone' => '']);

    $channels = $this->getJson('/api/v1/support')->assertOk()->json('channels');

    expect($channels)->not->toHaveKey('whatsapp')
        ->and($channels)->not->toHaveKey('phone')
        ->and($channels)->toHaveKey('email');
});

it('filters faqs to the app that asked', function (string $audience, string $absent) {
    $audiences = collect($this->getJson("/api/v1/support?audience={$audience}")->assertOk()->json('faqs'))
        ->pluck('audience')
        ->unique();

    expect($audiences)->toContain($audience)
        ->and($audiences)->toContain('all')
        ->and($audiences)->not->toContain($absent);
})->with([
    ['worker', 'employer'],
    ['employer', 'worker'],
]);

it('rejects an unknown audience', function () {
    $this->getJson('/api/v1/support?audience=admin')->assertStatus(422);
});
