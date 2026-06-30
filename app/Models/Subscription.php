<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'user_id', 'plan_id', 'stripe_subscription_id',
        'status', 'current_period_end', 'trial_ends_at', 'canceled_at',
    ];

    protected $casts = [
        'current_period_end' => 'datetime',
        'trial_ends_at'      => 'datetime',
        'canceled_at'        => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function plan() { return $this->belongsTo(Plan::class); }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trialing']);
    }
}
