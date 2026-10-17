<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\ScreeningCallStatus;
use App\Enums\UserRole;
use App\Jobs\PlaceScreeningCall;
use App\Mail\TemplatedMail;
use App\Models\AiCreditEntry;
use App\Models\AiCreditPack;
use App\Models\AiCreditPurchase;
use App\Models\Invoice;
use App\Models\JobApplication;
use App\Models\ScreeningCall;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AiCreditsLow;
use App\Services\AiCredits;
use App\Services\Billing\Gst;
use App\Services\RazorpayService;
use App\Services\Screening\ScreeningService;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/**
 * AI call credits: employers recharge a prepaid balance, every AI screening
 * call takes credits per started minute (only when the worker talked), and
 * every change is one ledger row.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Notification::fake();
    Mail::fake();

    config([
        'scout.driver' => 'null',
        'services.ai.key' => null,
        'screening.from_number' => '+918000000000',
        'screening.webhook_secret' => 'test-secret',
        'screening.window.start' => '00:00',
        'screening.window.end' => '23:59',
        // 5 min max + 1 of slack = a 6-credit hold per call at 1 credit/min.
        'screening.max_call_seconds' => 300,
        'services.razorpay.key' => 'rzp_test_key',
        'services.razorpay.secret' => 'secret',
        'services.razorpay.webhook_secret' => 'whsec',
    ]);
    Setting::set(Gst::SELLER_GSTIN_KEY, '06AAFCP6967R1ZF');
    Setting::set(ScreeningService::ENABLED_KEY, '1');
    // The migration's starter packs; each test makes the ones it needs.
    AiCreditPack::query()->delete();

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value, 'email' => 'owner@example.com']);
    giveJobPlan($this->employer);
    $this->employer->employerProfile()->create(['company_name' => 'Sri Sai Constructions', 'city' => 'Gurugram', 'state' => 'Haryana']);

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value, 'phone' => '9876500011', 'name' => 'Ramesh Kumar']);
    $this->worker->workerProfile()->create(['skills' => ['Plumbing'], 'city' => 'Gurugram', 'available' => true]);

    $this->job = $this->employer->jobListings()->create([
        'title' => 'Plumber for apartment project', 'description' => 'Site work', 'category' => 'Plumbing',
        'city' => 'Gurugram', 'state' => 'Haryana', 'vacancies' => 2, 'contact_mode' => 'apply',
        'requires_worker_fee' => false, 'status' => JobStatus::Active,
    ]);

    $this->application = JobApplication::create([
        'job_listing_id' => $this->job->id,
        'worker_id' => $this->worker->id,
        'status' => ApplicationStatus::Pending,
    ]);
});

function dialAndFinish(array $result): ScreeningCall
{
    (new PlaceScreeningCall(test()->application->id))->handle(app(ScreeningService::class));
    $call = ScreeningCall::latest('id')->firstOrFail();

    test()->postJson('/api/v1/webhooks/screening-call', ['call_id' => $call->provider_call_id, ...$result], [
        'X-Screening-Signature' => 'test-secret',
    ])->assertOk();

    return $call->refresh();
}

/**
 * A signed Razorpay webhook for a one-time order payment.
 */
function creditWebhook(string $event, string $orderId, string $paymentId): TestResponse
{
    $body = json_encode(['event' => $event, 'payload' => ['payment' => ['entity' => ['id' => $paymentId, 'order_id' => $orderId]]]]);

    return test()->call('POST', '/razorpay/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'whsec'),
    ], $body);
}

function fakeRazorpay(bool $signatureOk = true): void
{
    $razorpay = Mockery::mock(RazorpayService::class)->makePartial();
    $razorpay->shouldReceive('createOrder')->andReturn(['id' => 'order_test_1']);
    $razorpay->shouldReceive('verifyPaymentSignature')->andReturn($signatureOk);
    app()->instance(RazorpayService::class, $razorpay);
}

it('charges a call per started minute and writes it to the ledger', function () {
    giveCredits($this->employer, 50);

    $call = dialAndFinish(['status' => 'completed', 'outcome' => 'interested', 'duration_seconds' => 134]);

    $entry = AiCreditEntry::where('type', AiCreditEntry::TYPE_CALL)->sole();

    // 2 min 14 s = 3 started minutes.
    expect($entry->credits)->toBe(-3)
        ->and($entry->balance_after)->toBe(47)
        ->and($entry->screening_call_id)->toBe($call->id)
        ->and($entry->seconds)->toBe(134)
        ->and($entry->description)->toContain('Ramesh Kumar')->toContain('2 min 14 s')
        ->and($call->credits_charged)->toBe(3)
        ->and($this->employer->fresh()->ai_credits)->toBe(47);
});

