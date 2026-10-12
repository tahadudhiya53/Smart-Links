<?php

namespace Tahadudhiya\SmartLinks\models;

use craft\base\ElementInterface;
use Tahadudhiya\SmartLinks\enums\StaleUsage;

/**
 * One occurrence of a link in Smart Links field content: which element, site and field holds it,
 * where that element sits, its position in the field and the target it points at.
 *
 * What the index recorded (the occurrence, its target, the field's ID) is read from the index; what
 * describes the source now (its name, type, the field placed there, its owners) is read from Craft,
 * so renaming a field or moving a nested entry shows at once, without indexing again. Nothing is
 * filled in from elsewhere when it can't be read now: it is null, and {@see $stale} says why.
 */
final class LinkUsage
{
    /**
     * @param class-string<ElementInterface>|string $elementType The element's class, as Craft stores it.
     * @param string|null $elementTypeName Null when that class is not available.
     * @param string|null $siteName Null when the site has been deleted.
     * @param string|null $fieldHandle The handle of the Smart Links field in that place of the element's
     *        layout now; null when the element can't be loaded or no Smart Links field is there.
     * @param string|null $fieldName The name of that field as placed there now; null likewise.
     * @param string|null $recordedFieldName The name of the field the index recorded (by ID), whatever it is
     *        and wherever it is now; null when that field has been deleted. Not a description of the place.
     * @param int $position The link's place in the field's value, from 1.
     * @param ElementInterface|null $element The element holding the link, in its site; null when it can't be loaded.
     * @param list<array{label: string, url: string|null, via: string|null}> $path The element and what
     *        it is nested in, outermost first: each one's name, its edit page if the current user may
     *        view it, and the field of its owner it is in. An owner that can't be loaded is a step of
     *        its own, saying so. Empty when the element can't be loaded.
     * @param StaleUsage|null $stale Why the occurrence may differ from content now; null when it is current.
     */
    public function __construct(
        public readonly int $id,
        public readonly int $indexId,
        public readonly string $targetKey,
        public readonly string $linkType,
        public readonly int $elementId,
        public readonly string $elementType,
        public readonly ?string $elementTypeName,
        public readonly int $siteId,
        public readonly ?string $siteName,
        public readonly int $fieldId,
        public readonly ?string $fieldHandle,
        public readonly ?string $fieldName,
        public readonly ?string $recordedFieldName,
        public readonly ?string $recordedFieldHandle,
        public readonly string $layoutElementUid,
        public readonly string $linkUid,
        public readonly int $position,
        public readonly ?string $label,
        public readonly ?ElementInterface $element,
        public readonly array $path,
        public readonly ?StaleUsage $stale,
    ) {
    }

    /**
     * The occurrence's identity: the element, the site, the place in its field layout and the
     * link's UID. It stays the same while the link is edited, moved within its field or pointed at
     * another target, and through any rebuild; a copy of the link in another site or element (a
     * propagated value, a duplicate) is another occurrence.
     */
    public function key(): string
    {
        return "$this->elementId:$this->siteId:$this->layoutElementUid:$this->linkUid";
    }

    /**
     * The element's edit page, if it could be loaded and the current user may view it.
     */
    public function editUrl(): ?string
    {
        return $this->path !== [] ? $this->path[array_key_last($this->path)]['url'] : null;
    }
}
