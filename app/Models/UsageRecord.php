<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageRecord extends Model
{
    protected $fillable = [
        'user_id', 'period', 'analyses_used', 'tokens_used', 'assistant_messages_used',
    ];

    public function user() { return $this->belongsTo(User::class); }

    public function incrementAnalysis(): void
    {
        $this->increment('analyses_used');
    }

    public function incrementTokens(int $tokens): void
    {
        $this->increment('tokens_used', $tokens);
    }

    public function incrementAssistantMessages(): void
    {
        $this->increment('assistant_messages_used');
    }
}
