<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Facades\Bfsg as BfsgFacade;
use ItsJustVita\LaravelBfsg\Mcp\BfsgMcpServer;
use ItsJustVita\LaravelBfsg\Middleware\CheckAccessibility;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;
use ItsJustVita\LaravelBfsg\Tests\Support\DocSamples;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
use Monolog\Handler\TestHandler;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Keeps README.md, UPGRADE.md and SPA-TESTING.md honest: code samples lint and (where marked) run and print what the
 * docs show, every command, option, config key, environment variable, class and facade method they mention exists,
 * and the README documents every option, config key, MCP tool and analyzer.
 */
class DocumentationTest extends TestCase
{
    use RefreshDatabase;

    /** Options every Symfony command has; not documented per command. */
    private const GLOBAL_OPTIONS = ['help', 'silent', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env', 'q', 'v', 'h', 'n'];

    /** Removed 2.x commands and options that UPGRADE.md names on purpose. */
    private const REMOVED = ['bfsg:analyze', 'verify-ssl'];

    /** Config settings whose children are data (ConfigKeysTest::WHOLE_ARRAYS). */
    private const WHOLE_ARRAYS = ['checks', 'scoring.weights'];

    private function docs(): DocSamples
    {
        return DocSamples::forPackage();
    }

    /** @return array<string, list<string>> command => option names (long and short) */
    private function commandOptions(): array
    {
        $options = [];

        foreach (['bfsg:check', 'bfsg:history', 'bfsg:mcp-server'] as $name) {
            $definition = Artisan::all()[$name]->getDefinition();
            $options[$name] = [];

            foreach ($definition->getOptions() as $option) {
                $options[$name][] = $option->getName();

                if ($option->getShortcut() !== null) {
                    $options[$name] = [...$options[$name], ...explode('|', $option->getShortcut())];
                }
            }
        }

        return $options;
    }

    /** @return list<string> dotted paths of every config setting */
    private function configLeaves(array $config, string $prefix = ''): array
    {
        $paths = [];

        foreach ($config as $key => $value) {
            $path = $prefix === '' ? $key : $prefix.'.'.$key;

            if (is_array($value) && $value !== [] && ! array_is_list($value) && ! in_array($path, self::WHOLE_ARRAYS, true)) {
                $paths = [...$paths, ...$this->configLeaves($value, $path)];

                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    private function where(array $block): string
    {
        return $block['file'].':'.$block['line'];
    }

    public function test_every_php_sample_is_valid_php(): void
    {
        $blocks = array_values(array_filter($this->docs()->blocks(), fn (array $block) => $block['lang'] === 'php'));
        $this->assertGreaterThan(10, count($blocks));

        foreach ($blocks as $block) {
            $base = tempnam(sys_get_temp_dir(), 'bfsg-doc-');
            $file = $base.'.php';
            file_put_contents($file, str_starts_with($block['code'], '<?php') ? $block['code'] : "<?php\n".$block['code']);

            try {
                $lint = new SymfonyProcess([PHP_BINARY, '-l', $file]);
                $lint->run();
            } finally {
                unlink($file);
                unlink($base);
            }

            $this->assertTrue($lint->isSuccessful(), $this->where($block).': '.$lint->getOutput().$lint->getErrorOutput());
        }
    }

    public function test_package_classes_and_facade_methods_in_samples_exist(): void
    {
        foreach ($this->docs()->blocks() as $block) {
            if ($block['lang'] !== 'php' || $block['marker'] === 'v2') {
                continue;
            }

            preg_match_all('/\\\\?(ItsJustVita\\\\LaravelBfsg\\\\[A-Za-z\\\\]+[A-Za-z])/', $block['code'], $classes);

            foreach ($classes[1] as $class) {
                $this->assertTrue(class_exists($class) || interface_exists($class) || enum_exists($class), $this->where($block).": {$class} does not exist");
            }

            preg_match_all('/\bBfsg::([a-zA-Z]+)\(/', $block['code'], $calls);

            foreach ($calls[1] as $method) {
                $this->assertTrue(method_exists(Bfsg::class, $method) && (new ReflectionMethod(Bfsg::class, $method))->isPublic(), $this->where($block).": Bfsg::{$method}() does not exist");
            }
        }
    }

    public function test_every_artisan_command_and_option_in_the_docs_exists(): void
    {
        $known = $this->commandOptions();
        $calls = $this->docs()->artisanCalls();
        $this->assertGreaterThan(20, count($calls));

        foreach ($calls as $call) {
            $where = $call['file'].':'.$call['line'];
            $this->assertArrayHasKey($call['command'], $known, "{$where}: unknown command {$call['command']}");

            foreach ($call['options'] as $option) {
                $this->assertContains($option, $known[$call['command']], "{$where}: {$call['command']} has no option --{$option}");
            }
        }

        $all = array_merge(...array_values($known));

        foreach (DocSamples::FILES as $file) {
            foreach ($this->docs()->inlineOptions($file) as $option) {
                $this->assertTrue(in_array($option, $all, true) || in_array($option, ['tag', 'force'], true) || ($file === 'UPGRADE.md' && in_array($option, self::REMOVED, true)), "{$file}: `--{$option}` is not an option of a bfsg command");
            }

            preg_match_all('/`(bfsg:[a-z-]+)/', $this->docs()->prose($file), $commands);

            foreach (array_unique($commands[1]) as $command) {
                $this->assertTrue(isset($known[$command]) || ($file === 'UPGRADE.md' && in_array($command, self::REMOVED, true)), "{$file}: unknown command {$command}");
            }
        }
    }

    public function test_the_readme_documents_every_option_of_the_commands(): void
    {
        $documented = $this->docs()->inlineOptions('README.md');

        foreach ($this->commandOptions() as $command => $options) {
            foreach ($options as $option) {
                if (strlen($option) > 1 && ! in_array($option, self::GLOBAL_OPTIONS, true)) {
                    $this->assertContains($option, $documented, "README.md does not document {$command} --{$option}");
                }
            }
        }
    }

    public function test_config_keys_in_the_docs_exist_and_the_readme_documents_every_key(): void
    {
        $config = require __DIR__.'/../../config/bfsg.php';

        foreach (DocSamples::FILES as $file) {
            foreach ($this->docs()->configKeys($file) as $key) {
                $this->assertTrue(Arr::has($config, $key), "{$file}: bfsg.{$key} is not a config key");
            }
        }

        $readme = $this->docs()->configKeys('README.md');

        foreach ($this->configLeaves($config) as $leaf) {
            $this->assertContains($leaf, $readme, "README.md does not document bfsg.{$leaf}");
        }
    }

    public function test_environment_variables_in_the_docs_are_read_by_the_package(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../config/bfsg.php');

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../src')) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source .= file_get_contents($file->getPathname());
            }
        }

        foreach (DocSamples::FILES as $file) {
            // UPGRADE.md also names the variables of removed settings, on lines that say so
            $text = implode("\n", array_filter(explode("\n", $this->docs()->markdown($file)), fn (string $line) => $file !== 'UPGRADE.md' || ! str_contains($line, 'removed')));
            preg_match_all('/\bBFSG_[A-Z_]+\b/', $text, $variables);

            foreach (array_unique($variables[0]) as $variable) {
                $this->assertStringContainsString("'{$variable}'", $source, "{$file}: {$variable} is not read by the package");
            }
        }
    }

    public function test_the_readme_lists_every_analyzer_and_mcp_tool(): void
    {
        $readme = $this->docs()->markdown('README.md');

        foreach (array_keys(Bfsg::ANALYZERS) as $key) {
            $this->assertMatchesRegularExpression('/^\| `'.preg_quote($key, '/').'` \|/m', $readme, "README.md analyzer table lacks {$key}");
        }

        foreach ((new ReflectionClass(BfsgMcpServer::class))->getDefaultProperties()['tools'] as $tool) {
            $name = (new ReflectionClass($tool))->getDefaultProperties()['name'];
            $this->assertMatchesRegularExpression('/^\| `'.preg_quote($name, '/').'` \|/m', $readme, "README.md MCP table lacks {$name}");
        }
    }

    public function test_blade_json_and_report_samples_are_valid(): void
    {
        foreach ($this->docs()->blocks() as $block) {
            if ($block['marker'] === 'v2') {
                continue;
            }

            if ($block['lang'] === 'blade') {
                $this->assertStringContainsString('<img', Blade::render($block['code']), $this->where($block));
            }

            if ($block['lang'] === 'json') {
                $this->assertIsArray(json_decode($block['code'], true), $this->where($block).': invalid JSON');
            }

            if ($block['marker'] === 'json-report') {
                $sample = json_decode($block['code'], true);
                // The page the README describes: lang, a title, a heading and one image without alt
                $page = '<!DOCTYPE html><html lang="en"><head><title>Pricing – Example</title></head><body><main><h1>Pricing</h1><img src="hero.jpg"></main></body></html>';
                $real = json_decode((new ReportGenerator(app(Bfsg::class)->analyze($page, ['url' => 'https://example.com/pricing'])))->toJson(), true);
                unset($sample['analyzed_at'], $sample['package_version'], $real['analyzed_at'], $real['package_version']);

                $this->assertSame($real, $sample, $this->where($block).': the sample differs from the real report');
            }
        }
    }

    public function test_runnable_samples_run_and_print_what_the_docs_show(): void
    {
        $output = '';
        $ran = 0;

        foreach ($this->docs()->blocks() as $block) {
            if ($block['marker'] === 'run') {
                ob_start();

                // eval() of the package's own, versioned documentation: the point of the test is to run exactly what the docs show.
                // Each sample runs in its own static closure, so it cannot use a variable of an earlier sample or of this test.
                try {
                    (static function (string $code): void {
                        eval($code);
                    })((string) preg_replace('/^<\?php\s*/', '', $block['code']));
                } finally {
                    $output .= ob_get_clean();
                }

                $ran++;
            }

            if ($block['marker'] === 'output') {
                $this->assertSame(trim($block['code']), trim($output), $this->where($block).': the output shown differs from the real output');
                $output = '';
            }
        }

        $this->assertGreaterThanOrEqual(5, $ran);
        $this->assertContains('marquee.moving_content', array_map(fn ($violation) => $violation->key, BfsgFacade::analyze('<marquee>Sale</marquee>')->all()), 'the custom analyzer example is registered and reports');
    }

    /** @return array<string, class-string> short class name => class, for every class under src/ (a top-level class wins a clash) */
    private function packageClasses(): array
    {
        $src = realpath(__DIR__.'/../../src');
        $classes = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relative = substr($file->getPathname(), strlen($src) + 1, -4);
                $short = basename($relative);

                if (! isset($classes[$short]) || ! str_contains($relative, '/')) {
                    $classes[$short] = 'ItsJustVita\\LaravelBfsg\\'.str_replace('/', '\\', $relative);
                }
            }
        }

        return $classes;
    }

