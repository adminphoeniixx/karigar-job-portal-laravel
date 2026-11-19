<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\ScreeningCallStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\Review;
use App\Models\ScreeningCall;
use App\Models\Subscription;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Billing\InvoiceDocument;
use App\Services\RazorpayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

/*
 * Deleting an account from the apps (DELETE /account), the website and the
 * admin panel: plans stop at Razorpay before anything goes, invoices and the
 * reviews the user wrote stay, files go, and the shared test logins are
 * refused.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'scout.driver' => 'null',
        'services.razorpay.key' => 'rzp_test_key',
        'services.razorpay.secret' => 'secret',
    ]);
    // Deleting a photo or logo calls the CDN.
    Http::fake();

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value, 'phone' => '9811111111']);
    $this->employer->employerProfile()->create([
        'company_name' => 'Sri Sai Constructions', 'gstin' => '06ABCDE1234F1Z5',
        'address' => 'Plot 4, Sector 18', 'city' => 'Gurugram', 'state' => 'Haryana',
    ]);
});

/**
 * Razorpay, recording which subscriptions were cancelled.
 */
function deletionRazorpay(?Throwable $cancelFails = null, ?string $statusAtRazorpay = null): MockInterface
{
    $razorpay = Mockery::mock(RazorpayService::class)->makePartial();
    $cancel = $razorpay->shouldReceive('cancelSubscription');
    $cancelFails !== null ? $cancel->andThrow($cancelFails) : $cancel->andReturnNull();
    $razorpay->shouldReceive('subscriptionStatus')->andReturn($statusAtRazorpay);
    app()->instance(RazorpayService::class, $razorpay);

    return $razorpay;
}

