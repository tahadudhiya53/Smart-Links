<?php

namespace Tahadudhiya\SmartLinks\linktypes;

/**
 * A phone link's data: the number, as `+` (when it has a country code) and digits.
 */
final class PhoneLinkData implements LinkTypeDataInterface
{
    public function __construct(
        public readonly string $number,
    ) {
    }

    public function toArray(): array
    {
        return ['number' => $this->number];
    }
}
