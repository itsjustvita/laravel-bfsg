<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit;

use DOMDocument;
use ItsJustVita\LaravelBfsg\Analyzers\MediaAnalyzer;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class MediaAnalyzerTest extends TestCase
{
    protected MediaAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new MediaAnalyzer;
    }

    protected function analyzeHtml(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        return $this->analyzer->analyze($dom);
    }

    public function test_detects_video_without_captions()
    {
        $result = $this->analyzeHtml('<video src="video.mp4" controls></video>');

        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], 'without captions')),
            'Should detect video without captions or subtitles'
        );
    }

    public function test_video_with_captions_track_has_no_caption_errors()
    {
        $result = $this->analyzeHtml('
            <video src="video.mp4" controls>
                <track kind="captions" src="captions.vtt" srclang="en">
                <track kind="descriptions" src="descriptions.vtt" srclang="en">
            </video>
        ');

        $captionIssues = collect($result['issues'])->filter(
            fn ($i) => str_contains($i['message'], 'without captions')
        );

        $this->assertEmpty($captionIssues, 'Video with captions track should not produce caption errors');
    }

    public function test_detects_video_with_autoplay()
    {
        $result = $this->analyzeHtml('<video src="video.mp4" autoplay controls></video>');

        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], 'autoplay')),
            'Should detect video with autoplay enabled'
        );
    }

    public function test_detects_audio_without_transcript()
    {
        $result = $this->analyzeHtml('<audio src="audio.mp3" controls></audio>');

        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], 'without transcript')),
            'Should detect audio without transcript reference'
        );
    }

    public function test_detects_audio_with_autoplay()
    {
        $result = $this->analyzeHtml('<audio src="audio.mp3" autoplay controls></audio>');

        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], 'autoplay')),
            'Should detect audio with autoplay enabled'
        );
    }

    public function test_detects_iframe_without_title()
    {
        $result = $this->analyzeHtml('<iframe src="https://www.youtube.com/embed/abc123"></iframe>');

        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], 'without title')),
            'Should detect media iframe without title attribute'
        );
    }

    public function test_detects_youtube_iframe_without_cc_load_policy()
    {
        $result = $this->analyzeHtml('<iframe src="https://www.youtube.com/embed/abc123" title="Video"></iframe>');

        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], 'captions enabled')),
            'Should detect YouTube iframe without cc_load_policy'
        );
    }

    public function test_html_without_media_returns_empty_issues()
    {
        $result = $this->analyzeHtml('<html><body><p>No media here</p></body></html>');

        $this->assertEmpty($result['issues']);
    }

    public function test_detects_video_without_controls()
    {
        $result = $this->analyzeHtml('<video src="video.mp4"></video>');

        $this->assertTrue(
            collect($result['issues'])->contains(fn ($i) => str_contains($i['message'], 'without controls')),
            'Should detect video without controls attribute'
        );
    }
}
