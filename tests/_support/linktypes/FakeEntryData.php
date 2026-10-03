<?php

namespace Tahadudhiya\SmartLinks\Tests\_support\linktypes;

use Tahadudhiya\SmartLinks\linktypes\LinkTypeDataInterface;

/**
 * An entry link's data: which entry, in which site.
 */
final class FakeEntryData implements LinkTypeDataInterface
{
    public function __construct(
        public readonly int $elementId,
        public readonly int $siteId,
    ) {
    }

    public function toArray(): array
    {
        return ['elementId' => $this->elementId, 'siteId' => $this->siteId];
    }
}
