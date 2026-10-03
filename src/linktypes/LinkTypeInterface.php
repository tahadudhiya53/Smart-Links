<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use Tahadudhiya\SmartLinks\enums\LinkFeature;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\models\TargetIdentity;
use Tahadudhiya\SmartLinks\models\ValidationError;

/**
 * A kind of link: what its data is, how it is read from an author and from storage, which
 * optional link features apply to it, and what resolves it.
 *
 * A link type owns its data and its resolver, and nothing else. Every type names its resolver, so
 * nothing ever has to decide how to resolve a link by looking at its type handle. The link's common parts (UID, label, attributes)
 * are validated by the link validator, which asks the type only about its data and features.
 * Error paths a type reports are relative to the link's data, e.g. `url` for `data.url`.
 *
 * Types are registered with {@see \Tahadudhiya\SmartLinks\services\LinkTypes::EVENT_REGISTER_LINK_TYPES}
 * and constructed without arguments. A type whose data names a Craft element implements
 * {@see ElementLinkTypeInterface} too.
 */
interface LinkTypeInterface
{
    /**
     * The handle stored with every link of this type. It never changes, because stored links
     * are read back by it.
     */
    public function handle(): string;

    /**
     * The name authors see, e.g. in the field's link type selector.
     */
    public function displayName(): string;

    /**
     * The optional features links of this type may use.
     *
     * @return list<LinkFeature>
     */
    public function supportedFeatures(): array;

    /**
     * Turns what an author entered into this type's data. Only provably equivalent spellings are
     * normalized; anything invalid is reported, never repaired. The type's own stored form
     * ({@see LinkTypeDataInterface::toArray()}) is one such spelling, so a stored link can be
     * edited again as authoring input.
     *
     * @param array<mixed> $input
     * @throws LinkValidationException with every problem found.
     */
    public function normalizeData(array $input): LinkTypeDataInterface;

    /**
     * Reads this type's data back from its stored form, exactly as {@see LinkTypeDataInterface::toArray()}
     * wrote it, apart from the order of its keys: databases do not keep the order of a JSON
     * object's keys, so it is not part of the stored form.
     *
     * @param array<mixed> $stored
     * @throws LinkValidationException if it is not this type's data in canonical form.
     */
    public function dataFromArray(array $stored): LinkTypeDataInterface;

    /**
     * Checks data built in code: that it belongs to this type and is valid.
     *
     * @return list<ValidationError>
     */
    public function validateData(LinkTypeDataInterface $data): array;

    /**
     * The control panel inputs an author edits this type's data with.
     *
     * Inputs are named relative to the data (e.g. `url`), and post exactly what
     * {@see normalizeData()} reads. The field namespaces them, so they must not be namespaced
     * here. Everything shown must be HTML-encoded: values can be anything an author typed.
     *
     * @param LinkTypeDataInterface|array<mixed>|null $value The link's data; what the author
     * entered, when it was refused and is shown again; or null for a new link.
     * @param int|null $siteId The site of the content being edited, which e.g. decides which
     * site's version of an element is shown; null where there is no content (a field's default
     * links).
     */
    public function inputHtml(LinkTypeDataInterface|array|null $value, ?int $siteId): string;

    /**
     * What a link with this data points at, as opposed to where that leads now (see
     * {@see TargetIdentity}). Two links have the same target exactly when this gives the same
     * key, so it must be deterministic and built only from normalized values.
     *
     * @param int $sourceElementId The element the link appears in, for targets that depend on it
     * (an anchor points into the page it is on).
     * @param int $sourceSiteId The site of the content the link appears in, for targets that mean
     * something different in each site (a root-relative URL; an element linked in "the same
     * site").
     */
    public function targetIdentity(LinkTypeDataInterface $data, int $sourceElementId, int $sourceSiteId): TargetIdentity;

    /**
     * What works out where links of this type lead. It is only ever given links of this type,
     * with this type's data.
     */
    public function resolver(): LinkResolverInterface;
}
