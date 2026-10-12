<?php

namespace Tahadudhiya\SmartLinks\fields;

use Craft;
use craft\base\CrossSiteCopyableFieldInterface;
use craft\base\DefaultableFieldInterface;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\base\RelationalFieldInterface;
use craft\base\RelationalFieldTrait;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\web\View;
use GraphQL\Type\Definition\Type;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\errors\LinkResolutionException;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\gql\SmartLinkGql;
use Tahadudhiya\SmartLinks\linktypes\ElementLinkTypeInterface;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeInterface;
use Tahadudhiya\SmartLinks\models\InvalidLinkValue;
use Tahadudhiya\SmartLinks\models\LinkCollection;
use Tahadudhiya\SmartLinks\models\LinkPreset;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ValidationError;
use Tahadudhiya\SmartLinks\services\Links;
use Tahadudhiya\SmartLinks\SmartLinks;
use yii\db\Schema;

/**
 * A field holding an ordered list of structured links.
 *
 * Its value is a {@see LinkCollection}, stored in Craft's own element content, so sites, drafts,
 * revisions, duplication and restoring all work as they do for any field. Everything about a
 * link itself (normalizing, validating, storing, resolving, rendering) is the link core's, reached
 * through the {@see Links} service. The field adds only what belongs to one field: which link
 * types and presets it allows, how many links, and its default links.
 *
 * A value that is not valid is kept as an {@see InvalidLinkValue} rather than dropped or
 * repaired: author input is shown again with its errors and is never stored; stored content that
 * can no longer be read is kept exactly as it was, and must be fixed before a full save.
 *
 * Elements its links point at are Craft relations of the element, per site, as for Craft's own
 * Link field, so `relatedTo` and Craft's reverse lookup find them.
 */
class SmartLinkField extends Field implements PreviewableFieldInterface, CrossSiteCopyableFieldInterface, DefaultableFieldInterface, RelationalFieldInterface
{
    use RelationalFieldTrait;

    // Settings are declared with union types Craft's `Typecast` leaves alone, so a malformed
    // setting (`'two'`, `'1.5'`, `'banana'`) reaches the constructor and is refused, rather than
    // being quietly cast to `0`, `1` or `false` first. After construction each has the type its
    // PHPDoc states.

    /** @var list<string> Handles of the link types authors may choose, in the order offered. */
    public array|string $types = [];

    /** @var list<string> UIDs of the presets authors may choose, in the order offered. None when empty. */
    public array|string $presets = [];

    /** @var bool Whether the field holds any number of links, rather than at most one. */
    public bool|string $multiple = true;

    /** @var int|null The fewest links a live element may have, when it has any. Multiple-link fields only. */
    public int|string|null $minLinks = null;

    /** @var int|null The most links the field holds. Multiple-link fields only; a single-link field holds one. */
    public int|string|null $maxLinks = null;

    /**
     * @var array<string, mixed>|null The links new elements start with, in the stored form. Each
     * element gets its own occurrences of them, with new UIDs.
     */
    public mixed $defaultLinks = null;

    /**
     * The setting the settings form's link editor posts default links under. It is not a stored
     * setting: `defaultLinks` alone is, always in the stored form. So the settings form's input
     * and the stored configuration are told apart by which setting they arrive in, never by
     * their shape.
     */
    public const DEFAULT_LINKS_INPUT = 'defaultLinksInput';

    /** Default links posted from the settings form, until they are validated. */
    private mixed $postedDefaultLinks = null;

    private bool $hasPostedDefaultLinks = false;

    /** @var array<string, true> Settings posted in a form no setting can have. */
    private array $malformedSettings = [];

    public static function displayName(): string
    {
        return Craft::t('smart-links', 'Smart Links');
    }

    /**
     * Default links as the settings form's link editor posted them. They are checked, and stored
     * in the stored form, when the settings are validated; link types may not be registered yet
     * when the field is constructed.
     *
     * @see DEFAULT_LINKS_INPUT
     */
    public function setDefaultLinksInput(mixed $input): void
    {
        $this->postedDefaultLinks = $input;
        $this->hasPostedDefaultLinks = true;
    }

    public static function icon(): string
    {
        return 'link';
    }

    public static function phpType(): string
    {
        return sprintf('\\%s|\\%s', LinkCollection::class, InvalidLinkValue::class);
    }

    /**
     * The stored form is a JSON object, or null for no links, so `:empty:` and `:notempty:`
     * query conditions work.
     */
    public static function dbType(): string
    {
        return Schema::TYPE_JSON;
    }