    /** The package class a name in the docs refers to (FQCN, `Http\UrlFetcher`, `UrlFetcher`), '' for a package namespace that has no such class, null for other names. */
    private function packageClass(string $name): ?string
    {
        $name = ltrim($name, '\\');

        if (str_starts_with($name, 'ItsJustVita\\LaravelBfsg\\')) {
            return class_exists($name) || interface_exists($name) || enum_exists($name) ? $name : '';
        }

        if (str_contains($name, '\\')) {
            if (! is_dir(__DIR__.'/../../src/'.strstr($name, '\\', true))) {
                return null;
            }

            return $this->packageClass('ItsJustVita\\LaravelBfsg\\'.$name);
        }

        return $this->packageClasses()[$name] ?? null;
    }

    /** A method, or an Eloquent local scope (`BfsgReport::forUrl()` is `scopeForUrl()`). */
    private function hasMethod(string $class, string $method): bool
    {
        return method_exists($class, $method) || method_exists($class, 'scope'.ucfirst($method));
    }

    public function test_package_classes_and_methods_named_in_the_prose_exist(): void
    {
        $checked = 0;

        foreach (DocSamples::FILES as $file) {
            $prose = $this->docs()->prose($file);

            foreach (explode("\n", $prose) as $line) {
                // UPGRADE.md tables put the 2.x name in the first column: only the 3.0 columns are checked
                if ($file === 'UPGRADE.md' && str_starts_with($line, '|')) {
                    $line = (string) preg_replace('/^\|[^|]*\|/', '|', $line);
                }

                preg_match_all('/\\\\?ItsJustVita\\\\LaravelBfsg\\\\[A-Za-z\\\\]+[A-Za-z]/', $line, $fqcns);

                foreach ($fqcns[0] as $class) {
                    $this->assertNotSame('', $this->packageClass($class), "{$file}: {$class} does not exist");
                }

                $current = null;
                preg_match_all('/`([^`]+)`/', $line, $spans);

                foreach ($spans[1] as $span) {
                    // `Class`, `Ns\Class`, `Class::method(…)`, `Class::CONSTANT`, `new Class(…)`
                    if (preg_match('/^(?:new )?\\\\?([A-Z]\w*(?:\\\\[A-Z]\w*)*)(?:::(\w+)(\(.*)?|\(.*)?$/', $span, $m) === 1) {
                        $class = $this->packageClass($m[1]);

                        if ($class === null) {
                            continue;
                        }

                        $this->assertNotSame('', $class, "{$file}: {$m[1]} (in `{$span}`) does not exist");
                        $current = $class;
                        $checked++;

                        if (($m[2] ?? '') !== '' && str_starts_with($m[3] ?? '', '(')) {
                            $this->assertTrue($this->hasMethod($class, $m[2]), "{$file}: {$class}::{$m[2]}() does not exist");
                        } elseif (($m[2] ?? '') !== '' && $m[2] !== 'class') {
                            $this->assertTrue(defined($class.'::'.$m[2]), "{$file}: {$class}::{$m[2]} does not exist");
                        }

                        continue;
                    }

                    // A bare `method()` after a package class on the same line is a method of that class (PHP functions excepted)
                    if ($current !== null && preg_match('/^([a-z]\w*)\(/', $span, $m) === 1 && ! function_exists($m[1])) {
                        $this->assertTrue($this->hasMethod($current, $m[1]), "{$file}: {$current}::{$m[1]}() does not exist (`{$span}`)");
                        $checked++;
                    }
                }
            }
        }

        $this->assertGreaterThan(40, $checked);
    }

