<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The AI resume builder: turns what a karigar types into a short form into a
 * tidy resume — a headline, a summary, skills and work points — that
 * {@see ResumeStore::build()} then saves as their PDF.
 *
 * The model only rewrites what it is given. Name, phone, email, place, the
 * employers, the dates and the languages are copied from the form untouched;
 * the model is never asked for them, so it cannot get them wrong. Runs on
 * {@see AiClient}; with no key or a provider error it falls back to a plain
 * resume built from the same answers, so the worker always gets one.
 */
class ResumeWriter
{
    /** Languages the resume can be written in: English, or Hindi in Devanagari. */
    public const LANGUAGES = ['en', 'hi'];

    /** Jobs a karigar can list. Five covers anyone; more is a wall of text. */
    public const MAX_JOBS = 5;

    public const MAX_SKILLS = 20;

    /** PDF designs the karigar can pick from; see resources/views/resumes/. */
    public const TEMPLATES = ['modern', 'classic', 'bold'];

    /** Accent colours on offer: terracotta, ink blue, olive, plum. */
    public const ACCENTS = ['#bf3a16', '#1e3a5f', '#4f6b4a', '#6b2d5c'];

    public function __construct(private AiClient $ai) {}

    /**
     * Validation for the form the karigar fills in. Shared by the web page and
     * the app API so both accept exactly the same answers.
     *
     * @return array<string, mixed>
     */
    public static function inputRules(): array
    {
        return [
            'language' => ['required', Rule::in(self::LANGUAGES)],
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'trade' => ['required', 'string', 'max:100'],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:60'],
            'skills' => ['nullable', 'array', 'max:'.self::MAX_SKILLS],
            'skills.*' => ['string', 'max:60'],
            'tools' => ['nullable', 'string', 'max:300'],
            'work' => ['nullable', 'array', 'max:'.self::MAX_JOBS],
            'work.*.role' => ['required', 'string', 'max:100'],
            'work.*.employer' => ['nullable', 'string', 'max:120'],
            'work.*.place' => ['nullable', 'string', 'max:80'],
            'work.*.from' => ['nullable', 'string', 'max:20'],
            'work.*.to' => ['nullable', 'string', 'max:20'],
            'work.*.current' => ['nullable', 'boolean'],
            'work.*.details' => ['nullable', 'string', 'max:600'],
            'education' => ['nullable', 'string', 'max:150'],
            'training' => ['nullable', 'string', 'max:300'],
            'languages' => ['nullable', 'array', 'max:8'],
            'languages.*' => ['string', 'max:30'],
            'about' => ['nullable', 'string', 'max:600'],
            'achievements' => ['nullable', 'string', 'max:600'],
            'expected_wage' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'relocate' => ['nullable', 'boolean'],
            'template' => ['nullable', Rule::in(self::TEMPLATES)],
            'accent' => ['nullable', Rule::in(self::ACCENTS)],
            'show_photo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Validation for the resume itself, as the karigar may have edited it in
     * the preview before saving.
     *
     * @return array<string, mixed>
     */
    public static function resumeRules(): array
    {
        return [
            'resume' => ['required', 'array'],
            'resume.headline' => ['nullable', 'string', 'max:150'],
            'resume.summary' => ['nullable', 'string', 'max:1200'],
            'resume.skills' => ['nullable', 'array', 'max:'.self::MAX_SKILLS],
            'resume.skills.*' => ['nullable', 'string', 'max:60'],
            'resume.experience' => ['nullable', 'array', 'max:'.self::MAX_JOBS],
            'resume.experience.*.role' => ['required', 'string', 'max:100'],
            'resume.experience.*.employer' => ['nullable', 'string', 'max:120'],
            'resume.experience.*.place' => ['nullable', 'string', 'max:80'],
            'resume.experience.*.period' => ['nullable', 'string', 'max:60'],
            'resume.experience.*.points' => ['nullable', 'array', 'max:6'],
            'resume.experience.*.points.*' => ['nullable', 'string', 'max:300'],
            'resume.education' => ['nullable', 'array', 'max:4'],
            'resume.education.*' => ['nullable', 'string', 'max:200'],
            'resume.languages' => ['nullable', 'array', 'max:8'],
            'resume.languages.*' => ['nullable', 'string', 'max:30'],
            'resume.extras' => ['nullable', 'array', 'max:6'],
            'resume.extras.*' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * The form as it should open: the answers saved last time, or else what
     * the profile already knows, so a karigar never types their name twice.
     *
     * @return array<string, mixed>
     */
    public function form(User $user): array
    {
        // Read fresh: a loaded relation can predate the last builder save.
        $profile = $user->workerProfile()->first();
        $saved = $profile?->resume_builder['input'] ?? null;

        if (is_array($saved)) {
            return $this->input($saved);
        }

        return $this->input([
            'language' => 'en',
            'name' => $user->name,
            'phone' => $user->phone ?? $profile?->phone,
            'email' => $user->contactEmail(),
            'city' => $profile?->city,
            'state' => $profile?->state,
            'trade' => '',
            'experience_years' => $profile?->experience_years,
            'skills' => $profile?->skills ?? [],
            'education' => $profile?->education,
            'languages' => $profile?->spoken_languages ?? [],
            'about' => $profile?->bio,
            'expected_wage' => $profile?->expected_wage,
        ]);
    }

    /**
     * Draft a resume from the form answers.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function draft(array $input): array
    {
        $input = $this->input($input);
        $base = $this->fallback($input);

        if (! $this->ai->configured()) {
            return $base;
        }

        try {
            $raw = $this->ai->chat(
                $this->systemPrompt($input['language']),
                $this->userPrompt($input),
                maxTokens: 1500,
                temperature: 0.4,
                json: true,
            );

            return $this->merge($base, $this->parse($raw), count($input['work']));
        } catch (Throwable $e) {
            Log::warning('ResumeWriter failed, using the plain resume: '.$e->getMessage());

            return $base;
        }
    }

    /**
     * Clean a resume that came back from the preview: trim every string, drop
     * empty lines, and put the contact block back from the form answers so an
     * edited request cannot swap someone else's number into the PDF.
     *
     * @param  array<string, mixed>  $resume
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function clean(array $resume, array $input): array
    {
        $input = $this->input($input);

        return [
            ...$this->contact($input),
            'headline' => $this->str($resume['headline'] ?? null),
            'summary' => $this->str($resume['summary'] ?? null),
            'skills' => $this->lines($resume['skills'] ?? []),
            'experience' => array_values(array_map(fn (array $job) => [
                'role' => $this->str($job['role'] ?? null),
                'employer' => $this->str($job['employer'] ?? null),
                'place' => $this->str($job['place'] ?? null),
                'period' => $this->str($job['period'] ?? null),
                'points' => $this->lines($job['points'] ?? []),
            ], array_filter($resume['experience'] ?? [], 'is_array'))),
            'education' => $this->lines($resume['education'] ?? []),
            'languages' => $this->lines($resume['languages'] ?? []),
            'extras' => $this->lines($resume['extras'] ?? []),
        ];
    }

    /**
     * The resume as plain text — what {@see AiMatcher} reads when it scores
     * this karigar's applications, in place of text parsed out of an upload.
     *
     * @param  array<string, mixed>  $resume
     */
    public function text(array $resume): string
    {
        $out = array_filter([
            $resume['name'] ?? null,
            $resume['headline'] ?? null,
            implode(' · ', array_filter([$resume['location'] ?? null, $resume['phone'] ?? null, $resume['email'] ?? null])),
            $resume['summary'] ?? null,
        ]);

        if ($resume['skills'] ?? []) {
            $out[] = 'Skills: '.implode(', ', $resume['skills']);
        }

        foreach ($resume['experience'] ?? [] as $job) {
            $out[] = implode(' — ', array_filter([$job['role'], $job['employer'], $job['place'], $job['period']]));
            foreach ($job['points'] as $point) {
                $out[] = '- '.$point;
            }
        }

        foreach (['education' => 'Education', 'languages' => 'Languages', 'extras' => 'More'] as $key => $label) {
            if ($resume[$key] ?? []) {
                $out[] = $label.': '.implode('; ', $resume[$key]);
            }
        }

        return mb_substr(implode("\n", $out), 0, ResumeParser::MAX_CHARS);
    }

    /**
     * Every form key present, trimmed and typed, whatever was sent.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function input(array $input): array
    {
        $wage = $input['expected_wage'] ?? null;

        return [
            'language' => in_array($input['language'] ?? null, self::LANGUAGES, true) ? $input['language'] : 'en',
            'name' => $this->str($input['name'] ?? null),
            'phone' => $this->str($input['phone'] ?? null),
            'email' => $this->str($input['email'] ?? null),
            'city' => $this->str($input['city'] ?? null),
            'state' => $this->str($input['state'] ?? null),
            'trade' => $this->str($input['trade'] ?? null),
            'experience_years' => is_numeric($input['experience_years'] ?? null) ? (int) $input['experience_years'] : null,
            'skills' => array_slice($this->lines($input['skills'] ?? []), 0, self::MAX_SKILLS),
            'tools' => $this->str($input['tools'] ?? null),
            'work' => array_slice(array_values(array_map(fn (array $job) => [
                'role' => $this->str($job['role'] ?? null),
                'employer' => $this->str($job['employer'] ?? null),
                'place' => $this->str($job['place'] ?? null),
                'from' => $this->str($job['from'] ?? null),
                'to' => $this->str($job['to'] ?? null),
                'current' => filter_var($job['current'] ?? false, FILTER_VALIDATE_BOOL),
                'details' => $this->str($job['details'] ?? null),
            ], array_filter($input['work'] ?? [], fn ($job) => is_array($job) && trim((string) ($job['role'] ?? '')) !== ''))), 0, self::MAX_JOBS),
            'education' => $this->str($input['education'] ?? null),
            'training' => $this->str($input['training'] ?? null),
            'languages' => $this->lines($input['languages'] ?? []),
            'about' => $this->str($input['about'] ?? null),
            'achievements' => $this->str($input['achievements'] ?? null),
            'expected_wage' => is_numeric($wage) && (float) $wage > 0 ? (int) round((float) $wage) : null,
            'relocate' => filter_var($input['relocate'] ?? false, FILTER_VALIDATE_BOOL),
            'template' => in_array($input['template'] ?? null, self::TEMPLATES, true) ? $input['template'] : self::TEMPLATES[0],
            'accent' => in_array($input['accent'] ?? null, self::ACCENTS, true) ? $input['accent'] : self::ACCENTS[0],
            'show_photo' => filter_var($input['show_photo'] ?? true, FILTER_VALIDATE_BOOL),
        ];
    }

    private function systemPrompt(string $language): string
    {
        // The answers often arrive in Roman Hindi ("roz 1 kurta banati hu");
        // the model has to be told twice that a Hindi resume means Devanagari,
        // or it copies the Roman spelling straight through.
        $writing = $language === 'hi'
            ? 'Hindi written ONLY in Devanagari script (देवनागरी), in simple everyday words a karigar uses. Convert any Roman-letter Hindi in the answers into Devanagari. Spell karigar as कारीगर. Write numbers with 0-9 digits, not Devanagari digits. Keep common English work words (machine, design, order, export) in English, and keep names of people, firms and places exactly as given'
            : 'plain, simple English that a small workshop owner reads easily. Translate any Hindi in the answers into English';

        return <<<TXT
        You write resumes for Indian karigars: skilled hand workers in crafts
        such as weaving, embroidery, zari, chikankari, tailoring, pottery, wood
        carving, block printing and jewellery making.
        You get the karigar's own answers as JSON. Turn them into a short,
        honest resume. Language: {$writing}.
        Rules:
        - Use ONLY facts in the answers. Never add a skill, tool, task, number,
          employer, date, award or qualification that is not in them. If
          something is not given, leave it out.
        - Resume style: no "I", "my" or "me" anywhere.
        - headline: one line, the trade plus years of experience if given.
        - summary: 2 to 3 sentences on the work they do, what they are best
          at, and that they are looking for work. E.g. "Chikankari karigar
          with 12 years of experience in hand embroidery on kurtas and sarees."
        - skills: short skill names found in the skills, tools and work
          details; at most 12; no duplicates.
        - experience: one entry per job in "work", in the SAME order, each with
          2 to 4 short points (under 15 words each) rewriting that job's
          details. Present tense for a job marked current, past tense
          otherwise. Never merge, drop or add jobs.
        - education: lines for schooling and any training or certificates.
        - extras: short lines for tools/machines used, achievements, and
          willingness to travel, only if given. Never mention salary.
        - Plain text inside the strings. No markdown, no bullet characters.
        Reply with ONLY a JSON object in exactly this shape:
        {"headline":"","summary":"","skills":[""],"experience":[{"points":[""]}],"education":[""],"extras":[""]}
        TXT;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function userPrompt(array $input): string
    {
        $answers = [
            'trade' => $input['trade'],
            'experience_years' => $input['experience_years'],
            'city' => $input['city'],
            'skills' => $input['skills'],
            'tools_and_machines' => $input['tools'],
            'work' => array_map(fn (array $job) => [
                'role' => $job['role'],
                'employer' => $job['employer'],
                'place' => $job['place'],
                'current' => $job['current'],
                'details' => $job['details'],
            ], $input['work']),
            'education' => $input['education'],
            'training_or_certificates' => $input['training'],
            'about_me' => $input['about'],
            'achievements' => $input['achievements'],
            'ready_to_travel_or_relocate' => $input['relocate'],
        ];

        $answers = array_filter($answers, fn ($v) => $v !== null && $v !== '' && $v !== []);

        $language = $input['language'] === 'hi'
            ? 'Write every string in Hindi, in Devanagari script (देवनागरी), not Roman letters.'
            : 'Write every string in English.';

        return json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n\n{$language} Return the JSON now.";
    }

    /**
     * Pull the resume out of the model's reply, tolerating prose around it.
     *
     * @return array<string, mixed>
     */
    private function parse(string $raw): array
    {
        if (! preg_match('/\{.*\}/s', $raw, $m)) {
            return [];
        }

        $json = json_decode($m[0], true);

        return is_array($json) ? $json : [];
    }

    /**
     * Lay the model's wording over the plain resume, keeping every fact field
     * (role, employer, place, period, languages, contact) from the form.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $ai
     * @return array<string, mixed>
     */
    private function merge(array $base, array $ai, int $jobs): array
    {
        $headline = $this->str($ai['headline'] ?? null);
        $summary = $this->str($ai['summary'] ?? null);
        $skills = array_slice($this->lines($ai['skills'] ?? []), 0, 12);
        $education = array_slice($this->lines($ai['education'] ?? []), 0, 4);
        $extras = array_slice($this->lines($ai['extras'] ?? []), 0, 6);
        $aiJobs = array_values(array_filter($ai['experience'] ?? [], 'is_array'));

        foreach (array_slice($base['experience'], 0, $jobs) as $i => $job) {
            $points = array_slice($this->lines($aiJobs[$i]['points'] ?? []), 0, 4);
            if ($points !== []) {
                $base['experience'][$i]['points'] = array_map(fn ($p) => mb_substr($p, 0, 300), $points);
            }
        }

        return [
            ...$base,
            'headline' => $headline ? mb_substr($headline, 0, 150) : $base['headline'],
            'summary' => $summary ? mb_substr($summary, 0, 1200) : $base['summary'],
            'skills' => $skills !== [] ? array_map(fn ($s) => mb_substr($s, 0, 60), $skills) : $base['skills'],
            'education' => $education !== [] ? array_map(fn ($s) => mb_substr($s, 0, 200), $education) : $base['education'],
            'extras' => $extras !== [] ? array_map(fn ($s) => mb_substr($s, 0, 300), $extras) : $base['extras'],
        ];
    }

    /**
     * The resume built straight from the answers, no model involved. Plain on
     * purpose: it is the no-key fallback and the base the model writes over.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function fallback(array $input): array
    {
        $hi = $input['language'] === 'hi';
        $years = $input['experience_years'];
        $trade = $input['trade'] ?? '';

        $headline = $trade.($years ? ' · '.($hi ? "{$years} साल का अनुभव" : "{$years} ".($years === 1 ? 'year' : 'years')) : '');

        $summary = $hi
            ? trim(($years ? "{$years} साल के अनुभव वाला " : '').$trade.'।'
                .($input['skills'] ? ' '.implode(', ', array_slice($input['skills'], 0, 5)).' का काम अच्छे से आता है।' : '')
                .' मेहनत और साफ़ काम के साथ अच्छे काम की तलाश है।')
            : trim($trade.($years ? " with {$years} ".($years === 1 ? 'year' : 'years').' of experience' : '').'.'
                .($input['skills'] ? ' Skilled in '.implode(', ', array_slice($input['skills'], 0, 5)).'.' : '')
                .' Careful, reliable work and looking for steady work.');

        if ($input['about']) {
            $summary = $input['about'];
        }

        $experience = array_map(fn (array $job) => [
            'role' => $job['role'],
            'employer' => $job['employer'],
            'place' => $job['place'],
            'period' => $this->period($job, $hi),
            'points' => $this->sentences($job['details']),
        ], $input['work']);

        $extras = array_filter([
            $input['tools'] ? ($hi ? 'औज़ार / मशीन: ' : 'Tools / machines: ').$input['tools'] : null,
            $input['achievements'],
            $input['relocate'] ? ($hi ? 'काम के लिए दूसरे शहर जाने को तैयार।' : 'Ready to travel or relocate for work.') : null,
        ]);

        return [
            ...$this->contact($input),
            'headline' => $headline,
            'summary' => $summary,
            'skills' => $input['skills'],
            'experience' => $experience,
            'education' => array_values(array_filter([$input['education'], $input['training']])),
            'languages' => $input['languages'],
            'extras' => array_values($extras),
        ];
    }

    /**
     * The fields copied verbatim from the form onto every resume, design
     * choices included.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function contact(array $input): array
    {
        return [
            'language' => $input['language'],
            'name' => $input['name'],
            'phone' => $input['phone'],
            'email' => $input['email'],
            'location' => implode(', ', array_filter([$input['city'], $input['state']])) ?: null,
            'experience_years' => $input['experience_years'],
            'relocate' => $input['relocate'],
            'template' => $input['template'],
            'accent' => $input['accent'],
            'show_photo' => $input['show_photo'],
            'expected_wage' => $input['expected_wage']
                ? '₹'.number_format($input['expected_wage']).($input['language'] === 'hi' ? ' / महीना' : ' / month')
                : null,
        ];
    }

    /**
     * "2019 – 2023", "2019 – Present", or null when no dates were given.
     *
     * @param  array<string, mixed>  $job
     */
    private function period(array $job, bool $hi): ?string
    {
        $to = $job['current'] ? ($hi ? 'अब तक' : 'Present') : $job['to'];

        return implode(' – ', array_filter([$job['from'], $to])) ?: null;
    }

    /**
     * Split free text into resume points: one per line or sentence.
     *
     * @return list<string>
     */
    private function sentences(?string $text): array
    {
        if (! $text) {
            return [];
        }

        $parts = preg_split('/(?<=[.।!?])\s+|\n+/u', $text) ?: [];

        return array_slice($this->lines(array_map(fn ($p) => rtrim(trim($p), '.।'), $parts)), 0, 4);
    }

    /**
     * Trimmed, non-empty, de-duplicated strings.
     *
     * @return list<string>
     */
    private function lines(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $value) {
            $value = is_scalar($value) ? trim((string) $value) : '';
            if ($value !== '' && ! in_array(mb_strtolower($value), array_map('mb_strtolower', $out), true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    private function str(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
