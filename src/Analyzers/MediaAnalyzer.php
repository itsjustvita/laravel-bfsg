<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

use DOMAttr;
use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\Element;
use ItsJustVita\LaravelBfsg\Dom\Text;
use ItsJustVita\LaravelBfsg\Severity;

class MediaAnalyzer extends BaseAnalyzer
{
    private const MAX_SRC = 60;

    private const CAPTION_KINDS = ['captions', 'subtitles'];

    private const VIDEO_HOSTS = '~(?:^|[/.])(?:youtube\.com|youtu\.be|youtube-nocookie\.com|vimeo\.com)(?:[/:?#]|$)~i';

    private const TRANSCRIPT_WORDS = ['transcript', 'transkript', 'mitschrift'];

    protected string $key = 'media';

    protected string $description = 'Alternatives and controls for audio, video and embedded media';

    protected array $rules = ['1.2.1', '1.2.2', '1.2.5', '1.4.2', '2.1.1', '2.2.2', '4.1.2'];

    protected function inspect(): void
    {
        foreach ($this->queryVisible('//video') as $video) {
            $this->checkVideo($video);
        }

        foreach ($this->queryVisible('//audio') as $audio) {
            $this->checkAudio($audio);
        }

        foreach ($this->queryVisible('//iframe') as $iframe) {
            $this->checkIframe($iframe);
        }
    }

    protected function checkVideo(DOMElement $video): void
    {
        $src = $this->mediaSource($video);
        $muted = $video->hasAttribute('muted');
        $autoplay = $video->hasAttribute('autoplay');
        $controlled = $video->hasAttribute('controls') || $this->hasPlayerHint($video);
        $kinds = array_map(
            fn (DOMElement $track) => $track->hasAttribute('kind') ? Element::enumAttr($track, 'kind') : 'subtitles',
            $this->query('./track', $video),
        );

        if (! $muted && array_intersect($kinds, self::CAPTION_KINDS) === []) {
            $this->report('video_missing_captions', Severity::Error, '1.2.2', $video, ['src' => $src]);
        }

        if (! $muted && ! in_array('descriptions', $kinds, true)) {
            $this->report('video_missing_audio_description', Severity::Notice, '1.2.5', $video, ['src' => $src]);
        }

        if ($autoplay && ! $muted) {
            $this->report('autoplay_with_audio', Severity::Error, '1.4.2', $video, ['tag' => 'video']);
        } elseif ($autoplay && ! $controlled) {
            $this->report('autoplay_without_pause', Severity::Warning, '2.2.2', $video, ['src' => $src]);
        }

        if (! $autoplay && ! $controlled) {
            $this->report('video_missing_controls', Severity::Warning, '2.1.1', $video, ['src' => $src]);
        }
    }

    protected function checkAudio(DOMElement $audio): void
    {
        if ($audio->hasAttribute('autoplay') && ! $audio->hasAttribute('muted')) {
            $this->report('autoplay_with_audio', Severity::Error, '1.4.2', $audio, ['tag' => 'audio']);
        }

        if ($audio->hasAttribute('aria-describedby') || $audio->hasAttribute('aria-details') || $this->hasTranscriptNearby($audio)) {
            return;
        }

        $this->report('audio_missing_transcript', Severity::Warning, '1.2.1', $audio, ['src' => $this->mediaSource($audio)]);
    }

    protected function checkIframe(DOMElement $iframe): void
    {
        $src = trim($iframe->getAttribute('src')) ?: trim($iframe->getAttribute('data-src'));

        if ($this->authoredName($iframe) === '') {
            $this->report('iframe_missing_title', Severity::Error, '4.1.2', $iframe, ['src' => Text::truncate($src, self::MAX_SRC)]);
        }

        if (preg_match(self::VIDEO_HOSTS, $src) === 1) {
            $this->report('embedded_video_captions_unknown', Severity::Notice, '1.2.2', $iframe, ['src' => Text::truncate($src, self::MAX_SRC)]);
        }
    }

    /** Custom players: data-plyr*, class video-js, data-video-player, or a sibling controls container. */
    protected function hasPlayerHint(DOMElement $video): bool
    {
        if (Element::hasClassToken($video, 'video-js') || $video->hasAttribute('data-video-player')) {
            return true;
        }

        foreach ($video->attributes as $attribute) {
            if ($attribute instanceof DOMAttr && str_starts_with(strtolower($attribute->nodeName), 'data-plyr')) {
                return true;
            }
        }

        return $video->parentNode !== null
            // Substring match on purpose (spec A.10 `[class*=controls]`): player skins use "vjs-controls", "plyr__controls".
            && $this->query('./*[contains(@class, "controls") and not(self::video)]', $video->parentNode) !== [];
    }

    /** A transcript link or details element in the enclosing figure (or the parent element). */
    protected function hasTranscriptNearby(DOMElement $audio): bool
    {
        $container = Element::closest($audio, 'figure') ?? $audio->parentNode;

        if (! $container instanceof DOMElement) {
            return false;
        }

        foreach ($this->query('.//a[@href]|.//details', $container) as $candidate) {
            $text = Text::lower(Element::text($candidate).' '.$candidate->getAttribute('href'));

            foreach (self::TRANSCRIPT_WORDS as $word) {
                if (str_contains($text, $word)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** The src attribute, or the first <source> child's src; '' when neither is present. */
    protected function mediaSource(DOMElement $media): string
    {
        $src = $media->getAttribute('src');

        if ($src !== '') {
            return $src;
        }

        return ($this->query('./source', $media)[0] ?? null)?->getAttribute('src') ?? '';
    }
}
