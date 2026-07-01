<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int         $id
 * @property int         $analysis_id
 * @property int         $owner_id
 * @property string      $invitee_email
 * @property int|null    $shared_with_user_id   Resolved after invite acceptance
 * @property string      $invite_token
 * @property string      $status                pending | accepted | revoked
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon|null $accepted_at
 */
class SharedAccess extends Model
{
    // Migration creates the table as singular "shared_access"; override the
    // default plural "shared_accesses" so queries hit the right table.
    protected $table = 'shared_access';

    protected $fillable = [
        'analysis_id',
        'owner_id',
        'invitee_email',
        'shared_with_user_id',
        'invite_token',
        'status',
        'expires_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'  => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_with_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'accepted')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Generate a cryptographically random 64-character invite token. */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isValid(): bool
    {
        return $this->isPending() && ! $this->isExpired();
    }

    /** Accept this invite and link the resolved user account. */
    public function accept(User $user): void
    {
        $this->update([
            'status'              => 'accepted',
            'shared_with_user_id' => $user->id,
            'accepted_at'         => now(),
        ]);
    }

    /** Revoke this share. */
    public function revoke(): void
    {
        $this->update(['status' => 'revoked']);
    }
}
