<?php

// Checks every `php artisan bfsg:*` line of README.md, UPGRADE.md and SPA-TESTING.md against the commands of the
// package as installed in the app (php artisan help <command> --format=json, run in the current directory).
// Usage (from the app directory): php <package>/tests/Live/doc-commands.php <package-dir>

require __DIR__.'/../Support/DocSamples.php';

use ItsJustVita\LaravelBfsg\Tests\Support\DocSamples;

$docs = new DocSamples($argv[1] ?? dirname(__DIR__, 2));
$options = [];
$failures = [];
$calls = $docs->artisanCalls();

foreach ($calls as $call) {
    $where = "{$call['file']}:{$call['line']}";

    if (! array_key_exists($call['command'], $options)) {
        $help = json_decode((string) shell_exec('php artisan help '.escapeshellarg($call['command']).' --format=json 2>/dev/null'), true);
        $options[$call['command']] = null;

        if (is_array($help)) {
            $options[$call['command']] = [];

            foreach ($help['definition']['options'] ?? [] as $name => $option) {
                $options[$call['command']][] = $name;

                foreach (explode('|', (string) ($option['shortcut'] ?? '')) as $shortcut) {
                    if ($shortcut !== '') {
                        $options[$call['command']][] = ltrim($shortcut, '-');
                    }
                }
            }
        }
    }

    if ($options[$call['command']] === null) {
        $failures[] = "{$where}: {$call['command']} is not a command of the installed package";

        continue;
    }

    foreach (array_diff($call['options'], $options[$call['command']]) as $unknown) {
        $failures[] = "{$where}: {$call['command']} has no option --{$unknown}";
    }
}

if (count($calls) < 20) {
    $failures[] = 'only '.count($calls).' artisan calls found in the docs';
}

foreach ($failures as $failure) {
    fwrite(STDERR, $failure."\n");
}

echo count($calls).' documented bfsg command lines checked against the installed package'."\n";

exit($failures === [] ? 0 : 1);
