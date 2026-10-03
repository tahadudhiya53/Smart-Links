<?php

namespace Tahadudhiya\SmartLinks\gql;

use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\gql\base\ElementResolver;
use LogicException;
use Tahadudhiya\SmartLinks\linktypes\ElementLinkTypeInterface;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeInterface;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\RenderedLink;
use Tahadudhiya\SmartLinks\models\ResolvedLink;
use Tahadudhiya\SmartLinks\SmartLinks;

/**
 * One link as GraphQL reads it: the link, and the site of the content it is in.
 *
 * It is resolved and rendered at most once, however many of its fields a query asks for.
 * Resolving or rendering a link can fail (see {@see \Tahadudhiya\SmartLinks\services\Links}),
 * and a failure surfaces as that field's GraphQL error, never as a missing link.
 */
final class GqlLink
{
    private ?ResolvedLink $resolved = null;
    private ?RenderedLink $rendered = null;
    private bool $isRendered = false;

    public function __construct(
        public readonly LinkValue $link,
        public readonly int $siteId,
    ) {
    }

    /**
     * @throws LogicException if the link's type is not registered: a value with such a link is
     * never a link collection, so this cannot happen for a value Smart Links read.
     */
    public function type(): LinkTypeInterface
    {
        return SmartLinks::getInstance()->getLinkTypes()->getType($this->link->type)
            ?? throw new LogicException("The link type “{$this->link->type}” is not registered.");
    }

    public function resolved(): ResolvedLink
    {
        return $this->resolved ??= SmartLinks::getInstance()->getLinks()->resolve($this->link, $this->siteId);
    }

    public function rendered(): ?RenderedLink
    {
        if (!$this->isRendered) {
            $this->rendered = SmartLinks::getInstance()->getLinks()->renderFor($this->link, $this->resolved());
            $this->isRendered = true;
        }

        return $this->rendered;
    }

    /**
     * The element the link points at, read through its element type's GraphQL resolver, so that
     * it is only ever returned when the active schema may read it, and only when it is live in
     * the site the link leads to. Null otherwise, and for links that are not to elements.
     *
     * @throws LogicException if a link type names something that is not an element resolver.
     */
    public function element(): ?ElementInterface
    {
        $type = $this->type();

        if (!$type instanceof ElementLinkTypeInterface || ($resolver = $type->gqlElementResolver()) === null) {
            return null;
        }

        // Craft runs an element resolver only for a schema that may query that element type.
        if (!$type->gqlCanQueryElements()) {
            return null;
        }

        if (!is_subclass_of($resolver, ElementResolver::class)) {
            throw new LogicException(sprintf('The %s link type’s GraphQL resolver must extend %s.', $type->handle(), ElementResolver::class));
        }

        $arguments = [
            'id' => $type->elementId($this->link->data),
            'siteId' => $type->elementSiteId($this->link->data, $this->siteId) ?? $this->siteId,
        ];

        // A schema-scoped root query: Craft's public `prepareRootQuery()` where it has one (not in 5.9),
        // else the element resolvers' own `prepareQuery()`, which they declare public.
        /** @phpstan-ignore if.alwaysTrue (Analysed against a Craft that has it; Craft 5.9, which the plugin supports, does not.) */
        if (is_callable([$resolver, 'prepareRootQuery'])) {
            $query = call_user_func([$resolver, 'prepareRootQuery'], $arguments);
        } elseif (is_callable([$resolver, 'prepareQuery'])) {
            $query = call_user_func([$resolver, 'prepareQuery'], null, $arguments);
        } else {
            throw new LogicException(sprintf('The %s link type’s GraphQL resolver gives no schema-scoped query.', $type->handle()));
        }

        // Anything but a query is the resolver saying the schema can read none of these elements.
        if (!$query instanceof ElementQueryInterface) {
            return null;
        }

        $element = $query->one();

        return $element instanceof ElementInterface ? $element : null;
    }
}
