<?php

namespace Tahadudhiya\SmartLinks\models;

use Craft;
use craft\base\Model;
use Tahadudhiya\SmartLinks\enums\LinkFeature;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\SmartLinks;

/**
 * A link preset: reusable authoring configuration, defined in project config.
 *
 * A preset says which link types it is for, which settings a link made with it starts with, and
 * which of those settings it locks. It is applied when an author chooses it: the editor fills in
 * its defaults where the link has nothing yet. What it filled in is then the link's own, so a
 * link's value never depends on a preset, and changing a preset never changes links already
 * made. A link refers to the preset it was made with by UID only.
 *
 * Locked settings are the one rule a preset adds to its links: while a link has the preset, each
 * setting it locks must be exactly the preset's (or, if the preset does not set it, empty). That
 * is checked, never applied silently.
 *
 * Only settings that describe a kind of link can be defaults. A title, ID, ARIA label or download
 * filename describes one link, and would be wrong, or duplicated on a page, as a default.
 */
class LinkPreset extends Model
{
    /** The features a preset can set for its links. */
    public const DEFAULT_FEATURES = [
        LinkFeature::URL_SUFFIX,
        LinkFeature::TARGET,
        LinkFeature::REL,
        LinkFeature::CLASS_NAMES,
        LinkFeature::DOWNLOAD,
        LinkFeature::CUSTOM_ATTRIBUTES,
    ];

    /**
     * The features a preset can lock. Custom attributes are a list authors extend, so they can be
     * defaults but not locked.
     */
    public const LOCKABLE_FEATURES = [
        LinkFeature::URL_SUFFIX,
        LinkFeature::TARGET,
        LinkFeature::REL,
        LinkFeature::CLASS_NAMES,
        LinkFeature::DOWNLOAD,
    ];

    /** The preset's settings, as the preset form shows them and its errors are reported at. */
    private const SETTINGS = ['name', 'enabled', 'types', 'urlSuffix', 'target', 'rel', 'class', 'download', 'custom', 'locked'];

    public ?string $uid = null;

    public string $name = '';

    /** Whether authors are offered the preset. Links already made with a disabled one keep it. */
    public bool $enabled = true;

    /** @var list<string> Handles of the link types the preset is for, in order; none means every type. */
    public array $types = [];

    /** The URL suffix links start with. */
    public ?string $urlSuffix = null;

    /** The attributes links start with: only those of {@see DEFAULT_FEATURES}. */
    public LinkAttributes $linkAttributes;

    /** @var list<string> Values of the {@see LOCKABLE_FEATURES} the preset locks. */
    public array $locked = [];

    /** @var list<ValidationError> Problems with the shape of the input the defaults were read from. */
    private array $inputErrors = [];

    public function __construct($config = [])
    {
        $this->linkAttributes = new LinkAttributes();
        parent::__construct($config);
    }

    /**
     * The settings a preset can set, by feature, named as the control panel names them.
     *
     * @return array<string, string>
     */
    public static function settingNames(): array
    {
        $names = [
            LinkFeature::URL_SUFFIX->value => Craft::t('smart-links', 'URL suffix'),
            LinkFeature::TARGET->value => Craft::t('smart-links', 'Target'),
            LinkFeature::REL->value => Craft::t('smart-links', 'Relationship (rel)'),
            LinkFeature::CLASS_NAMES->value => Craft::t('smart-links', 'CSS classes'),
            LinkFeature::DOWNLOAD->value => Craft::t('smart-links', 'Download'),
            LinkFeature::CUSTOM_ATTRIBUTES->value => Craft::t('smart-links', 'Custom attributes'),
        ];

        return array_intersect_key($names, array_flip(array_map(static fn(LinkFeature $feature): string => $feature->value, self::DEFAULT_FEATURES)));
    }

