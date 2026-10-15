<?php

namespace App\Support;

use App\Enums\ScreeningCallStatus;
use App\Models\ScreeningCall;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every AI screening call across an employer's jobs, for the "AI Interviews"
 * screen: what is booked and when, what is ringing now, and what each
 * finished call found out (with the interview it led to). Shared by the
 * website and the employer app.
 */
final class AiInterviewList
{
    public const FILTERS = ['upcoming', 'done', 'all'];

    /** Booked, or on its way: not finished yet. */
    private const OPEN = [
        ScreeningCallStatus::Scheduled,
        ScreeningCallStatus::Queued,
        ScreeningCallStatus::Dialing,
        ScreeningCallStatus::InProgress,
    ];

    /**
     * @return LengthAwarePaginator<int, ScreeningCall>
     */
    public static function page(User $employer, string $filter = 'upcoming', int $perPage = 20): LengthAwarePaginator
    {
        $query = self::base($employer)->with(
            'application:id,job_listing_id,worker_id,status,interview_at,interview_mode',
            'application.job:id,title',
            'application.worker:id,name,phone',
            'application.worker.workerProfile:id,user_id,phone,city,area',
        );

        match ($filter) {
            'done' => $query->whereNotIn('status', self::OPEN)->latest('updated_at'),
            'all' => $query->latest(),
            // Soonest first; a call ringing now sorts with its booking time or creation.
            default => $query->whereIn('status', self::OPEN)->orderByRaw('COALESCE(scheduled_at, created_at) asc'),
        };

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Tab badges.
     *
     * @return array{upcoming: int, done: int, all: int}
     */
    public static function counts(User $employer): array
    {
        $upcoming = self::base($employer)->whereIn('status', self::OPEN)->count();
        $all = self::base($employer)->count();

        return ['upcoming' => $upcoming, 'done' => $all - $upcoming, 'all' => $all];
    }

    /**
     * One row as both screens show it.
     *
     * @return array<string, mixed>
     */
    public static function row(ScreeningCall $call): array
    {
        $application = $call->application;
        $worker = $application?->worker;
        $tz = config('app.display_timezone');

        return [
            'id' => $call->id,
            'status' => $call->status->value,
            'status_label' => $call->status->label(),
            'source' => $call->source,
            'attempt' => $call->attempt,
            'scheduled_at' => $call->scheduled_at?->toIso8601String(),
            'scheduled_label' => $call->scheduled_at?->timezone($tz)->format('d M Y, g:i A'),
            'started_at' => $call->started_at?->toIso8601String(),
            'called_label' => ($call->started_at ?? $call->created_at)?->timezone($tz)->format('d M Y, g:i A'),
            'outcome' => $call->outcome?->value,
            'outcome_label' => $call->outcome?->label(),
            'summary' => $call->summary,
            // Whether "View conversation" has a transcript to show.
            'has_conversation' => $call->hasConversation(),
            'failure_reason' => $call->failure_reason,
            'proposed_interview_label' => $call->proposed_interview_at?->format('d M Y, g:i A'),
            'awaiting_confirmation' => $call->awaitingConfirmation(),
            'can_cancel' => $call->status === ScreeningCallStatus::Scheduled,
            'candidate' => [
                'application_id' => $application?->id,
                'worker_id' => $worker?->id,
                'name' => $worker?->name,
                'phone' => $worker?->workerProfile?->phone ?? $worker?->phone,
                'location_label' => KarigarLocation::label($worker?->workerProfile),
            ],
            'job' => $application?->job ? ['id' => $application->job->id, 'title' => $application->job->title] : null,
            // The interview the call led to, once the employer confirmed it.
            'interview' => $application?->interview_at !== null ? [
                'at_label' => $application->interview_at->format('d M Y, g:i A'),
                'mode' => $application->interview_mode,
            ] : null,
        ];
    }

    /**
     * @return Builder<ScreeningCall>
     */
    private static function base(User $employer): Builder
    {
        $account = $employer->employerAccount();

        return ScreeningCall::query()->whereHas('application.job', fn (Builder $job) => $job->where('employer_id', $account->id));
    }
}
