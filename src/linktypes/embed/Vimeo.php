<?php

namespace Tahadudhiya\SmartLinks\linktypes\embed;

/**
 * Vimeo videos. The media ID is the video's number, followed by `/` and its privacy hash for an
 * unlisted video, which cannot be played without it.
 */
final class Vimeo extends BaseEmbedProvider
{
    public function handle(): string
    {
        return 'vimeo';
    }

    public function name(): string
    {
        return 'Vimeo';
    }

    public function isMediaId(string $mediaId): bool
    {
        return (bool)preg_match('~^[1-9][0-9]*(?:/[0-9a-f]+)?$~D', $mediaId);
    }

    public function pageUrl(string $mediaId): string
    {
        return "https://vimeo.com/$mediaId";
    }

    public function embedUrl(string $mediaId): string
    {
        [$video, $hash] = array_pad(explode('/', $mediaId, 2), 2, null);

        return "https://player.vimeo.com/video/$video" . ($hash !== null ? "?h=$hash" : '');
    }

    protected function hosts(): array
    {
        return ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'];
    }

    protected function mediaIdFromPath(string $host, string $path, array $query): ?string
    {
        $pattern = $host === 'player.vimeo.com' ? '~^/video/([0-9]+)$~' : '~^/([0-9]+)(?:/([0-9a-f]+))?$~';

        if (!preg_match($pattern, $path, $match)) {
            return null;
        }

        // An unlisted video's hash is in its path, or in `h`; without it, it cannot be played.
        $pathHash = $match[2] ?? null;
        $queryHash = isset($query['h']) ? self::single($query, 'h') : null;

        if (isset($query['h']) && ($queryHash === null || ($pathHash !== null && $pathHash !== $queryHash))) {
            return null;
        }

        $hash = $pathHash ?? $queryHash;

        return $match[1] . ($hash !== null ? "/$hash" : '');
    }
}
