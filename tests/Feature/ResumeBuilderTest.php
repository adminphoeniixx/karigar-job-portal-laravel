<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Services\ResumeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * The AI resume builder: a karigar fills a form, gets an AI draft they can
 * edit, and saves it as their resume PDF — which employers download and the
 * matcher reads.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(ResumeStore::DISK);
    config(['services.ai.key' => null, 'scout.driver' => null]);

    $this->worker = User::factory()->create([
        'role' => UserRole::Worker->value,
        'name' => 'Ramesh Kumar',
        'phone' => '9876543210',
        'email' => '9876543210@phone.karigar',
    ]);
    $this->worker->workerProfile()->create([
        'city' => 'Jaipur', 'state' => 'Rajasthan', 'skills' => ['Zari', 'Aari work'],
        'experience_years' => 8, 'spoken_languages' => ['Hindi'],
    ]);
});

function builderInput(array $overrides = []): array
{
    return array_replace([
        'language' => 'en',
        'name' => 'Ramesh Kumar',
        'phone' => '9876543210',
        'city' => 'Jaipur',
        'state' => 'Rajasthan',
        'trade' => 'Zari embroidery karigar',
        'experience_years' => 8,
        'skills' => ['Zari', 'Aari work'],
        'tools' => 'Aari needle, adda frame',
        'work' => [[
            'role' => 'Embroidery karigar', 'employer' => 'Shree Handicrafts', 'place' => 'Jaipur',
            'from' => '2018', 'current' => true,
            'details' => 'Zari work on bridal lehengas. Finish 2 pieces a day.',
        ]],
        'education' => '10th pass',
        'languages' => ['Hindi', 'Rajasthani'],
        'expected_wage' => 18000,
        'relocate' => true,
    ], $overrides);
}

test('the builder opens filled in from the profile', function () {
    $this->actingAs($this->worker)
        ->get('/worker/resume/builder')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('worker/ResumeBuilder')
            ->where('form.name', 'Ramesh Kumar')
            ->where('form.phone', '9876543210')
            // The phone-OTP placeholder address is not a real email.
            ->where('form.email', null)
            ->where('form.city', 'Jaipur')
            ->where('form.skills', ['Zari', 'Aari work'])
            ->where('form.experience_years', 8)
            ->where('form.template', 'modern')
            ->where('form.show_photo', true)
            ->where('templates', ['modern', 'classic', 'bold'])
            ->where('verified', false)
            ->where('saved', null));
});

test('with no AI key the draft is built straight from the answers', function () {
    $response = $this->actingAs($this->worker)
        ->postJson('/worker/resume/builder/draft', builderInput())
        ->assertOk();

    expect($response->json('resume'))
        ->name->toBe('Ramesh Kumar')
        ->headline->toBe('Zari embroidery karigar · 8 years')
        ->location->toBe('Jaipur, Rajasthan')
        ->skills->toBe(['Zari', 'Aari work'])
        ->languages->toBe(['Hindi', 'Rajasthani'])
        ->expected_wage->toBe('₹18,000 / month');

    expect($response->json('resume.experience.0'))
        ->role->toBe('Embroidery karigar')
        ->period->toBe('2018 – Present')
        ->points->toBe(['Zari work on bridal lehengas', 'Finish 2 pieces a day']);

    // The answers are kept, so the form opens filled in next time.
    expect($this->worker->workerProfile->fresh()->resume_builder['input']['trade'])->toBe('Zari embroidery karigar');
});

test('the web draft answers 422 JSON, not a redirect, when the work is missing', function () {
    $this->actingAs($this->worker)
        ->postJson('/worker/resume/builder/draft', builderInput(['trade' => '']))
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['trade']]);
});

test('the AI rewrites the wording but never the facts', function () {
    config(['services.ai.key' => 'test-key', 'services.ai.base_url' => 'https://ai.test/v1', 'services.ai.provider' => 'openai-compatible']);

    Http::fake(['ai.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
        'headline' => 'Zari embroidery karigar · 8 years',
        'summary' => 'Experienced zari karigar who does fine bridal work.',
        'skills' => ['Zari', 'Aari work', 'Bridal embroidery'],
        // A model that invents a second job and an employer must not be heeded.
        'experience' => [
            ['role' => 'Master karigar', 'employer' => 'Made Up Ltd', 'points' => ['Did zari work on bridal lehengas', 'Finished 2 pieces every day']],
            ['role' => 'Invented', 'points' => ['Invented job']],
        ],
        'education' => ['10th pass'],
        'extras' => ['Uses aari needle and adda frame'],
        'name' => 'Someone Else',
        'phone' => '0000000000',
    ])]]]])]);

    $resume = $this->actingAs($this->worker)
        ->postJson('/worker/resume/builder/draft', builderInput())
        ->assertOk()
        ->json('resume');

    expect($resume)
        ->summary->toBe('Experienced zari karigar who does fine bridal work.')
        ->skills->toContain('Bridal embroidery')
        ->name->toBe('Ramesh Kumar')
        ->phone->toBe('9876543210')
        ->experience->toHaveCount(1);

    expect($resume['experience'][0])
        ->role->toBe('Embroidery karigar')
        ->employer->toBe('Shree Handicrafts')
        ->points->toBe(['Did zari work on bridal lehengas', 'Finished 2 pieces every day']);

    Http::assertSent(fn ($request) => str_contains($request->body(), 'Shree Handicrafts'));
});

