<?php

namespace Tahadudhiya\SmartLinks\models;

/**
 * What a link renders as: its href, its text and its final attributes.
 *
 * Values are raw, not HTML-escaped. Whatever writes them into markup escapes them, so a value
 * can never be escaped twice or not at all depending on where it came from.
 */
final class RenderedLink
{
    /**
     * @param array<string, string|true> $attributes Every attribute besides `href`, in render
     * order. `true` is an attribute without a value, e.g. `download`.
     * @param bool $external Whether the destination is outside the site, e.g. for an icon.
     */
    public function __construct(
        public readonly string $href,
        public readonly string $text,
        public readonly array $attributes,
        public readonly bool $external,
    ) {
    }
}
