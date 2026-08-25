<?php

use App\Enums\UserRole;
use App\Models\PushCampaign;
use App\Models\User;
use App\Services\PushMessageWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * A model reply shaped the way the writer's prompt asks for it.
 *
 * @param  array<int, array{title: string, body: string}>  $variations
 * @return array<string, mixed>
 */
function pushDraftResponse(array $variations): array
{
    return ['choices' => [['message' => ['content' => json_encode(['variations' => $variations])]]]];
}

beforeEach(function () {
    config(['services.ai.key' => null, 'scout.driver' => null]);

    $this->admin = User::factory()->create(['role' => UserRole::Admin->value]);
});

it('drafts notification copy from an idea', function () {
    config(['services.ai.key' => 'test-key']);
    Http::fake(['*' => Http::response(pushDraftResponse([
        ['title' => 'Jaipur me naye plumber jobs', 'body' => 'Abhi apply karein.'],
        ['title' => 'Plumber ka naya kaam', 'body' => 'Naye sites khul gaye hain.'],
    ]))]);

    $this->actingAs($this->admin)
        ->postJson('/admin/push-notifications/suggest', [
            'idea' => 'new plumbing jobs in Jaipur',
            'count' => 2,
        ])
        ->assertOk()
        ->assertJsonCount(2, 'variations')
        ->assertJsonPath('variations.0.title', 'Jaipur me naye plumber jobs')
        ->assertJsonPath('variations.1.body', 'Naye sites khul gaye hain.');

    // The idea and the requested count both reach the model.
    Http::assertSent(function ($request) {
        $messages = $request->data()['messages'];

        return str_contains($messages[1]['content'], 'new plumbing jobs in Jaipur')
            && str_contains($messages[0]['content'], 'Write 2 different notifications');
    });
});

it('asks the model for the language the admin picked', function () {
    config(['services.ai.key' => 'test-key']);
    Http::fake(['*' => Http::response(pushDraftResponse([['title' => 'x', 'body' => 'y']]))]);

    $this->actingAs($this->admin)
        ->postJson('/admin/push-notifications/suggest', [
            'idea' => 'new jobs',
            'count' => 1,
            'language' => 'hindi',
        ])
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->data()['messages'][0]['content'], 'Devanagari'));
});

it('trims copy the model made too long for a phone to show', function () {
    config(['services.ai.key' => 'test-key']);
    Http::fake(['*' => Http::response(pushDraftResponse([[
        'title' => str_repeat('a', 200),
        'body' => str_repeat('b', 400),
    ]]))]);

    $this->actingAs($this->admin)
        ->postJson('/admin/push-notifications/suggest', ['idea' => 'anything', 'count' => 1])
        ->assertOk()
        ->assertJsonPath('variations.0.title', str_repeat('a', PushMessageWriter::TITLE_LIMIT))
        ->assertJsonPath('variations.0.body', str_repeat('b', PushMessageWriter::BODY_LIMIT));
});

it('keeps the usable drafts when one of them is malformed', function () {
    config(['services.ai.key' => 'test-key']);
    Http::fake(['*' => Http::response(pushDraftResponse([
        ['title' => 'Good one', 'body' => 'Readable body.'],
        ['title' => 'Missing its body'],
        ['body' => 'Missing its title'],
        ['title' => 'Another good one', 'body' => 'Also fine.'],
    ]))]);

    $this->actingAs($this->admin)
        ->postJson('/admin/push-notifications/suggest', ['idea' => 'anything', 'count' => 4])
        ->assertOk()
        ->assertJsonCount(2, 'variations')
        ->assertJsonPath('variations.1.title', 'Another good one');
});

it('falls back to a plain draft rather than an error when the provider fails', function () {
    config(['services.ai.key' => 'test-key']);
    Http::fake(['*' => Http::response(['error' => 'upstream exploded'], 500)]);

    $this->actingAs($this->admin)
        ->postJson('/admin/push-notifications/suggest', ['idea' => 'Jobs are live now', 'count' => 5])
        ->assertOk()
        ->assertJsonPath('variations.0.body', 'Jobs are live now');
});

it('falls back when no model is configured at all', function () {
    $this->actingAs($this->admin)
        ->postJson('/admin/push-notifications/suggest', ['idea' => 'Jobs are live now'])
        ->assertOk()
        ->assertJsonPath('variations.0.body', 'Jobs are live now');

    Http::assertNothingSent();
});

it('rejects an empty idea and a count beyond the ceiling', function () {
    // 422 with an `errors` bag, not a thrown exception or a 302: web routes do
    // not render JSON validation errors, so the controller does it by hand.
    $this->actingAs($this->admin)
        ->postJson('/admin/push-notifications/suggest', ['idea' => ''])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['idea']]);

    $this->actingAs($this->admin)
        ->postJson('/admin/push-notifications/suggest', ['idea' => 'x', 'count' => 500])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['count']]);
});

it('is closed to everyone but admins', function () {
    $worker = User::factory()->create(['role' => UserRole::Worker->value]);

    $this->actingAs($worker)
        ->postJson('/admin/push-notifications/suggest', ['idea' => 'anything'])
        ->assertForbidden();
});

it('drafts copy but never sends anything by itself', function () {
    config(['services.ai.key' => 'test-key']);
    Http::fake(['*' => Http::response(pushDraftResponse([['title' => 'x', 'body' => 'y']]))]);

    $this->actingAs($this->admin)
        ->postJson('/admin/push-notifications/suggest', ['idea' => 'anything'])
        ->assertOk();

    // Drafting is not a broadcast: nothing may be logged as one.
    expect(PushCampaign::count())->toBe(0);
});
