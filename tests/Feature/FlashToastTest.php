<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Toasts flashed the Laravel way (->with('toast'|'success'|'error')) reach
 * the page as Inertia flash data, which is what the front end listens for.
 */
uses(RefreshDatabase::class);

/**
 * The `flash` the page was rendered with.
 *
 * @return array<string, mixed>|null
 */
function renderedFlash(string $html): ?array
{
    preg_match('/<script[^>]*data-page[^>]*>(.*?)<\/script>/s', $html, $m) || preg_match('/data-page="([^"]+)"/', $html, $m);

    return json_decode(html_entity_decode($m[1] ?? '{}'), true)['flash'] ?? null;
}

it('shows a toast flashed with ->with(toast)', function () {
    $employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $worker = User::factory()->create(['role' => UserRole::Worker->value]);

    // Messaging a worker who never applied is refused with a toast.
    $this->actingAs($employer)->from('/messages')->post('/messages', ['worker_id' => $worker->id])->assertRedirect('/messages');

    expect(renderedFlash($this->actingAs($employer)->get('/messages')->getContent()))
        ->toBe(['toast' => ['type' => 'error', 'message' => 'You can only message workers who applied to one of your jobs.']]);
});

it('shows ->with(success) and ->with(error) as toasts too', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $worker = User::factory()->create(['role' => UserRole::Worker->value, 'name' => 'Ramesh']);

    $this->actingAs($admin)->from('/admin/users')->post("/admin/users/{$worker->id}/suspend");
    expect(renderedFlash($this->actingAs($admin)->get('/admin/users')->getContent()))
        ->toBe(['toast' => ['type' => 'success', 'message' => 'Ramesh has been suspended.']]);

    $this->actingAs($admin)->from('/admin/users')->post("/admin/users/{$admin->id}/suspend");
    expect(renderedFlash($this->actingAs($admin)->get('/admin/users')->getContent())['toast']['type'])->toBe('error');
});

it('lets an admin take a job down and restore it, but never publish a draft', function () {
    config(['scout.driver' => 'null']);
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $job = fn (JobStatus $status) => $employer->jobListings()->create([
        'title' => 'Plumber needed', 'description' => 'Fix pipes', 'status' => $status->value, 'vacancies' => 1,
    ]);
    $live = $job(JobStatus::Active);
    $draft = $job(JobStatus::Draft);

    $this->actingAs($admin)->post("/admin/jobs/{$live->id}/toggle");
    expect($live->fresh()->status)->toBe(JobStatus::Closed);
    $this->actingAs($admin)->post("/admin/jobs/{$live->id}/toggle");
    expect($live->fresh()->status)->toBe(JobStatus::Active);

    $this->actingAs($admin)->post("/admin/jobs/{$draft->id}/toggle");
    expect($draft->fresh()->status)->toBe(JobStatus::Draft);
});
