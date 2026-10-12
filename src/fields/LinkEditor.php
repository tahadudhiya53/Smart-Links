<?php

namespace Tahadudhiya\SmartLinks\fields;

use Craft;
use craft\base\ElementInterface;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Json;
use craft\web\View;
use Tahadudhiya\SmartLinks\enums\LinkFeature;
use Tahadudhiya\SmartLinks\models\InvalidLinkValue;
use Tahadudhiya\SmartLinks\models\LinkCollection;
use Tahadudhiya\SmartLinks\models\LinkPreset;
use Tahadudhiya\SmartLinks\models\ValidationError;
use Tahadudhiya\SmartLinks\SmartLinks;
use Tahadudhiya\SmartLinks\web\assets\field\SmartLinkFieldAsset;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

/**
 * The link editor for one Smart Links field value: what it offers, and its markup.
 *
 * The field renders it, and the field controller renders links into it when they are copied,
 * pasted or duplicated. The page carries a signed context that says which editor it is: which
 * field, in which field layout element, of which element in which site (or which field's
 * settings). It holds identity and the names the editor's inputs are rendered under, never the
 * rules: those are always read from the field itself, so a context cannot offer what the field
 * does not, nor outlive a change to the field. A request is served only for the editor its
 * context names, and only to a user Craft lets save that element.
 */
final class LinkEditor
{
    /** @var array<string, LinkPreset>|null */
    private ?array $allPresets = null;

    /** The key new links are rendered under until the page gives them their own. */
    public const PLACEHOLDER = '__SMARTLINK__';

    /** The keys of a signed context, in order. */
    private const CONTEXT_KEYS = ['scope', 'field', 'layoutElement', 'element', 'site', 'name', 'id', 'namespace', 'editor'];

    /** An editor for a field's value in an element that exists. */
    private const SCOPE_ELEMENT = 'element';

    /** An editor for a field's value in an element that does not exist yet. */
    private const SCOPE_NEW_ELEMENT = 'newElement';

    /** The editor for a field's default links, in its settings. */
    private const SCOPE_SETTINGS = 'settings';

    /**
     * @param string $name The input name the value posts under, before namespacing.
     * @param string $id The editor's ID, before namespacing.
     * @param list<string> $types Handles of the link types authors may add, in order.
     * @param list<string> $presets UIDs of the presets authors may choose, in order: those the
     * field allows that exist, are enabled, and are for a link type the editor offers.
     * @param list<string> $allowedPresets UIDs of the presets the field allows, whatever their state:
     * what a link is checked against (a disabled one only where a link already had it).
     * @param int|null $max The most links the value holds, or null for no limit.
     * @param string|null $namespace The input namespace the editor is rendered in.
     * @param array<string, mixed>|null $binding Which editor this is; null when it cannot be
     * named (a field not saved yet), so links cannot be copied or pasted in it.
     * @param int|null $siteId The site of the content being edited; null for default links.
     */
    private function __construct(
        public readonly string $name,
        public readonly string $id,
        public readonly array $types,
        public readonly array $presets,
        public readonly array $allowedPresets,
        public readonly ?int $max,
        public readonly ?string $namespace,
        private readonly ?array $binding,
        public readonly ?int $siteId = null,
    ) {
    }

    /**
     * The editor for a field's value in an element, in the current input namespace.
     */
    public static function forField(SmartLinkField $field, ?ElementInterface $element): self
    {
        $binding = null;

        if ($field->uid !== null) {
            $binding = [
                'scope' => $element?->id ? self::SCOPE_ELEMENT : self::SCOPE_NEW_ELEMENT,
                'field' => $field->uid,
                'layoutElement' => $field->layoutElement?->uid,
                'element' => $element?->id ? (int)$element->id : null,
                'site' => $element?->siteId !== null ? (int)$element->siteId : null,
            ];
        }

        return self::create((string)$field->handle, $field->getInputId(), $field->types, $field->presets, $field->maxCount(), $binding, $element?->siteId !== null ? (int)$element->siteId : null);
    }

