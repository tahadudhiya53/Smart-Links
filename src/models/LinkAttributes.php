<?php

namespace Tahadudhiya\SmartLinks\models;

use Tahadudhiya\SmartLinks\enums\LinkFeature;

/**
 * The HTML attributes a link is authored with, beyond where it goes.
 *
 * Presentation only: nothing here identifies the target. The rules for each attribute live in
 * {@see \Tahadudhiya\SmartLinks\links\LinkValidator}.
 */
final class LinkAttributes
{
    /**
     * @param string|null $target A keyword such as `_blank`, or a browsing context name.
     * @param list<string> $rel Lowercase, distinct link types, e.g. `noopener`.
     * @param list<string> $class Distinct class names.
     * @param string|null $downloadFilename The suggested filename, when `download` is set.
     * @param array<string, string> $custom `data-*` and `aria-*` attributes, in authored order.
     */
    public function __construct(
        public readonly ?string $target = null,
        public readonly array $rel = [],
        public readonly ?string $title = null,
        public readonly array $class = [],
        public readonly ?string $id = null,
        public readonly ?string $ariaLabel = null,
        public readonly bool $download = false,
        public readonly ?string $downloadFilename = null,
        public readonly array $custom = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->usedFeatures() === [];
    }

    /**
     * The link features these attributes use, in a fixed order.
     *
     * @return list<LinkFeature>
     */
    public function usedFeatures(): array
    {
        $used = [
            [LinkFeature::TARGET, $this->target !== null],
            [LinkFeature::REL, $this->rel !== []],
            [LinkFeature::TITLE, $this->title !== null],
            [LinkFeature::CLASS_NAMES, $this->class !== []],
            [LinkFeature::ID, $this->id !== null],
            [LinkFeature::ARIA_LABEL, $this->ariaLabel !== null],
            [LinkFeature::DOWNLOAD, $this->download || $this->downloadFilename !== null],
            [LinkFeature::CUSTOM_ATTRIBUTES, $this->custom !== []],
        ];

        return array_values(array_map(
            static fn(array $entry): LinkFeature => $entry[0],
            array_filter($used, static fn(array $entry): bool => $entry[1]),
        ));
    }
}