it('uses the admin rate per minute', function () {
    Setting::set(AiCredits::PER_MINUTE_KEY, '2');
    giveCredits($this->employer, 50);

    dialAndFinish(['status' => 'completed', 'duration_seconds' => 61]);

    expect($this->employer->fresh()->ai_credits)->toBe(46);
});

it('does not charge a call nobody picked up', function (string $status) {
    giveCredits($this->employer, 50);

    dialAndFinish(['status' => $status, 'duration_seconds' => 40]);

    expect(AiCreditEntry::where('type', AiCreditEntry::TYPE_CALL)->count())->toBe(0)
        ->and($this->employer->fresh()->ai_credits)->toBe(50);
})->with(['no_answer', 'busy', 'failed']);

it('charges a call once however often its result arrives', function () {
    giveCredits($this->employer, 50);
    $call = dialAndFinish(['status' => 'completed', 'duration_seconds' => 90]);

    AiCredits::for($this->employer)->chargeCall($call);

    expect(AiCreditEntry::where('type', AiCreditEntry::TYPE_CALL)->count())->toBe(1)
        ->and($this->employer->fresh()->ai_credits)->toBe(48);
});

it('will not dial without enough credits for a whole call, and tells the employer', function () {
    giveCredits($this->employer, 5); // a call holds 6

    expect(app(ScreeningService::class)->blocker($this->application))->toBe('no_credits');

    (new PlaceScreeningCall($this->application->id))->handle(app(ScreeningService::class));

    expect(ScreeningCall::count())->toBe(0);
    Notification::assertSentTo($this->employer, AiCreditsLow::class, fn (AiCreditsLow $n) => $n->empty === true);
});

it('holds credits while a call is on the line, so a second call cannot overspend', function () {
    giveCredits($this->employer, 8);

    (new PlaceScreeningCall($this->application->id))->handle(app(ScreeningService::class));
    expect(ScreeningCall::sole()->status)->toBe(ScreeningCallStatus::Dialing)
        ->and(AiCredits::for($this->employer)->available())->toBe(2);

    $other = JobApplication::create([
        'job_listing_id' => $this->job->id,
        'worker_id' => User::factory()->create(['role' => UserRole::Worker->value, 'phone' => '9876500022'])->id,
        'status' => ApplicationStatus::Pending,
    ]);

    expect(app(ScreeningService::class)->blocker($other->load('job.employer')))->toBe('no_credits');
});

it('cancels a booked call when its time comes and the credits are gone', function () {
    giveCredits($this->employer, 10);
    $call = app(ScreeningService::class)->schedule($this->application, now()->addHour()->toImmutable());

    AiCredits::for($this->employer)->record(AiCreditEntry::TYPE_ADMIN_DEBIT, -10, 'Test');

    app(ScreeningService::class)->placeScheduled($call);

    expect($call->refresh()->status)->toBe(ScreeningCallStatus::Cancelled)
        ->and($call->failure_reason)->toBe('no_credits');
});

it('makes every call free while the admin has credits switched off', function () {
    Setting::set(AiCredits::ENABLED_KEY, '0');

    $call = dialAndFinish(['status' => 'completed', 'duration_seconds' => 200]);

    expect($call->status)->toBe(ScreeningCallStatus::Completed)
        ->and(AiCreditEntry::count())->toBe(0);
});

it('alerts once when the balance drops under the alert line', function () {
    Setting::set(AiCredits::LOW_ALERT_KEY, '45');
    giveCredits($this->employer, 50);

    dialAndFinish(['status' => 'completed', 'duration_seconds' => 360]);

    Notification::assertSentToTimes($this->employer, AiCreditsLow::class, 1);
    expect($this->employer->fresh()->ai_low_credit_alerted_at)->not->toBeNull();

    // Topping up above the line arms the alert again.
    giveCredits($this->employer, 100);
    expect($this->employer->fresh()->ai_low_credit_alerted_at)->toBeNull();
});

it('refuses to post a job with AI calls on until the employer has credits', function () {
    $payload = [
        'title' => 'Electrician', 'description' => 'Wiring work', 'category' => 'Electrical',
        'city' => 'Gurugram', 'state' => 'Haryana', 'vacancies' => 1, 'status' => 'active',
        'ai_call_enabled' => true,
    ];

    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', $payload)
        ->assertStatus(422)
        ->assertJsonPath('code', 'ai_credits_required')
        ->assertJsonPath('ai_credits.min_for_job', 10);

    // With AI calls off it goes through.
    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', [...$payload, 'ai_call_enabled' => false])
        ->assertCreated();

    // A draft is always saved.
    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', [...$payload, 'status' => 'draft'])
        ->assertCreated();

    giveCredits($this->employer, 10);

    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', $payload)->assertCreated();
});

