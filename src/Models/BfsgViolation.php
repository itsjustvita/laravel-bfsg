<?php

namespace ItsJustVita\LaravelBfsg\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BfsgViolation extends Model
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

    public function report(): BelongsTo
    {
        return $this->belongsTo(BfsgReport::class, 'report_id');
    }
}
