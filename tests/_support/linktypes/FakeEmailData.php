<?php

namespace Tahadudhiya\SmartLinks\Tests\_support\linktypes;

use Tahadudhiya\SmartLinks\linktypes\LinkTypeDataInterface;

/**
 * An email link's data: an address.
 */
final class FakeEmailData implements LinkTypeDataInterface
{
    public function __construct(
        public readonly string $address,
    ) {
    }

    public function toArray(): array
    {
        return ['address' => $this->address];
    }
}
