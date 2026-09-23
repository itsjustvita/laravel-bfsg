@php
    $t = fn (string $key, array $replace = []) => __('bfsg::report.'.$key, $replace, $locale);
    $summary = $report['summary'];
    // Page-controlled text (messages carry page params, the URL, snippets) must not become Markdown structure or
    // raw HTML: one line each, <, > and & as entities outside code spans, code spans fenced longer than any
    // backtick run inside them (their content is literal, so no entity escaping there).
    $line = fn (?string $text) => trim((string) preg_replace('/\s*\R\s*/u', ' ', (string) $text));
    $text = fn (?string $value) => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $line($value));
    $cell = fn (?string $value) => str_replace('|', '\|', $text($value));
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
| {{ $t('url') }} | {!! $cell($report['url'] ?? '–') !!} |
| {{ $t('date') }} | {{ $analyzedAt->format('Y-m-d H:i') }} |
| {{ $t('score') }} | {!! $cell($t('score_value', ['score' => $summary['score']])) !!} |
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
