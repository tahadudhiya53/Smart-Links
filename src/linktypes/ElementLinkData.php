<?php

namespace Tahadudhiya\SmartLinks\linktypes;

/**
 * An element link's data: which element, and, for a localized element type, optionally which
 * site's version of it.
 *
 * Only the IDs are kept. The element's title, URL and status are always read from the element.
 */
final class ElementLinkData implements LinkTypeDataInterface
{
    /**
     * @param int $elementId The canonical element's ID, never a draft's or revision's.
     * @param int|null $siteId The site whose version of the element is linked to. Null links to
     * the version in the site of the content the link is in, so propagated content links to each
     * site's own version.
     */
    public function __construct(
        public readonly int $elementId,
        public readonly ?int $siteId = null,
    ) {
    }

    public function toArray(): array
    {
        return $this->siteId === null ? ['elementId' => $this->elementId] : ['elementId' => $this->elementId, 'siteId' => $this->siteId];
    }
}
