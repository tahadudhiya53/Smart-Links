<?php

namespace Tahadudhiya\SmartLinks\controllers;

use Craft;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Tahadudhiya\SmartLinks\models\LinkPreset;
use Tahadudhiya\SmartLinks\services\Presets;
use Tahadudhiya\SmartLinks\SmartLinks;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Link presets in the control panel: list, create, edit, enable or disable, reorder and delete.
 *
 * Every action needs the “Manage link presets” permission. Presets are project config, so
 * changing them also needs an environment that allows administrative changes, as Craft's own
 * settings do; elsewhere they can be looked at, not changed.
 */
class PresetsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(SmartLinks::PERMISSION_MANAGE_PRESETS);

        return true;
    }

    public function actionIndex(): Response
    {
        $presets = $this->presets()->getAllPresets();
        $problems = [];

        // What a preset needs of the link types registered now is checked when it is saved; the
        // list says which presets no longer have it (e.g. a type a plugin no longer provides).
        foreach ($presets as $uid => $preset) {
            if (!$preset->validate()) {
                $problems[$uid] = array_merge(...array_values($preset->getErrors()));
            }
        }

        return $this->renderTemplate('smart-links/presets/_index', [
            'presets' => $presets,
            'problems' => $problems,
            'typeNames' => $this->typeNames(),
            'settingNames' => LinkPreset::settingNames(),
            'readOnly' => !self::changesAllowed(),
        ]);
    }

    /**
     * @param LinkPreset|null $preset A preset that failed to save, shown again with its errors.
     * @throws NotFoundHttpException if there is no preset with that UID.
     */
    public function actionEdit(?string $presetUid = null, ?LinkPreset $preset = null): Response
    {
        if ($preset === null && $presetUid !== null) {
            $preset = $this->presets()->getPresetByUid($presetUid) ?? throw new NotFoundHttpException('Link preset not found.');
        }

        $preset ??= new LinkPreset();

        return $this->renderTemplate('smart-links/presets/_edit', [
            'preset' => $preset,
            'isNew' => $preset->uid === null,
            'typeNames' => $this->typeNames(),
            'lockableSettings' => LinkPreset::lockableSettingNames(),
            'readOnly' => !self::changesAllowed(),
        ]);
    }

    /**
     * @throws BadRequestHttpException if the preset UID is not text.
     * @throws ForbiddenHttpException if administrative changes are not allowed here.
     * @throws NotFoundHttpException if the preset being edited no longer exists.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireChangesAllowed();

        $uid = $this->request->getBodyParam('presetUid');
        $preset = null;

        // A new preset posts an empty UID; anything but a UID of an existing preset is refused,
        // never taken as a new preset.
        if (!is_string($uid)) {
            throw new BadRequestHttpException('The preset UID must be text.');
        }

        if ($uid !== '') {
            $preset = $this->presets()->getPresetByUid($uid) ?? throw new NotFoundHttpException('Link preset not found.');
        }

        $input = [];

        foreach (['name', 'enabled', 'types', 'urlSuffix', 'attributes', 'locked'] as $key) {
            $input[$key] = $this->request->getBodyParam($key);
        }

        $preset = $this->presets()->presetFromInput($input, $preset);

        if (!$this->presets()->savePreset($preset)) {
            return $this->asModelFailure($preset, Craft::t('smart-links', 'Couldn’t save the preset.'), 'preset');
        }

        return $this->asModelSuccess($preset, Craft::t('smart-links', 'Preset saved.'), 'preset', [], UrlHelper::cpUrl('smart-links/presets'));
    }

    /**
     * @throws ForbiddenHttpException if administrative changes are not allowed here.
     * @throws NotFoundHttpException if there is no preset with that UID.
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireChangesAllowed();

        $uid = $this->request->getRequiredBodyParam('id');
        $preset = is_string($uid) ? $this->presets()->getPresetByUid($uid) : null;

        if ($preset === null) {
            throw new NotFoundHttpException('Link preset not found.');
        }

        $this->presets()->deletePreset($preset);

        return $this->asSuccess();
    }

    /**
     * @throws ForbiddenHttpException if administrative changes are not allowed here.
     * @throws BadRequestHttpException if the order does not name every preset exactly once.
     */
    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireChangesAllowed();

        $uids = Json::decodeIfJson($this->request->getRequiredBodyParam('ids'));

        if (!is_array($uids) || !array_is_list($uids) || array_filter($uids, 'is_string') !== $uids) {
            throw new BadRequestHttpException('The order must be a list of preset UIDs.');
        }

        try {
            $this->presets()->reorderPresets($uids);
        } catch (\InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), 0, $exception);
        }

        return $this->asSuccess();
    }

    private static function changesAllowed(): bool
    {
        return Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
    }

    /**
     * @throws ForbiddenHttpException
     */
    private function requireChangesAllowed(): void
    {
        if (!self::changesAllowed()) {
            throw new ForbiddenHttpException('Administrative changes are disallowed in this environment.');
        }
    }

    /**
     * The registered link types' names, by handle.
     *
     * @return array<string, string>
     */
    private function typeNames(): array
    {
        $types = SmartLinks::getInstance()->getLinkTypes()->getTypeSet();
        $names = [];

        foreach ($types->handles() as $handle) {
            $names[$handle] = $types->get($handle)?->displayName() ?? $handle;
        }

        return $names;
    }

    private function presets(): Presets
    {
        return SmartLinks::getInstance()->getPresets();
    }
}
