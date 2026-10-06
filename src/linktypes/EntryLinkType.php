<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use craft\elements\Entry;

/**
 * A link to an entry, in the content's site or in a chosen one.
 */
class EntryLinkType extends BaseElementLinkType
{
    public function handle(): string
    {
        return 'entry';
    }

    public function elementType(): string
    {
        return Entry::class;
    }

    public function gqlElementResolver(): ?string
    {
        return \craft\gql\resolvers\elements\Entry::class;
    }

    public function gqlCanQueryElements(): bool
    {
        return \craft\helpers\Gql::canQueryEntries();
    }
}
