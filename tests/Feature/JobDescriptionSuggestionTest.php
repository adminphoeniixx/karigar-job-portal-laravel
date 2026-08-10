<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Services\JobDescriptionWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * A model reply shaped the way the writer's prompt asks for it.
 */
function draftResponse(string ...$drafts): array
{
    return ['choices' => [['message' => ['content' => json_encode(['suggestions' => $drafts])]]]];
}

beforeEach(function () {
    config(['services.ai.key' => null, 'scout.driver' => null]);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->employer->employerProfile()->create(['company_name' => 'Test Employer']);
});

it('drafts descriptions from the title with the configured model', function () {
    config(['services.ai.key' => 'test-key']);
    Http::fake(['*' => Http::response(draftResponse(
        'We need an experienced plumber for an apartment project in Jaipur.',
        'Looking for a skilled plumber to join our site team in Jaipur.',
    ))]);

    $this->actingAs($this->employer)
        ->getJson('/employer/jobs/suggest-description?'.http_build_query([
            'title' => 'Plumber for apartment project',
            'category' => 'Plumbing',
            'skills' => ['Pipe Fitting'],
            'city' => 'Jaipur',
            'state' => 'Rajasthan',
        ]))
        ->assertOk()
        ->assertJsonCount(2, 'suggestions')
        ->assertJsonPath('suggestions.0', 'We need an experienced plumber for an apartment project in Jaipur.');

    // The job details the employer has filled in so far all reach the model.
    Http::assertSent(function ($request) {
        $prompt = $request->data()['messages'][1]['content'];

        return str_contains($prompt, 'Plumber for apartment project')
            && str_contains($prompt, 'Plumbing')
            && str_contains($prompt, 'Pipe Fitting')
            && str_contains($prompt, 'Jaipur, Rajasthan');
    });
});

it('reuses the cached drafts instead of paying for the same title twice', function () {
    config(['services.ai.key' => 'test-key']);
    Http::fake(['*' => Http::response(draftResponse('First draft.', 'Second draft.'))]);

    $url = '/employer/jobs/suggest-description?title=Site+electrician+needed';

    $this->actingAs($this->employer)->getJson($url)->assertOk();
    $this->actingAs($this->employer)->getJson($url)->assertOk()->assertJsonCount(2, 'suggestions');

    Http::assertSentCount(1);
});

it('falls back to a usable template when no model is configured', function () {
    $this->actingAs($this->employer)
        ->getJson('/employer/jobs/suggest-description?'.http_build_query([
            'title' => 'Tile fitter for bulk hiring',
            'category' => 'Tiling',
            'city' => 'Chennai',
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'suggestions')
        ->assertJsonPath('suggestions.0', fn (string $text) => str_contains($text, 'Tile fitter for bulk hiring')
            && str_contains($text, 'Chennai'));
});

it('falls back to the template when the model errors', function () {
    config(['services.ai.key' => 'test-key']);
    Http::fake(['*' => Http::response('upstream on fire', 500)]);

    $this->actingAs($this->employer)
        ->getJson('/employer/jobs/suggest-description?title=Mason+needed+for+boundary+wall')
        ->assertOk()
        ->assertJsonCount(1, 'suggestions')
        ->assertJsonPath('suggestions.0', fn (string $text) => str_contains($text, 'Mason needed for boundary wall'));
});

it('ignores a reply that is not shaped like suggestions', function () {
    config(['services.ai.key' => 'test-key']);
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Sure! Here you go.']]]])]);

    $this->actingAs($this->employer)
        ->getJson('/employer/jobs/suggest-description?title=Helper+for+warehouse')
        ->assertOk()
        ->assertJsonCount(1, 'suggestions');
});

it('caps how much text a single draft can carry', function () {
    config(['services.ai.key' => 'test-key']);
    Http::fake(['*' => Http::response(draftResponse(str_repeat('x', 5000)))]);

    $response = $this->actingAs($this->employer)
        ->getJson('/employer/jobs/suggest-description?title=Painter+for+interior+work')
        ->assertOk();

    expect(mb_strlen($response->json('suggestions.0')))->toBe(1200);
});

it('needs a real title', function () {
    $this->actingAs($this->employer)
        ->getJson('/employer/jobs/suggest-description?title=ab')
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['title']]);
});

it('is closed to workers', function () {
    $worker = User::factory()->create(['role' => UserRole::Worker->value]);

    $this->actingAs($worker)
        ->getJson('/employer/jobs/suggest-description?title=Plumber+needed')
        ->assertForbidden();
});

it('offers two drafts', function () {
    expect(JobDescriptionWriter::COUNT)->toBe(2);
});
