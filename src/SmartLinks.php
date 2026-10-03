<?php

namespace Tahadudhiya\SmartLinks;

use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;
use Tahadudhiya\SmartLinks\services\Index;
use Tahadudhiya\SmartLinks\services\Links;
use Tahadudhiya\SmartLinks\services\LinkTypes;
use Tahadudhiya\SmartLinks\services\Presets;
use yii\base\Event;

/**
 * Smart Links — link management and link intelligence for Craft CMS.
 *
 * The plugin class is bootstrap and registration only. Domain behaviour belongs in services.
 *
 * Craft routes the control panel section to `templates/index.twig` and gates it, together with
 * its nav item, behind the “Access Smart Links” permission it registers for every plugin with a
 * section. The one controller, the field editor's copy and paste, needs only Craft's control
 * panel access, so no permission of the plugin's own exists yet.
 *
 * @property-read Index $index
 * @property-read Links $links
 * @property-read LinkTypes $linkTypes
 * @property-read Presets $presets
 */
class SmartLinks extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'index' => ['class' => Index::class],
                'links' => ['class' => Links::class],
                'linkTypes' => ['class' => LinkTypes::class],
                'presets' => ['class' => Presets::class],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, static function(RegisterComponentTypesEvent $event): void {
            $event->types[] = SmartLinkField::class;
        });

        // The built-in link types are registered as any other plugin's are.
        Event::on(LinkTypes::class, LinkTypes::EVENT_REGISTER_LINK_TYPES, static function(RegisterComponentTypesEvent $event): void {
            array_push($event->types, ...LinkTypes::builtInTypes());
        });
    }

    public function getIndex(): Index
    {
        /** @var Index */
        return $this->get('index');
    }

    public function getLinks(): Links
    {
        /** @var Links */
        return $this->get('links');
    }

    public function getLinkTypes(): LinkTypes
    {
        /** @var LinkTypes */
        return $this->get('linkTypes');
    }

    public function getPresets(): Presets
    {
        /** @var Presets */
        return $this->get('presets');
    }
}