test('a provider error still gives the karigar a resume', function () {
    config(['services.ai.key' => 'test-key', 'services.ai.base_url' => 'https://ai.test/v1']);
    Http::fake(['ai.test/*' => Http::response('down', 500)]);

    $this->actingAs($this->worker)
        ->postJson('/worker/resume/builder/draft', builderInput())
        ->assertOk()
        ->assertJsonPath('resume.headline', 'Zari embroidery karigar · 8 years');
});

test('saving the edited draft makes it the resume employers and the matcher see', function () {
    $draft = $this->actingAs($this->worker)
        ->postJson('/worker/resume/builder/draft', builderInput())
        ->json('resume');

    $draft['summary'] = 'Edited by the karigar.';
    $draft['experience'][0]['points'][] = 'Trained 3 new workers';

    $this->actingAs($this->worker)
        ->post('/worker/resume/builder', [...builderInput(), 'resume' => $draft])
        ->assertRedirect('/worker/resume/builder');

    $profile = $this->worker->workerProfile->fresh();

    expect($profile)
        ->resume_source->toBe('ai')
        ->resume_name->toBe('Resume - Ramesh Kumar.pdf')
        ->and($profile->resume_text)
        ->toContain('Edited by the karigar.')
        ->toContain('Trained 3 new workers')
        ->toContain('Shree Handicrafts');

    expect(Storage::disk(ResumeStore::DISK)->get($profile->resume_path))->toStartWith('%PDF');
    expect($profile->resumeSummary()['source'])->toBe('ai');
    expect($profile->resume_builder['resume']['summary'])->toBe('Edited by the karigar.');

    // And the worker can open it.
    $this->actingAs($this->worker)->get('/worker/resume')->assertOk();
});

test('a Hindi resume renders', function () {
    $input = builderInput(['language' => 'hi', 'trade' => 'ज़री कढ़ाई कारीगर']);

    $draft = $this->actingAs($this->worker)->postJson('/worker/resume/builder/draft', $input)->json('resume');

    expect($draft)
        ->headline->toBe('ज़री कढ़ाई कारीगर · 8 साल का अनुभव')
        ->and($draft['experience'][0]['period'])->toBe('2018 – अब तक');

    $this->actingAs($this->worker)->post('/worker/resume/builder', [...$input, 'resume' => $draft])->assertRedirect();

    $profile = $this->worker->workerProfile->fresh();
    expect(Storage::disk(ResumeStore::DISK)->get($profile->resume_path))->toStartWith('%PDF');
});

test('saving a new resume replaces the old file, and uploading one switches back', function () {
    $draft = $this->actingAs($this->worker)->postJson('/worker/resume/builder/draft', builderInput())->json('resume');
    $this->actingAs($this->worker)->post('/worker/resume/builder', [...builderInput(), 'resume' => $draft]);
    $first = $this->worker->workerProfile->fresh()->resume_path;

    $this->actingAs($this->worker)->post('/worker/resume/builder', [...builderInput(), 'resume' => $draft]);
    Storage::disk(ResumeStore::DISK)->assertMissing($first);

    $this->actingAs($this->worker)->post('/worker/resume', [
        'resume' => new UploadedFile(base_path('tests/fixtures/resumes/plumber-strong-match.pdf'), 'cv.pdf', 'application/pdf', null, true),
    ]);

    $profile = $this->worker->workerProfile->fresh();
    expect($profile->resume_source)->toBe('upload')
        // The builder answers survive, so it is one tap to go back.
        ->and($profile->resume_builder['input']['trade'])->toBe('Zari embroidery karigar');
});

test('employers cannot use the builder', function () {
    $employer = User::factory()->create(['role' => UserRole::Employer->value]);

    $this->actingAs($employer)->get('/worker/resume/builder')->assertForbidden();
    $this->actingAs($employer)->postJson('/worker/resume/builder/draft', builderInput())->assertForbidden();
});

