<?php

namespace ItsJustVita\LaravelBfsg\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ItsJustVita\LaravelBfsg\Database\Factories\BfsgReportFactory;
use ItsJustVita\LaravelBfsg\Persistence\ReportRepository;

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

    /** Reports of $url, compared in its stored form (ReportRepository::storedUrl()), so a pasted URL with query string, fragment or credentials matches. */
    public function scopeForUrl($query, string $url)
    {
        return $query->where('url', ReportRepository::storedUrl($url));
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
