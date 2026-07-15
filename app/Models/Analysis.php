<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int         $id
 * @property int         $user_id
 * @property string|null $partner_name       SHA-256 hash of contact label (privacy-safe)
 * @property string      $platform           instagram | whatsapp
 * @property string      $status             pending | processing | completed | failed
 * @property string|null $clean_payload_hash SHA-256 of normalised payload (kept for analytics only, not unique)
 * @property string      $language           english | spanish | darija
 * @property string|null $ai_provider        gemini | openai
 * @property array|null  $report_data        Structured AI report (chemistry_score, insights, etc.)
 * @property int         $message_count
 * @property string|null $failure_reason
 * @property bool        $safety_flag
 * @property \Carbon\Carbon|null $processed_at
 */
class Analysis extends Model
{
    protected $fillable = [
        'user_id',
        'partner_name',
        'platform',
        'status',
        'language',
        'clean_payload_hash',
        'ai_provider',
        'report_data',
        'message_count',
        'failure_reason',
        'safety_flag',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'report_data'  => 'array',
            'safety_flag'  => 'boolean',
            'processed_at' => 'datetime',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function report(): HasOne
    {
        return $this->hasOne(Report::class);
    }

    public function aiRequests(): HasMany
    {
        return $this->hasMany(AiRequest::class);
    }

    public function sharedAccess(): HasMany
    {
        return $this->hasMany(SharedAccess::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeForPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    // ── State Machine ─────────────────────────────────────────────────────────

    public function markProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markCompleted(array $reportData, string $aiProvider): void
    {
        $this->update([
            'status'       => 'completed',
            'report_data'  => $reportData,
            'ai_provider'  => $aiProvider,
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $reason = ''): void
    {
        $this->update([
            'status'         => 'failed',
            'failure_reason' => $reason,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function chemistryScore(): ?int
    {
        return $this->report_data['chemistry_score'] ?? null;
    }

    /**
     * Hash a raw contact name to a SHA-256 digest before persisting.
     * Never store the plaintext partner name server-side.
     */
    public static function hashPartnerName(string $rawName): string
    {
        return hash('sha256', mb_strtolower(trim($rawName)));
    }
}
