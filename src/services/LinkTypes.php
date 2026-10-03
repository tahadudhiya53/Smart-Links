<?php

namespace Tahadudhiya\SmartLinks\services;

use Craft;
use craft\base\Component;
use craft\events\RegisterComponentTypesEvent;
use Tahadudhiya\SmartLinks\linktypes\AssetLinkType;
use Tahadudhiya\SmartLinks\linktypes\CategoryLinkType;
use Tahadudhiya\SmartLinks\linktypes\EmailLinkType;
use Tahadudhiya\SmartLinks\linktypes\EmbedLinkType;
use Tahadudhiya\SmartLinks\linktypes\EntryLinkType;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeInterface;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeSet;
use Tahadudhiya\SmartLinks\linktypes\PhoneLinkType;
use Tahadudhiya\SmartLinks\linktypes\ProductLinkType;
use Tahadudhiya\SmartLinks\linktypes\SmsLinkType;
use Tahadudhiya\SmartLinks\linktypes\SocialLinkType;
use Tahadudhiya\SmartLinks\linktypes\UrlLinkType;
use Tahadudhiya\SmartLinks\linktypes\UserLinkType;
use yii\base\InvalidConfigException;

/**
 * The link types Smart Links knows about, registered by plugins and modules.
 *
 * Link types are code, so they are registered through an event, as Craft's own link types are
 * (`craft\fields\Link::EVENT_REGISTER_LINK_TYPES`), rather than stored anywhere. Every registered
 * type is available to read and validate stored links with. Which of them a field lets authors
 * choose is that field's setting.
 */
class LinkTypes extends Component
{
    /**
     * @event RegisterComponentTypesEvent Collects the class names of the available link types.
     * Each must implement {@see LinkTypeInterface} and be constructible without arguments.
     * Element types can extend {@see \Tahadudhiya\SmartLinks\linktypes\BaseElementLinkType}.
     *
     * ```php
     * Event::on(LinkTypes::class, LinkTypes::EVENT_REGISTER_LINK_TYPES, function(RegisterComponentTypesEvent $event) {
     *     $event->types[] = MyLinkType::class;
     * });
     * ```
     */
    public const EVENT_REGISTER_LINK_TYPES = 'registerLinkTypes';

    private ?LinkTypeSet $types = null;

    /**
     * The built-in link types, in the order fields offer them: those whose dependencies are
     * present (the Commerce product type needs Commerce).
     *
     * @return list<class-string<LinkTypeInterface>>
     */
    public static function builtInTypes(): array
    {
        return array_values(array_filter([
            UrlLinkType::class,
            EntryLinkType::class,
            CategoryLinkType::class,
            AssetLinkType::class,
            UserLinkType::class,
            ProductLinkType::isAvailable() ? ProductLinkType::class : null,
            EmailLinkType::class,
            PhoneLinkType::class,
            SmsLinkType::class,
            SocialLinkType::class,
            EmbedLinkType::class,
        ]));
    }

    /**
     * Every registered link type, collected once per request.
     *
     * @throws InvalidConfigException if plugins have not all loaded yet (their types would be
     * missing), a registered class is not a link type, or two types share a handle.
     */
    public function getTypeSet(): LinkTypeSet
    {
        if ($this->types !== null) {
            return $this->types;
        }

        // Plugins register their types as they load; asked any earlier, links of their types
        // would read as unknown for the rest of the request.
        if (!Craft::$app->getPlugins()->arePluginsLoaded()) {
            throw new InvalidConfigException('Link types are available once every plugin has loaded.');
        }

        $event = new RegisterComponentTypesEvent(['types' => []]);
        $this->trigger(self::EVENT_REGISTER_LINK_TYPES, $event);

        $types = [];

        foreach ($event->types as $class) {
            if (!is_subclass_of($class, LinkTypeInterface::class)) {
                throw new InvalidConfigException(sprintf('A registered link type must be the name of a class implementing %s.', LinkTypeInterface::class));
            }

            $types[] = new $class();
        }

        try {
            return $this->types = new LinkTypeSet($types);
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidConfigException($exception->getMessage(), 0, $exception);
        }
    }

    public function getType(string $handle): ?LinkTypeInterface
    {
        return $this->getTypeSet()->get($handle);
    }
}