    /**
     * Of the allowed presets, those authors are offered: the ones that exist, are enabled, and
     * are for at least one of the link types offered, in the allowed order.
     *
     * @param list<string> $allowed
     * @param list<string> $types
     * @return list<string>
     */
    private static function offeredPresets(array $allowed, array $types): array
    {
        $all = SmartLinks::getInstance()->getPresets()->getAllPresets();

        return array_values(array_filter($allowed, static function(string $uid) use ($all, $types): bool {
            $preset = $all[$uid] ?? null;

            return $preset !== null && $preset->enabled && array_filter($types, static fn(string $type): bool => $preset->isForType($type)) !== [];
        }));
    }

    /**
     * Every preset's UID: default links may use any preset.
     *
     * @return list<string>
     */
    private static function everyPreset(): array
    {
        return array_keys(SmartLinks::getInstance()->getPresets()->getAllPresets());
    }

    /**
     * The editor for a field's default links, in its settings. Defaults may use any registered
     * type that can be a default (not one that links to elements) and any enabled preset for one
     * of them; the
     * field's own rules are checked when the settings are saved. Its
     * context names the field whose settings these are; a field not saved yet cannot be named,
     * so its defaults editor offers no copying or pasting.
     */
    public static function forSettings(SmartLinkField $field): self
    {
        $binding = $field->uid === null ? null : [
            'scope' => self::SCOPE_SETTINGS,
            'field' => $field->uid,
            'layoutElement' => null,
            'element' => null,
            'site' => null,
        ];

        return self::create(SmartLinkField::DEFAULT_LINKS_INPUT, 'defaultLinks', self::defaultTypes(), self::everyPreset(), $field->maxCount(), $binding);
    }

    /**
     * The registered link types default links can have.
     *
     * @return list<string>
     */
    private static function defaultTypes(): array
    {
        $types = SmartLinks::getInstance()->getLinkTypes()->getTypeSet();

        return array_values(array_filter($types->handles(), static fn(string $handle): bool => ($type = $types->get($handle)) !== null && SmartLinkField::allowsAsDefault($type)));
    }

    /**
     * @param list<string> $types
     * @param list<string> $allowedPresets
     * @param array<string, mixed>|null $binding
     */
    private static function create(string $name, string $id, array $types, array $allowedPresets, ?int $max, ?array $binding, ?int $siteId = null): self
    {
        $view = Craft::$app->getView();

        if ($binding !== null) {
            $binding += ['name' => $name, 'id' => $id, 'namespace' => $view->getNamespace(), 'editor' => $view->namespaceInputId($id)];
        }

        return new self($name, $id, $types, self::offeredPresets($allowedPresets, $types), $allowedPresets, $max, $view->getNamespace(), $binding, $siteId);
    }

    /**
     * The editor a request is for, with the rules of the field it belongs to as they are now.
     *
     * @param mixed $context The signed context the page holds.
     * @param mixed $destination The editor the page says the request is for: its namespaced
     * editor ID, field UID, element ID and site ID, as JSON. A context is served only for the
     * editor it names.
     * @throws BadRequestHttpException if the context was not signed here, has been altered, is
     * for another editor, or names a field or element that no longer matches.
     * @throws ForbiddenHttpException if the user may not edit what the context names.
     */
    public static function fromRequest(mixed $context, mixed $destination, ?User $user): self
    {
        $json = is_string($context) ? Craft::$app->getSecurity()->validateData($context) : false;
        $binding = is_string($json) ? Json::decodeIfJson($json) : null;

        if (!is_array($binding) || array_keys($binding) !== self::CONTEXT_KEYS) {
            throw new BadRequestHttpException('The link editor’s context is missing or has been altered.');
        }

        $declared = is_string($destination) ? Json::decodeIfJson($destination) : null;

        if ($declared !== self::destinationOf($binding)) {
            throw new BadRequestHttpException('This link editor’s context belongs to another editor.');
        }

        if ($user === null) {
            throw new ForbiddenHttpException('User is not authorized to perform this action.');
        }

        if ($binding['scope'] === self::SCOPE_SETTINGS) {
            // Field settings are an admin's to change, as Craft's own fields controller requires.
            if (!$user->admin || !Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
                throw new ForbiddenHttpException('User is not authorized to perform this action.');
            }

            // The field whose settings these are, as Craft has it now: it must still exist.
            $field = is_string($binding['field']) ? Craft::$app->getFields()->getFieldByUid($binding['field']) : null;

            if (!$field instanceof SmartLinkField) {
                throw new BadRequestHttpException('The link editor’s field no longer exists.');
            }

            $types = self::defaultTypes();

            return new self($binding['name'], $binding['id'], $types, self::offeredPresets(self::everyPreset(), $types), self::everyPreset(), $field->maxCount(), $binding['namespace'], $binding);
        }

        $field = self::boundField($binding, $user);

        return new self($binding['name'], $binding['id'], $field->types, self::offeredPresets($field->presets, $field->types), $field->presets, $field->maxCount(), $binding['namespace'], $binding, is_int($binding['site']) ? $binding['site'] : null);
    }

