<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Labels for the job details added on 2026-11-19 (job type, education,
 * English, reach, fee purpose, documents, days, urgency), and the value +
 * label lists the job form and the employer app build their choices from.
 */
final class JobDetailLabels
{
    public static function jobType(?string $type): ?string
    {
        return match ($type) {
            'full_time' => __('Full time'),
            'part_time' => __('Part time'),
            default => null,
        };
    }

    /**
     * The lowest education a job takes, "or above".
     */
    public static function education(?string $level): ?string
    {
        return match ($level) {
            'Below 10th' => __('Below 10th pass is fine'),
            '10th Pass' => __('10th pass or above'),
            '12th Pass' => __('12th pass or above'),
            'ITI / Diploma' => __('ITI / Diploma or above'),
            'Graduate' => __('Graduate or above'),
            'Post Graduate' => __('Post graduate'),
            default => null,
        };
    }

    public static function english(?string $level): ?string
    {
        return match ($level) {
            'none' => __('No English needed'),
            'basic' => __('Thoda English'),
            'good' => __('Good English'),
            'fluent' => __('Fluent English'),
            default => null,
        };
    }

    public static function reach(?string $reach, ?string $city = null): ?string
    {
        return match ($reach) {
            'nearby' => __('Karigars within :km km', ['km' => ReferenceData::NEARBY_KM]),
            'city' => filled($city) ? __('Karigars from anywhere in :city', ['city' => $city]) : __('Karigars from anywhere in the city'),
            default => null,
        };
    }

    public static function feePurpose(?string $purpose): ?string
    {
        return match ($purpose) {
            'uniform' => __('Uniform / T-shirt / bag'),
            'tools' => __('Tools / kit'),
            'security_deposit' => __('Security deposit'),
            'training' => __('Training fee'),
            'exam' => __('Exam fee'),
            'documentation' => __('Documentation'),
            'other' => __('Other'),
            default => null,
        };
    }

    public static function asset(string $asset): string
    {
        return match ($asset) {
            'aadhaar' => __('Aadhaar card'),
            'pan' => __('PAN card'),
            'bank_account' => __('Bank account'),
            'smartphone' => __('Smartphone'),
            'bike' => __('Bike'),
            'driving_licence' => __('Driving licence'),
            'own_tools' => __('Own tools'),
            'certificate' => __('ITI / training certificate'),
            default => $asset,
        };
    }

    public static function urgency(?string $urgency): ?string
    {
        return match ($urgency) {
            'immediate' => __('Hiring immediately'),
            'can_wait' => __('Can wait'),
            default => null,
        };
    }

    public static function weekday(string $day): string
    {
        return match ($day) {
            'mon' => __('Mon'), 'tue' => __('Tue'), 'wed' => __('Wed'), 'thu' => __('Thu'),
            'fri' => __('Fri'), 'sat' => __('Sat'), 'sun' => __('Sun'),
            default => $day,
        };
    }

    /**
     * Days as people say them: "Every day", "Mon–Sat", "Mon, Wed, Fri".
     *
     * @param  list<string>|null  $days
     */
    public static function days(?array $days): ?string
    {
        $order = array_flip(ReferenceData::WEEKDAYS);
        $picked = collect($days ?? [])->filter(fn ($d) => isset($order[$d]))->unique()
            ->sortBy(fn ($d) => $order[$d])->values();

        if ($picked->isEmpty()) {
            return null;
        }

        if ($picked->count() === 7) {
            return __('Every day');
        }

        $indexes = $picked->map(fn ($d) => $order[$d]);
        $consecutive = $indexes->last() - $indexes->first() === $picked->count() - 1;

        if ($consecutive && $picked->count() >= 3) {
            return self::weekday($picked->first()).'–'.self::weekday($picked->last());
        }

        return $picked->map(fn ($d) => self::weekday($d))->join(', ');
    }

    /** "18–40 yrs", "18+ yrs", "Up to 40 yrs". */
    public static function age(?int $min, ?int $max): ?string
    {
        return match (true) {
            $min !== null && $max !== null => __(':min–:max yrs', ['min' => $min, 'max' => $max]),
            $min !== null => __(':min+ yrs', ['min' => $min]),
            $max !== null => __('Up to :max yrs', ['max' => $max]),
            default => null,
        };
    }

    /** "Up to ₹2,000 a month · Commission". */
    public static function incentive(mixed $max, ?string $type): ?string
    {
        if ($max === null || (float) $max <= 0) {
            return null;
        }

        $line = __('Up to :amount a month', ['amount' => '₹'.number_format((float) $max)]);

        return filled($type) ? $line.' · '.trim((string) $type) : $line;
    }

    /** "11:00 AM – 4:00 PM". */
    public static function hours(?string $start, ?string $end): ?string
    {
        if (! $start || ! $end) {
            return null;
        }

        $time = fn (string $t) => Carbon::createFromFormat('H:i', $t)->format('g:i A');

        return $time($start).' – '.$time($end);
    }

    /**
     * Value + label lists for the job form and the app.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function options(): array
    {
        $list = fn (array $values, callable $label) => array_map(fn ($v) => ['value' => $v, 'label' => (string) $label($v)], $values);

        return [
            'job_types' => $list(ReferenceData::JOB_TYPES, self::jobType(...)),
            'education_levels' => $list(ReferenceData::EDUCATION_LEVELS, self::education(...)),
            'english_levels' => $list(ReferenceData::ENGLISH_LEVELS, self::english(...)),
            'reach' => $list(ReferenceData::JOB_REACH, fn ($v) => self::reach($v)),
            'worker_fee_purposes' => $list(ReferenceData::WORKER_FEE_PURPOSES, self::feePurpose(...)),
            'assets' => $list(ReferenceData::JOB_ASSETS, self::asset(...)),
            'weekdays' => $list(ReferenceData::WEEKDAYS, self::weekday(...)),
            'hiring_urgencies' => $list(ReferenceData::HIRING_URGENCIES, self::urgency(...)),
        ];
    }
}
