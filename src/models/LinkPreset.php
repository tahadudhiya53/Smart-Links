<?php

namespace Tahadudhiya\SmartLinks\models;

/**
 * A link preset: reusable authoring configuration, defined in project config.
 *
 * A link refers to the preset it was authored from by UID only. What a preset sets up for an
 * author is not part of a link's value.
 */
final class LinkPreset
{
    public function __construct(
        public readonly string $uid,
        public readonly string $name,
    ) {
    }
}
