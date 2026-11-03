@php
    $t = fn (string $key, array $replace = []) => __('bfsg::report.'.$key, $replace, $locale);
    $summary = $report['summary'];
    // Page-controlled text (messages carry page params, the URL, snippets) must not become Markdown structure,
    // inline markup or raw HTML: valid UTF-8 without control characters, one line each; outside code spans <, >
    // and & as entities and inline punctuation (also | for table cells) backslash-escaped (Sanitizer::markdown);
    // code spans fenced longer than any backtick run inside them (their content is literal, so no escaping there).
    $line = fn (?string $text) => trim((string) preg_replace('/\s*\R\s*/u', ' ', \ItsJustVita\LaravelBfsg\Support\Sanitizer::terminal((string) $text)));
    $text = fn (?string $value) => \ItsJustVita\LaravelBfsg\Support\Sanitizer::markdown($line($value));
    $code = function (string $value) use ($line): string {
        $value = $line($value);
        preg_match_all('/`+/', $value, $runs);
        $longest = max(array_map('strlen', $runs[0]) ?: [0]);
        $fence = str_repeat('`', $longest + 1);

        return $fence.($longest > 0 ? ' '.$value.' ' : $value).$fence;
    };
@endphp
# {{ $t('title') }}

| | |
|---|---|
| {{ $t('url') }} | {!! $text($report['url'] ?? '–') !!} |
| {{ $t('date') }} | {{ $analyzedAt->format('Y-m-d H:i') }} |
| {{ $t('score') }} | {!! $text($t('score_value', ['score' => $summary['score']])) !!} |
| {{ $t('grade') }} | {{ $summary['grade'] }} |
| {{ $t('total_issues') }} | {{ $summary['total'] }} |
| {{ $t('errors') }} | {{ $summary['errors'] }} |
| {{ $t('warnings') }} | {{ $summary['warnings'] }} |
| {{ $t('notices') }} | {{ $summary['notices'] }} |

@if($summary['total'] === 0)
{!! $t('no_issues') !!}
@else
## {!! $t('findings') !!}
@foreach($report['violations'] as $analyzer => $violations)

### {{ $analyzer }} ({{ count($violations) }})

@foreach($violations as $violation)
- **{!! \ItsJustVita\LaravelBfsg\Severity::from($violation['severity'])->label($locale) !!}** · {!! $violation['rule'] === null ? $t('no_rule') : $t('rule').' '.$violation['rule'] !!}{!! $violation['element'] === null ? '' : ' · '.$code($violation['element']) !!}  
  {!! $text($violation['message']) !!}  
  _{!! $t('suggestion') !!}:_ {!! $text($violation['suggestion']) !!}  
@if($violation['snippet'] !== null)
  {!! $code($violation['snippet']) !!}
@endif
@endforeach
@endforeach
@endif

{!! $t('generated_by', ['version' => $version, 'date' => $analyzedAt->format('Y-m-d H:i')]) !!}
