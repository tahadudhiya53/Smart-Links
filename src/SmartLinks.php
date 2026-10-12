<?php

namespace Tahadudhiya\SmartLinks;

use Craft;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\events\ApplyFieldSaveEvent;
use craft\events\ElementEvent;
use craft\events\FieldEvent;
use craft\events\FieldLayoutEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\SiteEvent;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\Sites;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use Tahadudhiya\SmartLinks\console\controllers\SmartLinksController;
use Tahadudhiya\SmartLinks\errors\IndexLockedException;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;
use Tahadudhiya\SmartLinks\services\Index;
use Tahadudhiya\SmartLinks\services\Links;
use Tahadudhiya\SmartLinks\services\LinkTypes;
use Tahadudhiya\SmartLinks\services\Presets;
use Tahadudhiya\SmartLinks\services\Usage;
use yii\base\Event;

/**
 * Smart Links — link management and link intelligence for Craft CMS.
 *
 * The plugin class is bootstrap and registration only. Domain behaviour belongs in services.
 *
 * Craft gates the control panel section, together with its nav item, behind the “Access Smart
 * Links” permission it registers for every plugin with a section. Within it, the link inventory,
 * where each link is used, and managing link presets each need the plugin's own permission, which
 * their controllers require for every action, since Craft's section gate does not cover action requests. The field
 * editor's copy and paste needs only control panel access and the right to save the element being
 * edited.
 *
 * Craft's element, field, field layout and site events are the only signals the link index
 * listens to, so it follows every content and structure change however it was made.
 *
 * @property-read Index $index
 * @property-read Links $links
 * @property-read LinkTypes $linkTypes
 * @property-read Presets $presets
 * @property-read Usage $usage
 */
class SmartLinks extends Plugin
{
    /** Where Smart Links keeps its configuration in project config. */
    public const PROJECT_CONFIG_KEY = 'smartLinks';

    /** See every link target Smart Link fields use, and where. */
    public const PERMISSION_VIEW_INVENTORY = 'smartLinks:viewInventory';

    /** See every place a link target is used. Nested under viewing the inventory. */
    public const PERMISSION_VIEW_USAGE = 'smartLinks:viewUsage';

    /** Rebuild the link index. Nested under viewing the inventory. */
    public const PERMISSION_REBUILD_INDEX = 'smartLinks:rebuildIndex';

    /** Create, edit, reorder, enable, disable and delete link presets. */
    public const PERMISSION_MANAGE_PRESETS = 'smartLinks:managePresets';

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
                'usage' => ['class' => Usage::class],
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

        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, static function(RegisterUserPermissionsEvent $event): void {
            $event->permissions[] = [
                'heading' => Craft::t('smart-links', 'Smart Links'),
                'permissions' => [
                    self::PERMISSION_VIEW_INVENTORY => [
                        'label' => Craft::t('smart-links', 'View the link inventory'),
                        'info' => Craft::t('smart-links', 'Lists every link target in every site, and the content using it.'),
                        'nested' => [
                            self::PERMISSION_VIEW_USAGE => ['label' => Craft::t('smart-links', 'View where links are used')],
                            self::PERMISSION_REBUILD_INDEX => ['label' => Craft::t('smart-links', 'Rebuild the link index')],
                        ],
                    ],
                    self::PERMISSION_MANAGE_PRESETS => ['label' => Craft::t('smart-links', 'Manage link presets')],
                ],
            ];
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, static function(RegisterUrlRulesEvent $event): void {
            $event->rules['smart-links/links'] = 'smart-links/links/index';
            $event->rules['smart-links/links/<indexId:\d+>'] = 'smart-links/links/usage';
            $event->rules['smart-links/presets'] = 'smart-links/presets/index';
            $event->rules['smart-links/presets/new'] = 'smart-links/presets/edit';
            $event->rules['smart-links/presets/<presetUid:{uid}>'] = 'smart-links/presets/edit';
        });

        Event::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, function(ElementEvent $event): void {
            $this->getIndex()->elementSaving($event->element);
        });

        foreach ([Elements::EVENT_AFTER_SAVE_ELEMENT, Elements::EVENT_AFTER_DELETE_ELEMENT, Elements::EVENT_AFTER_RESTORE_ELEMENT, Elements::EVENT_AFTER_DELETE_FOR_SITE, Elements::EVENT_AFTER_UPDATE_SLUG_AND_URI] as $name) {
            Event::on(Elements::class, $name, function(ElementEvent $event): void {
                $this->getIndex()->elementChanged($event->element);
            });
        }

        // Where Smart Link fields are changes what content holds links, without saving it.
        Event::on(Fields::class, Fields::EVENT_BEFORE_SAVE_FIELD_LAYOUT, function(FieldLayoutEvent $event): void {
            $this->getIndex()->layoutSaving($event->layout);
        });
        Event::on(Fields::class, Fields::EVENT_AFTER_SAVE_FIELD_LAYOUT, function(FieldLayoutEvent $event): void {
            $this->getIndex()->layoutSaved($event->layout);
        });
        Event::on(Fields::class, Fields::EVENT_BEFORE_APPLY_FIELD_SAVE, function(ApplyFieldSaveEvent $event): void {
            $this->getIndex()->fieldApplying($event->field);
        });
        Event::on(Fields::class, Fields::EVENT_AFTER_SAVE_FIELD, function(FieldEvent $event): void {
            $this->getIndex()->fieldSaved($event->field, $event->isNew);
        });
        Event::on(Fields::class, Fields::EVENT_AFTER_DELETE_FIELD, function(FieldEvent $event): void {
            if ($event->field instanceof SmartLinkField) {
                $this->getIndex()->structureDeleted();
            }
        });
        Event::on(Sites::class, Sites::EVENT_AFTER_DELETE_SITE, function(): void {
            $this->getIndex()->structureDeleted();
        });
        Event::on(Sites::class, Sites::EVENT_AFTER_SAVE_SITE, function(SiteEvent $event): void {
            $this->getIndex()->siteSaved($event->isNew);
        });

        // Garbage collection deletes trashed elements and sites without element events. If the
        // index is being written meanwhile, the next collection removes what is left.
        Event::on(Gc::class, Gc::EVENT_RUN, function(): void {
            try {
                $this->getIndex()->pruneUnusedTargets();
            } catch (IndexLockedException $exception) {
                Craft::warning('Unused link targets were not removed during garbage collection: ' . $exception->getMessage(), __METHOD__);
            }
        });

        if (Craft::$app instanceof ConsoleApplication) {
            Craft::$app->controllerMap['smartlinks'] = SmartLinksController::class;
        }
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if ($item === null) {
            return null;
        }

        $user = Craft::$app->getUser();
        $subnav = ['overview' => ['label' => Craft::t('smart-links', 'Overview'), 'url' => 'smart-links']];

        if ($user->checkPermission(self::PERMISSION_VIEW_INVENTORY)) {
            $subnav['links'] = ['label' => Craft::t('smart-links', 'Links'), 'url' => 'smart-links/links'];
        }

        if ($user->checkPermission(self::PERMISSION_MANAGE_PRESETS)) {
            $subnav['presets'] = ['label' => Craft::t('smart-links', 'Presets'), 'url' => 'smart-links/presets'];
        }

        if (count($subnav) > 1) {
            $item['subnav'] = $subnav;
        }

        return $item;
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

    public function getUsage(): Usage
    {
        /** @var Usage */
        return $this->get('usage');
    }
}
