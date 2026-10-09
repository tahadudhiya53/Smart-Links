<?php

namespace Tahadudhiya\SmartLinks\services;

use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use Tahadudhiya\SmartLinks\enums\LinkFeature;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\links\LinkValidator;
use Tahadudhiya\SmartLinks\models\LinkPreset;
use Tahadudhiya\SmartLinks\models\ValidationError;
use Tahadudhiya\SmartLinks\SmartLinks;
use yii\base\InvalidConfigException;

/**
 * The link presets, defined in project config under `smartLinks.presets.<uid>`.
 *
 * Presets are configuration, so they live in project config and deploy with it, and project
 * config is their only storage: it is read each time rather than mirrored, so a change applied
 * from another environment is seen at once. A definition that is not exactly what a preset can be
 * is refused rather than partly read.
 *
 * A definition holds `name` (unique, ignoring case) and `sortOrder` (a whole number from 1, unique),
 * and optionally `enabled` (default on), `types` (link type handles; none means every type),
 * `urlSuffix`, `attributes` (in the stored form attributes have within a stored link) and `locked`
 * (feature names). Only what is set is written. It holds no IDs, so it means the same in every
 * environment.
 *
 * Reading checks everything a definition says on its own. What depends on the link types
 * registered now (that its types are registered and support what it sets and locks) is checked
 * when a preset is saved, and reported on the presets page, not when one is read: link types come
 * and go with plugins, and links made with a preset must stay readable meanwhile. A setting a
 * link's type lacks never reaches the link: the editor's input for it is disabled, a lock on it
 * does not apply, and the link core refuses it.
 */
class Presets extends Component
{
    public const CONFIG_KEY = SmartLinks::PROJECT_CONFIG_KEY . '.presets';

    /** The keys a preset definition can have. */
    private const KEYS = ['name', 'enabled', 'sortOrder', 'types', 'urlSuffix', 'attributes', 'locked'];

    /**
     * Every preset, by UID, in the order they are offered.
     *
     * @return array<string, LinkPreset>
     * @throws InvalidConfigException if a definition is malformed.
     */
    public function getAllPresets(): array
    {
        $config = Craft::$app->getProjectConfig()->get(self::CONFIG_KEY) ?? [];

        if (!is_array($config)) {
            throw new InvalidConfigException(sprintf('“%s” must map preset UIDs to preset definitions.', self::CONFIG_KEY));
        }

        $presets = [];
        $order = [];
        $names = [];

        foreach ($config as $uid => $definition) {
            $path = self::CONFIG_KEY . ".$uid";

            if (!is_string($uid) || !StringHelper::isUUID($uid)) {
                throw new InvalidConfigException(sprintf('“%s” is not keyed by a preset UID.', $path));
            }

            [$preset, $sortOrder] = $this->presetFromConfig($uid, $definition, $path);

            // Authors choose presets by name and in this order, so two cannot share either.
            $name = mb_strtolower($preset->name);

            if (isset($names[$name])) {
                throw new InvalidConfigException(sprintf('“%s” has the name of “%s”.', $path, self::CONFIG_KEY . '.' . $names[$name]));
            }

            if (in_array($sortOrder, $order, true)) {
                throw new InvalidConfigException(sprintf('“%s.sortOrder” is the place of another preset.', $path));
            }

            $names[$name] = $uid;
            $presets[$uid] = $preset;
            $order[$uid] = $sortOrder;
        }

        uksort($presets, static fn(string $a, string $b): int => $order[$a] <=> $order[$b]);

        return $presets;
    }

    /**
     * @throws InvalidConfigException if a definition is malformed.
     */
    public function getPresetByUid(string $uid): ?LinkPreset
    {
        return $this->getAllPresets()[$uid] ?? null;
    }

