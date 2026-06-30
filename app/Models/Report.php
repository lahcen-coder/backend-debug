<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    protected $fillable = [
        'analysis_id', 'chemistry_score', 'chemistry_breakdown',
        'common_ground', 'memory_box', 'misunderstandings', 'icebreakers',
        'safety_flag', 'ai_model', 'raw_ai_output', 'raw_ai_expires_at',
    ];

    protected $casts = [
        'chemistry_breakdown' => 'array',
        'common_ground'       => 'array',
        'memory_box'          => 'array',
        'misunderstandings'   => 'array',
        'icebreakers'         => 'array',
        'raw_ai_output'       => 'array',
        'safety_flag'         => 'boolean',
        'raw_ai_expires_at'   => 'datetime',
    ];

    protected $hidden = ['raw_ai_output'];

    public function analysis()
    {
        return $this->belongsTo(Analysis::class);
    }
}