    public function test_the_readme_config_table_defaults_match_the_config(): void
    {
        $config = require __DIR__.'/../../config/bfsg.php';
        preg_match_all('/^\| `bfsg\.([a-z_.]+)` \|[^|]*\|([^|]*)\|/m', $this->docs()->markdown('README.md'), $rows, PREG_SET_ORDER);
        $compared = 0;

        foreach ($rows as [, $key, $default]) {
            // Only defaults written as code values (`30`, `null`, a list of `path/*`); prose defaults such as "all `true`" are skipped
            if (preg_match('/^`[^`]*`(?:, `[^`]*`)*$/', trim($default)) !== 1) {
                continue;
            }

            preg_match_all('/`([^`]*)`/', $default, $values);
            $actual = Arr::get($config, $key);

            if (is_array($actual)) {
                $this->assertSame($actual, $values[1], "README.md: default of bfsg.{$key}");
            } else {
                $shown = match (true) {
                    $values[1][0] === 'null' => null,
                    $values[1][0] === 'true' => true,
                    $values[1][0] === 'false' => false,
                    is_numeric($values[1][0]) => $values[1][0] + 0,
                    default => $values[1][0],
                };
                // Paths are shown relative to the application root
                $actual = is_string($actual) ? str_replace(base_path().'/', '', $actual) : $actual;

                $this->assertSame($actual, $shown, "README.md: default of bfsg.{$key}");
            }

            $compared++;
        }

        $this->assertGreaterThan(12, $compared);
    }

