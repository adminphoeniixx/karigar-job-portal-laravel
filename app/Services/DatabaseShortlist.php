<?php

namespace App\Services;

use App\Models\JobApplication;
use App\Models\JobInvite;
use App\Models\JobListing;
use App\Models\User;
use App\Models\WorkerProfile;
use App\Notifications\JobInviteNotification;
use App\Services\Screening\ScreeningService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "AI shortlist from the Worker Database": for one job, the AI picks the
 * karigars in the database who fit best and invites them. Nobody is rung out
 * of the blue: a karigar who accepts the invite (applies) is shortlisted, and
 * only then, if the employer asked for it, gets an AI screening call booked.
 * No database unlock is spent; an applicant's number shows anyway.
 *
 * Many karigars are ranked with the quick score first, and only the best few
 * go to the model, so the employer is not kept waiting on dozens of AI calls.
 */
class DatabaseShortlist
{
    /** Below this the model does not think the karigar fits. */
    public const MIN_SCORE = 60;

    public const MAX_PICKS = 10;

    /** Karigars looked at per pick, before scoring. */
    private const POOL = 40;

    public function __construct(private AiMatcher $matcher) {}

    /**
     * Pick and invite up to $count karigars.
     *
     * @return list<array{worker: User, score: int, summary: string}>
     */
    public function pick(JobListing $job, User $employer, int $count = 5, bool $autoCall = true, ?CarbonImmutable $callAt = null, ?string $message = null): array
    {
        $count = max(1, min(self::MAX_PICKS, $count));

        $ranked = $this->candidates($job, $employer)
            ->map(fn (WorkerProfile $profile) => ['worker' => $profile->user, 'quick' => $this->matcher->quickScore($job, $profile->user)])
            ->sortByDesc('quick')
            ->take(min(self::MAX_PICKS, $count + 3));

        $picks = $ranked
            ->map(function (array $row) use ($job) {
                $score = $this->matcher->score($job, $row['worker']);

                return ['worker' => $row['worker'], 'score' => (int) $score['score'], 'summary' => (string) ($score['summary'] ?? '')];
            })
            ->filter(fn (array $row) => $row['score'] >= self::MIN_SCORE)
            ->sortByDesc('score')
            ->take($count)
            ->values();

        $job->loadMissing('employer:id,name');

        foreach ($picks as $pick) {
            JobInvite::create([
                'job_listing_id' => $job->id,
                'worker_id' => $pick['worker']->id,
                'employer_id' => $job->employer_id,
                'message' => $message,
                'source' => 'ai',
                'ai_score' => $pick['score'],
                'ai_summary' => $pick['summary'],
                'auto_call' => $autoCall,
                'call_at' => $callAt?->setTimezone(config('app.timezone')),
            ]);

            $pick['worker']->notify(new JobInviteNotification($job, $message));
        }

        return $picks->all();
    }

    /**
     * A karigar the AI invited has applied: shortlist them, and book the AI
     * call the employer asked for (at their time, or the next calling hour;
     * `screening:place-due` rings it).
     */
    public static function onApplied(JobApplication $application): void
    {
        $invite = JobInvite::where('job_listing_id', $application->job_listing_id)
            ->where('worker_id', $application->worker_id)
            ->where('source', 'ai')
            ->first();

        if ($invite === null) {
            return;
        }

        if ($application->shortlisted_at === null) {
            $application->update(['shortlisted_at' => now()]);
        }

        if ($invite->auto_call) {
            app(ScreeningService::class)->schedule(
                $application,
                $invite->call_at?->isFuture() ? $invite->call_at->toImmutable() : null,
                'invite',
            );
        }
    }

    /**
     * Available karigars in the job's trade who have not applied, been
     * invited or been rejected by this employer; same city first.
     *
     * @return Collection<int, WorkerProfile>
     */
    private function candidates(JobListing $job, User $employer): Collection
    {
        $skills = collect($job->skills ?? [])->filter()->push($job->category)->filter()->unique()->values();
        $excluded = $job->applications()->pluck('worker_id')
            ->merge($job->invites()->pluck('worker_id'))
            ->merge(WorkerRejections::for($employer)->ids())
            ->unique()
            ->all();

        return WorkerProfile::query()
            ->with('user:id,name,phone,role', 'user.kyc')
            ->whereHas('user', fn (Builder $q) => $q->where('role', 'worker'))
            ->whereNotIn('user_id', $excluded)
            ->where('available', true)
            ->when($skills->isNotEmpty(), fn (Builder $q) => $q->where(function (Builder $q) use ($skills) {
                foreach ($skills as $skill) {
                    $q->orWhereJsonContains('skills', $skill);
                }
            }))
            ->when($job->city, fn (Builder $q) => $q->orderByRaw('CASE WHEN LOWER(city) = ? THEN 0 ELSE 1 END', [mb_strtolower((string) $job->city)]))
            ->orderByDesc('experience_years')
            ->limit(self::POOL)
            ->get()
            ->filter(fn (WorkerProfile $profile) => $profile->user !== null)
            ->values();
    }
}
