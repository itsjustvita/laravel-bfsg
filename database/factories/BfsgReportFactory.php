<?php

namespace ItsJustVita\LaravelBfsg\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;

/** @extends Factory<BfsgReport> */
class BfsgReportFactory extends Factory
{
    protected $model = BfsgReport::class;

    public function definition(): array
    {
        return [
            'url' => 'https://example.com/'.Str::lower(Str::random(8)),
            'total_violations' => 0,
            'score' => 100,
            'grade' => 'A+',
            'metadata' => ['compliance_level' => 'AA', 'source' => 'factory'],
        ];
    }
}
