<?php

namespace Tahadudhiya\SmartLinks\linktypes\embed;

/**
 * SoundCloud tracks and playlists. The media ID is the path of the public page, e.g.
 * `artist/track` or `artist/sets/playlist`. Private tracks, whose URLs carry a secret token, are
 * not supported.
 */
final class SoundCloud extends BaseEmbedProvider
{
    private const SEGMENT = '[A-Za-z0-9_-]+';

    /** Site pages, not artists, whose paths have the same shape as a track's. */
    private const RESERVED_FIRST = ['discover', 'search', 'stream', 'you', 'upload', 'charts', 'pages', 'settings', 'messages', 'notifications', 'terms-of-use', 'imprint'];

    /** An artist's own pages, not tracks: `artist/tracks` lists their tracks. */
    private const RESERVED_SECOND = ['tracks', 'albums', 'sets', 'reposts', 'likes', 'followers', 'following', 'popular-tracks', 'comments', 'spotlight'];

    public function handle(): string
    {
        return 'soundcloud';
    }

    public function name(): string
    {
        return 'SoundCloud';
    }

    public function isMediaId(string $mediaId): bool
    {
        $segment = self::SEGMENT;

        if (!preg_match("~^($segment)/(?:(sets)/)?($segment)$~D", $mediaId, $match)) {
            return false;
        }

        // `artist/sets/playlist` is a playlist; `artist/tracks`, `artist/likes`… are not tracks.
        return !in_array(strtolower($match[1]), self::RESERVED_FIRST, true)
            && ($match[2] === 'sets' || !in_array(strtolower($match[3]), self::RESERVED_SECOND, true));
    }

    public function pageUrl(string $mediaId): string
    {
        return "https://soundcloud.com/$mediaId";
    }

    public function embedUrl(string $mediaId): string
    {
        return 'https://w.soundcloud.com/player/?url=' . rawurlencode($this->pageUrl($mediaId));
    }

    protected function hosts(): array
    {
        return ['soundcloud.com', 'www.soundcloud.com', 'm.soundcloud.com'];
    }

    protected function mediaIdFromPath(string $host, string $path, array $query): string
    {
        return ltrim($path, '/');
    }
}
