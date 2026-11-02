<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use ItsJustVita\LaravelBfsg\Analyzers\FormAnalyzer;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Tests\Support\PerformanceBudget;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

class FormLabelScalingTest extends TestCase
{
    private function labelledForm(): HtmlDocument
    {
        $body = '';

        for ($i = 0; $i < 4000; $i++) {
            $body .= sprintf('<label for="f%d">Field %d *</label><input type="text" id="f%d" name="f%d" required>', $i, $i, $i, $i);
        }

        return HtmlDocument::fromHtml('<html lang="en"><head><title>Forms</title></head><body><main><form>'.$body.'</form></main></body></html>');
    }

    public function test_many_labelled_controls_are_analyzed_without_violations(): void
    {
        $violations = (new FormAnalyzer)->analyze($this->labelledForm());

        $this->assertSame([], array_map(fn ($v) => $v->key, $violations));
    }

    #[Group('performance')]
    public function test_many_labelled_controls_are_analyzed_within_the_time_budget(): void
    {
        $document = $this->labelledForm();

        $start = microtime(true);
        (new FormAnalyzer)->analyze($document);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(PerformanceBudget::seconds(1.0), $elapsed, sprintf('forms took %.2f s for 4000 labelled controls', $elapsed));
    }

    public function test_labels_for_returns_every_label_for_an_id_in_document_order(): void
    {
        $document = HtmlDocument::fromHtml('<body><label for="a">One</label><label for="b">Other</label><input id="a"><label for="a">Two</label><label for=" a">Spaced</label></body>');

        $this->assertSame(['One', 'Two'], array_map(fn ($label) => $label->textContent, $document->labelsFor('a')));
        $this->assertSame(['Other'], array_map(fn ($label) => $label->textContent, $document->labelsFor('b')));
        $this->assertSame([], $document->labelsFor('missing'));
    }

    public function test_label_map_is_rebuilt_after_elements_are_removed(): void
    {
        $document = HtmlDocument::fromHtml('<body><label for="a" class="gone">One</label><label for="a">Two</label><input id="a"></body>');

        $this->assertCount(2, $document->labelsFor('a'));

        $document->removeMatching(['.gone']);

        $this->assertSame(['Two'], array_map(fn ($label) => $label->textContent, $document->labelsFor('a')));
    }
}
