<?php

use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Models\KycDocument;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\KycReviewedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value]);
    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->employer->employerProfile()->create(['company_name' => 'Sri Sai Interiors']);
    giveJobPlan($this->employer);
});

function photo(string $name = 'doc.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name);
}

function liveJob(): array
{
    return [
        'title' => 'Carpenter needed',
        'description' => 'Modular kitchen work',
        'vacancies' => 1,
        'contact_mode' => 'apply',
        'requires_worker_fee' => false,
        'status' => 'active',
    ];
}

it('takes a worker Aadhaar and PAN with their photos', function () {
    $this->actingAs($this->worker, 'sanctum')
        ->post('/api/v1/kyc', [
            'aadhaar_number' => '1234 5678 9012',
            'aadhaar_doc' => photo(),
            'pan_number' => 'abcde1234f',
            'pan_doc' => photo(),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('kyc.status', 'pending')
        ->assertJsonPath('kyc.documents.0.type', 'aadhaar')
        ->assertJsonPath('kyc.documents.0.number', 'XXXX XXXX 9012')
        ->assertJsonPath('kyc.documents.1.number', 'ABXXXXXF')
        ->assertJsonPath('kyc.has_missing_documents', false);
});

it('lets a worker without PAN send an alternate ID and a reason instead', function () {
    $this->actingAs($this->worker, 'sanctum')
        ->post('/api/v1/kyc', [
            'aadhaar_number' => '123456789012',
            'aadhaar_doc' => photo(),
            'pan_missing' => '1',
            'pan_alt_type' => 'voter_id',
            'pan_alt_number' => 'XYZ1234567',
            'pan_alt_doc' => photo(),
            'pan_reason' => 'Never applied for a PAN',
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('kyc.has_missing_documents', true)
        ->assertJsonPath('kyc.documents.1.missing', true)
        ->assertJsonPath('kyc.documents.1.alternate.label', 'Voter ID')
        ->assertJsonPath('kyc.documents.1.alternate.number', 'XXXXXX4567');

    $kyc = $this->worker->fresh()->kyc;
    expect($kyc->pan_number)->toBeNull()
        ->and($kyc->missing_documents['pan']['reason'])->toBe('Never applied for a PAN');
    Storage::disk('local')->assertExists($kyc->missing_documents['pan']['doc_path']);
});

it('needs the alternate details when a document is marked missing', function () {
    $this->actingAs($this->worker, 'sanctum')
        ->postJson('/api/v1/kyc', [
            'aadhaar_number' => '123456789012',
            'pan_missing' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['aadhaar_doc', 'pan_alt_type', 'pan_alt_doc', 'pan_reason'])
        ->assertJsonMissingValidationErrors(['pan_number', 'pan_doc']);
});

it('asks a company for its PAN and GST and saves the business details', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->post('/api/v1/employer/kyc', [
            'business_type' => 'private_limited',
            'legal_name' => 'Sri Sai Interiors Pvt Ltd',
            'registered_address' => '12 MG Road, Bengaluru 560001',
            'pan_number' => 'AAFCS1234K',
            'pan_doc' => photo(),
            'gstin' => '29AAFCS1234K1Z5',
            'gst_doc' => photo(),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('required_documents', ['pan', 'gst'])
        ->assertJsonPath('business.legal_name', 'Sri Sai Interiors Pvt Ltd')
        ->assertJsonPath('gstin', '29AAFCS1234K1Z5')
        ->assertJsonPath('verification.status', 'pending');

    $kyc = $this->employer->fresh()->kyc;
    expect($kyc->business_type)->toBe('private_limited')
        ->and($kyc->aadhaar_number)->toBeNull()
        ->and($kyc->gst_doc_path)->not->toBeNull();
});

it('asks a proprietor for Aadhaar too, and lets GST be swapped for Udyam', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->post('/api/v1/employer/kyc', [
            'business_type' => 'proprietorship',
            'legal_name' => 'Ramesh Furniture Works',
            'registered_address' => 'Jodhpur, Rajasthan',
            'aadhaar_number' => '123456789012',
            'aadhaar_doc' => photo(),
            'pan_number' => 'ABCPR1234K',
            'pan_doc' => photo(),
            'gst_missing' => '1',
            'gst_alt_type' => 'udyam',
            'gst_alt_number' => 'UDYAM-RJ-17-0012345',
            'gst_alt_doc' => photo(),
            'gst_reason' => 'Turnover is below the GST limit',
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('required_documents', ['aadhaar', 'pan', 'gst'])
        ->assertJsonPath('gstin', null)
        ->assertJsonPath('kyc.documents.2.alternate.label', 'Udyam registration');
});

it('rejects a GSTIN that does not carry the PAN given', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->post('/api/v1/employer/kyc', [
            'business_type' => 'partnership',
            'legal_name' => 'Sharma & Sons',
            'registered_address' => 'Delhi',
            'pan_number' => 'AAFFS1234K',
            'pan_doc' => photo(),
            'gstin' => '07AAFFX9999K1Z5',
            'gst_doc' => photo(),
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('gstin');
});

it('keeps an unverified employer from putting a job live, but saves drafts', function () {
    Setting::set('employer_verification_required', '1');

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', liveJob())
        ->assertUnprocessable()
        ->assertJsonPath('code', 'verification_required');

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', ['status' => 'draft'] + liveJob())
        ->assertCreated();

    $this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/employer/dashboard')
        ->assertJsonPath('verification.can_post_jobs', false)
        ->assertJsonPath('verification.status', 'not_submitted');
});

it('lets a verified employer post', function () {
    Setting::set('employer_verification_required', '1');
    $this->employer->kyc()->create(['pan_number' => 'AAFCS1234K', 'status' => KycStatus::Verified]);

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', liveJob())
        ->assertCreated();
});

it('lets anyone post while the admin has the rule off', function () {
    Setting::set('employer_verification_required', '0');

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', liveJob())
        ->assertCreated();
});

it('shows the admin full PAN and the alternate document, and tells the user the outcome', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($this->worker, 'sanctum')->post('/api/v1/kyc', [
        'aadhaar_missing' => '1',
        'aadhaar_alt_type' => 'ration_card',
        'aadhaar_alt_doc' => photo(),
        'aadhaar_reason' => 'Aadhaar lost in a flood',
        'pan_number' => 'ABCDE1234F',
        'pan_doc' => photo(),
    ], ['Accept' => 'application/json'])->assertCreated();

    $kyc = KycDocument::first();

    $this->actingAs($admin)->get('/admin/kyc?missing=1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Kyc')
            ->where('missingPending', 1)
            ->where('documents.data.0.documents.0.alternate.label', 'Ration card')
            ->where('documents.data.0.documents.1.number', 'ABCDE1234F'));

    $this->actingAs($admin)->get("/admin/kyc/{$kyc->id}/document/alt-aadhaar")->assertOk();
    $this->actingAs($admin)->get("/admin/kyc/{$kyc->id}/document/gst")->assertNotFound();

    $this->actingAs($admin)->post("/admin/kyc/{$kyc->id}/approve")->assertRedirect();

    expect($kyc->fresh()->status)->toBe(KycStatus::Verified);
    Notification::assertSentTo($this->worker, KycReviewedNotification::class);
});

it('drops files a resubmission no longer needs', function () {
    $this->actingAs($this->worker, 'sanctum')->post('/api/v1/kyc', [
        'aadhaar_number' => '123456789012',
        'aadhaar_doc' => photo(),
        'pan_number' => 'ABCDE1234F',
        'pan_doc' => photo(),
    ], ['Accept' => 'application/json'])->assertCreated();

    $oldPan = $this->worker->fresh()->kyc->pan_doc_path;

    $this->actingAs($this->worker, 'sanctum')->post('/api/v1/kyc', [
        'aadhaar_number' => '123456789012',
        'pan_missing' => '1',
        'pan_alt_type' => 'form_60',
        'pan_alt_doc' => photo(),
        'pan_reason' => 'No PAN',
    ], ['Accept' => 'application/json'])->assertCreated();

    Storage::disk('local')->assertMissing($oldPan);
    expect($this->worker->fresh()->kyc->aadhaar_doc_path)->not->toBeNull();
});
