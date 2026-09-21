<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Severity;

class MediaAnalyzer extends BaseAnalyzer
{
    private const MAX_SRC = 60;

    private const CAPTION_KINDS = ['captions', 'subtitles'];

    private const MEDIA_HOSTS = '/(youtube\.com|youtu\.be|vimeo\.com|dailymotion\.com)/i';

    private const YOUTUBE_HOSTS = '/(youtube\.com|youtu\.be)/i';

    protected string $key = 'media';

    protected string $description = 'Alternatives and controls for audio, video and embedded media';

    protected array $rules = ['1.2.1', '1.2.2', '1.2.5', '1.4.2', '2.1.1', '4.1.2'];

    protected function inspect(): void
    {
        foreach ($this->query('//video') as $video) {
            $this->checkVideo($video);
        }

        foreach ($this->query('//audio') as $audio) {
            $this->checkAudio($audio);
        }

        foreach ($this->query('//iframe') as $iframe) {
            $this->checkIframe($iframe);
        }
    }

    protected function checkVideo(DOMElement $video): void
    {
        $src = $this->mediaSource($video);
        $tracks = $this->query('.//track', $video);
        $kinds = array_map(fn (DOMElement $track) => $track->getAttribute('kind'), $tracks);

        if (array_intersect($kinds, self::CAPTION_KINDS) === []) {
            $this->report('video_missing_captions', Severity::Error, '1.2.2', $video, ['src' => $src]);
        }

        // Audio description is only demanded where the author already ships tracks.
        if ($tracks !== [] && ! in_array('descriptions', $kinds, true)) {
            $this->report('video_missing_audio_description', Severity::Warning, '1.2.5', $video, ['src' => $src]);
        }

        if ($video->hasAttribute('autoplay')) {
            $this->report('autoplay_with_audio', Severity::Error, '1.4.2', $video, ['tag' => 'video'], related: ['2.2.2']);
        }

        if (! $video->hasAttribute('controls')) {
            $this->report('video_missing_controls', Severity::Error, '2.1.1', $video, ['src' => $src]);
        }
    }

    protected function checkAudio(DOMElement $audio): void
    {
        $src = $this->mediaSource($audio);

        // Simplified check: a transcript is only detectable via an explicit reference.
        if (! $audio->hasAttribute('aria-describedby')) {
            $this->report('audio_missing_transcript', Severity::Warning, '1.2.1', $audio, ['src' => $src]);
        }

        if ($audio->hasAttribute('autoplay')) {
            $this->report('autoplay_with_audio', Severity::Error, '1.4.2', $audio, ['tag' => 'audio']);
        }

        if (! $audio->hasAttribute('controls')) {
            $this->report('audio_missing_controls', Severity::Error, '2.1.1', $audio, ['src' => $src]);
        }
    }

    protected function checkIframe(DOMElement $iframe): void
    {
        $src = $iframe->getAttribute('src');

        if (preg_match(self::MEDIA_HOSTS, $src) !== 1) {
            return;
        }

        if (trim($iframe->getAttribute('title')) === '') {
            $this->report('iframe_missing_title', Severity::Error, '4.1.2', $iframe, [
                'src' => Text::truncate($src, self::MAX_SRC),
            ]);
        }

        if (preg_match(self::YOUTUBE_HOSTS, $src) === 1 && ! str_contains($src, 'cc_load_policy=1')) {
            $this->report('embedded_video_captions_unknown', Severity::Warning, '1.2.2', $iframe, ['src' => $src]);
        }
    }

    /** The src attribute, or the first <source> child's src; '' when neither is present. */
    protected function mediaSource(DOMElement $media): string
    {
        $src = $media->getAttribute('src');

        if ($src !== '') {
            return $src;
        }

        return ($this->query('.//source', $media)[0] ?? null)?->getAttribute('src') ?? '';
    }
}