    /**
     * The field a context names, as it is now, once the user is known to be allowed to edit it.
     *
     * @param array<string, mixed> $binding
     */
    private static function boundField(array $binding, User $user): SmartLinkField
    {
        if ($binding['scope'] === self::SCOPE_ELEMENT) {
            $element = is_int($binding['element']) ? Craft::$app->getElements()->getElementById($binding['element'], null, $binding['site']) : null;
            $layoutElement = is_string($binding['layoutElement']) ? $element?->getFieldLayout()?->getElementByUid($binding['layoutElement']) : null;
            $field = $layoutElement instanceof CustomField ? $layoutElement->getField() : null;

            if ($element === null || !$field instanceof SmartLinkField || $field->uid !== $binding['field']) {
                throw new BadRequestHttpException('The link editor’s field is no longer part of this element.');
            }

            // Only someone Craft lets save the element may work with its links.
            if (!Craft::$app->getElements()->canSave($element, $user)) {
                throw new ForbiddenHttpException('User is not authorized to perform this action.');
            }

            // A field's settings are the field's, not its placement's, so its rules are read from
            // the field as Craft has it now.
            $current = Craft::$app->getFields()->getFieldByUid($field->uid);

            return $current instanceof SmartLinkField ? $current : throw new BadRequestHttpException('The link editor’s field no longer exists.');
        }

        // An element that does not exist yet can only be checked against the field itself.
        $field = is_string($binding['field']) ? Craft::$app->getFields()->getFieldByUid($binding['field']) : null;

        if ($binding['scope'] !== self::SCOPE_NEW_ELEMENT || !$field instanceof SmartLinkField) {
            throw new BadRequestHttpException('The link editor’s field no longer exists.');
        }

        return $field;
    }

    /**
     * The signed context, or null when this editor cannot be named.
     */
    public function context(): ?string
    {
        return $this->binding !== null ? Craft::$app->getSecurity()->hashData(Json::encode($this->binding)) : null;
    }

    /**
     * What the page says, with every request, about which editor it is.
     *
     * @return array{editor: string, field: string, element: int|null, site: int|null}|null
     */
    public function destination(): ?array
    {
        return $this->binding !== null ? self::destinationOf($this->binding) : null;
    }

    /**
     * @param array<string, mixed> $binding
     * @return array{editor: string, field: string, element: int|null, site: int|null}
     */
    private static function destinationOf(array $binding): array
    {
        return ['editor' => $binding['editor'], 'field' => $binding['field'], 'element' => $binding['element'], 'site' => $binding['site']];
    }

