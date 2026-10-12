<?php

namespace App\Support;

use App\Enums\JobStatus;
use App\Models\JobListing;
use App\Models\User;
use App\Notifications\JobModerationNotification;
use App\Services\JobModerator;

/**
 * The safety check where a job goes live, shared by the website and the
 * employer app. A job that looks harmful ({@see JobModerator}) is saved as a
 * draft held for review instead: nothing of the plan is spent, no karigar is
 * alerted, and an admin approves (it goes live) or rejects it.
 */
final class JobModeration
{
    /** Fields whose change on a live job is checked again. */
    private const TEXT = ['title', 'description', 'category', 'skills', 'perks', 'requires_worker_fee', 'worker_fee_amount'];

    /**
     * Check a job about to be live. Returns the reasons to hold it (and turns
     * $data into a draft) or null when it may go live.
     *
     * @param  array<string, mixed>  $data  the validated input, changed in place when held
     * @return list<string>|null
     */
    public static function screen(array &$data, ?JobListing $existing = null): ?array
    {
        $fields = array_merge($existing?->only(self::TEXT) ?? [], $data);
        $result = app(JobModerator::class)->check($fields);

        if (! $result['flagged']) {
            return null;
        }

        $data['status'] = JobStatus::Draft->value;

        return $result['reasons'];
    }

    /**
     * Whether saving $data would put text a reader has not checked yet in
     * front of karigars: publishing, or changing a live job's words.
     *
     * @param  array<string, mixed>  $data
     */
    public static function needsCheck(array $data, ?JobListing $existing = null): bool
    {
        if (($data['status'] ?? $existing?->status?->value) !== JobStatus::Active->value) {
            return false;
        }

        if ($existing === null || $existing->status !== JobStatus::Active) {
            return true;
        }

        foreach (self::TEXT as $field) {
            if (array_key_exists($field, $data) && $data[$field] != $existing->getAttribute($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mark a saved job as held, and tell the employer.
     *
     * @param  list<string>  $reasons
     */
    public static function hold(JobListing $job, array $reasons, User $employer): void
    {
        $job->update([
            'status' => JobStatus::Draft,
            'moderation_status' => 'pending',
            'moderation_reasons' => $reasons,
            'moderation_note' => null,
            'moderated_at' => null,
            'moderated_by' => null,
        ]);

        $employer->notify(new JobModerationNotification($job, 'held'));
    }

    /**
     * What the employer's screens show about a held job, or null.
     *
     * @return array{status: string, reasons: list<string>, note: string|null}|null
     */
    public static function summary(JobListing $job): ?array
    {
        if ($job->moderation_status === null || $job->moderation_status === 'approved') {
            return null;
        }

        return [
            'status' => $job->moderation_status,
            'reasons' => $job->moderation_reasons ?? [],
            'note' => $job->moderation_note,
        ];
    }

    public static function heldMessage(): string
    {
        return __('Your job needs a quick check by our team before it goes live, usually within a working day. Nothing from your plan has been used.');
    }
}