    /**
     * Reads a preset from what the preset form posts. Every setting is read explicitly; the
     * defaults are read by the link core, as a link's attributes are, and any problem with them
     * is reported when the preset is validated.
     *
     * @param array<mixed> $input `name`, `enabled`, `types`, `urlSuffix`, `attributes` (`target`,
     * `rel`, `class`, `download`, and `custom` rows of `name` and `value`) and `locked`, each
     * required. Nothing else is read, and nothing posted under these is dropped or coerced: what
     * does not fit is reported when the preset is validated.
     */
    public function presetFromInput(array $input, ?LinkPreset $preset = null): LinkPreset
    {
        $preset ??= new LinkPreset();
        $errors = [];

        // The form posts every setting, `''` for one left empty. One not posted at all is not
        // the same as an empty one, so a partial request never clears what it leaves out.
        foreach (['name', 'enabled', 'types', 'urlSuffix', 'attributes', 'locked'] as $key) {
            if (($input[$key] ?? null) === null) {
                $errors[] = new ValidationError($key === 'attributes' ? 'linkAttributes' : $key, Code::MISSING, '“{key}” is required.', ['key' => $key]);
            }
        }

        $preset->name = self::text($input, 'name', $errors) ?? '';
        $preset->types = self::list($input, 'types', $errors);
        $preset->locked = self::list($input, 'locked', $errors);
        $preset->urlSuffix = self::text($input, 'urlSuffix', $errors);

        // A lightswitch posts `'1'` or `''`.
        $enabled = $input['enabled'] ?? null;
        $preset->enabled = in_array($enabled, [true, 1, '1'], true);

        if ($enabled !== null && !$preset->enabled && !in_array($enabled, [false, 0, '0', ''], true)) {
            $errors[] = new ValidationError('enabled', Code::WRONG_TYPE, '“{key}” must be on or off.', ['key' => 'enabled']);
        }

        $attributes = $input['attributes'] ?? [];

        if ($attributes === '') {
            $attributes = [];
        } elseif (!is_array($attributes)) {
            $errors[] = new ValidationError('linkAttributes', Code::WRONG_TYPE, 'Link attributes must be an object.');
            $attributes = [];
        }

        // The form posts custom attributes as table rows, keyed by row, or `''` for none; a blank
        // row is no row. Everything else is read by the link core, which reports what no link
        // attribute is; what a preset may not set is reported when it is validated.
        if (($attributes['custom'] ?? null) === '') {
            unset($attributes['custom']);
        } elseif (is_array($attributes['custom'] ?? null)) {
            $attributes['custom'] = array_values(array_filter(
                $attributes['custom'],
                static fn(mixed $row): bool => !is_array($row) || array_diff(array_keys($row), ['name', 'value']) !== [] || ($row['name'] ?? '') !== '' || ($row['value'] ?? '') !== '',
            ));
        }

        [$preset->linkAttributes, $attributeErrors] = SmartLinks::getInstance()->getLinks()->getNormalizer()->readAttributes($attributes);
        $preset->setInputErrors(array_merge($errors, $attributeErrors));

        return $preset;
    }

    /**
     * Optional text from a form: `''` or not posted is not set; anything but text is reported.
     *
     * @param array<mixed> $input
     * @param list<ValidationError> $errors
     */
    private static function text(array $input, string $key, array &$errors): ?string
    {
        $value = $input[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            $errors[] = new ValidationError($key, Code::WRONG_TYPE, '“{key}” must be text.', ['key' => $key]);

            return null;
        }

        return $value;
    }

    /**
     * Saves a preset to project config. A new preset gets a UID and comes after the others.
     *
     * @throws InvalidConfigException if the existing definitions are malformed.
     */
    public function savePreset(LinkPreset $preset, bool $runValidation = true): bool
    {
        if ($runValidation && !$preset->validate()) {
            Craft::info('Link preset not saved due to validation errors.', __METHOD__);

            return false;
        }

        $presets = $this->getAllPresets();
        $existing = $preset->uid !== null ? $this->definition($preset->uid) : null;
        $preset->uid ??= StringHelper::UUID();

        $sortOrder = is_int($existing['sortOrder'] ?? null) ? $existing['sortOrder'] : $this->nextSortOrder($presets);

        Craft::$app->getProjectConfig()->set(self::CONFIG_KEY . ".{$preset->uid}", $this->configFor($preset, $sortOrder), "Save the “{$preset->name}” link preset");

        return true;
    }

    /**
     * Removes a preset from project config. Links made with it keep its UID, and are reported as
     * made with a preset that no longer exists until they are given another or none.
     */
    public function deletePreset(LinkPreset $preset): void
    {
        if ($preset->uid === null) {
            return;
        }

        Craft::$app->getProjectConfig()->remove(self::CONFIG_KEY . ".{$preset->uid}", "Delete the “{$preset->name}” link preset");
    }

    /**
     * Puts the presets in this order. Every preset must be named exactly once.
     *
     * @param list<string> $uids
     * @throws \InvalidArgumentException if the UIDs are not exactly every preset's.
     */
    public function reorderPresets(array $uids): void
    {
        $presets = $this->getAllPresets();
        $sorted = $uids;
        $all = array_keys($presets);
        sort($sorted);
        sort($all);

        if ($sorted !== $all) {
            throw new \InvalidArgumentException('Reordering presets must name every preset exactly once.');
        }

        $projectConfig = Craft::$app->getProjectConfig();

        foreach ($uids as $index => $uid) {
            $definition = $this->definition($uid) ?? [];

            if (($definition['sortOrder'] ?? null) !== $index + 1) {
                $projectConfig->set(self::CONFIG_KEY . ".$uid.sortOrder", $index + 1, 'Reorder link presets');
            }
        }
    }

