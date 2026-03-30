<?php

namespace ItsJustVita\LaravelBfsg\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BfsgReport extends Model
{
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
        'score' => 'decimal:2',
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
}
