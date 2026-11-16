<?php

namespace App\Models;

use App\Enums\ScreeningCallStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The short AI call on the morning of a booked interview: "it is today at
 * four, are you coming?". One per interview time, so a moved interview gets
 * a fresh one.
 */
class InterviewReminderCall extends Model
{
    public const OUTCOMES = ['will_come', 'reschedule', 'not_coming', 'callback_later', 'unclear'];

    protected $fillable = [
        'job_application_id',
        'worker_id',
        'interview_at',
        'provider',
        'provider_call_id',
        'status',
        'language',
        'attempt',
        'scheduled_at',
        'outcome',
        'proposed_interview_at',
        'summary',
        'transcript',
        'failure_reason',
        'started_at',
        'ended_at',
        'duration_seconds',
        'credits_charged',
    ];

    protected function casts(): array
    {
        return [
            'status' => ScreeningCallStatus::class,
            'interview_at' => 'datetime',
            'proposed_interview_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }

    public function outcomeLabel(): ?string
    {
        return match ($this->outcome) {
            'will_come' => __('Confirmed, coming'),
            'reschedule' => __('Asked for another time'),
            'not_coming' => __('Not coming'),
            'callback_later' => __('Could not talk'),
            'unclear' => __('No clear answer'),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        $tz = config('app.display_timezone');

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'outcome' => $this->outcome,
            'outcome_label' => $this->outcomeLabel(),
            'summary' => $this->summary,
            'proposed_interview_at' => $this->proposed_interview_at?->toIso8601String(),
            // Indian wall-clock as the agent reported it, like a screening call's slot.
            'proposed_interview_label' => $this->proposed_interview_at?->format('d M Y, g:i A'),
            'scheduled_label' => $this->scheduled_at?->timezone($tz)->format('d M, g:i A'),
            'called_label' => $this->started_at?->timezone($tz)->format('d M, g:i A'),
            'credits_charged' => $this->credits_charged,
        ];
    }
}