    /**
     * A preset's definition as project config holds it.
     *
     * @return array<string, mixed>
     * @throws LinkValidationException if the preset's defaults break the link rules.
     */
    public function configFor(LinkPreset $preset, ?int $sortOrder = null): array
    {
        return array_filter([
            'name' => $preset->name,
            'enabled' => $preset->enabled,
            'sortOrder' => $sortOrder,
            'types' => $preset->types,
            'urlSuffix' => $preset->urlSuffix,
            'attributes' => SmartLinks::getInstance()->getLinks()->getSerializer()->serializeAttributes($preset->linkAttributes),
            'locked' => $preset->locked,
        ], static fn(mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array{LinkPreset, int}
     * @throws InvalidConfigException
     */
    private function presetFromConfig(string $uid, mixed $definition, string $path): array
    {
        if (!is_array($definition)) {
            throw new InvalidConfigException(sprintf('“%s” must be a preset definition.', $path));
        }

        $unknown = array_diff(array_map('strval', array_keys($definition)), self::KEYS);

        if ($unknown !== []) {
            throw new InvalidConfigException(sprintf('“%s” has properties no preset has: %s.', $path, implode(', ', $unknown)));
        }

        $name = $definition['name'] ?? null;

        if (!is_string($name) || trim($name) === '') {
            throw new InvalidConfigException(sprintf('“%s” needs a name.', $path));
        }

        $enabled = $definition['enabled'] ?? true;
        $sortOrder = $definition['sortOrder'] ?? null;

        if (!is_bool($enabled)) {
            throw new InvalidConfigException(sprintf('“%s.enabled” must be true or false.', $path));
        }

        if (!is_int($sortOrder) || $sortOrder < 1) {
            throw new InvalidConfigException(sprintf('“%s.sortOrder” must be a whole number from 1.', $path));
        }

        $types = $this->configList($definition, 'types', $path, static fn(string $handle): bool => LinkValidator::isTypeHandle($handle));
        $lockable = array_map(static fn(LinkFeature $feature): string => $feature->value, LinkPreset::LOCKABLE_FEATURES);
        $locked = $this->configList($definition, 'locked', $path, static fn(string $feature): bool => in_array($feature, $lockable, true));

        $urlSuffix = $definition['urlSuffix'] ?? null;
        $links = SmartLinks::getInstance()->getLinks();

        if ($urlSuffix !== null && (!is_string($urlSuffix) || $links->getValidator()->validateUrlSuffix($urlSuffix) !== [])) {
            throw new InvalidConfigException(sprintf('“%s.urlSuffix” is not a URL suffix.', $path));
        }

        try {
            $attributes = $links->getSerializer()->deserializeAttributes($definition['attributes'] ?? []);
        } catch (LinkValidationException $exception) {
            throw new InvalidConfigException(sprintf('“%s.attributes” are not valid link attributes: %s', $path, $exception->getMessage()), 0, $exception);
        }

        $preset = new LinkPreset([
            'uid' => $uid,
            'name' => $name,
            'enabled' => $enabled,
            'types' => $types,
            'urlSuffix' => $urlSuffix,
            'linkAttributes' => $attributes,
            'locked' => $locked,
        ]);

        $notDefault = array_filter($preset->defaultFeatures(), static fn(LinkFeature $feature): bool => !in_array($feature, LinkPreset::DEFAULT_FEATURES, true));

        if ($notDefault !== [] || $attributes->downloadFilename !== null) {
            throw new InvalidConfigException(sprintf('“%s.attributes” set what describes one link, which a preset can’t.', $path));
        }

        return [$preset, $sortOrder];
    }

    /**
     * A list of distinct strings that each pass a check.
     *
     * @param array<mixed> $definition
     * @param callable(string): bool $check
     * @return list<string>
     * @throws InvalidConfigException
     */
    private function configList(array $definition, string $key, string $path, callable $check): array
    {
        $value = $definition[$key] ?? [];

        if (!is_array($value) || !array_is_list($value) || array_filter($value, static fn(mixed $item): bool => is_string($item) && $check($item)) !== $value || count(array_unique($value)) !== count($value)) {
            throw new InvalidConfigException(sprintf('“%s.%s” must be a list of distinct valid values.', $path, $key));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function definition(string $uid): ?array
    {
        $definition = Craft::$app->getProjectConfig()->get(self::CONFIG_KEY . ".$uid");

        return is_array($definition) ? $definition : null;
    }

    /**
     * @param array<string, LinkPreset> $presets
     */
    private function nextSortOrder(array $presets): int
    {
        $max = 0;

        foreach (array_keys($presets) as $uid) {
            $max = max($max, (int)$this->definition($uid)['sortOrder']);
        }

        return $max + 1;
    }

    /**
     * What a form posts for a list: the values of the checked boxes, or `''` for none. Anything
     * else is reported, not coerced.
     *
     * @param array<mixed> $input
     * @param list<ValidationError> $errors
     * @return list<string>
     */
    private static function list(array $input, string $key, array &$errors): array
    {
        $value = $input[$key] ?? '';

        if ($value === '' || $value === []) {
            return [];
        }

        if (!is_array($value) || !array_is_list($value) || array_filter($value, 'is_string') !== $value) {
            $errors[] = new ValidationError($key, Code::WRONG_TYPE, '“{key}” must be text or a list of words.', ['key' => $key]);

            return [];
        }

        return $value;
    }
}