it('switches AI calls off on a new job from an older app that does not send the switch', function () {
    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', [
        'title' => 'Mason', 'description' => 'Brick work', 'category' => 'Masonry',
        'city' => 'Gurugram', 'state' => 'Haryana', 'vacancies' => 1, 'status' => 'active',
    ])->assertCreated();

    expect($this->employer->jobListings()->latest('id')->first()->ai_call_enabled)->toBeFalse();
});

it('leaves a live job alone when its AI calls were already on', function () {
    $this->job->update(['published_at' => now(), 'ai_call_enabled' => true]);

    $this->actingAs($this->employer, 'sanctum')->patchJson("/api/v1/employer/jobs/{$this->job->id}", [
        'title' => 'Plumber (urgent)', 'description' => 'Site work', 'category' => 'Plumbing',
        'city' => 'Gurugram', 'state' => 'Haryana', 'vacancies' => 2, 'status' => 'active', 'ai_call_enabled' => true,
    ])->assertOk();
});

it('blocks the web job form too, with the reason on the AI call switch', function () {
    $this->actingAs($this->employer)->post('/employer/jobs', [
        'title' => 'Electrician', 'description' => 'Wiring work', 'category' => 'Electrical',
        'city' => 'Gurugram', 'state' => 'Haryana', 'vacancies' => 1, 'status' => 'active',
        'ai_call_enabled' => true,
    ])->assertSessionHasErrors('ai_call_enabled');
});

it('recharges a pack: credits, bonus, GST invoice and email, once', function () {
    $this->seed(EmailTemplateSeeder::class);
    fakeRazorpay();
    $pack = AiCreditPack::create(['name' => 'Growth', 'price' => 999, 'credits' => 90, 'bonus_credits' => 10, 'is_active' => true]);

    $this->actingAs($this->employer, 'sanctum')->postJson("/api/v1/employer/ai-credits/packs/{$pack->id}/checkout")
        ->assertCreated()
        ->assertJsonPath('razorpay_order_id', 'order_test_1')
        ->assertJsonPath('amounts.total', 1178.82)
        ->assertJsonPath('amount_paise', 117882);

    $callback = ['razorpay_payment_id' => 'pay_1', 'razorpay_order_id' => 'order_test_1', 'razorpay_signature' => 'sig'];

    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/ai-credits/callback', $callback)
        ->assertOk()
        ->assertJsonPath('credits.balance', 100);

    // The app retried and the webhook arrived as well: still credited once.
    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/ai-credits/callback', $callback)->assertOk();
    creditWebhook('payment.captured', 'order_test_1', 'pay_1')->assertOk();

    $purchase = AiCreditPurchase::sole();
    $invoice = Invoice::sole();

    expect($purchase->status)->toBe(AiCreditPurchase::STATUS_PAID)
        ->and($this->employer->fresh()->ai_credits)->toBe(100)
        ->and(AiCreditEntry::pluck('type', 'credits')->all())->toBe([90 => 'recharge', 10 => 'bonus'])
        ->and($invoice->ai_credit_purchase_id)->toBe($purchase->id)
        ->and($invoice->subscription_id)->toBeNull()
        ->and((float) $invoice->total_amount)->toBe(1178.82)
        // Haryana buyer, Haryana seller: CGST + SGST.
        ->and((float) $invoice->cgst_amount)->toBe(89.91);

    Mail::assertQueued(TemplatedMail::class, 1);

    // The invoice page renders the recharge as its line item.
    $this->actingAs($this->employer)->get("/invoices/{$invoice->id}")
        ->assertInertia(fn ($page) => $page->where('invoice.kind', 'ai_credits')->where('invoice.credits', 100));
    $this->actingAs($this->employer)->get("/invoices/{$invoice->id}/pdf")->assertOk();
});

it('credits a recharge from the webhook alone when the app never called back', function () {
    fakeRazorpay();
    $pack = AiCreditPack::create(['name' => 'Starter', 'price' => 499, 'credits' => 40, 'is_active' => true]);

    $this->actingAs($this->employer, 'sanctum')->postJson("/api/v1/employer/ai-credits/packs/{$pack->id}/checkout")->assertCreated();

    creditWebhook('order.paid', 'order_test_1', 'pay_9')->assertOk();

    expect($this->employer->fresh()->ai_credits)->toBe(40)
        ->and(AiCreditPurchase::sole()->razorpay_payment_id)->toBe('pay_9');
});

