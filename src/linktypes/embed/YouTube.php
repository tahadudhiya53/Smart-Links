<?php

namespace Tahadudhiya\SmartLinks\linktypes\embed;

/**
 * YouTube videos, including Shorts and live streams. The media ID is the 11-character video ID.
 *
 * The player is YouTube's privacy-enhanced one (`youtube-nocookie.com`), which sets no cookies
 * until the video is played.
 */
final class YouTube extends BaseEmbedProvider
{
    public function handle(): string
    {
        return 'youtube';
    }

    public function name(): string
    {
        return 'YouTube';
    }

    public function isMediaId(string $mediaId): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_-]{11}$/D', $mediaId);
    }

    public function pageUrl(string $mediaId): string
    {
        return "https://www.youtube.com/watch?v=$mediaId";
    }

    public function embedUrl(string $mediaId): string
    {
        return "https://www.youtube-nocookie.com/embed/$mediaId";
    }

    protected function hosts(): array
    {
        return ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];
    }

    protected function mediaIdFromPath(string $host, string $path, array $query): ?string
    {
        if ($host === 'youtu.be') {
            return preg_match('~^/([^/]+)$~', $path, $match) ? $match[1] : null;
        }

        if ($path === '/watch') {
            return self::single($query, 'v');
        }

        return preg_match('~^/(?:shorts|embed|live|v)/([^/]+)$~', $path, $match) ? $match[1] : null;
    }
}
