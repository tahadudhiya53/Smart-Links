<?php

namespace Tahadudhiya\SmartLinks\controllers;

use Craft;
use craft\web\Controller;
use InvalidArgumentException;
use LogicException;
use Tahadudhiya\SmartLinks\enums\HealthState;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;
use Tahadudhiya\SmartLinks\models\InventoryCriteria;
use Tahadudhiya\SmartLinks\models\UsageCriteria;
use Tahadudhiya\SmartLinks\services\Index;
use Tahadudhiya\SmartLinks\SmartLinks;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The link inventory: every link target Smart Link fields use, searchable, filterable and
 * sortable, a page at a time; where each one is used; and rebuilding the index it is read from.
 *
 * Viewing it needs the “View the link inventory” permission; seeing where a target is used, and
 * rebuilding the index, each need a permission nested under it.
 */
class LinksController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(SmartLinks::PERMISSION_VIEW_INVENTORY);

        return true;
    }

    /**
     * @throws BadRequestHttpException if a filter, sort or page parameter is malformed.
     */
    public function actionIndex(): Response
    {
        try {
            $criteria = InventoryCriteria::fromParams($this->request->getQueryParams());
        } catch (InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), 0, $exception);
        }

        $index = $this->index();
        $types = SmartLinks::getInstance()->getLinkTypes()->getTypeSet();
        $typeOptions = [];

        foreach ($types->handles() as $handle) {
            $typeOptions[$handle] = ($types->get($handle) ?? throw new LogicException("The link type “{$handle}” is listed but not registered."))->displayName();
        }

        $sourceOptions = [];

        foreach (Craft::$app->getFields()->getFieldsByType(SmartLinkField::class) as $field) {
            $sourceOptions[(string)$field->handle] = $field->name;
        }

        return $this->renderTemplate('smart-links/links/_index', [
            'criteria' => $criteria,
            'inventory' => $index->inventory($criteria),
            'status' => $index->status(),
            'canRebuild' => Craft::$app->getUser()->checkPermission(SmartLinks::PERMISSION_REBUILD_INDEX),
            'canViewUsage' => Craft::$app->getUser()->checkPermission(SmartLinks::PERMISSION_VIEW_USAGE),
            'typeOptions' => $typeOptions,
            'sourceOptions' => $sourceOptions,
            'healthStates' => HealthState::cases(),
            'targetStates' => ResolutionStatus::cases(),
        ]);
    }

    /**
     * Where one link target is used: every occurrence, with the element, site and field holding
     * it, a page at a time.
     *
     * Two kinds of not found, said apart: an ID the index has no target for (never had, or removed
     * once nothing used it), and a target the index still holds that nothing uses any more, which
     * the next pruning removes.
     *
     * @throws BadRequestHttpException if a filter or page parameter is malformed.
     * @throws NotFoundHttpException if the index has no such target, or nothing uses it any more.
     */
    public function actionUsage(int $indexId): Response
    {
        $this->requirePermission(SmartLinks::PERMISSION_VIEW_USAGE);

        try {
            $criteria = UsageCriteria::fromParams($this->request->getQueryParams());
        } catch (InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), 0, $exception);
        }

        try {
            $usage = SmartLinks::getInstance()->getUsage()->ofTarget($indexId, $criteria);
        } catch (InvalidArgumentException $exception) {
            throw new NotFoundHttpException(Craft::t('smart-links', 'There is no such link target.'), 0, $exception);
        }

        $target = $this->index()->inventoryItem($indexId) ?? throw new NotFoundHttpException(Craft::t('smart-links', 'No link uses this target any more.'));
        $sourceOptions = [];

        foreach (Craft::$app->getFields()->getFieldsByType(SmartLinkField::class) as $field) {
            $sourceOptions[(string)$field->handle] = $field->name;
        }

        return $this->renderTemplate('smart-links/links/_usage', [
            'target' => $target,
            'criteria' => $criteria,
            'usage' => $usage,
            'sourceOptions' => $sourceOptions,
        ]);
    }

    public function actionRebuild(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(SmartLinks::PERMISSION_REBUILD_INDEX);

        $this->index()->queueRebuild();

        return $this->asSuccess(Craft::t('smart-links', 'The link index is being rebuilt. The inventory stays available meanwhile.'), redirect: 'smart-links/links');
    }

    private function index(): Index
    {
        return SmartLinks::getInstance()->getIndex();
    }
}
