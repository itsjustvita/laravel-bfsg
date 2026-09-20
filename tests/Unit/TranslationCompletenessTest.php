<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Tests\TestCase;

class TranslationCompletenessTest extends TestCase
{
    private const LOCALES = ['en', 'de'];

    /** @return array<string, list<string>> analyzer key => check keys reported in src/Analyzers */
    private function reportedKeys(): array
    {
        $keys = [];

        foreach (glob(__DIR__.'/../../src/Analyzers/*Analyzer.php') as $file) {
            $source = file_get_contents($file);

            if (preg_match("/protected string \\\$key = '([a-z_]+)';/", $source, $m) !== 1) {
                continue; // not migrated yet
            }

            preg_match_all("/->report\\(\\s*'([a-z0-9_]+)'/", $source, $found);
            $keys[$m[1]] = array_values(array_unique($found[1]));
        }

        return $keys;
    }

    private function lang(string $locale, string $file): array
    {
        return require __DIR__.'/../../lang/'.$locale.'/'.$file.'.php';
    }

    private function placeholders(string $text): array
    {
        preg_match_all('/:([a-z_]+)/', $text, $m);
        sort($m[1]);

        return array_values(array_unique($m[1]));
    }

    public function test_every_reported_key_exists_in_every_locale_with_matching_placeholders(): void
    {
        $reported = $this->reportedKeys();

        if ($reported === []) {
            $this->markTestSkipped('no migrated analyzer yet');
        }

        foreach (self::LOCALES as $locale) {
            $lang = $this->lang($locale, 'violations');

            foreach ($reported as $analyzer => $keys) {
                foreach ($keys as $key) {
                    $this->assertArrayHasKey($analyzer, $lang, "[$locale] analyzer '$analyzer' missing");
                    $this->assertArrayHasKey($key, $lang[$analyzer], "[$locale] $analyzer.$key missing");
                    $this->assertArrayHasKey('message', $lang[$analyzer][$key], "[$locale] $analyzer.$key.message missing");
                    $this->assertArrayHasKey('suggestion', $lang[$analyzer][$key], "[$locale] $analyzer.$key.suggestion missing");
                    $this->assertNotSame('', trim($lang[$analyzer][$key]['message']));
                }
            }
        }

        $en = $this->lang('en', 'violations');

        foreach (self::LOCALES as $locale) {
            $lang = $this->lang($locale, 'violations');

            foreach ($en as $analyzer => $checks) {
                foreach ($checks as $key => $texts) {
                    foreach (['message', 'suggestion'] as $part) {
                        $this->assertSame(
                            $this->placeholders($texts[$part]),
                            $this->placeholders($lang[$analyzer][$key][$part] ?? ''),
                            "[$locale] $analyzer.$key.$part placeholders differ from en",
                        );
                    }
                }
            }
        }
    }

    public function test_no_unused_translation_keys(): void
    {
        $reported = $this->reportedKeys();
        $en = $this->lang('en', 'violations');

        foreach ($en as $analyzer => $checks) {
            if (! isset($reported[$analyzer])) {
                continue; // analyzer not migrated yet — checked again once it is
            }

            foreach (array_keys($checks) as $key) {
                $this->assertContains($key, $reported[$analyzer], "en $analyzer.$key is defined but never reported");
            }
        }
    }

    public function test_report_labels_exist_in_every_locale(): void
    {
        $en = $this->lang('en', 'report');

        foreach (self::LOCALES as $locale) {
            $lang = $this->lang($locale, 'report');
            $this->assertSame(array_keys($en), array_keys($lang), "[$locale] report.php keys differ");
            $this->assertSame(array_keys($en['severity']), array_keys($lang['severity']));
        }
    }
}