    public function __construct($config = [])
    {
        // A settings form posts numbers as text and nothing as an empty string. Anything else is
        // kept out of the typed property and refused by validation, never coerced.
        foreach (['minLinks', 'maxLinks'] as $key) {
            if (!array_key_exists($key, $config)) {
                continue;
            }

            $value = $config[$key];

            if ($value === '' || $value === null) {
                $config[$key] = null;
            } elseif (is_string($value) && preg_match('/^-?\d+$/', $value)) {
                $config[$key] = (int)$value;
            } elseif (!is_int($value)) {
                $this->malformedSettings[$key] = true;
                $config[$key] = null;
            }
        }

        // A lightswitch posts `'1'` or `''`.
        if (array_key_exists('multiple', $config)) {
            $multiple = $config['multiple'];

            if (in_array($multiple, [true, 1, '1'], true)) {
                $config['multiple'] = true;
            } elseif (in_array($multiple, [false, 0, '0', ''], true)) {
                $config['multiple'] = false;
            } else {
                $this->malformedSettings['multiple'] = true;
                $config['multiple'] = true;
            }
        }

        foreach (['types', 'presets'] as $key) {
            if (!array_key_exists($key, $config)) {
                continue;
            }

            if ($config[$key] === '') {
                $config[$key] = [];
            } elseif (!is_array($config[$key]) || !array_is_list($config[$key]) || array_filter($config[$key], 'is_string') !== $config[$key]) {
                $this->malformedSettings[$key] = true;
                $config[$key] = [];
            }
        }

        parent::__construct($config);
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['multiple'], 'validateMultipleSetting', 'skipOnEmpty' => false];
        $rules[] = [['types'], 'validateTypes', 'skipOnEmpty' => false];
        $rules[] = [['presets'], 'validatePresets', 'skipOnEmpty' => false];
        $rules[] = [['minLinks', 'maxLinks'], 'validateLimits', 'skipOnEmpty' => false];
        $rules[] = [['defaultLinks'], 'validateDefaultLinks', 'skipOnEmpty' => false];

