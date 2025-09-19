<?php

use ItsJustVita\LaravelBfsg\Bfsg;

it('analyzes HTML for multiple violations', function () {
    $html = '<html><body>
        <img src="test.jpg">
        <h3>Skipped heading levels</h3>
        <form><h2>Form</h2><input type="text" name="email"></form>
        <a href="#">Click here</a>
    </body></html>';

    $bfsg = new Bfsg();
    $violations = $bfsg->analyze($html);

    expect($violations)->toHaveKey('images')
        ->and($violations)->toHaveKey('headings')
        ->and($violations)->toHaveKey('forms')
        ->and($violations)->toHaveKey('links');
});

it('returns empty array for accessible HTML', function () {
    $html = '<!DOCTYPE html><html><body>
        <h1>Main Heading</h1>
        <img src="test.jpg" alt="Test image">
        <form aria-label="Contact Form">
            <label for="email">Email</label>
            <input type="email" id="email" name="email">
        </form>
        <a href="/about">Learn more about us</a>
    </body></html>';

    $bfsg = new Bfsg();
    $violations = $bfsg->analyze($html);

    expect($violations)->toBeEmpty();
});

it('correctly identifies accessible content', function () {
    $accessibleHtml = '<!DOCTYPE html><html><body><h1>Title</h1><img src="test.jpg" alt="Description"></body></html>';
    $inaccessibleHtml = '<!DOCTYPE html><html><body><img src="test.jpg"><h3>Wrong heading level</h3></body></html>';

    $bfsg = new Bfsg();

    expect($bfsg->isAccessible($accessibleHtml))->toBeTrue()
        ->and($bfsg->isAccessible($inaccessibleHtml))->toBeFalse();
});

it('can get violations after analysis', function () {
    $html = '<!DOCTYPE html><html><body><img src="test.jpg"></body></html>';

    $bfsg = new Bfsg();
    $bfsg->analyze($html);
    $violations = $bfsg->getViolations();

    expect($violations)->toHaveKey('images')
        ->and($violations['images'])->not->toBeEmpty();
});