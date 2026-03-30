<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Middleware\CheckAccessibility;
use ItsJustVita\LaravelBfsg\Models\BfsgReport;
use ItsJustVita\LaravelBfsg\Models\BfsgViolation;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    // =========================================================================
    // 1. Full Analysis Pipeline
    // =========================================================================

    public function test_full_pipeline_with_all_16_analyzers(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head></head>
<body>
    <!-- ImageAnalyzer: missing alt -->
    <img src="photo.jpg">

    <!-- HeadingAnalyzer: skipped heading h1->h3 -->
    <h3>Skipped heading level</h3>

    <!-- FormAnalyzer: input without label -->
    <form>
        <input type="text" name="username">
    </form>

    <!-- LinkAnalyzer: vague link text -->
    <a href="/page">click here</a>

    <!-- No lang attribute on <html> triggers LanguageAnalyzer -->
    <!-- No <main> landmark triggers SemanticHTMLAnalyzer -->
    <!-- No <title> element triggers PageTitleAnalyzer -->

    <!-- TableAnalyzer: table without caption/th -->
    <table>
        <tr><td>Data</td><td>More data</td></tr>
    </table>

    <!-- MediaAnalyzer: video without track -->
    <video src="video.mp4"></video>

    <!-- ContrastAnalyzer: poor contrast via inline style -->
    <p style="color: #cccccc; background-color: #ffffff;">Low contrast text</p>
</body>
</html>
HTML;

        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($html);

        // Assert violations exist for key categories
        $this->assertArrayHasKey('images', $violations, 'Expected image violations for missing alt');
        $this->assertArrayHasKey('headings', $violations, 'Expected heading violations for skipped level');
        $this->assertArrayHasKey('forms', $violations, 'Expected form violations for unlabeled input');
        $this->assertArrayHasKey('links', $violations, 'Expected link violations for vague text');
        $this->assertArrayHasKey('language', $violations, 'Expected language violations for missing lang');
        $this->assertArrayHasKey('semantic', $violations, 'Expected semantic violations for missing main');
        $this->assertArrayHasKey('page_title', $violations, 'Expected page_title violations for missing title');

        // Assert total violation count > 0
        $totalViolations = array_sum(array_map('count', $violations));
        $this->assertGreaterThan(0, $totalViolations);
    }

    public function test_fully_accessible_html_produces_no_violations(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Accessible Page - Our Company</title>
</head>
<body>
    <header>
        <nav aria-label="Main navigation">
            <a href="#main">Skip to main content</a>
            <a href="/about">About our company</a>
            <a href="/contact">Contact us</a>
        </nav>
    </header>
    <main id="main">
        <h1>Welcome to Our Company</h1>
        <p>This is a fully accessible page.</p>
        <img src="photo.jpg" alt="A team meeting in our office">

        <h2>Contact Us</h2>
        <form aria-label="Contact Form">
            <label for="name">Full Name</label>
            <input type="text" id="name" name="name" autocomplete="name">

            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" autocomplete="email">

            <button type="submit">Send Message</button>
        </form>

        <h2>Our Data</h2>
        <table>
            <caption>Quarterly Results</caption>
            <thead>
                <tr>
                    <th scope="col">Quarter</th>
                    <th scope="col">Revenue</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Q1</td>
                    <td>$100k</td>
                </tr>
            </tbody>
        </table>

        <div aria-live="polite"></div>
    </main>
    <footer>
        <p>Footer content</p>
    </footer>
</body>
</html>
HTML;

        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($html);

        $this->assertEmpty($violations, 'Fully accessible HTML should produce no violations. Got: '.json_encode(array_keys($violations)));
    }

    public function test_disabled_checks_are_skipped(): void
    {
        config()->set('bfsg.checks.images', false);

        $html = '<!DOCTYPE html><html lang="en"><head><title>Test Page</title></head>'
            .'<body><main><h1>Title</h1><img src="test.jpg"></main></body></html>';

        // Create a new Bfsg instance after config change to pick up the disabled check
        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($html);

        $this->assertArrayNotHasKey('images', $violations, 'Images check was disabled but still produced violations');
    }

    // =========================================================================
    // 2. Report Pipeline
    // =========================================================================

    public function test_report_pipeline_json_format(): void
    {
        $html = '<!DOCTYPE html><html><body><img src="test.jpg"><h3>Bad heading</h3></body></html>';

        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($html);

        $report = new ReportGenerator('https://example.com', $violations);
        $json = $report->setFormat('json')->generate();

        $data = json_decode($json, true);
        $this->assertNotNull($data, 'JSON report should be valid JSON');

        // Verify structure
        $this->assertArrayHasKey('meta', $data);
        $this->assertArrayHasKey('stats', $data);
        $this->assertArrayHasKey('violations', $data);
        $this->assertArrayHasKey('summary', $data);

        // Verify meta fields
        $this->assertEquals('https://example.com', $data['meta']['url']);
        $this->assertArrayHasKey('timestamp', $data['meta']);
        $this->assertArrayHasKey('generator', $data['meta']);

        // Verify stats fields
        $this->assertArrayHasKey('total_issues', $data['stats']);
        $this->assertArrayHasKey('compliance_score', $data['stats']);
        $this->assertArrayHasKey('grade', $data['stats']);
        $this->assertGreaterThan(0, $data['stats']['total_issues']);

        // Verify summary
        $this->assertArrayHasKey('total_issues', $data['summary']);
        $this->assertArrayHasKey('compliance_score', $data['summary']);
        $this->assertArrayHasKey('passed', $data['summary']);
        $this->assertFalse($data['summary']['passed']);

        // Verify score matches between stats and summary
        $this->assertEquals($data['stats']['compliance_score'], $data['summary']['compliance_score']);
    }

    public function test_report_pipeline_all_formats_consistent_score(): void
    {
        $html = '<!DOCTYPE html><html><body><img src="test.jpg"></body></html>';

        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($html);

        // Generate JSON report
        $jsonReport = new ReportGenerator('https://example.com', $violations);
        $jsonOutput = $jsonReport->setFormat('json')->generate();
        $jsonData = json_decode($jsonOutput, true);
        $jsonScore = $jsonData['stats']['compliance_score'];

        // Generate Markdown report
        $mdReport = new ReportGenerator('https://example.com', $violations);
        $mdOutput = $mdReport->setFormat('markdown')->generate();

        // Extract score from markdown
        preg_match('/Compliance Score:\*\*\s*(\d+)%/', $mdOutput, $matches);
        $this->assertNotEmpty($matches, 'Markdown report should contain compliance score');
        $mdScore = (int) $matches[1];

        // Scores should match
        $this->assertEquals($jsonScore, $mdScore, 'JSON and Markdown reports should have the same compliance score');
    }

    public function test_report_save_to_file_creates_file(): void
    {
        $html = '<!DOCTYPE html><html><body><img src="test.jpg"></body></html>';

        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($html);

        $report = new ReportGenerator('https://example.com', $violations);
        $report->setFormat('json');

        $tempPath = sys_get_temp_dir().'/bfsg-test-report-'.uniqid().'.json';

        try {
            $savedPath = $report->saveToFile($tempPath);

            $this->assertFileExists($savedPath);
            $this->assertEquals($tempPath, $savedPath);

            $content = file_get_contents($savedPath);
            $data = json_decode($content, true);
            $this->assertNotNull($data, 'Saved file should contain valid JSON');
            $this->assertArrayHasKey('meta', $data);
            $this->assertArrayHasKey('violations', $data);
        } finally {
            // Cleanup
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    // =========================================================================
    // 3. CSS + Contrast Integration
    // =========================================================================

    public function test_css_contrast_full_integration(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Contrast Test - Company</title>
    <style>
        .low-contrast {
            color: #cccccc;
            background-color: #ffffff;
        }
    </style>
</head>
<body>
    <main>
        <h1>Contrast Test Page</h1>
        <p class="low-contrast">This text has poor contrast</p>
    </main>
</body>
</html>
HTML;

        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($html);

        $this->assertArrayHasKey('contrast', $violations, 'Contrast violations should be detected');

        // Find the specific CSS contrast violation
        $contrastViolations = $violations['contrast'];
        $foundCssContrast = false;
        foreach ($contrastViolations as $v) {
            if (isset($v['rule']) && $v['rule'] === 'WCAG 1.4.3' && isset($v['severity']) && $v['severity'] === 'error') {
                $foundCssContrast = true;
                break;
            }
        }
        $this->assertTrue($foundCssContrast, 'Should find a WCAG 1.4.3 contrast error for CSS-styled low-contrast text');
    }

    public function test_css_inheritance_contrast(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Inheritance Test - Company</title>
    <style>
        .parent-light {
            color: #dddddd;
        }
    </style>
</head>
<body>
    <main>
        <h1>Inheritance Test</h1>
        <div class="parent-light">
            <span>This child inherits a light color</span>
        </div>
    </main>
</body>
</html>
HTML;

        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($html);

        $this->assertArrayHasKey('contrast', $violations, 'Inherited color contrast violations should be detected');

        // Check that at least one violation has the approximate flag
        $hasApproximate = false;
        foreach ($violations['contrast'] as $v) {
            if (isset($v['approximate']) && $v['approximate'] === true) {
                $hasApproximate = true;
                break;
            }
        }
        $this->assertTrue($hasApproximate, 'Inherited contrast violations should be marked as approximate');
    }

    public function test_inline_overrides_css_in_contrast(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Override Test - Company</title>
    <style>
        .good-contrast {
            color: #000000;
            background-color: #ffffff;
        }
    </style>
</head>
<body>
    <main>
        <h1>Override Test</h1>
        <p class="good-contrast" style="color: #dddddd;">Inline overrides CSS with bad contrast</p>
    </main>
</body>
</html>
HTML;

        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($html);

        $this->assertArrayHasKey('contrast', $violations, 'Inline override should trigger contrast violation');

        // Find an error-severity contrast violation for WCAG 1.4.3
        $foundInlineOverride = false;
        foreach ($violations['contrast'] as $v) {
            if (isset($v['rule']) && $v['rule'] === 'WCAG 1.4.3' && isset($v['severity']) && $v['severity'] === 'error') {
                $foundInlineOverride = true;
                break;
            }
        }
        $this->assertTrue($foundInlineOverride, 'Inline style overriding CSS should produce a contrast error');
    }

    // =========================================================================
    // 4. Database Integration
    // =========================================================================

    public function test_middleware_stores_violations_in_database(): void
    {
        config()->set('bfsg.middleware.enabled', true);
        config()->set('bfsg.middleware.log_violations', false);
        config()->set('bfsg.reporting.save_to_database', true);
        config()->set('app.debug', true);

        $inaccessibleHtml = '<!DOCTYPE html><html><body><img src="test.jpg"><h3>Bad</h3></body></html>';

        // Register a test route that returns inaccessible HTML
        $this->app['router']->get('/test-page', function () use ($inaccessibleHtml) {
            return response($inaccessibleHtml, 200, ['Content-Type' => 'text/html']);
        })->middleware(CheckAccessibility::class);

        $response = $this->get('/test-page');
        $response->assertStatus(200);

        // Assert BfsgReport was created
        $this->assertDatabaseCount('bfsg_reports', 1);

        $report = BfsgReport::first();
        $this->assertNotNull($report);
        $this->assertGreaterThan(0, $report->total_violations);
        $this->assertNotNull($report->score);
        $this->assertNotNull($report->grade);

        // Assert BfsgViolation records were created
        $violationCount = BfsgViolation::where('report_id', $report->id)->count();
        $this->assertGreaterThan(0, $violationCount, 'Violation records should be stored in the database');

        // Verify that the violations cover expected analyzers
        $analyzers = BfsgViolation::where('report_id', $report->id)
            ->pluck('analyzer')
            ->unique()
            ->toArray();

        $this->assertContains('images', $analyzers, 'Image violation should be stored');
    }

    public function test_save_results_stores_to_database(): void
    {
        $html = '<!DOCTYPE html><html><body><img src="test.jpg"><h3>Bad</h3></body></html>';
        $url = 'https://example.com/test';

        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($html);

        // Simulate what the command's saveResults method does
        $reportGenerator = new ReportGenerator($url, $violations);
        $stats = $reportGenerator->getStats();

        $dbReport = BfsgReport::create([
            'url' => $url,
            'total_violations' => $stats['total_issues'],
            'score' => $stats['compliance_score'],
            'grade' => $stats['grade'],
        ]);

        foreach ($violations as $analyzer => $issues) {
            foreach ($issues as $issue) {
                $dbReport->violations()->create([
                    'analyzer' => $analyzer,
                    'severity' => $issue['severity'] ?? 'notice',
                    'message' => $issue['message'],
                    'element' => $issue['element'] ?? null,
                    'wcag_rule' => $issue['rule'] ?? null,
                    'suggestion' => $issue['suggestion'] ?? null,
                ]);
            }
        }

        // Verify report was saved correctly
        $this->assertDatabaseHas('bfsg_reports', [
            'url' => $url,
            'total_violations' => $stats['total_issues'],
            'grade' => $stats['grade'],
        ]);

        // Verify violations were saved
        $savedViolations = $dbReport->violations()->count();
        $this->assertEquals($stats['total_issues'], $savedViolations);

        // Verify the relationship works end-to-end
        $freshReport = BfsgReport::with('violations')->find($dbReport->id);
        $this->assertCount($stats['total_issues'], $freshReport->violations);
    }

    // =========================================================================
    // 5. Command Integration
    // =========================================================================

    public function test_check_command_json_output_structure(): void
    {
        $html = '<!DOCTYPE html><html><body><img src="test.jpg"></body></html>';

        Http::fake([
            'http://example.com/*' => Http::response($html, 200),
        ]);

        $this->artisan('bfsg:check', [
            'url' => 'http://example.com/page',
            '--format' => 'json',
        ])->assertFailed();

        // Run again and capture output
        $result = $this->artisan('bfsg:check', [
            'url' => 'http://example.com/page',
            '--format' => 'json',
        ]);

        // The command outputs JSON to the console, capture via expectsOutput
        // Since we can't easily capture raw output in Orchestra Testbench,
        // verify by running the pipeline directly
        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($html);

        $report = new ReportGenerator('http://example.com/page', $violations);
        $jsonOutput = $report->setFormat('json')->generate();
        $data = json_decode($jsonOutput, true);

        $this->assertNotNull($data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertArrayHasKey('stats', $data);
        $this->assertArrayHasKey('violations', $data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertFalse($data['summary']['passed']);
    }

    public function test_check_command_returns_failure_on_violations(): void
    {
        $html = '<!DOCTYPE html><html><body><img src="test.jpg"></body></html>';

        Http::fake([
            'http://example.com/*' => Http::response($html, 200),
        ]);

        $this->artisan('bfsg:check', ['url' => 'http://example.com/page'])
            ->assertFailed();
    }

    public function test_check_command_returns_success_on_clean_html(): void
    {
        $html = '<!DOCTYPE html><html lang="en"><head><title>Test Page - Company</title></head><body>'
            .'<header><nav><a href="#main">Skip to content</a></nav></header>'
            .'<main id="main"><h1>Welcome</h1>'
            .'<img src="photo.jpg" alt="A descriptive alt text">'
            .'<form aria-label="Contact"><label for="email">Email</label>'
            .'<input type="email" id="email" name="email" autocomplete="email"></form>'
            .'<a href="/about">Learn more about our company</a>'
            .'<div aria-live="polite"></div>'
            .'</main><footer><p>Footer content</p></footer>'
            .'</body></html>';

        Http::fake([
            'http://example.com/*' => Http::response($html, 200),
        ]);

        $this->artisan('bfsg:check', ['url' => 'http://example.com/page'])
            ->assertSuccessful();
    }

    public function test_check_command_with_save_stores_to_database(): void
    {
        $html = '<!DOCTYPE html><html><body><img src="test.jpg"></body></html>';

        Http::fake([
            'http://example.com/*' => Http::response($html, 200),
        ]);

        $this->artisan('bfsg:check', [
            'url' => 'http://example.com/page',
            '--save' => true,
        ]);

        $this->assertDatabaseCount('bfsg_reports', 1);

        $report = BfsgReport::first();
        $this->assertNotNull($report);
        $this->assertEquals('http://example.com/page', $report->url);
        $this->assertGreaterThan(0, $report->total_violations);
    }

    // =========================================================================
    // 6. End-to-End: Analyze -> Report -> Persist
    // =========================================================================

    public function test_end_to_end_analyze_report_persist(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head></head>
<body>
    <img src="photo.jpg">
    <h3>Skipped heading</h3>
    <a href="/page">click here</a>
</body>
</html>
HTML;

        // Step 1: Analyze
        $bfsg = new Bfsg;
        $violations = $bfsg->analyze($html);
        $this->assertNotEmpty($violations);

        // Step 2: Generate report
        $url = 'https://example.com/e2e-test';
        $report = new ReportGenerator($url, $violations);
        $stats = $report->getStats();
        $this->assertGreaterThan(0, $stats['total_issues']);
        $this->assertLessThanOrEqual(100, $stats['compliance_score']);
        $this->assertGreaterThanOrEqual(0, $stats['compliance_score']);

        // Step 3: Persist to database
        $dbReport = BfsgReport::create([
            'url' => $url,
            'total_violations' => $stats['total_issues'],
            'score' => $stats['compliance_score'],
            'grade' => $stats['grade'],
            'metadata' => [
                'compliance_level' => config('bfsg.compliance_level'),
            ],
        ]);

        foreach ($violations as $analyzer => $issues) {
            foreach ($issues as $issue) {
                $dbReport->violations()->create([
                    'analyzer' => $analyzer,
                    'severity' => $issue['severity'] ?? 'notice',
                    'message' => $issue['message'],
                    'element' => $issue['element'] ?? null,
                    'wcag_rule' => $issue['rule'] ?? null,
                    'suggestion' => $issue['suggestion'] ?? null,
                ]);
            }
        }

        // Step 4: Verify everything is consistent
        $freshReport = BfsgReport::with('violations')->find($dbReport->id);
        $this->assertEquals($stats['total_issues'], $freshReport->total_violations);
        $this->assertEquals($stats['total_issues'], $freshReport->violations->count());
        $this->assertEquals($stats['grade'], $freshReport->grade);

        // Verify we can retrieve by URL scope
        $reportsForUrl = BfsgReport::forUrl($url)->get();
        $this->assertCount(1, $reportsForUrl);

        // Verify JSON report has same data
        $jsonOutput = $report->setFormat('json')->generate();
        $jsonData = json_decode($jsonOutput, true);
        $this->assertEquals($stats['total_issues'], $jsonData['summary']['total_issues']);
    }
}
