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

    public function test_video_without_captions_cites_1_2_2(): void
    {
        $violations = $this->analyze('<video src="movie.mp4" controls></video>');

        $violation = $this->assertHasViolation($violations, 'media.video_missing_captions', element: 'video', severity: Severity::Error);
        $this->assertSame('1.2.2', $violation->rule);
        $this->assertSame(['src' => 'movie.mp4'], $violation->params);
    }

    public function test_caption_track_kinds(): void
    {
        $this->assertNoViolation($this->analyze('<video controls><track kind="CAPTIONS" src="c.vtt"></video>'), 'media.video_missing_captions');
        $this->assertNoViolation($this->analyze('<video controls><track src="s.vtt"></video>'), 'media.video_missing_captions');
        $this->assertHasViolation($this->analyze('<video controls><track kind="chapters" src="c.vtt"></video>'), 'media.video_missing_captions');
        $this->assertHasViolation($this->analyze('<video controls><track kind="" src="c.vtt"></video>'), 'media.video_missing_captions');
    }

    public function test_audio_description_is_a_notice_for_every_unmuted_video(): void
    {
        $violations = $this->analyze('<video controls><track kind="captions" src="c.vtt"></video><video controls><track kind="descriptions" src="d.vtt"><track kind="captions" src="c.vtt"></video>');

        $violation = $this->assertHasViolation($violations, 'media.video_missing_audio_description', severity: Severity::Notice);
        $this->assertSame('1.2.5', $violation->rule);
        $this->assertViolationCount($violations, 'media.video_missing_audio_description', 1);
    }

    public function test_muted_video_needs_no_captions_or_description(): void
    {
        $violations = $this->analyze('<video muted controls src="loop.mp4"></video>');

        $this->assertSame([], $violations);
    }

    public function test_video_missing_controls_is_a_warning_with_player_hints(): void
    {
        $html = '<video id="bare" src="a.mp4"><track kind="captions"><track kind="descriptions"></video>'
            .'<video class="video-js" src="b.mp4"><track kind="captions"><track kind="descriptions"></video>'
            .'<video data-plyr-provider="html5" src="c.mp4"><track kind="captions"><track kind="descriptions"></video>'
            .'<div><video src="d.mp4"><track kind="captions"><track kind="descriptions"></video><div class="player-controls">▶</div></div>'
            .'<video aria-hidden="true" src="e.mp4"></video>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'media.video_missing_controls', element: 'video#bare', severity: Severity::Warning);
        $this->assertSame('2.1.1', $violation->rule);
        $this->assertCount(1, $violations);
    }

    public function test_autoplay_with_sound_is_an_error(): void
    {
        $violations = $this->analyze('<video autoplay controls src="a.mp4"><track kind="captions"><track kind="descriptions"></video><audio autoplay controls src="a.mp3" aria-describedby="t"></audio><p id="t">Transcript</p>');

        $violation = $this->assertHasViolation($violations, 'media.autoplay_with_audio', element: 'video', severity: Severity::Error);
        $this->assertSame('1.4.2', $violation->rule);
        $this->assertSame([], $violation->related);
        $this->assertHasViolation($violations, 'media.autoplay_with_audio', element: 'audio');
        $this->assertCount(2, $violations);
    }

    public function test_muted_autoplay_without_controls_needs_a_pause_mechanism(): void
    {
        $violations = $this->analyze('<video autoplay muted loop src="bg.mp4"></video><video autoplay muted controls src="ok.mp4"></video>');

        $violation = $this->assertHasViolation($violations, 'media.autoplay_without_pause', severity: Severity::Warning);
        $this->assertSame('2.2.2', $violation->rule);
        $this->assertSame(['src' => 'bg.mp4'], $violation->params);
        $this->assertCount(1, $violations);
    }

    public function test_video_source_element_provides_the_src_param(): void
    {
        $violation = $this->assertHasViolation($this->analyze('<video controls><source src="movie.webm" type="video/webm"></video>'), 'media.video_missing_captions');

        $this->assertSame(['src' => 'movie.webm'], $violation->params);
    }

    public function test_audio_transcript_detection(): void
    {
        $html = '<audio id="a" src="a.mp3" controls></audio>'
            .'<figure><audio src="b.mp3" controls></audio><figcaption><a href="/b-transkript">Transkript lesen</a></figcaption></figure>'
            .'<div><audio src="c.mp3" controls></audio><details><summary>Transcript</summary>…</details></div>'
            .'<audio src="d.mp3" controls aria-details="t"></audio><div id="t">Text</div>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'media.audio_missing_transcript', element: 'audio#a', severity: Severity::Warning);
        $this->assertSame('1.2.1', $violation->rule);
        $this->assertViolationCount($violations, 'media.audio_missing_transcript', 1);
    }

    public function test_every_iframe_needs_a_name(): void
    {
        $html = '<iframe src="https://maps.example.com/embed"></iframe><iframe data-src="/lazy.html"></iframe>'
            .'<iframe src="/a" title="Opening hours"></iframe><iframe src="/b" aria-label="Map"></iframe>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'media.iframe_missing_title', element: 'iframe', severity: Severity::Error);
        $this->assertSame(['src' => 'https://maps.example.com/embed'], $violation->params);
        $this->assertViolationCount($violations, 'media.iframe_missing_title', 2);
    }

    public function test_iframe_src_param_is_truncated(): void
    {
        $violation = $this->assertHasViolation($this->analyze('<iframe src="https://example.com/'.str_repeat('a', 100).'"></iframe>'), 'media.iframe_missing_title');

        $this->assertSame(60, mb_strlen($violation->params['src']));
    }

    public function test_embedded_video_captions_are_a_reminder(): void
    {
        $html = '<iframe title="Video" src="https://www.youtube-nocookie.com/embed/x?cc_load_policy=1"></iframe>'
            .'<iframe title="Video" data-src="https://player.vimeo.com/video/1"></iframe>'
            .'<iframe title="Video" src="https://youtu.be/abc"></iframe>'
            .'<iframe title="Not a video" src="https://notyoutube.com.example.org/"></iframe>';

        $violations = $this->analyze($html);

        $violation = $this->assertHasViolation($violations, 'media.embedded_video_captions_unknown', severity: Severity::Notice);
        $this->assertSame('1.2.2', $violation->rule);
        $this->assertViolationCount($violations, 'media.embedded_video_captions_unknown', 3);
    }

    public function test_hidden_media_is_skipped(): void
    {
        $this->assertSame([], $this->analyze('<div hidden><video src="a.mp4"></video><audio src="a.mp3"></audio><iframe src="/x"></iframe></div>'));
    }
}
