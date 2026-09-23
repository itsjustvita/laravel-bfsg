<?php

namespace ItsJustVita\LaravelBfsg\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ItsJustVita\LaravelBfsg\Database\Factories\BfsgReportFactory;

class BfsgReport extends BfsgModel
{
    /** @use HasFactory<BfsgReportFactory> */
    use HasFactory;

    protected $table = 'bfsg_reports';

    protected $fillable = [
        'url',
        'total_violations',
        'score',
        'grade',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'score' => 'float',
        'total_violations' => 'integer',
        'created_at' => 'datetime',
    ];

    public function violations(): HasMany
    {
        return $this->hasMany(BfsgViolation::class, 'report_id');
    }

    public function scopeForUrl($query, string $url)
    {
        return $query->where('url', $url);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    protected static function newFactory(): BfsgReportFactory
    {
        return BfsgReportFactory::new();
    }
}
