<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use craft\elements\Category;

/**
 * A link to a category, in the content's site or in a chosen one.
 */
class CategoryLinkType extends BaseElementLinkType
{
    public function handle(): string
    {
        return 'category';
    }

    public function elementType(): string
    {
        return Category::class;
    }

    public function gqlElementResolver(): ?string
    {
        return \craft\gql\resolvers\elements\Category::class;
    }

    public function gqlCanQueryElements(): bool
    {
        return \craft\helpers\Gql::canQueryCategories();
    }
}
