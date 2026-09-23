@php
    $t = fn (string $key, array $replace = []) => __('bfsg::report.'.$key, $replace, $locale);
    $summary = $report['summary'];
    $host = parse_url((string) $report['url'], PHP_URL_HOST) ?: (string) $report['url'];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ \Illuminate\Support\Str::limit($t('title').($host === '' ? '' : ' – '.$host), 70) }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; line-height: 1.5; color: #1f2937; background: #ffffff; }
        .masthead { background: #111827; color: #ffffff; padding: 32px 40px; }
        .masthead h1 { margin: 0 0 16px; font-size: 28px; }
        .masthead .brand { margin: 0 0 4px; font-size: 14px; color: #e5e7eb; }
        .meta { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px; margin: 0; font-size: 14px; }
        .meta dt { font-weight: 600; color: #e5e7eb; }
        .meta dd { margin: 0; color: #ffffff; word-break: break-all; }
        main { max-width: 1100px; margin: 0 auto; padding: 32px 40px; }
        h2 { font-size: 22px; margin: 32px 0 16px; }
        h3 { font-size: 18px; margin: 24px 0 12px; border-bottom: 2px solid #d1d5db; padding-bottom: 4px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin: 0; }
        .stats div { border: 1px solid #d1d5db; border-radius: 8px; padding: 12px 16px; }
        .stats dt { font-size: 13px; color: #4b5563; }
        .stats dd { margin: 4px 0 0; font-size: 26px; font-weight: 700; color: #111827; }
        .clean { font-size: 18px; color: #166534; }
        .findings { list-style: none; margin: 0; padding: 0; }
        .finding { border: 1px solid #d1d5db; border-left-width: 6px; border-radius: 6px; padding: 12px 16px; margin: 0 0 12px; }
        .finding-error { border-left-color: #b91c1c; }
        .finding-warning { border-left-color: #b45309; }
        .finding-notice { border-left-color: #1d4ed8; }
        .finding p { margin: 4px 0; }
        .badge { display: inline-block; border: 1px solid currentColor; border-radius: 4px; padding: 0 8px; font-size: 13px; font-weight: 700; }
        .badge-error { color: #b91c1c; }
        .badge-warning { color: #b45309; }
        .badge-notice { color: #1d4ed8; }
        .criterion { font-size: 13px; color: #374151; }
        .finding-text { font-weight: 600; color: #111827; }
        .finding-hint { color: #374151; }
        code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; color: #111827; background: #f3f4f6; }
        pre { padding: 8px 12px; border-radius: 4px; white-space: pre-wrap; word-break: break-all; margin: 8px 0 0; }
        footer { max-width: 1100px; margin: 0 auto; padding: 16px 40px 32px; font-size: 13px; color: #4b5563; }
        footer a { color: #1d4ed8; }
        @media print { .masthead { background: #ffffff; color: #111827; border-bottom: 2px solid #111827; } .masthead .brand, .meta dt, .meta dd { color: #111827; } .finding { page-break-inside: avoid; } }
    </style>
</head>
<body>
<header class="masthead">
    <p class="brand">laravel-bfsg</p>
    <h1>{{ $t('title') }}</h1>
    <dl class="meta">
        <dt>{{ $t('url') }}</dt>
        <dd>{{ $report['url'] ?? '–' }}</dd>
        <dt>{{ $t('date') }}</dt>
        <dd>{{ $analyzedAt->format('Y-m-d H:i') }}</dd>
    </dl>
</header>
<main>
    <section aria-labelledby="bfsg-summary">
        <h2 id="bfsg-summary">{{ $t('summary') }}</h2>
        <dl class="stats">
            <div><dt>{{ $t('score') }}</dt><dd>{{ $t('score_value', ['score' => $summary['score']]) }}</dd></div>
            <div><dt>{{ $t('grade') }}</dt><dd>{{ $summary['grade'] }}</dd></div>
            <div><dt>{{ $t('total_issues') }}</dt><dd>{{ $summary['total'] }}</dd></div>
            <div><dt>{{ $t('errors') }}</dt><dd>{{ $summary['errors'] }}</dd></div>
            <div><dt>{{ $t('warnings') }}</dt><dd>{{ $summary['warnings'] }}</dd></div>
            <div><dt>{{ $t('notices') }}</dt><dd>{{ $summary['notices'] }}</dd></div>
        </dl>
    </section>
@if($summary['total'] === 0)
    <p class="clean">{{ $t('no_issues') }}</p>
@else
    <section aria-labelledby="bfsg-findings">
        <h2 id="bfsg-findings">{{ $t('findings') }}</h2>
@foreach($report['violations'] as $analyzer => $violations)
        <section aria-labelledby="bfsg-analyzer-{{ $analyzer }}">
            <h3 id="bfsg-analyzer-{{ $analyzer }}">{{ $analyzer }} ({{ count($violations) }})</h3>
            <ol class="findings">
@foreach($violations as $violation)
                <li class="finding finding-{{ $violation['severity'] }}">
                    <p><span class="badge badge-{{ $violation['severity'] }}">{{ \ItsJustVita\LaravelBfsg\Severity::from($violation['severity'])->label($locale) }}</span>
                        <span class="criterion">{{ $violation['rule'] === null ? $t('no_rule') : $t('rule').' '.$violation['rule'] }}</span></p>
                    <p class="finding-text">{{ $violation['message'] }}</p>
                    <p class="finding-hint">{{ $t('suggestion') }}: {{ $violation['suggestion'] }}</p>
@if($violation['element'] !== null)
                    <p>{{ $t('element') }}: <code>{{ $violation['element'] }}</code></p>
@endif
@if($violation['snippet'] !== null)
                    <pre><code>{{ $violation['snippet'] }}</code></pre>
@endif
                </li>
@endforeach
            </ol>
        </section>
@endforeach
    </section>
@endif
</main>
<footer>
    <p>{{ $t('generated_by', ['version' => $version, 'date' => $analyzedAt->format('Y-m-d H:i')]) }} · <a href="https://github.com/itsjustvita/laravel-bfsg">{{ $t('source') }}</a></p>
</footer>
</body>
</html>
