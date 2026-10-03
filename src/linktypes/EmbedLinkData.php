<?php

namespace Tahadudhiya\SmartLinks\linktypes;

/**
 * An embed link's data: which provider, and which of its media.
 */
final class EmbedLinkData implements LinkTypeDataInterface
{
    /**
     * @param string $provider The provider's handle.
     * @param string $mediaId The provider's ID of the media, in canonical form.
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $mediaId,
    ) {
    }

    public function toArray(): array
    {
        return ['provider' => $this->provider, 'mediaId' => $this->mediaId];
    }
}
