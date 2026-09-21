<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Mcp\Tools\CheckContrast;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use ItsJustVita\LaravelBfsg\Models\BfsgViolation;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use Laravel\Mcp\Request;

class BfsgMcpServerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    // --- analyze_html logic ---

    public function test_analyze_html_returns_violations_and_score(): void
    {
        $html = '<!DOCTYPE html><html><body><img src="test.jpg"></body></html>';

        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($html)->toArray()['violations'];

        $report = new ReportGenerator('inline-html', $violations);
        $stats = $report->getStats();

        $this->assertNotEmpty($violations);
        $this->assertGreaterThan(0, $stats['total_issues']);
        $this->assertLessThan(100, $stats['compliance_score']);
        $this->assertNotEmpty($stats['grade']);
    }

    public function test_analyze_html_clean_html_returns_perfect_score(): void
    {
        $html = '<!DOCTYPE html><html lang="en"><head><title>Test Page</title></head><body>'
            .'<header><nav><a href="#main">Skip</a></nav></header>'
            .'<main id="main"><h1>Title</h1>'
            .'<img src="test.jpg" alt="Description">'
            .'<form aria-label="Contact"><label for="email">Email</label>'
            .'<input type="email" id="email" name="email" autocomplete="email"></form>'
            .'<a href="/about">Learn more about us</a>'
            .'<div aria-live="polite"></div>'
            .'</main><footer><p>Footer</p></footer></body></html>';

        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($html)->toArray()['violations'];

        $report = new ReportGenerator('inline-html', $violations);
        $stats = $report->getStats();

        $this->assertEmpty($violations);
        $this->assertEquals(100, $stats['compliance_score']);
        $this->assertEquals('A+', $stats['grade']);
    }

    // --- analyze_url logic ---

    public function test_analyze_url_fetches_and_analyzes(): void
    {
        $html = '<!DOCTYPE html><html><body><img src="test.jpg"></body></html>';

        Http::fake([
            'https://example.com' => Http::response($html, 200),
        ]);

        $response = Http::withOptions(['verify' => false])
            ->timeout(30)
            ->get('https://example.com');

        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($response->body())->toArray()['violations'];

        $this->assertNotEmpty($violations);
        $this->assertArrayHasKey('images', $violations);
    }

    public function test_analyze_url_fails_on_bad_url(): void
    {
        Http::fake([
            'https://bad.example.com' => Http::response('', 500),
        ]);

        $response = Http::get('https://bad.example.com');
        $this->assertTrue($response->failed());
    }

    // --- check_contrast logic ---

    public function test_check_contrast_good_ratio(): void
    {
        $payload = $this->checkContrast('#000000', '#ffffff');

        $this->assertEqualsWithDelta(21.0, $payload['ratio'], 0.01);
        $this->assertTrue($payload['aa_normal']);
        $this->assertTrue($payload['aaa_normal']);
    }

    public function test_check_contrast_bad_ratio(): void
    {
        $payload = $this->checkContrast('#999999', '#aaaaaa');

        $this->assertLessThan(4.5, $payload['ratio']);
        $this->assertFalse($payload['aa_normal']);
    }

    public function test_check_contrast_invalid_colors(): void
    {
        $response = (new CheckContrast)->handle(new Request(['foreground' => 'not-a-color', 'background' => '#ffffff']));

        $this->assertTrue($response->isError());
    }

    /** @return array<string, mixed> */
    private function checkContrast(string $foreground, string $background): array
    {
        $response = (new CheckContrast)->handle(new Request(['foreground' => $foreground, 'background' => $background]));

        $this->assertFalse($response->isError());

        return json_decode((string) $response->content(), true);
    }

    // --- list_analyzers logic ---

    public function test_list_analyzers_returns_all_16(): void
    {
        $checks = config('bfsg.checks');
        $this->assertCount(16, $checks);
    }

    // --- get_history logic ---

    public function test_get_history_returns_reports(): void
    {
        BfsgReport::create(['url' => 'https://example.com', 'total_violations' => 5, 'score' => 82, 'grade' => 'B']);
        BfsgReport::create(['url' => 'https://other.com', 'total_violations' => 0, 'score' => 100, 'grade' => 'A+']);

        $reports = BfsgReport::latest()->limit(20)->get();
        $this->assertCount(2, $reports);
    }

    public function test_get_history_filters_by_url(): void
    {
        BfsgReport::create(['url' => 'https://example.com', 'total_violations' => 5, 'score' => 82, 'grade' => 'B']);
        BfsgReport::create(['url' => 'https://other.com', 'total_violations' => 0, 'score' => 100, 'grade' => 'A+']);

        $reports = BfsgReport::forUrl('https://example.com')->get();
        $this->assertCount(1, $reports);
        $this->assertEquals('https://example.com', $reports->first()->url);
    }

    // --- get_report logic ---

    public function test_get_report_returns_report_with_violations(): void
    {
        $report = BfsgReport::create(['url' => 'https://example.com', 'total_violations' => 1, 'score' => 95, 'grade' => 'A']);
        BfsgViolation::create([
            'report_id' => $report->id,
            'analyzer' => 'images',
            'severity' => 'error',
            'message' => 'Missing alt',
        ]);

        $loaded = BfsgReport::with('violations')->find($report->id);
        $this->assertNotNull($loaded);
        $this->assertCount(1, $loaded->violations);
        $this->assertEquals('images', $loaded->violations->first()->analyzer);
    }

    public function test_get_report_not_found(): void
    {
        $report = BfsgReport::find(99999);
        $this->assertNull($report);
    }

    // --- generate_report logic ---

    public function test_generate_report_json_format(): void
    {
        $html = '<!DOCTYPE html><html><body><img src="test.jpg"></body></html>';

        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($html)->toArray()['violations'];
        $reportGenerator = new ReportGenerator('https://example.com', $violations);

        $json = $reportGenerator->setFormat('json')->generate();
        $data = json_decode($json, true);

        $this->assertArrayHasKey('meta', $data);
        $this->assertArrayHasKey('stats', $data);
        $this->assertArrayHasKey('violations', $data);
    }

    public function test_generate_report_with_save(): void
    {
        $html = '<!DOCTYPE html><html><body><img src="test.jpg"></body></html>';

        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($html)->toArray()['violations'];
        $reportGenerator = new ReportGenerator('https://example.com', $violations);
        $stats = $reportGenerator->getStats();

        $dbReport = BfsgReport::create([
            'url' => 'https://example.com',
            'total_violations' => $stats['total_issues'],
            'score' => $stats['compliance_score'],
            'grade' => $stats['grade'],
        ]);

        $this->assertDatabaseHas('bfsg_reports', ['url' => 'https://example.com']);
    }
}