it('refuses a recharge whose signature does not check out', function () {
    fakeRazorpay(signatureOk: false);
    $pack = AiCreditPack::create(['name' => 'Starter', 'price' => 499, 'credits' => 40, 'is_active' => true]);

    $this->actingAs($this->employer, 'sanctum')->postJson("/api/v1/employer/ai-credits/packs/{$pack->id}/checkout")->assertCreated();

    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/ai-credits/callback', [
        'razorpay_payment_id' => 'pay_1', 'razorpay_order_id' => 'order_test_1', 'razorpay_signature' => 'bad',
    ])->assertStatus(422);

    expect($this->employer->fresh()->ai_credits)->toBe(0);
});

it('lets only the account owner recharge', function () {
    fakeRazorpay();
    $pack = AiCreditPack::create(['name' => 'Starter', 'price' => 499, 'credits' => 40, 'is_active' => true]);
    $recruiter = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->employer->teamMembers()->create(['user_id' => $recruiter->id, 'role' => 'manager']);

    $this->actingAs($recruiter, 'sanctum')->postJson("/api/v1/employer/ai-credits/packs/{$pack->id}/checkout")->assertForbidden();
    // They still see the account's balance.
    giveCredits($this->employer, 25);
    $this->actingAs($recruiter, 'sanctum')->getJson('/api/v1/employer/ai-credits')
        ->assertOk()
        ->assertJsonPath('credits.balance', 25)
        ->assertJsonPath('can_buy', false);
});

it('shows the balance, packs and ledger in the app', function () {
    AiCreditPack::create(['name' => 'Starter', 'price' => 499, 'credits' => 40, 'is_active' => true]);
    AiCreditPack::create(['name' => 'Old', 'price' => 99, 'credits' => 5, 'is_active' => false]);
    giveCredits($this->employer, 50);
    dialAndFinish(['status' => 'completed', 'duration_seconds' => 70]);

    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/ai-credits')
        ->assertOk()
        ->assertJsonPath('credits.balance', 48)
        ->assertJsonPath('credits.minutes', 48)
        ->assertJsonCount(1, 'packs')
        ->assertJsonPath('packs.0.price_with_gst', 588.82)
        ->assertJsonPath('totals.spent', 2);

    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/ai-credits/ledger?filter=spent')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'call')
        ->assertJsonPath('data.0.credits', -2)
        ->assertJsonPath('data.0.balance_after', 48);

    $this->actingAs($this->employer)->get('/employer/ai-credits')
        ->assertInertia(fn ($page) => $page->component('employer/AiCredits')->where('summary.balance', 48)->has('ledger.data', 2));
});

it('gives the admin\'s welcome credits once', function () {
    Setting::set(AiCredits::WELCOME_KEY, '15');

    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/ai-credits')->assertJsonPath('credits.balance', 15);
    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/ai-credits')->assertJsonPath('credits.balance', 15);

    expect(AiCreditEntry::where('type', AiCreditEntry::TYPE_WELCOME)->count())->toBe(1);
});

it('lets the admin set the rate, run packs and add or take credits', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $this->employer->update(['phone' => '9000000001']);

    $this->actingAs($admin)->patch('/admin/ai-credits/settings', [
        'enabled' => true, 'per_minute' => 2, 'min_balance' => 20, 'low_alert' => 30, 'welcome' => 5,
    ])->assertRedirect();
    expect(AiCredits::perMinute())->toBe(2)->and(AiCredits::minBalance())->toBe(20);

    $this->actingAs($admin)->post('/admin/ai-credits/packs', [
        'name' => 'Business', 'price' => 4999, 'credits' => 500, 'bonus_credits' => 100, 'is_active' => true, 'is_popular' => true,
    ])->assertRedirect();
    expect(AiCreditPack::where('name', 'Business')->value('bonus_credits'))->toBe(100);

    $this->actingAs($admin)->post('/admin/ai-credits/adjust', ['employer' => '9000000001', 'credits' => 30, 'reason' => 'Refund for a dropped call'])
        ->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/admin/ai-credits/adjust', ['employer' => '9000000001', 'credits' => -5, 'reason' => 'Correction'])
        ->assertSessionHasNoErrors();

    expect($this->employer->fresh()->ai_credits)->toBe(25)
        ->and(AiCreditEntry::where('type', AiCreditEntry::TYPE_ADMIN_CREDIT)->value('created_by'))->toBe($admin->id);

    $this->actingAs($admin)->get('/admin/ai-credits?q=9000000001')
        ->assertInertia(fn ($page) => $page->component('admin/AiCredits')->has('ledger.data', 2));
});
