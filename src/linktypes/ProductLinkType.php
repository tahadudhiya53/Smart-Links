<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use Craft;
use craft\base\ElementInterface;
use LogicException;

/**
 * A link to a Craft Commerce product, in the content's site or in a chosen one.
 *
 * Commerce is optional. This type is registered only when the Commerce plugin is installed and
 * enabled, and it names Commerce's product class only as a string, so nothing of Commerce is
 * loaded, or needed, when Commerce is absent. Links of this type that are stored while Commerce
 * is absent are kept unchanged and become editable again once it is back.
 */
class ProductLinkType extends BaseElementLinkType
{
    /** Commerce's product element class. A string, so Commerce never has to be present. */
    public const PRODUCT_CLASS = 'craft\\commerce\\elements\\Product';

    /** Commerce's plugin handle. */
    public const COMMERCE = 'commerce';

    /**
     * Whether Commerce is installed and enabled, so products can be linked to.
     */
    public static function isAvailable(): bool
    {
        return Craft::$app->getPlugins()->getPlugin(self::COMMERCE) !== null && class_exists(self::PRODUCT_CLASS);
    }

    public function handle(): string
    {
        return 'commerce-product';
    }

    /**
     * @throws LogicException if Commerce is not there: the type is only ever registered when it is.
     */
    public function elementType(): string
    {
        $class = self::PRODUCT_CLASS;

        if (!is_subclass_of($class, ElementInterface::class)) {
            throw new LogicException('Products can only be linked to while Craft Commerce is installed.');
        }

        return $class;
    }

    /**
     * Commerce's product resolver, which applies the schema's product type permissions. Named as
     * a string, like the product class.
     */
    public function gqlElementResolver(): ?string
    {
        return 'craft\\commerce\\gql\\resolvers\\elements\\Product';
    }

    public function gqlCanQueryElements(): bool
    {
        /** @var callable(): bool $canQuery Commerce's own check, named as a string like its classes. */
        $canQuery = ['craft\\commerce\\helpers\\Gql', 'canQueryProducts'];

        return $canQuery();
    }
}
