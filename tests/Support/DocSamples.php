<?php

namespace ItsJustVita\LaravelBfsg\Tests\Support;

/**
 * Reads the package's Markdown documentation for tests/Feature/DocumentationTest and tests/Live/doc-commands.php.
 * No Laravel dependency, so the live smoke test can use it in a plain PHP process.
 *
 * Markers: an HTML comment `<!-- docs: <marker> -->` on the line before a fenced block tags it:
 * `v2` (2.x code in UPGRADE.md: only linted), `run` (executed by DocumentationTest), `output` (the expected stdout
 * of the `run` blocks before it), `json-report` (a JSON report sample compared with a real report), `cli-output` and
 * `log-line` (README samples of the bfsg:check CLI output and the middleware log line, compared with real ones).
 */
final class DocSamples
{
    public const FILES = ['README.md', 'UPGRADE.md', 'SPA-TESTING.md'];

    public function __construct(private string $root) {}

    public static function forPackage(): self
    {
        return new self(dirname(__DIR__, 2));
    }

    public function markdown(string $file): string
    {
        return (string) file_get_contents($this->root.'/'.$file);
    }

    /** @return list<array{file: string, line: int, lang: string, code: string, marker: ?string}> fenced blocks in document order */
    public function blocks(?string $only = null): array
    {
        $blocks = [];

        foreach ($only === null ? self::FILES : [$only] as $file) {
            $lines = explode("\n", $this->markdown($file));
            $marker = null;

            for ($i = 0; $i < count($lines); $i++) {
                if (preg_match('/^<!-- docs: ([a-z0-9-]+) -->$/', trim($lines[$i]), $m) === 1) {
                    $marker = $m[1];

                    continue;
                }

                if (preg_match('/^```([a-z]*)\s*$/', $lines[$i], $m) === 1) {
                    $start = $i;
                    $code = [];

                    for ($i++; $i < count($lines) && ! preg_match('/^```\s*$/', $lines[$i]); $i++) {
                        $code[] = $lines[$i];
                    }

                    $blocks[] = ['file' => $file, 'line' => $start + 1, 'lang' => $m[1], 'code' => implode("\n", $code), 'marker' => $marker];
                }

                if (trim($lines[$i] ?? '') !== '') {
                    $marker = null;
                }
            }
        }

        return $blocks;
    }

    /** The Markdown of $file with every fenced block removed. */
    public function prose(string $file): string
    {
        return (string) preg_replace('/^```[a-z]*\s*$.*?^```\s*$/ms', '', $this->markdown($file));
    }

    /**
     * Every `artisan bfsg:<command> …` line in shell and YAML blocks that are not marked `v2`.
     *
     * @return list<array{file: string, line: int, command: string, options: list<string>}>
     */
    public function artisanCalls(): array
    {
        $calls = [];

        foreach ($this->blocks() as $block) {
            if (! in_array($block['lang'], ['bash', 'shell', 'sh', 'yaml', 'yml'], true) || $block['marker'] === 'v2') {
                continue;
            }

            foreach (explode("\n", $block['code']) as $offset => $line) {
                if (preg_match('/artisan (bfsg:[a-z-]+)(.*)$/', $line, $m) !== 1) {
                    continue;
                }

                $arguments = (string) preg_replace('/\s#\s.*$/', '', $m[2]);
                preg_match_all('/(?<=\s)--([a-z][a-z-]*)/', $arguments, $options);
                preg_match_all('/(?<=\s)-([a-zA-Z])(?=\s|$)/', $arguments, $short);

                $calls[] = [
                    'file' => $block['file'],
                    'line' => $block['line'] + $offset + 1,
                    'command' => $m[1],
                    'options' => array_values(array_unique([...$options[1], ...$short[1]])),
                ];
            }
        }

        return $calls;
    }

    /** @return list<string> option names written as inline code (`--name` or `--name=…`) in the prose of $file */
    public function inlineOptions(string $file): array
    {
        preg_match_all('/`--([a-z][a-z-]*)(?:=[^`]*)?`/', $this->prose($file), $m);

        return array_values(array_unique($m[1]));
    }

    /** @return list<string> `bfsg.*` config keys written as inline code or read with config() anywhere in $file (v2 blocks excluded) */
    public function configKeys(string $file): array
    {
        $text = $this->prose($file);

        foreach ($this->blocks($file) as $block) {
            if ($block['marker'] !== 'v2') {
                $text .= "\n".$block['code'];
            }
        }

        preg_match_all('/(?:`|config\([\'"])bfsg\.([a-z_]+(?:\.[a-z_]+)*)/', $text, $m);

        return array_values(array_unique($m[1]));
    }
}
