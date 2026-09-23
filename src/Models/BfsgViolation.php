<?php

namespace ItsJustVita\LaravelBfsg\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ItsJustVita\LaravelBfsg\Database\Factories\BfsgViolationFactory;

/**
 * One stored finding. `key` is the translation key (`images.missing_alt`), `fingerprint` the stable
 * Violation::fingerprint(), `context` holds selector, snippet, params, meta, related, tags and auto_fixable.
 */
class BfsgViolation extends BfsgModel
{
    /** @use HasFactory<BfsgViolationFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'bfsg_violations';

    protected $fillable = [
        'report_id',
        'analyzer',
        'key',
        'severity',
        'message',
        'element',
        'wcag_rule',
        'suggestion',
        'fingerprint',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(BfsgReport::class, 'report_id');
    }

    protected static function newFactory(): BfsgViolationFactory
    {
        return BfsgViolationFactory::new();
    }
}
