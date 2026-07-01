<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int         $id
 * @property string      $name
 * @property string      $email
 * @property string|null $stripe_id
 * @property string|null $pm_type
 * @property string|null $pm_last_four
 * @property string      $membership        free | pro
 * @property int         $plan_id
 * @property string      $locale
 * @property bool        $marketing_opt_in
 * @property string|null $consent_version
 * @property \Carbon\Carbon|null $consented_at
 * @property \Carbon\Carbon|null $trial_ends_at
 * @property \Carbon\Carbon|null $data_deletion_requested_at
 * @property \Carbon\Carbon|null $email_verified_at
 * @property \Carbon\Carbon|null $deleted_at
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        // Stripe / Cashier fields
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'trial_ends_at',
        // Membership & plan
        'membership',
        'plan_id',
        // Preferences
        'locale',
        'marketing_opt_in',
        // GDPR compliance
        'consent_version',
        'consented_at',
        'data_deletion_requested_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'           => 'datetime',
            'consented_at'                => 'datetime',
            'trial_ends_at'               => 'datetime',
            'data_deletion_requested_at'  => 'datetime',
            'marketing_opt_in'            => 'boolean',
            'password'                    => 'hashed',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(Analysis::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** Most recent active subscription. */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->where('stripe_status', 'active')
            ->latestOfMany();
    }

    /** All reports shared by this user with others. */
    public function sharedByMe(): HasMany
    {
        return $this->hasMany(SharedAccess::class, 'owner_id');
    }

    /** All reports shared with this user by others. */
    public function sharedWithMe(): HasMany
    {
        return $this->hasMany(SharedAccess::class, 'shared_with_user_id');
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Returns or creates the usage record for the current billing period. */
    public function currentUsage(): UsageRecord
    {
        $period = now()->format('Y-m');

        return UsageRecord::firstOrCreate(
            ['user_id' => $this->id, 'period' => $period],
            ['analyses_used' => 0, 'tokens_used' => 0, 'assistant_messages_used' => 0]
        );
    }

    // NOTE: Quotas are currently disabled — the app is open/unlimited for
    // all users. Re-enable the original checks below to bring back plan limits.
    public function hasReachedAnalysisLimit(): bool
    {
        return false;
    }

    public function hasReachedTokenLimit(): bool
    {
        return false;
    }

    /** Whether the user is on a paid membership tier. */
    public function isPro(): bool
    {
        return $this->membership === 'pro';
    }

    /** Whether the user has a pending GDPR deletion request. */
    public function hasPendingDeletion(): bool
    {
        return $this->data_deletion_requested_at !== null;
    }

    /**
     * Upgrade the cached membership tier. Called from the Stripe webhook
     * handler after a successful subscription event.
     */
    public function upgradeMembership(string $tier): void
    {
        $this->update(['membership' => $tier]);
    }
}
