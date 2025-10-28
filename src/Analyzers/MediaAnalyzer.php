<?php

namespace ItsJustVita\LaravelBfsg\Analyzers;

class MediaAnalyzer
{
    /**
     * Analyze video and audio elements for accessibility
     */
    public function analyze(\DOMDocument $dom): array
    {
        $issues = [];

        // Analyze video elements
        $videos = $dom->getElementsByTagName('video');
        foreach ($videos as $video) {
            $this->analyzeVideoElement($video, $issues);
        }

        // Analyze audio elements
        $audios = $dom->getElementsByTagName('audio');
        foreach ($audios as $audio) {
            $this->analyzeAudioElement($audio, $issues);
        }

        // Analyze iframe embeds (YouTube, Vimeo, etc.)
        $iframes = $dom->getElementsByTagName('iframe');
        foreach ($iframes as $iframe) {
            $this->analyzeIframeElement($iframe, $issues);
        }

        return [
            'issues' => $issues,
            'stats' => [
                'total_issues' => count($issues),
                'videos_found' => $videos->length,
                'audios_found' => $audios->length,
                'iframes_found' => $iframes->length,
            ],
        ];
    }

    /**
     * Analyze video element
     */
    protected function analyzeVideoElement(\DOMElement $video, array &$issues): void
    {
        // Check for captions/subtitles track
        $tracks = $video->getElementsByTagName('track');
        $hasCaptions = false;

        foreach ($tracks as $track) {
            $kind = $track->getAttribute('kind');
            if (in_array($kind, ['captions', 'subtitles'])) {
                $hasCaptions = true;
                break;
            }
        }

        if (!$hasCaptions) {
            $issues[] = [
                'rule' => 'WCAG 1.2.2, 1.2.4',
                'message' => 'Video element without captions or subtitles',
                'element' => 'video',
                'suggestion' => 'Add <track kind="captions"> or <track kind="subtitles"> element for accessibility',
                'severity' => 'error',
            ];
        }

        // Check for audio description track
        $hasAudioDescription = false;
        foreach ($tracks as $track) {
            if ($track->getAttribute('kind') === 'descriptions') {
                $hasAudioDescription = true;
                break;
            }
        }

        if (!$hasAudioDescription && $tracks->length > 0) {
            $issues[] = [
                'rule' => 'WCAG 1.2.5 (Level AA)',
                'message' => 'Video without audio description track',
                'element' => 'video',
                'suggestion' => 'Consider adding <track kind="descriptions"> for visual content explanation',
                'severity' => 'warning',
            ];
        }

        // Check for autoplay
        if ($video->hasAttribute('autoplay')) {
            $issues[] = [
                'rule' => 'WCAG 1.4.2, 2.2.2',
                'message' => 'Video with autoplay enabled',
                'element' => 'video',
                'suggestion' => 'Remove autoplay attribute; let users control media playback',
                'severity' => 'error',
            ];
        }

        // Check for controls
        if (!$video->hasAttribute('controls')) {
            $issues[] = [
                'rule' => 'WCAG 2.1.1',
                'message' => 'Video without controls attribute',
                'element' => 'video',
                'suggestion' => 'Add controls attribute to allow keyboard and mouse control',
                'severity' => 'error',
            ];
        }
    }

    /**
     * Analyze audio element
     */
    protected function analyzeAudioElement(\DOMElement $audio, array &$issues): void
    {
        // Check for transcript (usually linked nearby)
        // Note: This is a simplified check - in reality, transcript might be in surrounding context
        $hasTranscriptLink = $audio->hasAttribute('aria-describedby');

        if (!$hasTranscriptLink) {
            $issues[] = [
                'rule' => 'WCAG 1.2.1',
                'message' => 'Audio element without transcript reference',
                'element' => 'audio',
                'suggestion' => 'Provide a transcript and reference it with aria-describedby or link it nearby',
                'severity' => 'warning',
            ];
        }

        // Check for autoplay
        if ($audio->hasAttribute('autoplay')) {
            $issues[] = [
                'rule' => 'WCAG 1.4.2',
                'message' => 'Audio with autoplay enabled',
                'element' => 'audio',
                'suggestion' => 'Remove autoplay attribute; let users control audio playback',
                'severity' => 'error',
            ];
        }

        // Check for controls
        if (!$audio->hasAttribute('controls')) {
            $issues[] = [
                'rule' => 'WCAG 2.1.1',
                'message' => 'Audio without controls attribute',
                'element' => 'audio',
                'suggestion' => 'Add controls attribute to allow keyboard and mouse control',
                'severity' => 'error',
            ];
        }
    }

    /**
     * Analyze iframe embeds (YouTube, Vimeo, etc.)
     */
    protected function analyzeIframeElement(\DOMElement $iframe, array &$issues): void
    {
        $src = $iframe->getAttribute('src');

        // Check if it's a media iframe (YouTube, Vimeo, etc.)
        $isMediaIframe = preg_match('/(youtube\.com|youtu\.be|vimeo\.com|dailymotion\.com)/i', $src);

        if ($isMediaIframe) {
            // Check for title attribute
            $title = $iframe->getAttribute('title');
            if (empty($title)) {
                $issues[] = [
                    'rule' => 'WCAG 2.4.1, 4.1.2',
                    'message' => 'Media iframe without title attribute',
                    'element' => 'iframe',
                    'src' => substr($src, 0, 50) . '...',
                    'suggestion' => 'Add descriptive title attribute to iframe (e.g., "YouTube video: Tutorial title")',
                    'severity' => 'error',
                ];
            }

            // Check for YouTube CC parameter
            if (strpos($src, 'youtube.com') !== false || strpos($src, 'youtu.be') !== false) {
                if (strpos($src, 'cc_load_policy=1') === false) {
                    $issues[] = [
                        'rule' => 'WCAG 1.2.2',
                        'message' => 'YouTube iframe without captions enabled by default',
                        'element' => 'iframe',
                        'suggestion' => 'Add ?cc_load_policy=1 parameter to YouTube URL to enable captions',
                        'severity' => 'warning',
                    ];
                }
            }
        }
    }
}
