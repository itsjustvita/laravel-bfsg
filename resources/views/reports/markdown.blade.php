@php
    $t = fn (string $key, array $replace = []) => __('bfsg::report.'.$key, $replace, $locale);
    $summary = $report['summary'];
    $cell = fn (?string $text) => str_replace(['|', "\n"], ['\|', ' '], (string) $text);
    $code = function (string $text): string {
        $fence = str_contains($text, '`') ? '``' : '`';

        return $fence.($fence === '``' ? ' '.$text.' ' : $text).$fence;
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
  {!! $violation['message'] !!}  
  _{!! $t('suggestion') !!}:_ {!! $violation['suggestion'] !!}  
@if($violation['snippet'] !== null)
  {!! $code($violation['snippet']) !!}
@endif
@endforeach
@endforeach
@endif

{!! $t('generated_by', ['version' => $version, 'date' => $analyzedAt->format('Y-m-d H:i')]) !!}
