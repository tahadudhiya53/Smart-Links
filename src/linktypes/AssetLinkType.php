<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use Craft;
use craft\elements\Asset;
use craft\models\Volume;
use Tahadudhiya\SmartLinks\enums\LinkFeature;

/**
 * A link to an asset's file, which may be downloaded rather than opened.
 *
 * An asset in a volume whose filesystem has no public URLs leads nowhere (no URL), so authors
 * choose from volumes with public URLs, and only those they may view, as Craft's own asset links.
 */
class AssetLinkType extends BaseElementLinkType
{
    public function handle(): string
    {
        return 'asset';
    }

    public function elementType(): string
    {
        return Asset::class;
    }

    public function supportedFeatures(): array
    {
        return LinkFeature::cases();
    }

    protected function sources(): string|array
    {
        $user = Craft::$app->getUser();

        return array_values(array_map(
            static fn(Volume $volume): string => "volume:$volume->uid",
            array_filter(
                Craft::$app->getVolumes()->getAllVolumes(),
                static fn(Volume $volume): bool => $volume->getFs()->hasUrls && $user->checkPermission("viewAssets:$volume->uid"),
            ),
        ));
    }

    protected function selectionCriteria(): array
    {
        // Asset URLs come from their volume, not a URI, so the default criterion does not apply.
        return [];
    }

    public function gqlElementResolver(): ?string
    {
        return \craft\gql\resolvers\elements\Asset::class;
    }

    public function gqlCanQueryElements(): bool
    {
        return \craft\helpers\Gql::canQueryAssets();
    }
}
