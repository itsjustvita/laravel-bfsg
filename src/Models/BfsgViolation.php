<?php

namespace ItsJustVita\LaravelBfsg\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BfsgViolation extends BfsgModel
{
    public $timestamps = false;

    protected $table = 'bfsg_violations';

    protected $fillable = [
        'report_id',
        'analyzer',
        'severity',
        'message',
        'element',
        'wcag_rule',
        'suggestion',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(BfsgReport::class, 'report_id');
    }
}
