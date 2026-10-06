<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use Tahadudhiya\SmartLinks\models\CanonicalUrl;

/**
 * A URL link's data: an http(s) URL, a root-relative path, or an anchor in the same page, in
 * canonical form.
 */
final class UrlLinkData implements LinkTypeDataInterface
{
    /**
     * @param string $url The canonical form.
     * @param CanonicalUrl|null $canonical The parsed URL; null for an anchor (`#section`).
     */
    public function __construct(
        public readonly string $url,
        public readonly ?CanonicalUrl $canonical,
    ) {
    }

    public function isAnchor(): bool
    {
        return $this->canonical === null;
    }

    public function toArray(): array
    {
        return ['url' => $this->url];
    }
}