function deletionInvoice(Subscription $subscription): Invoice
{
    return Invoice::create([
        'subscription_id' => $subscription->id, 'employer_id' => $subscription->employer_id,
        'cycle' => 1, 'number' => 'KRG/26-27/00001', 'issued_at' => now(), 'plan_name' => 'Standard',
        'subtotal_amount' => 499, 'gst_percent' => 18, 'gst_amount' => 89.82, 'total_amount' => 588.82,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function deletionJob(User $employer, array $overrides = []): JobListing
{
    return $employer->jobListings()->create([
        'title' => 'Blouse Tailor', 'description' => 'Blouse stitching.', 'category' => 'Tailoring',
        'status' => JobStatus::Active, 'vacancies' => 1, 'city' => 'Jaipur', 'state' => 'Rajasthan',
        'published_at' => now(), ...$overrides,
    ]);
}

it('deletes an employer, stopping the plan at Razorpay before anything goes', function () {
    $razorpay = deletionRazorpay();
    $plan = giveJobPlan($this->employer);
    $plan->update(['razorpay_subscription_id' => 'sub_live']);
    $invoice = deletionInvoice($plan);
    $job = deletionJob($this->employer);
    $worker = User::factory()->create(['role' => UserRole::Worker->value]);
    $written = Review::create(['reviewer_id' => $this->employer->id, 'reviewee_id' => $worker->id, 'job_listing_id' => $job->id, 'rating' => 5, 'comment' => 'Neat work.']);
    $about = Review::create(['reviewer_id' => $worker->id, 'reviewee_id' => $this->employer->id, 'rating' => 4]);

    Sanctum::actingAs($this->employer);

    $this->deleteJson('/api/v1/account', ['confirm' => true])
        ->assertOk()
        ->assertJsonPath('message', 'Your account has been deleted.');

    $razorpay->shouldHaveReceived('cancelSubscription')->once()->with('sub_live', false);

    expect(User::find($this->employer->id))->toBeNull()
        ->and(JobListing::find($job->id))->toBeNull()
        ->and(Subscription::find($plan->id))->toBeNull()
        ->and(Review::find($about->id))->toBeNull();

    // The invoice stays (tax law), made out to the company it was issued to.
    $invoice->refresh();

    expect($invoice->employer_id)->toBeNull()
        ->and($invoice->subscription_id)->toBeNull()
        ->and($invoice->buyer)->toBe(['name' => 'Sri Sai Constructions', 'address' => 'Plot 4, Sector 18, Gurugram, Haryana', 'gstin' => '06ABCDE1234F1Z5'])
        ->and(InvoiceDocument::for($invoice)->data()['buyer'])->toMatchArray([
            'name' => 'Sri Sai Constructions', 'gstin' => '06ABCDE1234F1Z5', 'phone' => null, 'email' => null,
        ]);

    // The review they wrote stays, without their name.
    expect($written->fresh()->reviewer_id)->toBeNull();
});

it('keeps the account when Razorpay will not stop the plan', function () {
    deletionRazorpay(cancelFails: new RuntimeException('Razorpay is down'), statusAtRazorpay: 'active');
    $plan = giveJobPlan($this->employer);
    $plan->update(['razorpay_subscription_id' => 'sub_live']);

    Sanctum::actingAs($this->employer);

    $this->deleteJson('/api/v1/account', ['confirm' => true])
        ->assertStatus(409)
        ->assertJsonPath('code', 'plan_cancel_failed');

    expect($this->employer->fresh())->not->toBeNull()
        ->and($plan->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('goes ahead when Razorpay says the plan had already stopped', function () {
    deletionRazorpay(cancelFails: new RuntimeException('not cancellable'), statusAtRazorpay: 'cancelled');
    $plan = giveJobPlan($this->employer);
    $plan->update(['razorpay_subscription_id' => 'sub_old']);

    Sanctum::actingAs($this->employer);

    $this->deleteJson('/api/v1/account', ['confirm' => true])->assertOk();

    expect($this->employer->fresh())->toBeNull();
});

it('does not wait on a checkout nobody paid for', function () {
    deletionRazorpay(cancelFails: new RuntimeException('not cancellable'), statusAtRazorpay: 'created');
    $plan = giveJobPlan($this->employer);
    $plan->update(['razorpay_subscription_id' => 'sub_unpaid', 'status' => SubscriptionStatus::Created]);

    Sanctum::actingAs($this->employer);

    $this->deleteJson('/api/v1/account', ['confirm' => true])->assertOk();

    expect($this->employer->fresh())->toBeNull();
});

it('refuses the shared test logins, and says so in the summary', function () {
    config(['services.msg91.test_phone' => '9000000001, 9000000002', 'services.msg91.test_otp' => '1234']);
    $this->employer->forceFill(['phone' => '9000000001'])->save();

    Sanctum::actingAs($this->employer);

    $this->getJson('/api/v1/account/deletion')
        ->assertOk()
        ->assertJsonPath('can_delete', false)
        ->assertJsonPath('blocked_reason', 'test_account');

    $this->deleteJson('/api/v1/account', ['confirm' => true])
        ->assertForbidden()
        ->assertJsonPath('code', 'test_account');

    expect($this->employer->fresh())->not->toBeNull();
});

it('needs the confirm flag, in the body or the query string', function () {
    $worker = User::factory()->create(['role' => UserRole::Worker->value]);
    Sanctum::actingAs($worker);

    $this->deleteJson('/api/v1/account')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('confirm');

    expect($worker->fresh())->not->toBeNull();

    // Some HTTP clients drop the body of a DELETE.
    $this->deleteJson('/api/v1/account?confirm=1')->assertOk();

    expect($worker->fresh())->toBeNull();
});

it('lists what an employer loses, for the confirm sheet', function () {
    $plan = giveJobPlan($this->employer);
    giveCredits($this->employer, 196);
    deletionJob($this->employer);
    deletionJob($this->employer, ['status' => JobStatus::Closed]);
    deletionInvoice($plan);
    $member = User::factory()->create(['role' => UserRole::Employer->value]);
    TeamMember::create(['employer_id' => $this->employer->id, 'user_id' => $member->id, 'role' => 'recruiter']);

    Sanctum::actingAs($this->employer);

    $this->getJson('/api/v1/account/deletion')
        ->assertOk()
        ->assertJson([
            'role' => 'employer', 'can_delete' => true, 'blocked_reason' => null, 'team_member' => false,
            'ai_credits' => 196, 'jobs' => ['total' => 2, 'live' => 1], 'team_members' => 1,
            'applications' => null, 'invoices_kept' => 1,
        ])
        ->assertJsonPath('plans.0.name', 'Test Job Plan')
        ->assertJsonPath('plans.0.auto_renew', true)
        ->assertJsonPath('warnings', [
            'Your Test Job Plan plan stops now and its AutoPay is cancelled. The days left are not refunded.',
            'Your 196 AI call credits are lost.',
            'Your 2 job posts and their applicants are deleted.',
            '1 team member loses access.',
            'Your profile, messages and documents are deleted, and you are signed out everywhere. This cannot be undone.',
            'Your invoices are kept, because tax law requires it.',
        ]);
});

it("deletes only a team member's own login", function () {
    $razorpay = deletionRazorpay();
    $plan = giveJobPlan($this->employer);
    $plan->update(['razorpay_subscription_id' => 'sub_company']);
    $job = deletionJob($this->employer);
    $member = User::factory()->create(['role' => UserRole::Employer->value]);
    TeamMember::create(['employer_id' => $this->employer->id, 'user_id' => $member->id, 'role' => 'manager']);

    Sanctum::actingAs($member);

    $this->getJson('/api/v1/account/deletion')
        ->assertOk()
        ->assertJsonPath('team_member', true)
        ->assertJsonPath('plans', [])
        ->assertJsonPath('jobs', null)
        ->assertJsonPath('warnings.0', 'Only your own login is deleted. The company account, its plan and its jobs stay with the owner.');

    $this->deleteJson('/api/v1/account', ['confirm' => true])->assertOk();

    $razorpay->shouldNotHaveReceived('cancelSubscription');

    expect($member->fresh())->toBeNull()
        ->and($this->employer->fresh())->not->toBeNull()
        ->and($job->fresh())->not->toBeNull()
        ->and($plan->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(TeamMember::count())->toBe(0);
});

it("deletes a karigar's résumé, KYC documents, photo and call recordings", function () {
    Storage::fake('private');

    $worker = User::factory()->create(['role' => UserRole::Worker->value]);
    $worker->workerProfile()->create(['skills' => ['Tailoring'], 'city' => 'Jaipur', 'avatar_path' => 'avatars/me.jpg'])
        // Only ResumeStore writes the résumé columns; they are not mass-assignable.
        ->forceFill(['resume_path' => 'resumes/cv.pdf'])->save();
    $worker->kyc()->create([
        'aadhaar_doc_path' => 'kyc/aadhaar.jpg',
        'missing_documents' => ['pan' => ['type' => 'voter_id', 'number' => 'ABC1234567', 'doc_path' => 'kyc/voter.jpg']],
    ]);
    $job = deletionJob($this->employer);
    $application = JobApplication::create(['job_listing_id' => $job->id, 'worker_id' => $worker->id, 'status' => ApplicationStatus::Pending]);
    ScreeningCall::create([
        'job_application_id' => $application->id, 'worker_id' => $worker->id, 'provider' => 'livekit',
        'status' => ScreeningCallStatus::Completed, 'language' => 'hi', 'attempt' => 1, 'recording_path' => 'recordings/call.mp3',
    ]);

    $files = ['resumes/cv.pdf', 'kyc/aadhaar.jpg', 'kyc/voter.jpg', 'recordings/call.mp3'];

    foreach ($files as $path) {
        Storage::disk('private')->put($path, 'file');
    }

    Sanctum::actingAs($worker);

    $this->deleteJson('/api/v1/account', ['confirm' => true])->assertOk();

    foreach ($files as $path) {
        Storage::disk('private')->assertMissing($path);
    }

    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), 'avatars/me.jpg'));

    // The employer whose job they applied to keeps the job.
    expect($worker->fresh())->toBeNull()
        ->and($job->fresh())->not->toBeNull();
});

it('deletes through the web form the same way', function () {
    $razorpay = deletionRazorpay();
    $plan = giveJobPlan($this->employer);
    $plan->update(['razorpay_subscription_id' => 'sub_web']);

    $this->actingAs($this->employer)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $razorpay->shouldHaveReceived('cancelSubscription')->once()->with('sub_web', false);

    // Signing out must not write the deleted account back (logout() re-saves the remember token).
    expect($this->employer->fresh())->toBeNull();
});

it('lets an admin delete an account, stopping its plan too', function () {
    $razorpay = deletionRazorpay();
    $plan = giveJobPlan($this->employer);
    $plan->update(['razorpay_subscription_id' => 'sub_admin']);
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $this->employer))
        ->assertRedirect();

    $razorpay->shouldHaveReceived('cancelSubscription')->once()->with('sub_admin', false);

    expect($this->employer->fresh())->toBeNull();
});

it('shows a review whose writer deleted their account as from a former user', function () {
    $worker = User::factory()->create(['role' => UserRole::Worker->value]);
    $worker->workerProfile()->create(['skills' => ['Tailoring'], 'city' => 'Jaipur']);
    $job = deletionJob($this->employer);
    Review::create(['reviewer_id' => $this->employer->id, 'reviewee_id' => $worker->id, 'job_listing_id' => $job->id, 'rating' => 5, 'comment' => 'Neat work.']);

    $this->employer->delete();

    Sanctum::actingAs($worker);

    $this->getJson('/api/v1/worker/reviews')
        ->assertOk()
        ->assertJsonPath('data.0.rating', 5)
        ->assertJsonPath('data.0.reviewer', ['id' => null, 'name' => 'Former user'])
        ->assertJsonPath('data.0.job', null)
        ->assertJsonPath('summary.count', 1);
});
