<?php

namespace Tahadudhiya\SmartLinks\linktypes\embed;

/**
 * Spotify tracks, albums, playlists, artists, podcast shows and episodes. The media ID is the
 * kind and the 22-character Spotify ID, e.g. `track/4uLU6hMCjMI75M1A2tKUQC`.
 */
final class Spotify extends BaseEmbedProvider
{
    private const KINDS = 'track|album|playlist|artist|show|episode';

    public function handle(): string
    {
        return 'spotify';
    }

    public function name(): string
    {
        return 'Spotify';
    }

    public function isMediaId(string $mediaId): bool
    {
        return (bool)preg_match('~^(?:' . self::KINDS . ')/[A-Za-z0-9]{22}$~D', $mediaId);
    }

    public function pageUrl(string $mediaId): string
    {
        return "https://open.spotify.com/$mediaId";
    }

    public function embedUrl(string $mediaId): string
    {
        return "https://open.spotify.com/embed/$mediaId";
    }

    protected function hosts(): array
    {
        return ['open.spotify.com'];
    }

    protected function mediaIdFromPath(string $host, string $path, array $query): ?string
    {
        // Localized pages (`/intl-de/track/…`) and the player (`/embed/track/…`) name the same media.
        return preg_match('~^/(?:intl-[a-z]{2}(?:-[A-Za-z]+)?/|embed/)?((?:' . self::KINDS . ')/[^/]+)$~', $path, $match) ? $match[1] : null;
    }
}
