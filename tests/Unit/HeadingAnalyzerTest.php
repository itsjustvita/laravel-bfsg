<?php

use ItsJustVita\LaravelBfsg\Analyzers\HeadingAnalyzer;

it('detects missing h1', function () {
    $html = '<h2>Section</h2><p>Content</p>';
    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new HeadingAnalyzer();
    $violations = $analyzer->analyze($dom);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]['message'])->toBe('No h1 heading found on the page')
        ->and($violations[0]['type'])->toBe('warning');
});

it('detects broken heading hierarchy', function () {
    $html = '<h1>Main</h1><h3>Skipped h2</h3>';
    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new HeadingAnalyzer();
    $violations = $analyzer->analyze($dom);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]['message'])->toContain('Heading hierarchy broken');
});

it('accepts proper heading hierarchy', function () {
    $html = '<h1>Main</h1><h2>Section</h2><h3>Subsection</h3>';
    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new HeadingAnalyzer();
    $violations = $analyzer->analyze($dom);

    expect($violations)->toBeEmpty();
});

it('detects empty headings', function () {
    $html = '<h1></h1><h2>   </h2>';
    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new HeadingAnalyzer();
    $violations = $analyzer->analyze($dom);

    expect($violations)->not->toBeEmpty()
        ->and($violations[0]['message'])->toContain('Empty h1 heading found');
});

it('warns about multiple h1 tags', function () {
    $html = '<h1>First</h1><h1>Second</h1>';
    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new HeadingAnalyzer();
    $violations = $analyzer->analyze($dom);

    $messages = array_column($violations, 'message');
    expect($messages)->toContain('Multiple h1 headings found (2 total)');
});