    /**
     * The settings a preset can lock, by feature, named as the control panel names them.
     *
     * @return array<string, string>
     */
    public static function lockableSettingNames(): array
    {
        return array_intersect_key(self::settingNames(), array_flip(array_map(static fn(LinkFeature $feature): string => $feature->value, self::LOCKABLE_FEATURES)));
    }

    /**
     * Problems found reading the defaults from input, reported when the preset is validated.
     *
     * @param list<ValidationError> $errors
     */
    public function setInputErrors(array $errors): void
    {
        $this->inputErrors = $errors;
    }

    /**
     * The features the preset gives its links a value for, in a fixed order.
     *
     * @return list<LinkFeature>
     */
    public function defaultFeatures(): array
    {
        $features = $this->linkAttributes->usedFeatures();

        return $this->urlSuffix !== null ? [LinkFeature::URL_SUFFIX, ...$features] : $features;
    }

    /**
     * @return list<LinkFeature>
     */
    public function lockedFeatures(): array
    {
        return array_values(array_filter(array_map(static fn(string $value): ?LinkFeature => LinkFeature::tryFrom($value), $this->locked)));
    }

    /**
     * Whether links of a type can be made with the preset.
     */
    public function isForType(string $handle): bool
    {
        return $this->types === [] || in_array($handle, $this->types, true);
    }

    /**
     * What the preset refuses in a link made with it: a link type it is not for, or a locked
     * setting that is not the preset's. A locked feature the link's type does not have does not
     * apply to it. Nothing is changed; each problem is reported where it is.
     *
     * @param list<LinkFeature> $supported The features of the link's type.
     * @return list<ValidationError>
     */
    public function linkProblems(LinkValue $link, string $path, string $typeName, array $supported): array
    {
        if (!$this->isForType($link->type)) {
            return [new ValidationError("$path.presetUid", Code::NOT_SUPPORTED, 'The “{preset}” preset is not for {type} links.', ['preset' => $this->name, 'type' => $typeName])];
        }

        $problems = [];

        foreach ($this->lockedFeatures() as $feature) {
            if (!in_array($feature, $supported, true) || self::featureValue($feature, $link->urlSuffix, $link->attributes) === self::featureValue($feature, $this->urlSuffix, $this->linkAttributes)) {
                continue;
            }

            $featurePath = $feature === LinkFeature::URL_SUFFIX ? "$path.urlSuffix" : "$path.attributes.{$feature->value}";
            $problems[] = new ValidationError($featurePath, Code::INVALID, '“{feature}” is locked by the “{preset}” preset, so it must stay as the preset sets it.', ['feature' => $feature->value, 'preset' => $this->name]);
        }

        return $problems;
    }

    /**
     * A feature's value as a lock compares it. `rel` and class names are sets of words, so their
     * order does not matter; everything else is compared exactly.
     */
    private static function featureValue(LinkFeature $feature, ?string $urlSuffix, LinkAttributes $attributes): mixed
    {
        $sorted = static function(array $tokens): array {
            sort($tokens, SORT_STRING);

            return $tokens;
        };

        return match ($feature) {
            LinkFeature::URL_SUFFIX => $urlSuffix,
            LinkFeature::TARGET => $attributes->target,
            LinkFeature::REL => $sorted($attributes->rel),
            LinkFeature::CLASS_NAMES => $sorted($attributes->class),
            LinkFeature::DOWNLOAD => $attributes->download,
            default => throw new \LogicException("“{$feature->value}” cannot be locked."),
        };
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['name'], 'trim'];
        $rules[] = [['name'], 'required'];
        $rules[] = [['name'], 'string', 'max' => 255];
        $rules[] = [['name'], 'validateUniqueName'];
        $rules[] = [['types'], 'validateTypes', 'skipOnEmpty' => false];
        $rules[] = [['linkAttributes'], 'validateDefaults', 'skipOnEmpty' => false];
        $rules[] = [['locked'], 'validateLocked', 'skipOnEmpty' => false];

