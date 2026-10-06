<?php

namespace Tahadudhiya\SmartLinks\linktypes;

/**
 * A link type's own, typed description of what a link points at.
 *
 * Each link type has its own implementation, holding only what that type needs: a URL type a
 * canonical URL, an element type an element and site ID. Implementations are immutable.
 */
interface LinkTypeDataInterface
{
    /**
     * The stored form: a flat map of JSON-safe scalars in a fixed order, with nothing unset
     * included, so equal data always serializes identically. Empty when the type needs no data.
     * Only the link serializer calls it, as the data part of the one stored format.
     *
     * @return array<string, string|int|bool>
     */
    public function toArray(): array;
}
