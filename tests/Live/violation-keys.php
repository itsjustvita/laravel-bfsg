<?php

// Reads bfsg:check --format=json output on STDIN, tolerates status lines around the JSON object,
// and prints one "<severity> <key>" line per finding. Exits 1 when no JSON object can be parsed.

$raw = stream_get_contents(STDIN);
$start = strpos($raw, '{');
$end = strrpos($raw, '}');

if ($start === false || $end === false || $end < $start) {
    fwrite(STDERR, "no JSON object in bfsg:check output:\n{$raw}\n");
    exit(1);
}

try {
    $data = json_decode(substr($raw, $start, $end - $start + 1), true, flags: JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, 'bfsg:check JSON does not parse: '.$e->getMessage()."\n{$raw}\n");
    exit(1);
}

// Findings are arrays with a string "key" and "severity", wherever the report nests them.
$walk = function (mixed $node) use (&$walk): void {
    if (! is_array($node)) {
        return;
    }

    if (is_string($node['key'] ?? null) && is_string($node['severity'] ?? null)) {
        echo $node['severity'].' '.$node['key']."\n";

        return;
    }

    foreach ($node as $child) {
        $walk($child);
    }
};

$walk($data['violations'] ?? $data);
