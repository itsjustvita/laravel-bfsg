<?php

use ItsJustVita\LaravelBfsg\Analyzers\ImageAnalyzer;

it('detects missing alt attributes', function () {
    $html = '<img src="test.jpg">';
    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new ImageAnalyzer();
    $violations = $analyzer->analyze($dom);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]['message'])->toBe('Image without alt text found')
        ->and($violations[0]['rule'])->toBe('WCAG 1.1.1');
});

it('accepts images with alt text', function () {
    $html = '<img src="test.jpg" alt="Test image">';
    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new ImageAnalyzer();
    $violations = $analyzer->analyze($dom);

    expect($violations)->toBeEmpty();
});

it('accepts decorative images with empty alt', function () {
    $html = '<img src="decoration.jpg" alt="" role="presentation">';
    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new ImageAnalyzer();
    $violations = $analyzer->analyze($dom);

    expect($violations)->toBeEmpty();
});

it('warns about empty alt without decorative role', function () {
    $html = '<img src="important.jpg" alt="">';
    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new ImageAnalyzer();
    $violations = $analyzer->analyze($dom);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]['type'])->toBe('warning')
        ->and($violations[0]['message'])->toBe('Image with empty alt text may not be decorative');
});