        return $rules;
    }

    public function attributeLabels(): array
    {
        return [
            'name' => Craft::t('smart-links', 'Name'),
            'types' => Craft::t('smart-links', 'Link types'),
            'locked' => Craft::t('smart-links', 'Locked settings'),
        ];
    }

    public function validateUniqueName(string $attribute): void
    {
        foreach (SmartLinks::getInstance()->getPresets()->getAllPresets() as $preset) {
            if ($preset->uid !== $this->uid && mb_strtolower($preset->name) === mb_strtolower($this->name)) {
                $this->addError($attribute, Craft::t('smart-links', 'Another preset is already called “{name}”.', ['name' => $preset->name]));
            }
        }
    }

    public function validateTypes(string $attribute): void
    {
        $registered = SmartLinks::getInstance()->getLinkTypes()->getTypeSet();

        foreach ($this->types as $handle) {
            $type = $registered->get($handle);

            if ($type === null) {
                $this->addError($attribute, Craft::t('smart-links', '“{type}” is not an available link type.', ['type' => $handle]));

                continue;
            }

            // A preset for a type must be able to give links of that type everything it sets.
            foreach (array_unique([...$this->defaultFeatures(), ...$this->lockedFeatures()], SORT_REGULAR) as $feature) {
                if (!in_array($feature, $type->supportedFeatures(), true)) {
                    $this->addError($attribute, Craft::t('smart-links', '{type} links have no “{feature}”, which this preset sets.', ['type' => $type->displayName(), 'feature' => $feature->value]));
                }
            }
        }

        if (count(array_unique($this->types)) !== count($this->types)) {
            $this->addError($attribute, Craft::t('smart-links', 'Each link type can only be chosen once.'));
        }
    }

    /**
     * The defaults are held to the link rules, by the link core, and to what a preset may set.
     */
    public function validateDefaults(): void
    {
        $validator = SmartLinks::getInstance()->getLinks()->getValidator();
        $errors = $this->inputErrors;

        if ($this->urlSuffix !== null) {
            array_push($errors, ...$validator->validateUrlSuffix($this->urlSuffix, 'urlSuffix'));
        }

        array_push($errors, ...$validator->validateAttributes($this->linkAttributes, null));

        foreach ($this->linkAttributes->usedFeatures() as $feature) {
            if (!in_array($feature, self::DEFAULT_FEATURES, true)) {
                $errors[] = new ValidationError($feature->value, Code::NOT_SUPPORTED, '“{feature}” describes one link, so a preset can’t set it.', ['feature' => $feature->value]);
            }
        }

        if ($this->linkAttributes->downloadFilename !== null) {
            $errors[] = new ValidationError('downloadFilename', Code::NOT_SUPPORTED, '“{feature}” describes one link, so a preset can’t set it.', ['feature' => 'downloadFilename']);
        }

        foreach ($errors as $error) {
            // Each problem is shown at the setting it is about (`rel[1]` and `custom.data-x` at
            // theirs); anything else (the defaults as a whole, an attribute a preset has no
            // setting for) at the defaults.
            $setting = preg_match('/^[a-zA-Z]+/', $error->path, $match) && in_array($match[0], self::SETTINGS, true) ? $match[0] : 'linkAttributes';
            $this->addError($setting, $error->getMessage());
        }
    }

    public function validateLocked(string $attribute): void
    {
        $lockable = array_map(static fn(LinkFeature $feature): string => $feature->value, self::LOCKABLE_FEATURES);

        foreach ($this->locked as $value) {
            if (!in_array($value, $lockable, true)) {
                $this->addError($attribute, Craft::t('smart-links', '“{feature}” can’t be locked.', ['feature' => $value]));
            }
        }

        if (count(array_unique($this->locked)) !== count($this->locked)) {
            $this->addError($attribute, Craft::t('smart-links', 'Each setting can only be locked once.'));
        }
    }
}
