<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiRequest extends Model
{
    protected $fillable = [
        'analysis_id', 'user_id', 'provider', 'model', 'operation',
        'tokens_in', 'tokens_out', 'cost_usd', 'latency_ms', 'status',
    ];

    protected $casts = ['cost_usd' => 'decimal:6'];

    public function analysis() { return $this->belongsTo(Analysis::class); }
    public function user()     { return $this->belongsTo(User::class); }
}
