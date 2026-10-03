<?php

namespace Tahadudhiya\SmartLinks\linktypes\embed;

use Tahadudhiya\SmartLinks\models\CanonicalUrl;

/**
 * A media provider that Embed links can point at a video, a track or another piece of media on.
 *
 * Everything a provider does is offline: it recognises its media URLs by their form, and builds
 * page and embed URLs from a media ID. Nothing is ever fetched from it while authoring or
 * rendering. Providers are code, registered with
 * {@see \Tahadudhiya\SmartLinks\linktypes\EmbedLinkType::EVENT_REGISTER_PROVIDERS}.
 */
interface EmbedProviderInterface
{
    /**
     * Stored with every link to the provider, so it never changes. Lowercase words joined by
     * hyphens, e.g. `youtube`.
     */
    public function handle(): string;

    /**
     * The name authors see, e.g. `YouTube`.
     */
    public function name(): string;

    /**
     * The ID of the media a URL names, or null when it is not one of this provider's media URLs.
     * Only the media is identified: other parts of the URL, such as a start time or a share
     * tracker, are not part of it.
     */
    public function mediaIdFromUrl(CanonicalUrl $url): ?string;

    /**
     * Whether a string is a media ID of this provider, in canonical form.
     */
    public function isMediaId(string $mediaId): bool;

    /**
     * The media's own page: an absolute https URL in canonical form.
     */
    public function pageUrl(string $mediaId): string;

    /**
     * The URL of the provider's embeddable player for the media, e.g. for an `<iframe>`.
     */
    public function embedUrl(string $mediaId): string;
}
