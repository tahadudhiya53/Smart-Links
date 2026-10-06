<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use craft\base\ElementInterface;

/**
 * A link type whose links point at a Craft element, by its ID.
 *
 * The element itself is the source of truth: a link holds only its ID (and, for a localized
 * element, optionally the site whose version it links to), never a copy of its title or URL.
 * Fields write these elements into Craft's `relations` table, so `relatedTo` and Craft's own
 * reverse lookup find them. Element IDs differ between environments, so links of these types
 * cannot be a field's default links, which deploy with project config.
 */
interface ElementLinkTypeInterface extends LinkTypeInterface
{
    /**
     * The element type links of this type point at.
     *
     * @return class-string<ElementInterface>
     */
    public function elementType(): string;

    /**
     * The ID of the element a link with this data points at.
     */
    public function elementId(LinkTypeDataInterface $data): int;

    /**
     * The site whose version of the element a link with this data leads to, when it appears in
     * content of `$siteId`; null for an element type that is not localized.
     */
    public function elementSiteId(LinkTypeDataInterface $data, int $siteId): ?int;

    /**
     * The Craft GraphQL resolver for this element type (a subclass of
     * `craft\gql\base\ElementResolver`), through which GraphQL reads the linked element, so the
     * schema's read permissions apply to it as to any query. Null means GraphQL never shows the
     * linked element itself, only the link.
     *
     * @return string|null A class name.
     */
    public function gqlElementResolver(): ?string;

    /**
     * Whether the active GraphQL schema may query elements of this type at all (Craft's own
     * `craft\helpers\Gql::canQuery…()` check), before its resolver narrows that to what it may
     * read. Craft only runs a resolver once this holds.
     */
    public function gqlCanQueryElements(): bool;
}