test('the app API runs the same flow', function () {
    Sanctum::actingAs($this->worker);

    $this->getJson('/api/v1/worker/resume/builder')
        ->assertOk()
        ->assertJsonPath('form.name', 'Ramesh Kumar')
        ->assertJsonPath('languages', ['en', 'hi'])
        ->assertJsonPath('resume', null);

    $this->postJson('/api/v1/worker/resume/builder/draft', builderInput(['name' => '']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $draft = $this->postJson('/api/v1/worker/resume/builder/draft', builderInput())->assertOk()->json('resume');

    $this->postJson('/api/v1/worker/resume/builder', [...builderInput(), 'resume' => $draft])
        ->assertCreated()
        ->assertJsonPath('resume.source', 'ai')
        ->assertJsonPath('resume.name', 'Resume - Ramesh Kumar.pdf')
        ->assertJsonPath('saved.headline', 'Zari embroidery karigar · 8 years');

    $this->get('/api/v1/worker/resume/file')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->getJson('/api/v1/worker/resume/builder')->assertJsonPath('saved.name', 'Ramesh Kumar');
});

test('every design renders, in both languages, and the choice is kept', function () {
    foreach (['en', 'hi'] as $language) {
        $draft = $this->actingAs($this->worker)
            ->postJson('/worker/resume/builder/draft', builderInput(['language' => $language]))
            ->json('resume');

        foreach (['modern', 'classic', 'bold'] as $template) {
            $input = builderInput(['template' => $template, 'accent' => '#4f6b4a', 'language' => $language]);

            $pdf = $this->actingAs($this->worker)
                ->postJson('/worker/resume/builder/preview', [...$input, 'resume' => $draft])
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf')
                ->getContent();

            expect($pdf)->toStartWith('%PDF');
        }
    }

    // The preview saves nothing.
    expect($this->worker->workerProfile->fresh()->resume_path)->toBeNull();

    $input = builderInput(['template' => 'bold', 'accent' => '#6b2d5c', 'show_photo' => false]);
    $draft = $this->actingAs($this->worker)->postJson('/worker/resume/builder/draft', $input)->json('resume');
    $this->actingAs($this->worker)->post('/worker/resume/builder', [...$input, 'resume' => $draft]);

    $this->actingAs($this->worker)
        ->get('/worker/resume/builder')
        ->assertInertia(fn ($page) => $page
            ->where('form.template', 'bold')
            ->where('form.accent', '#6b2d5c')
            ->where('form.show_photo', false));
});

test('an unknown design or colour is refused', function () {
    $this->actingAs($this->worker)
        ->postJson('/worker/resume/builder/draft', builderInput(['template' => 'fancy', 'accent' => '#000000']))
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['template', 'accent']]);
});

test('the profile photo goes on the resume, cut round and fetched once', function () {
    // The round copy is a cache mPDF reads by path, so it is on the local disk.
    Storage::fake('local');
    Http::fake(['cdn.test/*' => Http::response(file_get_contents(public_path('images/categories/clay-work.jpg')))]);
    config(['services.bunnycdn.pull_zone_url' => 'https://cdn.test', 'services.bunnycdn.token_auth_key' => null]);
    $this->worker->workerProfile->update(['avatar_path' => 'avatars/ramesh.jpg']);

    $input = builderInput();
    $draft = $this->actingAs($this->worker)->postJson('/worker/resume/builder/draft', $input)->json('resume');

    $this->actingAs($this->worker)->postJson('/worker/resume/builder/preview', [...$input, 'resume' => $draft])->assertOk();
    $this->actingAs($this->worker)->postJson('/worker/resume/builder/preview', [...$input, 'resume' => $draft])->assertOk();

    Http::assertSentCount(1);
    $round = Storage::disk('local')->files('resume-photos');
    expect($round)->toHaveCount(1);
    expect(getimagesizefromstring(Storage::disk('local')->get($round[0]))['mime'])->toBe('image/png');
});

test('the app API previews a design without saving', function () {
    Sanctum::actingAs($this->worker);

    $this->getJson('/api/v1/worker/resume/builder')
        ->assertJsonPath('templates', ['modern', 'classic', 'bold'])
        ->assertJsonPath('accents.0', '#bf3a16');

    $input = builderInput(['template' => 'classic']);
    $draft = $this->postJson('/api/v1/worker/resume/builder/draft', $input)->json('resume');

    $this->post('/api/v1/worker/resume/builder/preview', [...$input, 'resume' => $draft], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($this->worker->workerProfile->fresh()->resume_path)->toBeNull();
});

test('the resume file 404s before there is one', function () {
    Sanctum::actingAs($this->worker);

    $this->get('/api/v1/worker/resume/file')->assertNotFound();
});

test('a builder resume whose file has gone is drawn again, an uploaded one 404s', function () {
    $draft = $this->actingAs($this->worker)->postJson('/worker/resume/builder/draft', builderInput())->json('resume');
    $this->actingAs($this->worker)->post('/worker/resume/builder', [...builderInput(), 'resume' => $draft]);
    $path = $this->worker->workerProfile->fresh()->resume_path;

    // The disk was wiped, as a deploy used to do.
    Storage::disk(ResumeStore::DISK)->delete($path);

    Sanctum::actingAs($this->worker);
    $this->get('/api/v1/worker/resume/file')->assertOk()->assertDownload();
    expect(Storage::disk(ResumeStore::DISK)->get($path))->toStartWith('%PDF');

    $this->post('/api/v1/worker/resume', [
        'resume' => new UploadedFile(base_path('tests/fixtures/resumes/plumber-strong-match.pdf'), 'cv.pdf', 'application/pdf', null, true),
    ])->assertCreated();
    Storage::disk(ResumeStore::DISK)->delete($this->worker->workerProfile->fresh()->resume_path);

    $this->get('/api/v1/worker/resume/file')->assertNotFound();
});
