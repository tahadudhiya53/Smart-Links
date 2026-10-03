<?php

namespace Tahadudhiya\SmartLinks\Tests\_support\linktypes;

use Tahadudhiya\SmartLinks\linktypes\LinkTypeDataInterface;
use Tahadudhiya\SmartLinks\models\CanonicalUrl;

/**
 * A URL link's data: a canonical URL and nothing else.
 */
final class FakeUrlData implements LinkTypeDataInterface
{
    public function __construct(
        public readonly CanonicalUrl $url,
    ) {
    }

    public function toArray(): array
    {
        return ['url' => $this->url->toString()];
    }
}
