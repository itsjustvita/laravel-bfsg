<?php

namespace ItsJustVita\LaravelBfsg\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use ItsJustVita\LaravelBfsg\Bfsg;
use ItsJustVita\LaravelBfsg\Facades\Bfsg as BfsgFacade;
use ItsJustVita\LaravelBfsg\Mcp\BfsgMcpServer;
use ItsJustVita\LaravelBfsg\Reports\ReportGenerator;
use ItsJustVita\LaravelBfsg\Tests\Support\DocSamples;
use ItsJustVita\LaravelBfsg\Tests\TestCase;
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
            $file = tempnam(sys_get_temp_dir(), 'bfsg-doc-').'.php';
            file_put_contents($file, str_starts_with($block['code'], '<?php') ? $block['code'] : "<?php\n".$block['code']);

            try {
                $lint = new SymfonyProcess([PHP_BINARY, '-l', $file]);
                $lint->run();
            } finally {
                unlink($file);
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

        foreach (['README.md', 'SPA-TESTING.md'] as $file) {
            preg_match_all('/\bBFSG_[A-Z_]+\b/', $this->docs()->markdown($file), $variables);

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

                // eval() of the package's own, versioned documentation: the point of the test is to run exactly what the docs show

                try {
                    eval(preg_replace('/^<\?php\s*/', '', $block['code']));
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
}
