<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use ItsJustVita\LaravelBfsg\AnalysisResult;
use ItsJustVita\LaravelBfsg\Analyzers\BaseAnalyzer;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use ItsJustVita\LaravelBfsg\Violation;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec §14 fixture corpus: keys that must and must not appear per fixture (Appendix A end state).
 */
class FixtureExpectationsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: list<string>, 2: list<string>}> */
    public static function fixtures(): array
    {
        return [
            'bootstrap modal' => ['bootstrap-modal.html', [
                'keyboard.dialog_missing_name',
                'keyboard.dialog_missing_aria_modal',
                'status_messages.alert_without_live_region',
            ], [
                'aria.hidden_focusable',
                'aria.dangling_idref',
                'keyboard.missing_skip_link',
                'keyboard.negative_tabindex_on_interactive',
                'forms.control_missing_label',
                'forms.button_missing_name',
                'input_purpose.missing_autocomplete',
                'headings.skipped_level',
                'contrast.insufficient',
                'links.non_descriptive',
                'semantic.missing_main',
            ]],
            'tailwind v4 marketing' => ['tailwind-v4-marketing.html', [
                'contrast.insufficient',
            ], [
                'contrast.analysis_truncated',
                'focus.outline_removed',
                'focus.outline_removed_global',
                'headings.missing_h1',
                'images.missing_alt',
                'semantic.section_without_heading',
                'links.non_descriptive',
            ]],
            'typo3 german' => ['typo3-german.html', [
                'images.suspicious_alt',
                'links.download_unannounced',
                'links.non_descriptive_in_context',
                'semantic.missing_main',
            ], [
                'page_title.generic_title',
                'keyboard.missing_skip_link',
                'keyboard.skip_link_target_missing',
                'language.possible_language_change',
                'language.invalid_lang',
                'language.unknown_lang',
                'forms.required_not_indicated',
                'forms.control_missing_label',
                'input_purpose.missing_autocomplete',
                'links.non_descriptive',
            ]],
            'laravel form (GET, before validation)' => ['laravel-form-get.html', [
                'forms.required_not_indicated',
                'input_purpose.missing_autocomplete',
            ], [
                'error_handling.no_error_strategy',
                'forms.control_missing_label',
                'input_purpose.invalid_autocomplete',
                'input_purpose.autocomplete_off_on_personal_field',
                'page_title.generic_title',
                'links.url_as_text',
                'status_messages.alert_without_live_region',
            ]],
            'data grid with ARIA' => ['data-grid-aria.html', [
                'aria.missing_required_state',
                'aria.dangling_idref',
            ], [
                'aria.unsupported_state',
                'aria.invalid_role',
                'aria.redundant_role',
                'keyboard.negative_tabindex_on_interactive',
                'keyboard.role_without_tabindex',
                'keyboard.click_without_keyboard',
                'forms.button_missing_name',
            ]],
            'card pattern' => ['card-pattern.html', [
                'links.non_descriptive_in_context',
                'links.missing_name',
            ], [
                'aria.hidden_focusable',
                'images.possibly_decorative',
                'links.non_descriptive',
                'links.adjacent_duplicate',
                'keyboard.negative_tabindex_on_interactive',
                'headings.skipped_level',
            ]],
        ];
    }

    private function analyzeFixture(string $file): AnalysisResult
    {
        return (new Bfsg)->analyze((string) file_get_contents(__DIR__.'/../Fixtures/'.$file));
    }

    /** @return list<string> */
    private function keys(AnalysisResult $result): array
    {
        return array_values(array_unique(array_map(fn (Violation $violation) => $violation->key, $result->all())));
    }

    /**
     * @param  list<string>  $present
     * @param  list<string>  $absent
     */
    #[DataProvider('fixtures')]
    public function test_expected_keys(string $file, array $present, array $absent): void
    {
        $keys = $this->keys($this->analyzeFixture($file));

        foreach ($present as $key) {
            $this->assertContains($key, $keys, "$file: expected $key. Found: ".implode(', ', $keys));
        }

        foreach ($absent as $key) {
            $this->assertNotContains($key, $keys, "$file: unexpected $key");
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Tailwind v4 utilities: the literal-hex text-gray-400 and the real v4 text-gray-300 (color: var(--color-gray-300),
     * an oklch() theme variable declared on :root inside @layer theme) are both measured definitely (error).
     */
    public function test_tailwind_v4_contrast_by_element(): void
    {
        $contrast = array_values(array_filter(
            $this->analyzeFixture('tailwind-v4-marketing.html')->all(),
            fn (Violation $violation) => $violation->key === 'contrast.insufficient',
        ));
        $byElement = array_combine(array_map(fn (Violation $violation) => (string) $violation->element, $contrast), $contrast);

        foreach (['p#fineprint.text-sm.text-gray-400', 'p#v4-muted.text-sm.text-gray-300'] as $element) {
            $this->assertArrayHasKey($element, $byElement);
            $this->assertSame(Severity::Error, $byElement[$element]->severity, $element);
            $this->assertFalse($byElement[$element]->meta['approximate'], $element);
        }

        $this->assertSame('#d1d5dc', $byElement['p#v4-muted.text-sm.text-gray-300']->params['foreground']);
    }

    /**
     * One finding per element and key: fingerprints (analyzer|key|rule|selector) never repeat.
     *
     * @param  list<string>  $present
     * @param  list<string>  $absent
     */
    #[DataProvider('fixtures')]
    public function test_fingerprints_are_unique(string $file, array $present, array $absent): void
    {
        $fingerprints = array_map(fn (Violation $violation) => $violation->fingerprint().' '.$violation->key.' '.$violation->selector, $this->analyzeFixture($file)->all());

        $this->assertSame([], array_values(array_diff_key($fingerprints, array_unique($fingerprints))), "$file: duplicate findings");
    }

    /**
     * Every finding's rule is declared in its analyzer's metadata and every param is scalar.
     *
     * @param  list<string>  $present
     * @param  list<string>  $absent
     */
    #[DataProvider('fixtures')]
    public function test_rules_are_declared_and_params_scalar(string $file, array $present, array $absent): void
    {
        $bfsg = new Bfsg;
        $analyzers = $bfsg->analyzers();

        foreach ($bfsg->analyze((string) file_get_contents(__DIR__.'/../Fixtures/'.$file))->all() as $violation) {
            $analyzer = $analyzers[$violation->analyzer];

            if ($violation->rule !== null && $analyzer instanceof BaseAnalyzer) {
                $this->assertContains($violation->rule, $analyzer->describe()['rules'], "$file: {$violation->key} cites undeclared {$violation->rule}");
            }

            foreach ($violation->params as $name => $value) {
                $this->assertTrue(is_scalar($value) || $value === null, "$file: {$violation->key} param $name is not scalar");
            }
        }

        $this->addToAssertionCount(1);
    }
}