    /**
     * The whole editor for a value, with the problems the field has with it shown on each link.
     *
     * @param list<ValidationError> $problems
     */
    public function html(LinkCollection|InvalidLinkValue $value, array $problems = []): string
    {
        $view = Craft::$app->getView();
        $form = LinkForm::view($value, $this->registeredTypes());

        [$problemsByLink, $generalProblems] = LinkForm::groupErrors($problems);

        foreach ($form['links'] as $index => $link) {
            $form['links'][$index]['errors'] = array_merge($link['errors'], $problemsByLink[$index] ?? []);
        }

        $view->registerAssetBundle(SmartLinkFieldAsset::class);
        $view->registerTranslations('smart-links', [
            'Link',
            'Moved link to position {position} of {total}.',
            'Link removed.',
            'Link added.',
            'Link duplicated.',
            'Links pasted.',
            'Link copied. Paste it into any Smart Links field.',
            'Links copied. Paste them into any Smart Links field.',
            'Actions for {link}',
            'Could not copy. {reason}',
            'Could not duplicate. {reason}',
            'Could not paste. {reason}',
            'Could not copy. This browser does not let the copied links be stored.',
            'There is nothing to paste.',
            'The server’s answer does not match this request. Reload the page and try again.',
            'The link was removed before its copy arrived.',
            'This field holds at most {max, number} {max, plural, =1{link} other{links}}.',
            'The server could not be reached. Check the connection and try again.',
            'The server did not answer in time. Try again.',
            'Something went wrong in the link editor. Reload the page and try again.',
            'You are not allowed to change these links.',
            'The server ran into a problem. Try again in a moment.',
            'The request failed with status {status}. Reload the page and try again.',
            'Clear this value? What it holds now is removed when the element is saved.',
            'Set by the “{preset}” preset.',
            '“{preset}” preset applied.',
            '“{preset}” preset applied, as a {type} link.',
            'Preset removed.',
        ]);

        $newLink = null;

        // The template a new link is made from, with the JavaScript its inputs need.
        if ($this->offeredTypes() !== []) {
            $view->startJsBuffer();
            $html = $this->linkHtml(['key' => self::PLACEHOLDER, 'type' => $this->offeredTypes()[0]['handle'], 'data' => null, 'custom' => [], 'errors' => []], $this->offeredTypes());
            $newLink = ['html' => $html, 'js' => (string)$view->clearJsBuffer(false)];
        }

        $view->registerJsWithVars(fn(string $container, string $settings) => <<<JS
new Craft.SmartLinks.Field('#' + $container, $settings);
JS, [$view->namespaceInputId($this->id), [
            'placeholder' => self::PLACEHOLDER,
            'max' => $this->max,
            'multiple' => $this->max === null || $this->max > 1,
            'newLinkJs' => $newLink['js'] ?? '',
            'context' => $this->context(),
            'destination' => $this->destination(),
            // Every preset, so a link keeps showing what its preset locks even when it is no
            // longer offered here.
            'presets' => array_map(static fn(LinkPreset $preset): array => LinkForm::presetView($preset), $this->allPresets()),
        ]]);

        return $view->renderTemplate('smart-links/_field/input', [
            'name' => $this->name,
            'id' => $this->id,
            'links' => array_map(fn(array $link): string => $this->linkHtml($link, $this->typesFor($form['links'])), $form['links']),
            'errors' => array_merge($form['errors'], $generalProblems),
            'stored' => $form['stored'],
            'newLinkHtml' => $newLink['html'] ?? null,
            'placeholder' => self::PLACEHOLDER,
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * Links rendered for this editor under new keys, each with the JavaScript its inputs need,
     * in the namespace the editor was rendered in.
     *
     * @param list<string> $keys One per link.
     * @return list<array{key: string, html: string, js: string}>
     */
    public function newLinksHtml(LinkCollection $links, array $keys): array
    {
        $view = Craft::$app->getView();
        $rendered = [];

        foreach ($links->links as $index => $link) {
            $key = $keys[$index];
            $view->startJsBuffer();
            $html = $view->namespaceInputs(function() use ($link, $key): string {
                $linkView = LinkForm::linkView($link, $key);
                // A pasted or duplicated link is a new occurrence, so it has no UID until saved.
                $linkView['uid'] = null;

                return $this->linkHtml($linkView, $this->offeredTypes());
            }, $this->namespace);
            $rendered[] = ['key' => $key, 'html' => $html, 'js' => (string)$view->clearJsBuffer(false)];
        }

        return $rendered;
    }

    /**
     * The link types the editor offers for adding links.
     *
     * @return list<array{handle: string, name: string, features: list<string>, type: \Tahadudhiya\SmartLinks\linktypes\LinkTypeInterface}>
     */
    public function offeredTypes(): array
    {
        return $this->typeOptions($this->types);
    }

    /**
     * @param array<string, mixed> $link
     * @param list<array<string, mixed>> $types
     */
    private function linkHtml(array $link, array $types): string
    {
        return Craft::$app->getView()->renderTemplate('smart-links/_field/link', [
            'name' => $this->name,
            'id' => $this->id,
            'link' => $link,
            'types' => $types,
            'siteId' => $this->siteId,
            'presets' => $this->presetOptions($link['presetUid'] ?? null),
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * The offered types, plus any registered type a link already has: a link keeps its type in
     * the selector when the field no longer allows it, so the author sees what it is.
     *
     * @param list<array<string, mixed>> $links
     * @return list<array<string, mixed>>
     */
    private function typesFor(array $links): array
    {
        $handles = $this->types;

        foreach ($links as $link) {
            $type = $link['type'] ?? null;

            if (is_string($type) && ($link['stored'] ?? null) === null && !in_array($type, $handles, true)) {
                $handles[] = $type;
            }
        }

        return $this->typeOptions($handles);
    }

    /**
     * @param list<string> $handles
     * @return list<array{handle: string, name: string, features: list<string>, type: \Tahadudhiya\SmartLinks\linktypes\LinkTypeInterface}>
     */
    private function typeOptions(array $handles): array
    {
        $options = [];

        foreach ($handles as $handle) {
            $type = $this->registeredTypes()->get($handle);

            if ($type !== null) {
                $options[] = [
                    'handle' => $handle,
                    'name' => $type->displayName(),
                    'features' => array_map(static fn(LinkFeature $feature): string => $feature->value, $type->supportedFeatures()),
                    'type' => $type,
                ];
            }
        }

        return $options;
    }

    /**
     * The presets a link can be set to: those offered, and the one it has, named for what it is
     * when it is not offered, so it is shown rather than lost.
     *
     * @return list<array{label: string, value: string}>
     */
    private function presetOptions(?string $current): array
    {
        $all = $this->allPresets();
        $options = array_values(array_map(
            static fn(LinkPreset $preset): array => ['label' => $preset->name, 'value' => (string)$preset->uid],
            array_filter(array_map(static fn(string $uid): ?LinkPreset => $all[$uid] ?? null, $this->presets)),
        ));

        if ($current !== null && !in_array($current, $this->presets, true)) {
            $options[] = [
                'label' => match (true) {
                    !isset($all[$current]) => Craft::t('smart-links', 'A preset that no longer exists ({uid})', ['uid' => $current]),
                    !$all[$current]->enabled => Craft::t('smart-links', '{preset} (disabled)', ['preset' => $all[$current]->name]),
                    !in_array($current, $this->allowedPresets, true) => Craft::t('smart-links', '{preset} (not allowed in this field)', ['preset' => $all[$current]->name]),
                    default => Craft::t('smart-links', '{preset} (not for this field’s link types)', ['preset' => $all[$current]->name]),
                },
                'value' => $current,
            ];
        }

        return $options;
    }

    /**
     * Every preset, read once for this editor's rendering rather than once per link.
     *
     * @return array<string, LinkPreset>
     */
    private function allPresets(): array
    {
        return $this->allPresets ??= SmartLinks::getInstance()->getPresets()->getAllPresets();
    }

    private function registeredTypes(): \Tahadudhiya\SmartLinks\linktypes\LinkTypeSet
    {
        return SmartLinks::getInstance()->getLinkTypes()->getTypeSet();
    }
}
