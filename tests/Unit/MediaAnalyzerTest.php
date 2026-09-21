<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use ItsJustVita\LaravelBfsg\Analyzers\MediaAnalyzer;
use ItsJustVita\LaravelBfsg\Contracts\Analyzer;
use ItsJustVita\LaravelBfsg\Severity;
use ItsJustVita\LaravelBfsg\Tests\Support\AnalyzerTestCase;

class MediaAnalyzerTest extends AnalyzerTestCase
{
    protected function analyzer(): Analyzer
    {
        return new MediaAnalyzer;
    }

    public function test_detects_video_without_captions(): void
    {
        $violations = $this->analyze('<video src="video.mp4" controls></video>');

        $violation = $this->assertHasViolation($violations, 'media.video_missing_captions', element: 'video', severity: Severity::Error);
        $this->assertSame('1.2.2', $violation->rule);
        $this->assertSame(['src' => 'video.mp4'], $violation->params);
    }

    public function test_video_with_captions_track_has_no_caption_errors(): void
    {
        $violations = $this->analyze('
            <video src="video.mp4" controls>
                <track kind="captions" src="captions.vtt" srclang="en">
                <track kind="descriptions" src="descriptions.vtt" srclang="en">
            </video>
        ');

        $this->assertNoViolation($violations, 'media.video_missing_captions');
        $this->assertNoViolation($violations, 'media.video_missing_audio_description');
    }

    public function test_detects_video_without_audio_description_when_tracks_exist(): void
    {
        $violations = $this->analyze('
            <video src="video.mp4" controls>
                <track kind="captions" src="captions.vtt" srclang="en">
            </video>
        ');

        $violation = $this->assertHasViolation($violations, 'media.video_missing_audio_description', element: 'video', severity: Severity::Warning);
        $this->assertSame('1.2.5', $violation->rule);
        $this->assertSame(['src' => 'video.mp4'], $violation->params);
    }

    public function test_detects_video_with_autoplay(): void
    {
        $violations = $this->analyze('<video src="video.mp4" autoplay controls></video>');

        $violation = $this->assertHasViolation($violations, 'media.autoplay_with_audio', element: 'video', severity: Severity::Error);
        $this->assertSame('1.4.2', $violation->rule);
        $this->assertSame(['tag' => 'video'], $violation->params);
        $this->assertSame(['2.2.2'], $violation->related);
    }

    public function test_detects_video_without_controls(): void
    {
        $violations = $this->analyze('<video src="video.mp4"></video>');

        $violation = $this->assertHasViolation($violations, 'media.video_missing_controls', element: 'video', severity: Severity::Error);
        $this->assertSame('2.1.1', $violation->rule);
        $this->assertSame(['src' => 'video.mp4'], $violation->params);
    }

    public function test_video_source_element_provides_the_src_param(): void
    {
        $violations = $this->analyze('
            <video controls>
                <source src="movie.webm" type="video/webm">
            </video>
        ');

        $violation = $this->assertHasViolation($violations, 'media.video_missing_captions', element: 'video');
        $this->assertSame(['src' => 'movie.webm'], $violation->params);
    }

    public function test_detects_audio_without_transcript(): void
    {
        $violations = $this->analyze('<audio src="audio.mp3" controls></audio>');

        $violation = $this->assertHasViolation($violations, 'media.audio_missing_transcript', element: 'audio', severity: Severity::Warning);
        $this->assertSame('1.2.1', $violation->rule);
        $this->assertSame(['src' => 'audio.mp3'], $violation->params);
    }

    public function test_audio_with_transcript_reference_has_no_transcript_warning(): void
    {
        $violations = $this->analyze('
            <audio src="audio.mp3" controls aria-describedby="transcript"></audio>
            <p id="transcript">Transcript of the recording</p>
        ');

        $this->assertNoViolation($violations, 'media.audio_missing_transcript');
    }

    public function test_detects_audio_with_autoplay(): void
    {
        $violations = $this->analyze('<audio src="audio.mp3" autoplay controls></audio>');

        $violation = $this->assertHasViolation($violations, 'media.autoplay_with_audio', element: 'audio', severity: Severity::Error);
        $this->assertSame('1.4.2', $violation->rule);
        $this->assertSame(['tag' => 'audio'], $violation->params);
        $this->assertSame([], $violation->related);
    }

    public function test_detects_audio_without_controls(): void
    {
        $violations = $this->analyze('<audio src="audio.mp3"></audio>');

        $violation = $this->assertHasViolation($violations, 'media.audio_missing_controls', element: 'audio', severity: Severity::Error);
        $this->assertSame('2.1.1', $violation->rule);
        $this->assertSame(['src' => 'audio.mp3'], $violation->params);
    }

    public function test_detects_iframe_without_title(): void
    {
        $violations = $this->analyze('<iframe src="https://www.youtube.com/embed/abc123"></iframe>');

        $violation = $this->assertHasViolation($violations, 'media.iframe_missing_title', element: 'iframe', severity: Severity::Error);
        $this->assertSame('4.1.2', $violation->rule);
        $this->assertSame(['src' => 'https://www.youtube.com/embed/abc123'], $violation->params);
    }

    public function test_iframe_title_param_is_truncated(): void
    {
        $src = 'https://www.youtube.com/embed/abc123?start=1&end=2&modestbranding=1&rel=0&enablejsapi=1';

        $violations = $this->analyze('<iframe src="'.$src.'"></iframe>');

        $violation = $this->assertHasViolation($violations, 'media.iframe_missing_title', element: 'iframe');
        $this->assertSame(60, mb_strlen((string) $violation->params['src']));
        $this->assertStringEndsWith('…', (string) $violation->params['src']);
    }

    public function test_detects_youtube_iframe_without_cc_load_policy(): void
    {
        $violations = $this->analyze('<iframe src="https://www.youtube.com/embed/abc123" title="Video"></iframe>');

        $violation = $this->assertHasViolation($violations, 'media.embedded_video_captions_unknown', element: 'iframe', severity: Severity::Warning);
        $this->assertSame('1.2.2', $violation->rule);
        $this->assertSame(['src' => 'https://www.youtube.com/embed/abc123'], $violation->params);
    }

    public function test_youtube_iframe_with_cc_load_policy_has_no_caption_warning(): void
    {
        $violations = $this->analyze('<iframe src="https://www.youtube.com/embed/abc123?cc_load_policy=1" title="Video"></iframe>');

        $this->assertNoViolation($violations, 'media.embedded_video_captions_unknown');
    }

    public function test_non_media_iframe_is_ignored(): void
    {
        $violations = $this->analyze('<iframe src="https://example.com/widget"></iframe>');

        $this->assertSame([], $violations);
    }

    public function test_html_without_media_returns_no_violations(): void
    {
        $violations = $this->analyze('<html><body><p>No media here</p></body></html>');

        $this->assertSame([], $violations);
    }
}
