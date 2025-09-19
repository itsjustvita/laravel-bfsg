<?php

use ItsJustVita\LaravelBfsg\Analyzers\FormAnalyzer;

it('detects inputs without labels', function () {
    $html = '<form><h2>Contact Form</h2><input type="text" name="email"></form>';
    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new FormAnalyzer();
    $violations = $analyzer->analyze($dom);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]['message'])->toBe('Form input without associated label')
        ->and($violations[0]['rule'])->toBe('WCAG 1.3.1, 3.3.2');
});

it('accepts inputs with labels', function () {
    $html = '<form><h2>Form</h2><label for="email">Email</label><input type="text" id="email" name="email"></form>';
    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new FormAnalyzer();
    $violations = $analyzer->analyze($dom);

    expect($violations)->toBeEmpty();
});

it('accepts inputs with aria-label', function () {
    $html = '<form aria-label="Contact Form"><input type="text" name="email" aria-label="Email address"></form>';
    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new FormAnalyzer();
    $violations = $analyzer->analyze($dom);

    expect($violations)->toBeEmpty();
});

it('warns about required fields without aria-required', function () {
    $html = '<form><legend>Form</legend><input type="text" name="email" required aria-label="Email"></form>';
    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new FormAnalyzer();
    $violations = $analyzer->analyze($dom);

    // Should have 1 violation: missing aria-required
    expect($violations)->toHaveCount(1)
        ->and($violations[0]['message'])->toBe('Required field without aria-required attribute')
        ->and($violations[0]['type'])->toBe('warning');
});

it('detects textareas without labels', function () {
    $html = '<form><legend>Contact</legend><textarea name="message"></textarea></form>';
    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $analyzer = new FormAnalyzer();
    $violations = $analyzer->analyze($dom);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]['message'])->toBe('Textarea without associated label');
});