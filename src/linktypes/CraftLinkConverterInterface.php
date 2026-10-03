<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use Tahadudhiya\SmartLinks\errors\LinkValidationException;

/**
 * A link type that can read the values of Craft's own Link field.
 *
 * Craft's Link field stores a link type ID (e.g. `entry`) and one string (e.g.
 * `{entry:12@1:url}`, `mailto:hello@example.com`). Converting that string gives authoring input
 * for this type's data, which is then normalized and validated like any other input, so a
 * conversion can never produce data the type would refuse. Nothing is guessed: what the type
 * cannot represent is reported, so content is never silently changed.
 */
interface CraftLinkConverterInterface extends LinkTypeInterface
{
    /**
     * The IDs of the Craft link types this type converts (`craft\fields\linktypes\BaseLinkType::id()`).
     *
     * @return list<string>
     */
    public function craftLinkTypes(): array;

    /**
     * This type's authoring input for one Craft Link field value.
     *
     * @return array<string, mixed>
     * @throws LinkValidationException if the value cannot be represented by this type.
     */
    public function dataFromCraftLink(string $value): array;
}