    public function test_the_readme_mcp_table_lists_the_arguments_of_every_tool(): void
    {
        $readme = $this->docs()->markdown('README.md');

        foreach ((new ReflectionClass(BfsgMcpServer::class))->getDefaultProperties()['tools'] as $tool) {
            $schema = app($tool)->toArray();
            $this->assertMatchesRegularExpression('/^\| `'.preg_quote($schema['name'], '/').'` \|([^|]*)\|/m', $readme);
            preg_match('/^\| `'.preg_quote($schema['name'], '/').'` \|([^|]*)\|/m', $readme, $row);
            // Argument names are the code spans outside the parenthesised notes
            preg_match_all('/`([a-z_]+)`/', (string) preg_replace('/\([^)]*\)/', '', $row[1]), $documented);
            $arguments = array_keys((array) ($schema['inputSchema']['properties'] ?? []));

            sort($arguments);
            $documented = $documented[1];
            sort($documented);

            $this->assertSame($arguments, $documented, "README.md: arguments of the MCP tool {$schema['name']}");
        }
    }

    public function test_the_cli_output_and_the_log_line_samples_match_real_output(): void
    {
        $samples = [];

        foreach ($this->docs()->blocks('README.md') as $block) {
            if (in_array($block['marker'], ['cli-output', 'log-line'], true)) {
                $samples[$block['marker']] = trim($block['code']);
            }
        }

        $this->assertCount(2, $samples);

        // The CLI sample: bfsg:check /contact on a page with an image without alt and a link "hier klicken"
        Route::get('/contact', fn () => '<!DOCTYPE html><html lang="en"><head><title>Contact – Example</title></head><body>'
            .'<header><nav><a href="#main">Skip to content</a></nav></header>'
            .'<main id="main"><h1>Contact</h1><img src="/produkt.jpg"><p><a href="/more">hier klicken</a></p></main>'
            .'<footer><p>Footer content</p></footer></body></html>');

        $this->assertSame(1, Artisan::call('bfsg:check', ['url' => '/contact']));
        $this->assertSame($samples['cli-output'], trim(Artisan::output()), 'README.md: the CLI output shown differs from the real output');

        // The log-line sample: the middleware on https://example.com/contact with one error, two warnings and one notice
        config()->set('bfsg.middleware.enabled', true);
        config()->set('bfsg.middleware.ignored_paths', []);
        config()->set('logging.channels.bfsg-docs', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        config()->set('bfsg.middleware.log_channel', 'bfsg-docs');
        config()->set('app.debug', false);

        $request = Request::create('https://example.com/contact');
        $response = new Response('<!DOCTYPE html><html lang="en"><head><title>Contact – Example</title></head><body>'
            .'<header><nav><a href="#main">Skip to content</a></nav></header>'
            .'<main id="main"><h1>Contact</h1><img src="team.jpg">'
            .'<p><a href="/hours">click here</a></p><p><a href="/directions">read more</a></p>'
            .'<p><a href="https://partner.example/" target="_blank" rel="noopener">Partner site of our company</a></p></main>'
            .'<footer><p>Footer content</p></footer></body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        $middleware = new CheckAccessibility;
        $middleware->terminate($request, $middleware->handle($request, fn () => $response));

        $records = Log::channel('bfsg-docs')->getLogger()->getHandlers()[0]->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame($samples['log-line'], $records[0]->message.' '.json_encode($records[0]->context), 'README.md: the log line shown differs from the real one');
    }
}
