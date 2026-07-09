<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property UserRole $role
 * @property string $locale
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'phone', 'password', 'role', 'locale', 'email_verified_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'suspended_at' => 'datetime',
            'role' => UserRole::class,
        ];
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isWorker(): bool
    {
        return $this->role === UserRole::Worker;
    }

    public function isEmployer(): bool
    {
        return $this->role === UserRole::Employer;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * @return HasOne<WorkerProfile, $this>
     */
    public function workerProfile(): HasOne
    {
        return $this->hasOne(WorkerProfile::class);
    }

    /**
     * @return HasOne<EmployerProfile, $this>
     */
    public function employerProfile(): HasOne
    {
        return $this->hasOne(EmployerProfile::class);
    }

    /**
     * @return HasOne<KycDocument, $this>
     */
    public function kyc(): HasOne
    {
        return $this->hasOne(KycDocument::class);
    }

    /**
     * @return HasMany<JobListing, $this>
     */
    public function jobListings(): HasMany
    {
        return $this->hasMany(JobListing::class, 'employer_id');
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'employer_id');
    }

    /**
     * Applications this user (a worker) has submitted.
     *
     * @return HasMany<JobApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class, 'worker_id');
    }

    /**
     * @return HasMany<SavedJob, $this>
     */
    public function savedJobs(): HasMany
    {
        return $this->hasMany(SavedJob::class);
    }

    /**
     * Reviews written about this user.
     *
     * @return HasMany<Review, $this>
     */
    public function reviewsReceived(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewee_id');
    }

    /**
     * Reviews this user has written.
     *
     * @return HasMany<Review, $this>
     */
    public function reviewsGiven(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewer_id');
    }

    public function averageRating(): float
    {
        return round((float) $this->reviewsReceived()->avg('rating'), 1);
    }

    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->whereIn('status', array_map(fn ($s) => $s->value, SubscriptionStatus::entitled()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->latest()
            ->first();
    }

    public function hasActiveSubscription(): bool
    {
        return $this->activeSubscription() !== null;
    }

    /**
     * How many worker-database contacts this employer can access: the active
     * plan's quota plus any admin-granted bonus. Zero without an active plan.
     */
    public function contactDatabaseQuota(): int
    {
        $planQuota = $this->activeSubscription()?->plan->contactDatabaseLimit() ?? 0;
        $bonus = (int) ($this->employerProfile?->contact_quota_bonus ?? 0);

        return $planQuota + $bonus;
    }
}