        return $rules;
    }

    public function validateMultipleSetting(string $attribute): void
    {
        if (isset($this->malformedSettings['multiple'])) {
            $this->addError($attribute, Craft::t('smart-links', 'This must be on or off.'));
        }
    }

    public function validateTypes(string $attribute): void
    {
        if (isset($this->malformedSettings['types'])) {
            $this->addError($attribute, Craft::t('smart-links', 'Allowed link types must be a list of link type handles.'));

            return;
        }

        if ($this->types === []) {
            $this->addError($attribute, Craft::t('smart-links', 'Choose at least one link type.'));

            return;
        }

        $registered = $this->plugin()->getLinkTypes()->getTypeSet();

        foreach ($this->types as $handle) {
            if ($registered->get($handle) === null) {
                $this->addError($attribute, Craft::t('smart-links', '“{type}” is not an available link type.', ['type' => $handle]));
            }
        }

        if (count(array_unique($this->types)) !== count($this->types)) {
            $this->addError($attribute, Craft::t('smart-links', 'Each link type can only be allowed once.'));
        }
    }

    public function validatePresets(string $attribute): void
    {
        if (isset($this->malformedSettings['presets'])) {
            $this->addError($attribute, Craft::t('smart-links', 'Allowed presets must be a list of preset UIDs.'));

            return;
        }

        $presets = $this->plugin()->getPresets()->getAllPresets();

        foreach ($this->presets as $uid) {
            if (!isset($presets[$uid])) {
                $this->addError($attribute, Craft::t('smart-links', '“{uid}” is not an existing preset.', ['uid' => $uid]));
            } elseif (array_filter($this->types, static fn(string $type): bool => $presets[$uid]->isForType($type)) === []) {
                $this->addError($attribute, Craft::t('smart-links', 'The “{preset}” preset is not for any link type this field allows.', ['preset' => $presets[$uid]->name]));
            }
        }

        if (count(array_unique($this->presets)) !== count($this->presets)) {
            $this->addError($attribute, Craft::t('smart-links', 'Each preset can only be allowed once.'));
        }
    }

    public function validateLimits(string $attribute): void
    {
        if (isset($this->malformedSettings[$attribute])) {
            $this->addError($attribute, Craft::t('smart-links', 'This must be a whole number.'));

            return;
        }

        if ($attribute === 'minLinks' && $this->minLinks !== null && $this->minLinks < 0) {
            $this->addError($attribute, Craft::t('smart-links', 'The minimum cannot be negative.'));
        }

        if ($attribute !== 'maxLinks') {
            return;
        }

        if ($this->maxLinks !== null && $this->maxLinks < 1) {
            $this->addError($attribute, Craft::t('smart-links', 'The maximum must be at least 1.'));
        } elseif (!$this->multiple && ($this->minLinks !== null || $this->maxLinks !== null)) {
            $this->addError($attribute, Craft::t('smart-links', 'A minimum and maximum apply only when multiple links are allowed.'));
        } elseif ($this->minLinks !== null && $this->maxLinks !== null && $this->minLinks > $this->maxLinks) {
            $this->addError($attribute, Craft::t('smart-links', 'The maximum cannot be less than the minimum.'));
        }
    }

    /**
     * Default links are held to the link rules and to this field's own, and are stored in their
     * one stored form once they pass.
     */
    public function validateDefaultLinks(string $attribute): void
    {
        $value = $this->hasPostedDefaultLinks ? $this->valueFromRequest($this->postedDefaultLinks, null) : $this->valueFromStorage($this->defaultLinks);

        if ($value instanceof InvalidLinkValue) {
            foreach ($value->errors as $error) {
                $this->addError($attribute, self::errorMessage($error));
            }

            return;
        }

        $problems = array_merge($this->linkProblems($value, $this->storedDefaultPresets()), self::defaultLinkProblems($value));

        foreach ($problems as $error) {
            $this->addError($attribute, self::errorMessage($error));
        }

        if ($problems === []) {
            $this->defaultLinks = $this->links()->getSerializer()->serialize($value);
            $this->hasPostedDefaultLinks = false;
            $this->postedDefaultLinks = null;
        }
    }

    /**
     * Whether links of a type can be default links. Default links deploy with project config, and
     * element IDs differ between environments, so links to elements cannot be.
     */
    public static function allowsAsDefault(LinkTypeInterface $type): bool
    {
        return !$type instanceof ElementLinkTypeInterface;
    }

    /**
     * @return list<ValidationError>
     */
    private static function defaultLinkProblems(LinkCollection $value): array
    {
        $problems = [];

        foreach ($value->links as $index => $link) {
            $type = SmartLinks::getInstance()->getLinkTypes()->getType($link->type);

            if ($type !== null && !self::allowsAsDefault($type)) {
                $problems[] = new ValidationError("links[$index].type", Code::NOT_SUPPORTED, '{type} links can’t be default links: element IDs differ between environments.', ['type' => $type->displayName()]);
            }
        }

        return $problems;
    }

    /**
     * Links as GraphQL reads them: see {@see SmartLinkGql}.
     */
    public function getContentGqlType(): Type|array
    {
        return SmartLinkGql::contentType($this);
    }

    /**
     * The whole value, as authoring input; Craft reads it as it reads the editor's form.
     */
    public function getContentGqlMutationArgumentType(): Type|array
    {
        return SmartLinkGql::mutationArgument($this);
    }

    public function useFieldset(): bool
    {
        return true;
    }

    // Value

    /**
     * Reads a value as Craft hands it over outside a request: stored content, the serialized form
     * {@see serializeValue()} returns (which is how Craft copies values for drafts, revisions,
     * duplicates and sites), or a value already normalized.
     *
     * Request data never comes here: Craft gives it to {@see normalizeValueFromRequest()}. So
     * anything else is stored content and is read only as stored content, whatever its shape; a
     * value that cannot be read is kept, never read as authoring input and rewritten. Code that
     * builds links from authoring input normalizes it with the link core and sets the result.
     *
     * The one exception is a draft's unfinished value (see {@see serializeValue()}): in a draft,
     * it is what its author entered, so it is read as that input again, with its errors. Anywhere
     * else it is not a value Smart Links writes, and is kept as unreadable stored content.
     */
    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof LinkCollection || $value instanceof InvalidLinkValue) {
            return $value;
        }

        if ($value === null) {
            return $this->isFresh($element) ? $this->getDefaultValue() ?? new LinkCollection() : new LinkCollection();
        }

        $serializer = $this->links()->getSerializer();

        if (self::isDraft($element) && $serializer->isUnfinished($value)) {
            return $this->valueFromInput($serializer->unfinishedInput($value));
        }

        return $this->valueFromStorage($value);
    }

    public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element): mixed
    {
        return $this->valueFromRequest($value, $element);
    }

    /**
     * Invalid input is stored only in a draft, which is work in progress: what its author entered
     * is kept exactly, in the draft's own unfinished form, until they finish it, as Craft's drafts
     * keep any field's unfinished value. It never reaches other content: a full save, applying the
     * draft and publishing all validate it, and outside a draft it is not stored at all.
     *
     * @throws LinkValidationException if the value is invalid input and the element is no draft.
     */
    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        // Nothing is nothing, whether or not the element is fresh: defaults are applied when a
        // value is read, never when one is written.
        if ($value === null) {
            return null;
        }

        $value = $this->normalizeValue($value, $element);

        if ($value instanceof InvalidLinkValue) {
            // Stored content that cannot be read is kept exactly as it was, not lost.
            if ($value->stored) {
                return $value->input;
            }

            if (self::isDraft($element)) {
                return $this->links()->getSerializer()->serializeUnfinished($value->input);
            }

            throw new LinkValidationException($value->errors);
        }

        /** @var LinkCollection $value */
        return $this->links()->getSerializer()->serialize($value);
    }

    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        if ($value instanceof InvalidLinkValue) {
            return false;
        }

        return !$value instanceof LinkCollection || $value->isEmpty();
    }

    /**
     * New occurrences of the default links, each with a new UID; null when there are none.
     *
     * Default links that can no longer be read (e.g. their link type is gone) are not quietly
     * skipped: new content starts with them as invalid input, which shows the problem and
     * cannot be saved until the author deals with it.
     */
    public function getDefaultValue(): LinkCollection|InvalidLinkValue|null
    {
        $defaults = $this->valueFromStorage($this->defaultLinks);

        if ($defaults instanceof InvalidLinkValue) {
            return InvalidLinkValue::fromInput($this->defaultLinks, array_merge(
                [new ValidationError('', Code::INVALID, 'This field’s default links can’t be read. Remove them here, and correct the field’s settings.')],
                $defaults->errors,
            ));
        }

        if ($defaults->isEmpty()) {
            return null;
        }

        return new LinkCollection(array_map(
            static fn(LinkValue $link): LinkValue => new LinkValue(StringHelper::UUID(), $link->type, $link->data, $link->label, $link->urlSuffix, $link->attributes, $link->presetUid),
            $defaults->links,
        ));
    }

    /**
     * The elements the value's links point at, in the order they first appear, as far as they
     * still exist: Craft's garbage collection deletes relations whose target is gone, so none is
     * written for one. Whether a target is disabled, trashed or not in a site does not matter to a
     * relation, which is between elements.
     */
    public function getRelationTargetIds(ElementInterface $element): array
    {
        $value = $element->getFieldValue((string)$this->handle);

        // A value that can't be read, or a draft's unfinished one, holds no links, so no relations.
        if (!$value instanceof LinkCollection) {
            return [];
        }

        $ids = [];

        foreach ($value->links as $link) {
            $type = $this->plugin()->getLinkTypes()->getType($link->type);

            if ($type instanceof ElementLinkTypeInterface) {
                $ids[] = $type->elementId($link->data);
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $existing = array_map('intval', (new Query())->select(['id'])->from(Table::ELEMENTS)->where(['id' => $ids])->column());

        return array_values(array_intersect($ids, $existing));
    }

    // Validation

    public function getElementValidationRules(): array
    {
        return [
            // Invalid input is stored only in a draft, so outside one it fails even an
            // essentials-only save; a draft's autosave keeps it. Unreadable stored content is kept
            // until a full save.
            ['validateValue', 'on' => [Element::SCENARIO_DEFAULT, Element::SCENARIO_LIVE, Element::SCENARIO_ESSENTIALS], 'skipOnEmpty' => false],
            ['validateLinks', 'skipOnEmpty' => false],
            // Craft saves drafts, duplicates, propagated sites and restored elements with
            // essentials only, so the field's other rules wait for a full save. Giving a link a
            // disabled preset is refused there too: whatever Craft stores is then something a
            // link may keep, which is what lets a stored value show which links already had it.
            ['validatePresetAssignments', 'on' => [Element::SCENARIO_ESSENTIALS], 'skipOnEmpty' => false],
            // Like Craft's relation fields, the minimum applies to live content with any links;
            // whether it needs links at all is the field's “required” setting.
            ['validateMinimum', 'on' => [Element::SCENARIO_LIVE]],
        ];
    }

    public function validateValue(ElementInterface $element): void
    {
        $value = $element->getFieldValue($this->handle);

        // An essentials-only save keeps unreadable stored content anywhere, and a draft's
        // unfinished input (the author's work in progress).
        if (!$value instanceof InvalidLinkValue || ($element->getScenario() === Element::SCENARIO_ESSENTIALS && ($value->stored || self::isDraft($element)))) {
            return;
        }

        foreach ($value->errors as $error) {
            $element->addError($this->handle, self::errorMessage($error));
        }
    }

    public function validateLinks(ElementInterface $element): void
    {
        $value = $element->getFieldValue($this->handle);

        if ($value instanceof LinkCollection) {
            foreach ($this->linkProblems($value, $this->keptPresets($value, $element)) as $error) {
                $element->addError($this->handle, self::errorMessage($error));
            }
        }
    }

    public function validatePresetAssignments(ElementInterface $element): void
    {
        $value = $element->getFieldValue($this->handle);

        if (!$value instanceof LinkCollection || !$this->hasDisabledPreset($value)) {
            return;
        }

        $presets = $this->plugin()->getPresets()->getAllPresets();
        $kept = $this->keptPresets($value, $element);

        foreach ($value->links as $index => $link) {
            $preset = $link->presetUid !== null ? $presets[$link->presetUid] ?? null : null;
            $error = $preset !== null ? self::disabledPresetProblem($link, "links[$index]", $preset, $kept) : null;

            if ($error !== null) {
                $element->addError($this->handle, self::errorMessage($error));
            }
        }
    }

    public function validateMinimum(ElementInterface $element): void
    {
        $value = $element->getFieldValue($this->handle);

        if ($this->multiple && $this->minLinks !== null && $value instanceof LinkCollection && count($value) < $this->minLinks) {
            $element->addError($this->handle, Craft::t('smart-links', '{field} needs at least {min, number} {min, plural, =1{link} other{links}}.', [
                'field' => $this->getUiLabel(),
                'min' => $this->minLinks,
            ]));
        }
    }

    /**
     * What this field refuses in an otherwise valid value: links of a type it does not allow,
     * links made with a preset it does not allow, that no longer exists, that is disabled (unless
     * the link already had it), or whose own rules they break, and more links than it holds.
     * Nothing is removed or replaced; each is reported where it is.
     *
     * @param array<string, list<string>> $kept The presets each link already had, by link UID, as
     * stored (see {@see keptPresets()}): a link keeps a disabled preset it had, but none is given one.
     * @return list<ValidationError>
     */
    public function linkProblems(LinkCollection $value, array $kept = []): array
    {
        $problems = [];
        $presets = array_filter($value->links, static fn(LinkValue $link): bool => $link->presetUid !== null) !== [] ? $this->plugin()->getPresets()->getAllPresets() : [];

        foreach ($value->links as $index => $link) {
            array_push($problems, ...self::ruleProblems($link, "links[$index]", $this->types, $this->presets, $presets, $kept));
        }

        $max = $this->maxCount();

        if ($max !== null && count($value) > $max) {
            $problems[] = new ValidationError('', Code::INVALID, 'This field holds at most {max, number} {max, plural, =1{link} other{links}}.', ['max' => $max]);
        }

        return $problems;
    }

    /**
     * What a field with these allowed types and presets refuses in one valid link: a type it
     * does not allow; a preset it does not allow or that no longer exists; or what the link's
     * preset refuses (a type it is not for, a locked setting changed). The one statement of
     * these rules, for a field's value and for links pasted into its editor. A disabled preset
     * is no longer offered, but links made with it are still valid.
     *
     * A disabled preset is not given to any link: a link keeps one only if it already had it, as
     * stored ($kept). Every other link with it (a new link, a pasted or duplicated one, a link
     * given it in a form, through GraphQL or in code) is refused.
     *
     * @param list<string> $types
     * @param list<string> $presets UIDs of the presets the field allows.
     * @param array<string, \Tahadudhiya\SmartLinks\models\LinkPreset> $allPresets Every defined preset, by UID.
     * @param array<string, list<string>> $kept The presets each link already had, by link UID.
     * @return list<ValidationError>
     */
    public static function ruleProblems(LinkValue $link, string $path, array $types, array $presets, array $allPresets, array $kept = []): array
    {
        $problems = [];

        if (!in_array($link->type, $types, true)) {
            $type = SmartLinks::getInstance()->getLinkTypes()->getType($link->type);
            $problems[] = new ValidationError("$path.type", Code::NOT_SUPPORTED, '{type} links are not allowed in this field.', ['type' => $type?->displayName() ?? $link->type]);
        }

        if ($link->presetUid !== null) {
            if (!isset($allPresets[$link->presetUid])) {
                $problems[] = new ValidationError("$path.presetUid", Code::INVALID, 'The preset this link was made with no longer exists.');
            } elseif (!in_array($link->presetUid, $presets, true)) {
                $problems[] = new ValidationError("$path.presetUid", Code::NOT_SUPPORTED, 'The “{preset}” preset is not allowed in this field.', ['preset' => $allPresets[$link->presetUid]->name]);
            } elseif (($disabled = self::disabledPresetProblem($link, $path, $allPresets[$link->presetUid], $kept)) !== null) {
                $problems[] = $disabled;
            } else {
                // A valid link's type is registered: the link core refuses any other.
                $type = SmartLinks::getInstance()->getLinkTypes()->getType($link->type) ?? throw new \LogicException("The link type “{$link->type}” of a valid link is not registered.");
                array_push($problems, ...$allPresets[$link->presetUid]->linkProblems($link, $path, $type->displayName(), $type->supportedFeatures()));
            }
        }

        return $problems;
    }

    /**
     * The one statement of the disabled-preset rule: a link with a disabled preset is refused
     * unless it already had that preset, as stored.
     *
     * @param array<string, list<string>> $kept
     */
    private static function disabledPresetProblem(LinkValue $link, string $path, LinkPreset $preset, array $kept): ?ValidationError
    {
        if ($preset->enabled || in_array($preset->uid, $kept[$link->uid] ?? [], true)) {
            return null;
        }

        return new ValidationError("$path.presetUid", Code::NOT_SUPPORTED, 'The “{preset}” preset is disabled, so no link can be given it. Links that already have it keep it.', ['preset' => $preset->name]);
    }

    /**
     * The presets each link already had, by link UID, as Craft has stored this field's value for
     * this occurrence. A link occurrence belongs to one element in one site, so only these count:
     * the element in its own site; its canonical element in that site, when it is a draft or
     * revision; the element it is being duplicated from, in that element's site (which is how
     * Craft makes drafts and revisions, applies drafts and reverts to revisions); and the site
     * version it is being propagated from. Never another site's value, another element's, or what
     * a request sends: a link keeps a disabled preset it had, but no link can be given one. Read
     * only when a link has a disabled preset.
     *
     * @return array<string, list<string>>
     */
    public function keptPresets(LinkCollection $value, ?ElementInterface $element): array
    {
        $layoutElementUid = $this->layoutElement?->uid;

        if ($element === null || $layoutElementUid === null || !$this->hasDisabledPreset($value)) {
            return [];
        }

        $sources = [[$element->id, $element->siteId], [$element->getCanonicalId(), $element->siteId]];

        if ($element instanceof Element) {
            $sources[] = [$element->duplicateOf?->id, $element->duplicateOf?->siteId];

            if ($element->propagating) {
                $sources[] = [$element->propagatingFrom?->id, $element->propagatingFrom?->siteId];
            }
        }

        // An element not saved yet, or a source that is not one, has nothing stored.
        $where = ['or'];

        foreach ($sources as [$elementId, $siteId]) {
            if ($elementId !== null && $siteId !== null) {
                $where[] = ['elementId' => (int)$elementId, 'siteId' => (int)$siteId];
            }
        }

        if (count($where) === 1) {
            return [];
        }

        $kept = [];

        foreach ((new Query())->select(['content'])->from(Table::ELEMENTS_SITES)->where($where)->column() as $content) {
            $stored = is_string($content) ? Json::decodeIfJson($content) : $content;

            if (is_array($stored) && array_key_exists($layoutElementUid, $stored)) {
                foreach (self::presetsOf($stored[$layoutElementUid]) as $uid => $presetUid) {
                    $kept[$uid][] = $presetUid;
                }
            }
        }

        return $kept;
    }

    /**
     * The preset each of this field's stored default links has, by link UID: default links the
     * field already had keep a preset that is disabled since.
     *
     * @return array<string, list<string>>
     */
    private function storedDefaultPresets(): array
    {
        $stored = $this->uid !== null ? Craft::$app->getFields()->getFieldByUid($this->uid) : null;

        return $stored instanceof self ? array_map(static fn(string $presetUid): array => [$presetUid], self::presetsOf($stored->defaultLinks)) : [];
    }

    /**
     * The preset of each link in a stored value, by link UID. Content that cannot be read holds
     * no links, so no presets.
     *
     * @return array<string, string>
     */
    private static function presetsOf(mixed $stored): array
    {
        try {
            $value = SmartLinks::getInstance()->getLinks()->getSerializer()->deserialize($stored);
        } catch (LinkValidationException) {
            return [];
        }

        $presets = [];

        foreach ($value->links as $link) {
            if ($link->presetUid !== null) {
                $presets[$link->uid] = $link->presetUid;
            }
        }

        return $presets;
    }

    /**
     * Whether any link has a preset that exists and is disabled.
     */
    private function hasDisabledPreset(LinkCollection $value): bool
    {
        $presets = null;

        foreach ($value->links as $link) {
            if ($link->presetUid !== null) {
                $presets ??= $this->plugin()->getPresets()->getAllPresets();

                if (isset($presets[$link->presetUid]) && !$presets[$link->presetUid]->enabled) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The most links the field holds, or null for no limit.
     */
    public function maxCount(): ?int
    {
        return $this->multiple ? $this->maxLinks : 1;
    }

    // Control panel

    public function getSettingsHtml(): ?string
    {
        $registered = $this->plugin()->getLinkTypes()->getTypeSet();
        $presets = $this->plugin()->getPresets()->getAllPresets();

        // Allowed ones first, in their order, then the rest. One that no longer exists stays
        // listed, so saving asks for it to be removed rather than dropping it.
        $handles = array_merge($this->types, array_values(array_diff($registered->handles(), $this->types)));
        $presetUids = array_merge($this->presets, array_values(array_diff(array_keys($presets), $this->presets)));

        $defaults = $this->hasPostedDefaultLinks ? $this->valueFromRequest($this->postedDefaultLinks, null) : $this->valueFromStorage($this->defaultLinks);

        $view = Craft::$app->getView();
        $view->registerJsWithVars(fn(string $switch, string $limits) => <<<JS
(() => {
  const \$switch = $('#' + $switch);
  const update = () => $('#' + $limits).find('input').prop('disabled', !\$switch.hasClass('on'));
  \$switch.on('change', update);
  update();
})();
JS, [$view->namespaceInputId('multiple'), $view->namespaceInputId('smartlinks-limits')]);

        $editor = LinkEditor::forSettings($this);

        return $view->renderTemplate('smart-links/_field/settings', [
            'field' => $this,
            'typeOptions' => array_map(fn(string $handle): array => [
                'label' => $registered->get($handle) !== null ? $this->typeName($handle) : Craft::t('smart-links', '{type} (not available)', ['type' => $handle]),
                'value' => $handle,
            ], $handles),
            'presetOptions' => array_map(static fn(string $uid): array => [
                'label' => match (true) {
                    !isset($presets[$uid]) => Craft::t('smart-links', 'A preset that no longer exists ({uid})', ['uid' => $uid]),
                    !$presets[$uid]->enabled => Craft::t('smart-links', '{preset} (disabled)', ['preset' => $presets[$uid]->name]),
                    default => $presets[$uid]->name,
                },
                'value' => $uid,
            ], $presetUids),
            'defaultLinksHtml' => $editor->html($defaults),
        ], View::TEMPLATE_MODE_CP);
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $value = $this->normalizeValue($value, $element);

        /** @var LinkCollection|InvalidLinkValue $value */
        $problems = $value instanceof LinkCollection ? $this->linkProblems($value, $this->keptPresets($value, $element)) : [];
        $editor = LinkEditor::forField($this, $element);

        return $editor->html($value, $problems);
    }

    public function getStaticHtml(mixed $value, ElementInterface $element): string
    {
        $value = $this->normalizeValue($value, $element);

        if ($value instanceof InvalidLinkValue) {
            return $this->invalidHtml('p');
        }

        /** @var LinkCollection $value */
        return Craft::$app->getView()->renderTemplate('smart-links/_field/static', [
            'links' => array_map(fn(LinkValue $link): array => [
                'type' => $this->typeName($link->type),
                'html' => $this->linkHtml($link, $element),
            ], $value->links),
        ], View::TEMPLATE_MODE_CP);
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        $value = $this->normalizeValue($value, $element);

        if ($value instanceof InvalidLinkValue) {
            return $this->invalidHtml('span');
        }

        /** @var LinkCollection $value */
        return implode(', ', array_map(fn(LinkValue $link): string => $this->linkHtml($link, $element), $value->links));
    }

    public function previewPlaceholderHtml(mixed $value, ?ElementInterface $element): string
    {
        if ($value instanceof LinkCollection && $element !== null) {
            return $this->getPreviewHtml($value, $element);
        }

        return Html::encode($this->getUiLabel());
    }

    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof LinkCollection) {
            return '';
        }

        $words = [];

        foreach ($value->links as $link) {
            array_push($words, ...array_filter([$link->label, $link->attributes->title, $link->attributes->ariaLabel], static fn(?string $word): bool => $word !== null));
        }

        return implode(' ', $words);
    }

    // Internals

    /**
     * The link as the control panel's previews show it, in the element's site.
     *
     * A link that resolves is shown as its rendered `<a>`, exactly as {@see Links::html()} renders
     * it. One that leads nowhere is a normal outcome of resolving it (its target is missing,
     * disabled or has no URL), so the preview names that status instead, as a status: it is never
     * shown as a link, nor given another destination. A link that cannot be resolved at all, or is
     * invalid, is a failure, and it is not caught here: it surfaces as the link core throws it.
     *
     * @throws LinkResolutionException if the link cannot be resolved at all.
     * @throws LinkValidationException if the link is invalid.
     */
    private function linkHtml(LinkValue $link, ElementInterface $element): string
    {
        $resolved = $this->links()->resolve($link, (int)$element->siteId);
        $html = $this->links()->htmlFor($link, $resolved);

        if ($html !== null) {
            return (string)$html;
        }

        $status = match ($resolved->status) {
            ResolutionStatus::MISSING => Craft::t('smart-links', 'its target doesn’t exist'),
            ResolutionStatus::DISABLED => Craft::t('smart-links', 'its target is disabled'),
            ResolutionStatus::NO_URL => Craft::t('smart-links', 'its target has no URL'),
            // A resolved link always renders, so this would be a link core bug, not a status.
            ResolutionStatus::RESOLVED => throw new \LogicException('A resolved link rendered as nothing.'),
        };

        // The target's own name is not shown: a disabled target, or one without a URL, is not
        // public, and whoever sees the preview may not be allowed to see it.
        return Html::tag('span', Html::encode(Craft::t('smart-links', '{label} (leads nowhere: {reason})', ['label' => $link->label ?? $this->typeName($link->type), 'reason' => $status])), [
            'class' => 'smartlinks-status warning',
            'data' => ['status' => $resolved->status->value],
        ]);
    }

    private function invalidHtml(string $tag): string
    {
        return Html::tag($tag, Html::encode(Craft::t('smart-links', 'This value is invalid. Edit the element to see why.')), ['class' => 'error']);
    }

    private function valueFromStorage(mixed $stored): LinkCollection|InvalidLinkValue
    {
        try {
            return $this->links()->getSerializer()->deserialize($stored);
        } catch (LinkValidationException $exception) {
            Craft::warning(sprintf('A stored value of the “%s” field cannot be read, and is kept as it is: %s', $this->handle, $exception->getMessage()), __METHOD__);

            return InvalidLinkValue::fromStorage($stored, $exception->errors);
        }
    }

    private function valueFromInput(mixed $input): LinkCollection|InvalidLinkValue
    {
        $result = $this->links()->getNormalizer()->normalize($input);

        /** @var LinkCollection|null $collection */
        $collection = $result->value;

        return $collection ?? InvalidLinkValue::fromInput($input, $result->errors);
    }

    /**
     * Reads what the editor posted. Links are authoring input. The one exception is a whole value
     * the editor kept as it was stored, posted back by its `stored` input: when that is exactly
     * what the element holds now, it is the stored content, unchanged, and stays so; anything
     * else posted that way is input like any other, and is never stored unless it is valid.
     */
    private function valueFromRequest(mixed $post, ?ElementInterface $element): LinkCollection|InvalidLinkValue
    {
        [$input, $formErrors, $kept] = LinkForm::fromPost($post, $this->plugin()->getLinkTypes()->getTypeSet());

        if ($kept) {
            $current = $element?->getFieldValue((string)$this->handle);

            if ($formErrors === [] && $current instanceof InvalidLinkValue && $current->stored && $current->input === $input) {
                return $current;
            }

            try {
                $value = $this->links()->getSerializer()->deserialize($input);
            } catch (LinkValidationException $exception) {
                return InvalidLinkValue::fromInput($input, array_merge($formErrors, $exception->errors));
            }
        } else {
            $value = $this->valueFromInput($input);
        }

        // Every problem is reported at once, the form's and the link rules' alike.
        if ($formErrors !== []) {
            return InvalidLinkValue::fromInput($input, array_merge($formErrors, $value instanceof InvalidLinkValue ? $value->errors : []));
        }

        return $value instanceof InvalidLinkValue ? InvalidLinkValue::fromInput($input, $value->errors) : $value;
    }

    /**
     * Whether an element is part of a draft (a provisional draft included): a draft, or an element
     * nested in one, such as a Matrix entry of a draft, which is work in progress that may keep
     * what its author has not finished. Craft's own test, which defers to the root owner.
     */
    private static function isDraft(?ElementInterface $element): bool
    {
        return $element !== null && ElementHelper::isDraft($element);
    }

    private function typeName(string $handle): string
    {
        return $this->plugin()->getLinkTypes()->getType($handle)?->displayName() ?? $handle;
    }

    private static function errorMessage(ValidationError $error): string
    {
        if (preg_match('/^links\[(\d+)\]/', $error->path, $match)) {
            return Craft::t('smart-links', 'Link {number}: {message}', ['number' => (int)$match[1] + 1, 'message' => $error->getMessage()]);
        }

        return $error->getMessage();
    }

    private function links(): Links
    {
        return $this->plugin()->getLinks();
    }

    private function plugin(): SmartLinks
    {
        return SmartLinks::getInstance();
    }
}
