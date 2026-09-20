<?php

namespace ItsJustVita\LaravelBfsg;

use Illuminate\Contracts\Container\Container;
use ItsJustVita\LaravelBfsg\Analyzers\AriaAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\ContrastAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\ErrorHandlingAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\FocusAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\FormAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\HeadingAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\ImageAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\InputPurposeAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\KeyboardNavigationAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\LanguageAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\LinkAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\MediaAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\PageTitleAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\SemanticHTMLAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\StatusMessageAnalyzer;
use ItsJustVita\LaravelBfsg\Analyzers\TableAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;

class Bfsg
{
    /** Registry key => analyzer class, in execution order. */
    public const ANALYZERS = [
        'images' => ImageAnalyzer::class,
        'forms' => FormAnalyzer::class,
        'headings' => HeadingAnalyzer::class,
        'contrast' => ContrastAnalyzer::class,
        'aria' => AriaAnalyzer::class,
        'links' => LinkAnalyzer::class,
        'keyboard' => KeyboardNavigationAnalyzer::class,
        'language' => LanguageAnalyzer::class,
        'tables' => TableAnalyzer::class,
        'media' => MediaAnalyzer::class,
        'semantic' => SemanticHTMLAnalyzer::class,
        'page_title' => PageTitleAnalyzer::class,
        'input_purpose' => InputPurposeAnalyzer::class,
        'focus' => FocusAnalyzer::class,
        'error_handling' => ErrorHandlingAnalyzer::class,
        'status_messages' => StatusMessageAnalyzer::class,
    ];

    private Container $container;

    /** @var array<string, mixed> the `bfsg` config array */
    private array $config;

    /** @var array<string, object|string> key => instance or class name */
    private array $analyzers = [];

    /** @var array<string, object> resolved instances */
    private array $instances = [];

    /**
     * @param  array<string, bool>|null  $checks  registry key => enabled (defaults to config bfsg.checks)
     * @param  array<string, mixed>|null  $config  the bfsg config array (defaults to config('bfsg'))
     */
    public function __construct(?Container $container = null, ?array $checks = null, ?array $config = null)
    {
        $this->container = $container ?? \Illuminate\Container\Container::getInstance();
        $this->config = $config ?? ($this->container->bound('config') ? (array) $this->container->make('config')->get('bfsg', []) : []);
        $checks ??= $this->config['checks'] ?? [];

        foreach (self::ANALYZERS as $key => $class) {
            if ($checks[$key] ?? true) {
                $this->analyzers[$key] = $class;
            }
        }
    }

    /** Register (or replace) an analyzer under a key. Legacy array-returning analyzers are accepted until Phase 1 Task 29. */
    public function register(string $key, object|string $analyzer): static
    {
        $this->analyzers[$key] = $analyzer;
        unset($this->instances[$key]);

        return $this;
    }

    public function forget(string $key): static
    {
        unset($this->analyzers[$key], $this->instances[$key]);

        return $this;
    }

    /** @param  list<string>  $keys */
    public function only(array $keys): static
    {
        $clone = clone $this;
        $clone->analyzers = array_intersect_key($clone->analyzers, array_flip($keys));
        $clone->instances = array_intersect_key($clone->instances, array_flip($keys));

        return $clone;
    }

    /** @param  list<string>  $keys */
    public function except(array $keys): static
    {
        $clone = clone $this;
        $clone->analyzers = array_diff_key($clone->analyzers, array_flip($keys));
        $clone->instances = array_diff_key($clone->instances, array_flip($keys));

        return $clone;
    }

    /** @return array<string, object> resolved analyzer instances in registry order */
    public function analyzers(): array
    {
        $resolved = [];

        foreach (array_keys($this->analyzers) as $key) {
            $resolved[$key] = $this->resolve($key);
        }

        return $resolved;
    }

    /**
     * @param  array{url?: ?string, locale?: ?string, ignoredSelectors?: list<string>, fragment?: ?bool}  $options
     */
    public function analyze(string $html, array $options = []): AnalysisResult
    {
        $document = HtmlDocument::fromHtml($html, [
            'fragment' => $options['fragment'] ?? null,
            'ignoredSelectors' => $options['ignoredSelectors'] ?? ($this->config['ignored_selectors'] ?? []),
        ]);

        return $this->analyzeDocument($document, $options);
    }

    /**
     * @param  array{url?: ?string, locale?: ?string}  $options
     */
    public function analyzeDocument(HtmlDocument $document, array $options = []): AnalysisResult
    {
        $url = $options['url'] ?? null;
        $locale = $options['locale'] ?? ($this->config['locale'] ?? null);

        if ($document->isEmpty()) {
            return new AnalysisResult([], [], $url, $locale);
        }

        $byAnalyzer = [];
        $run = [];

        foreach ($this->analyzers() as $key => $analyzer) {
            $run[] = $key;

            $violations = $analyzer instanceof Analyzer
                ? $analyzer->analyze($document)
                : $this->legacyViolations($key, $analyzer, $document);

            if ($violations !== []) {
                $byAnalyzer[$key] = array_values($violations);
            }
        }

        return new AnalysisResult($byAnalyzer, $run, $url, $locale);
    }

    public function isAccessible(string $html): bool
    {
        return $this->analyze($html)->isAccessible();
    }

    private function resolve(string $key): object
    {
        if (! isset($this->instances[$key])) {
            $entry = $this->analyzers[$key];
            $this->instances[$key] = is_string($entry) ? $this->container->make($entry) : $entry;
        }

        return $this->instances[$key];
    }

    /**
     * Compatibility shim for analyzers that still return ['issues' => [...]]. Removed in Task 29.
     *
     * @return list<Violation>
     */
    private function legacyViolations(string $key, object $analyzer, HtmlDocument $document): array
    {
        $result = $analyzer->analyze($document->dom());

        return array_map(fn (array $issue) => Violation::fromLegacy($key, $issue), $result['issues'] ?? []);
    }
}
