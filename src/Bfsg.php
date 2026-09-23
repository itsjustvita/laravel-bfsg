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

    /** @var array<string, mixed>|null explicit `bfsg` config array; null = read the container's config live */
    private ?array $config;

    /** @var array<string, Analyzer|class-string<Analyzer>> key => instance or class name */
    private array $analyzers = [];

    /** @var array<string, Analyzer> resolved instances */
    private array $instances = [];

    /**
     * The registry (which analyzers run) is fixed at construction from `$checks` or `bfsg.checks`; every other
     * setting (locale, ignored selectors) is read when analyze() runs, from `$config` when one is given and
     * otherwise live from the container's config repository, so runtime config changes apply to the singleton.
     *
     * @param  array<string, bool>|null  $checks  registry key => enabled (defaults to config bfsg.checks)
     * @param  array<string, mixed>|null  $config  a fixed bfsg config array (defaults to the live config('bfsg'))
     */
    public function __construct(?Container $container = null, ?array $checks = null, ?array $config = null)
    {
        $this->container = $container ?? \Illuminate\Container\Container::getInstance();
        $this->config = $config;
        $checks ??= (array) $this->setting('bfsg.checks', []);

        foreach (self::ANALYZERS as $key => $class) {
            if ($checks[$key] ?? true) {
                $this->analyzers[$key] = $class;
            }
        }
    }

    /**
     * Register (or replace) an analyzer under a key.
     *
     * @param  Analyzer|class-string<Analyzer>  $analyzer
     */
    public function register(string $key, Analyzer|string $analyzer): static
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

    /** @return list<string> registry keys in execution order, without resolving the analyzers */
    public function keys(): array
    {
        return array_keys($this->analyzers);
    }

    /** @return array<string, Analyzer> resolved analyzer instances in registry order */
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
            'ignoredSelectors' => $options['ignoredSelectors'] ?? (array) $this->setting('bfsg.ignored_selectors', []),
        ]);

        return $this->analyzeDocument($document, $options);
    }

    /**
     * @param  array{url?: ?string, locale?: ?string}  $options
     */
    public function analyzeDocument(HtmlDocument $document, array $options = []): AnalysisResult
    {
        $url = $options['url'] ?? null;
        $locale = $options['locale'] ?? ($this->setting('bfsg.locale') ?: null);

        if ($document->isEmpty()) {
            return new AnalysisResult([], [], $url, $locale);
        }

        $byAnalyzer = [];
        $run = [];

        foreach ($this->analyzers() as $key => $analyzer) {
            $run[] = $key;

            $violations = $analyzer->analyze($document);

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

    /** A `bfsg.*` setting from the explicit config array, or live from the container's config repository. */
    private function setting(string $key, mixed $default = null): mixed
    {
        if ($this->config !== null) {
            return data_get($this->config, substr($key, strlen('bfsg.')), $default);
        }

        return $this->container->bound('config') ? $this->container->make('config')->get($key, $default) : $default;
    }

    private function resolve(string $key): Analyzer
    {
        if (! isset($this->instances[$key])) {
            $entry = $this->analyzers[$key];
            $this->instances[$key] = is_string($entry) ? $this->container->make($entry) : $entry;
        }

        return $this->instances[$key];
    }
}
