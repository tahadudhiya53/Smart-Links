<?php

namespace Tahadudhiya\SmartLinks\services;

use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use Tahadudhiya\SmartLinks\models\LinkPreset;
use yii\base\InvalidConfigException;

/**
 * The link presets defined in project config, under `smartLinks.presets.<uid>`.
 *
 * Presets are configuration, so they live in project config and deploy with it. This service
 * reads them; a definition that is not exactly what a preset can be is refused rather than
 * partly read.
 */
class Presets extends Component
{
    public const CONFIG_KEY = 'smartLinks.presets';

    /** The keys a preset definition has. */
    private const KEYS = ['name'];

    /**
     * Every preset, by UID, in the order project config holds them.
     *
     * Project config is read each time rather than remembered, so a change to it is seen at once.
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

        foreach ($config as $uid => $definition) {
            $path = self::CONFIG_KEY . ".$uid";

            if (!is_string($uid) || !StringHelper::isUUID($uid)) {
                throw new InvalidConfigException(sprintf('“%s” is not keyed by a preset UID.', $path));
            }

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

            $presets[$uid] = new LinkPreset($uid, $name);
        }

        return $presets;
    }

    /**
     * @throws InvalidConfigException if a definition is malformed.
     */
    public function getPresetByUid(string $uid): ?LinkPreset
    {
        return $this->getAllPresets()[$uid] ?? null;
    }
}
