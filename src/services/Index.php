<?php

namespace Tahadudhiya\SmartLinks\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Address;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Json;
use craft\helpers\Queue;
use craft\models\FieldLayout;
use DateTime;
use InvalidArgumentException;
use LogicException;
use Tahadudhiya\SmartLinks\enums\HealthState;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;
use Tahadudhiya\SmartLinks\errors\IndexLockedException;
use Tahadudhiya\SmartLinks\errors\LinkResolutionException;
use Tahadudhiya\SmartLinks\errors\TargetHashCollisionException;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;
use Tahadudhiya\SmartLinks\jobs\RebuildIndex;
use Tahadudhiya\SmartLinks\jobs\UpdateIndex;
use Tahadudhiya\SmartLinks\models\CanonicalUrl;
use Tahadudhiya\SmartLinks\models\IndexingResult;
use Tahadudhiya\SmartLinks\models\IndexStatus;
use Tahadudhiya\SmartLinks\models\InvalidLinkValue;
use Tahadudhiya\SmartLinks\models\InventoryCriteria;
use Tahadudhiya\SmartLinks\models\InventoryItem;
use Tahadudhiya\SmartLinks\models\InventoryPage;
use Tahadudhiya\SmartLinks\models\LinkCollection;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\TargetIdentity;
use Tahadudhiya\SmartLinks\records\HealthRecord;
use Tahadudhiya\SmartLinks\records\IndexRecord;
use Tahadudhiya\SmartLinks\records\SourceRecord;
use Tahadudhiya\SmartLinks\records\UsageRecord;
use Tahadudhiya\SmartLinks\SmartLinks;
use WeakMap;
use yii\base\Component;
use yii\db\Expression;
use yii\db\IntegrityException;

/**
 * The link index: the one writer of `smartlinks_index`, `smartlinks_usage` and
 * `smartlinks_sources`.
 *
 * Everything here is derived from Smart Link field content, the only source the index reads,
 * which stays the source of truth: the index reads content and never writes it, so any of its
 * rows can be thrown away and built again. One pass over an element's content writes its usage
 * rows (one per link occurrence), finds or creates the target row each points at, and records
 * which version of the element it read (its source row), so content saved since can be found.
 *
 * A source is an element site of an element whose field layout holds a Smart Link field, that
 * is neither a draft, a revision nor in the trash, in a site that is not deleted. Of those, only
 * canonical content contributes links: an element nested in a draft or revision is recorded as
 * read, with none.
 *
 * **Every write holds one lock** ({@see LOCK}), in every process: an element's whole pass (its
 * targets found or created, its usage and source rows written), resolving targets again, and
 * every removal. A target is created before the usage that points at it, and the databases read a
 * `DELETE … WHERE NOT EXISTS` against a snapshot, so a removal running beside a pass could delete
 * a target the pass had just created, or, through the usage cascade, usage it had just written.
 * Holding one lock for both makes that impossible. Reading (the inventory, the status) takes no
 * lock and writes nothing.
 *
 * Every write is idempotent: indexing the same content again changes nothing, and nothing is
 * ever added twice, because usage, sources and targets each have a unique key and are replaced or
 * found by it.
 */
class Index extends Component
{
    /** Elements read per batch: per rebuild job, and per update job at most. */
    public const BATCH_SIZE = 100;

    /** The lock every write to the index holds. */
    public const LOCK = 'smartlinks:index';

    /**
     * @var int How long a write waits for another process's write to finish, in seconds, before
     * it gives up with {@see IndexLockedException}.
     */
    public int $lockTimeout = 60;

    /**
     * Element types whose elements all use the one layout of their type, whatever their
     * `fieldLayoutId` (which Craft leaves empty for them): `getFieldLayout()` of each reads the
     * layout by type. Any other element type is in the layout its `fieldLayoutId` names.
     */
    private const LAYOUT_BY_TYPE = [User::class, Address::class];

    /** The longest text a TEXT column holds whole on every supported database. */
    private const MAX_TEXT_BYTES = 65535;

    /** @var array<int, true> Targets resolved in the current batch, so each is resolved once in it. */
    private array $resolved = [];

    /** @var WeakMap<ElementInterface, true> Elements whose own save is running. */
    private WeakMap $saving;

    /** @var array<int, string> What a layout's Smart Link fields were before it was saved, by layout ID. */
    private array $layoutsBefore = [];

    /** @var array<string, bool> Whether a field was a Smart Link field before a save of it was applied, by UID. */
    private array $fieldsBefore = [];

    public function init(): void
    {
        parent::init();
        $this->saving = new WeakMap();
    }

    /**
     * Returns the ID of the index row for a target, creating the row if there is none. A row no
     * usage points at is removed by the next pruning, as any unused target is.
     *
     * @throws TargetHashCollisionException if a different target is stored under the same hash.
     * @throws IntegrityException if the insert is refused and no row explains it (see
     * {@see insertTarget()}).
     * @throws IndexLockedException if another process held the index's lock for too long.
     */
    public function targetId(TargetIdentity $identity): int
    {
        return $this->locked(fn(): int => $this->target($identity)->id);
    }

    // Content changes

    /**
     * Notes that an element's own save has begun, so the copies Craft saves of it in its other
     * sites meanwhile are known to be part of it.
     */
    public function elementSaving(ElementInterface $element): void
    {
        if (!$element->propagating) {
            $this->saving[$element] = true;
        }
    }

    /**
     * Queues what an element's change means for the index: reading its content again if it is a
     * source or was one, and resolving again the targets that point at it.
     *
     * Craft calls this for every save, deletion, restore and URI change. Drafts and revisions are
     * neither sources nor targets: a draft's links count once it is applied, which saves the
     * canonical element. A copy saved in another site as part of its element's own save is
     * covered by that save's update, which reads every site; one saved on its own (Craft
     * propagating an element to a site added since) is queued itself.
     */
    public function elementChanged(ElementInterface $element): void
    {
        if ($element->id === null || !$element->getIsCanonical()) {
            return;
        }

        if ($element->propagating) {
            if ($element->propagatingFrom !== null && isset($this->saving[$element->propagatingFrom])) {
                return;
            }
        } else {
            unset($this->saving[$element]);
        }

        $id = (int)$element->id;

        if (self::smartLinkFields($element->getFieldLayout()) !== [] || $this->isIndexed($id)) {
            Queue::push(new UpdateIndex(['elementIds' => [$id]]));
        }
    }

    /**
     * Brings the index up to date with elements as they are now: each one's content is read
     * again in every site it is a source in, its rows in sites where it no longer is one are
     * removed, targets that point at it are resolved again, and targets nothing uses any more
     * are removed.
     *
     * @param list<int> $elementIds At most {@see BATCH_SIZE}.
     * @throws InvalidArgumentException for more elements than one batch.
     * @throws IndexLockedException if another process held the index's lock for too long.
     */
    public function updateElements(array $elementIds, ?IndexingResult $result = null): IndexingResult
    {
        $elementIds = array_values(array_unique(array_map('intval', $elementIds)));

        if (count($elementIds) > self::BATCH_SIZE) {
            throw new InvalidArgumentException(sprintf('At most %d elements are updated at once.', self::BATCH_SIZE));
        }

        $result ??= new IndexingResult();
        $this->resolved = [];
        $affected = [];

        foreach ($elementIds as $elementId) {
            array_push($affected, ...$this->indexElement($elementId, false, $result));
        }

        $this->refreshTargetsOf($elementIds, $result);
        $this->pruneTargets($affected, $result);

        // An element deleted for good took its usage rows with it (they cascade), so which targets
        // it used can no longer be told: every unused target is removed instead.
        $existing = (new Query())->from(Table::ELEMENTS)->where(['id' => $elementIds])->count();

        if ((int)$existing < count($elementIds)) {
            $result->removedTargets += $this->pruneUnusedTargets();
        }

        return $result;
    }

    /**
     * Removes every target nothing uses any more, however its usage went: Craft's garbage
     * collection, for one, deletes trashed elements and sites without element events, and their
     * usage rows go with them. Health is left alone: it is kept by URL.
     *
     * @return int How many targets were removed.
     * @throws IndexLockedException if another process held the index's lock for too long.
     */
    public function pruneUnusedTargets(): int
    {
        return $this->locked(static fn(): int => self::deleteUnusedTargets());
    }

    /**
     * Deletes every target no usage points at. Only ever run under the index's lock.
     */
    private static function deleteUnusedTargets(): int
    {
        return Db::delete(IndexRecord::TABLE, ['not exists', self::usageOf(IndexRecord::TABLE . '.[[id]]')]);
    }

    // Changes to where Smart Link fields are

    /**
     * Notes which Smart Link fields a layout had, as stored, before it is saved.
     */
    public function layoutSaving(FieldLayout $layout): void
    {
        if ($layout->id !== null) {
            $this->layoutsBefore[(int)$layout->id] = $this->storedLayoutSignature((int)$layout->id);
        }
    }

    /**
     * Queues reading again every element of a layout whose Smart Link fields changed: added,
     * removed or replaced. Their elements were not saved, so nothing else would show the index
     * that their links changed.
     */
    public function layoutSaved(FieldLayout $layout): void
    {
        if ($layout->id === null) {
            return;
        }

        $before = $this->layoutsBefore[(int)$layout->id] ?? '';
        unset($this->layoutsBefore[(int)$layout->id]);

        if (self::layoutSignature($layout) !== $before) {
            Queue::push(new RebuildIndex(['fieldLayoutId' => (int)$layout->id]));
        }
    }

    /**
     * Notes whether an existing field was a Smart Link field, before a save of it is applied:
     * by the control panel, code or project config from another environment alike.
     */
    public function fieldApplying(?FieldInterface $stored): void
    {
        if ($stored?->uid !== null) {
            $this->fieldsBefore[$stored->uid] = $stored instanceof SmartLinkField;
        }
    }

    /**
     * Queues a rebuild when a field became, or stopped being, a Smart Link field: the layouts it
     * is in gained or lost one without being saved. Each such change queues its own rebuild;
     * rebuilds running at once are safe, only repeated work.
     */
    public function fieldSaved(FieldInterface $field, bool $isNew): void
    {
        $was = $field->uid !== null ? ($this->fieldsBefore[$field->uid] ?? null) : null;
        unset($this->fieldsBefore[(string)$field->uid]);

        // A new field is in no layout yet: placing it saves a layout, which is followed there.
        if ($isNew) {
            return;
        }

        if ($was === null) {
            // Craft notes every save it applies first; if it ever does not, nothing tells what the
            // field was, so the index is rebuilt rather than assumed unaffected.
            Craft::warning("Field {$field->id} was saved without its earlier type being noted; the link index is rebuilt.", __METHOD__);
            $this->queueRebuild();

            return;
        }

        if ($was !== $field instanceof SmartLinkField) {
            $this->queueRebuild();
        }
    }

    /**
     * Queues a rebuild when an existing site is saved: its base URL decides where root-relative
     * links lead and which URL a check of them requests, and a site deleted earlier comes back this
     * way (Craft restores it, then saves it), its element sites sources again. A new site is
     * followed through the elements Craft propagates to it.
     */
    public function siteSaved(bool $isNew): void
    {
        if (!$isNew) {
            $this->queueRebuild();
        }
    }

    /**
     * Queues a rebuild when a Smart Link field is deleted, or a site: what they held is gone from
     * every layout and element at once.
     */
    public function structureDeleted(): void
    {
        $this->queueRebuild();
    }

    // Rebuilding

    /**
     * Queues a full rebuild. The index stays readable throughout: nothing is emptied first, and
     * rows are only removed once a rebuild has shown they no longer describe anything.
     */
    public function queueRebuild(): void
    {
        Queue::push(new RebuildIndex(['startedAt' => Db::prepareDateForDb(new DateTime())]));
    }

    /**
     * Rebuilds the whole index in this process, batch by batch, as the rebuild jobs do.
     *
     * @param callable(int $done, int $total): void|null $progress
     */
    public function rebuild(?callable $progress = null): IndexingResult
    {
        $startedAt = Db::prepareDateForDb(new DateTime());
        $result = new IndexingResult();
        $total = $this->sourceElementCount();
        $done = 0;

        foreach ([[$this, 'rebuildBatch'], fn(int $after, int $limit, IndexingResult $result): array => $this->rebuildRemainder($startedAt, $after, $limit, $result)] as $step) {
            $after = 0;

            do {
                [$after, $count] = $step($after, self::BATCH_SIZE, $result);
                $done += $count;

                if ($progress !== null) {
                    $progress($done, max($total, $done));
                }
            } while ($after !== null);
        }

        $this->finishRebuild($result);

        return $result;
    }

    /**
     * Reads the next batch of source elements, in ID order, resolving again every target they
     * point at.
     *
     * @return array{0: int|null, 1: int} The last element ID read, or null when none are left,
     * and how many elements were read.
     */
    public function rebuildBatch(int $afterElementId, int $limit, IndexingResult $result): array
    {
        $ids = array_map('intval', $this->sourceQuery()
            ->select(['es.elementId'])
            ->distinct()
            ->andWhere(['>', 'es.elementId', $afterElementId])
            ->orderBy(['es.elementId' => SORT_ASC])
            ->limit($limit)
            ->column());

        return $this->indexBatch($ids, $limit, $result);
    }

    /**
     * Reads the next batch of what a rebuild that started at `$startedAt` left behind it, in ID
     * order: elements read before it started that it did not read again, elements read that are
     * no longer sources, and sources never read or saved since they were read (e.g. ones that
     * became sources behind it, as when a layout gained a Smart Link field). Run after the
     * walk, until it returns null.
     *
     * @return array{0: int|null, 1: int} As {@see rebuildBatch()}.
     */
    public function rebuildRemainder(string $startedAt, int $afterElementId, int $limit, IndexingResult $result): array
    {
        $unread = (new Query())->select(['elementId' => 's.elementId'])->from(['s' => SourceRecord::TABLE])->where(['<', 's.dateIndexed', $startedAt]);
        $gone = (new Query())->select(['elementId' => 's.elementId'])->from(['s' => SourceRecord::TABLE])->where(['not exists', $this->sourceFor('s')]);
        $behind = $this->sourceQuery()
            ->select(['elementId' => 'es.elementId'])
            ->leftJoin(['s' => SourceRecord::TABLE], '[[s.elementId]] = [[es.elementId]] AND [[s.siteId]] = [[es.siteId]]')
            ->andWhere(['or', ['s.id' => null], '[[s.elementDateUpdated]] <> [[e.dateUpdated]]']);

        $ids = array_map('intval', (new Query())
            ->select(['elementId'])
            ->distinct()
            ->from(['r' => $unread->union($gone)->union($behind)])
            ->where(['>', 'elementId', $afterElementId])
            ->orderBy(['elementId' => SORT_ASC])
            ->limit($limit)
            ->column());

        return $this->indexBatch($ids, $limit, $result);
    }

    /**
     * Completes a rebuild once nothing is left behind it: usage that no current source accounts
     * for is removed, then source rows of what is no longer a source, then every target nothing
     * uses.
     */
    public function finishRebuild(IndexingResult $result): void
    {
        // One hold of the lock for all three, in this order, so a target is unused only once nothing
        // that is no longer a source still points at it. Pure SQL on the index's own keys: whatever
        // stopped being a source behind the rebuild without an event (so no update of its own) is
        // gone afterwards, whichever phase it happened in.
        $this->locked(function() use ($result): void {
            $usage = UsageRecord::TABLE;
            $sourceRow = (new Query())
                ->from(['s' => SourceRecord::TABLE])
                ->where("[[s.elementId]] = $usage.[[elementId]] AND [[s.siteId]] = $usage.[[siteId]]");
            $currentSource = $this->sourceQuery()->andWhere("[[es.elementId]] = $usage.[[elementId]] AND [[es.siteId]] = $usage.[[siteId]]");
            $result->removedUsages += Db::delete($usage, ['or', ['not exists', $sourceRow], ['not exists', $currentSource]]);

            $sources = SourceRecord::TABLE;
            $result->removedSources += Db::delete($sources, ['not exists', $this->sourceQuery()->andWhere("[[es.elementId]] = $sources.[[elementId]] AND [[es.siteId]] = $sources.[[siteId]]")]);

            $result->removedTargets += self::deleteUnusedTargets();
        });
    }

    /**
     * Reads the next batch of the elements one layout is used by, in ID order, whether or not
     * they are still sources, so what its Smart Link fields became is reflected in the index.
     *
     * @return array{0: int|null, 1: int} As {@see rebuildBatch()}.
     */
    public function reindexLayoutBatch(int $layoutId, int $afterElementId, int $limit, IndexingResult $result): array
    {
        $type = Craft::$app->getFields()->getLayoutById($layoutId, true)?->type;
        $byType = in_array($type, self::LAYOUT_BY_TYPE, true);

        $ids = array_map('intval', (new Query())
            ->select(['id'])
            ->from(Table::ELEMENTS)
            ->where(['or', ['fieldLayoutId' => $layoutId], $byType ? ['and', ['fieldLayoutId' => null], ['type' => $type]] : '0=1'])
            ->andWhere(['draftId' => null, 'revisionId' => null, 'dateDeleted' => null])
            ->andWhere(['>', 'id', $afterElementId])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->column());

        return $this->indexBatch($ids, $limit, $result);
    }

    /**
     * How many elements a rebuild would read now.
     */
    public function sourceElementCount(): int
    {
        return (int)$this->sourceQuery()->select(['es.elementId'])->distinct()->count();
    }

    // Status

    /**
     * What the index holds, and every way it is known to differ from content: sources saved since
     * they were read or never read, sources whose values could not all be read, rows for sources
     * that no longer are any, usage of fields no longer in a layout, targets nothing uses, and
     * targets recorded as leading somewhere whose element or site is gone.
     *
     * Every count is one query over the index's own keys; no content is read, and nothing is
     * written. Changes Craft makes without saving an element, a layout or a field, such as a
     * site's base URL, are not seen; a rebuild picks them up.
     */
    public function status(): IndexStatus
    {
        $unindexed = (int)$this->sourceQuery()
            ->leftJoin(['s' => SourceRecord::TABLE], '[[s.elementId]] = [[es.elementId]] AND [[s.siteId]] = [[es.siteId]]')
            ->andWhere(['or', ['s.id' => null], '[[s.elementDateUpdated]] <> [[e.dateUpdated]]'])
            ->count();

        $unreadable = (int)(new Query())
            ->from(['s' => SourceRecord::TABLE])
            ->where(['>', 's.unreadableValues', 0])
            ->count();

        $orphanedSources = (int)(new Query())
            ->from(['s' => SourceRecord::TABLE])
            ->where(['not exists', $this->sourceFor('s')])
            ->count();

        $layoutElementUids = [];

        foreach ($this->sourceLayouts() as $fields) {
            foreach ($fields as $field) {
                $layoutElementUids[] = $field->layoutElement?->uid;
            }
        }

        $orphanedUsages = (int)(new Query())
            ->from(['u' => UsageRecord::TABLE])
            ->where(['or',
                ['not exists', (new Query())->from(['s' => SourceRecord::TABLE])->where('[[s.elementId]] = [[u.elementId]] AND [[s.siteId]] = [[u.siteId]]')],
                ['not in', 'u.layoutElementUid', array_values(array_filter($layoutElementUids)) ?: ['']],
            ])
            ->count();

        $unusedTargets = (int)(new Query())
            ->from(['i' => IndexRecord::TABLE])
            ->where(['not exists', self::usageOf('[[i.id]]')])
            ->count();

        $outdatedTargets = (int)(new Query())
            ->from(['i' => IndexRecord::TABLE])
            ->leftJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[i.targetElementId]]')
            ->leftJoin(['es' => Table::ELEMENTS_SITES], '[[es.elementId]] = [[i.targetElementId]] AND [[es.siteId]] = [[i.targetSiteId]]')
            ->leftJoin(['si' => Table::SITES], '[[si.id]] = [[i.targetSiteId]]')
            ->where(['i.targetStatus' => [ResolutionStatus::RESOLVED->value, ResolutionStatus::DISABLED->value, ResolutionStatus::NO_URL->value]])
            ->andWhere(['or',
                // The element it leads to is gone, or not in the site it is linked in.
                ['and', ['not', ['i.targetElementId' => null]], ['or',
                    ['e.id' => null],
                    ['not', ['e.dateDeleted' => null]],
                    ['and', ['not', ['i.targetSiteId' => null]], ['es.id' => null]],
                ]],
                // The site it was resolved in is gone.
                ['and', ['not', ['i.targetSiteId' => null]], ['or', ['si.id' => null], ['not', ['si.dateDeleted' => null]]]],
            ])
            ->count();

        return new IndexStatus(
            targets: (int)IndexRecord::find()->count(),
            usages: (int)UsageRecord::find()->count(),
            sources: (int)SourceRecord::find()->count(),
            unindexedSources: $unindexed,
            unreadableSources: $unreadable,
            orphanedSources: $orphanedSources,
            orphanedUsages: $orphanedUsages,
            unusedTargets: $unusedTargets,
            outdatedTargets: $outdatedTargets,
        );
    }

    // The inventory

    /**
     * One page of the link targets content uses, filtered and sorted as asked, with the health
     * last observed for each and where it is used.
     *
     * Only what the index holds is read, a page at a time: the targets are one query, and what is
     * shown about where each is used is a few more, for that page's targets only. Filtering by
     * site or field counts only usage in that site or field, and leaves out targets used
     * elsewhere only.
     */
    public function inventory(InventoryCriteria $criteria): InventoryPage
    {
        $query = $this->inventoryQuery($criteria);
        $total = (int)(clone $query)->count();
        $rows = $query
            ->orderBy([...$this->inventoryOrder($criteria), 'i.id' => SORT_ASC])
            ->offset(($criteria->page - 1) * InventoryCriteria::PAGE_SIZE)
            ->limit(InventoryCriteria::PAGE_SIZE)
            ->all();

        return new InventoryPage($this->inventoryItems($rows, $criteria), $total, $criteria->page, InventoryCriteria::PAGE_SIZE);
    }

    /**
     * One link target as the unfiltered inventory shows it; null if the index has no such target,
     * or nothing uses it any more.
     */
    public function inventoryItem(int $indexId): ?InventoryItem
    {
        $criteria = InventoryCriteria::fromParams([]);
        $rows = $this->inventoryQuery($criteria, $indexId)->all();

        return $this->inventoryItems($rows, $criteria)[0] ?? null;
    }

    /**
     * The inventory's targets matching the criteria, with their usage and health, unordered.
     *
     * @param int|null $indexId Only this target, so only its usage is counted.
     */
    private function inventoryQuery(InventoryCriteria $criteria, ?int $indexId = null): Query
    {
        $usage = $this->usageQuery($criteria)
            ->select(['indexId', 'usageCount' => 'COUNT(*)', 'sourceCount' => 'COUNT(DISTINCT [[elementId]])'])
            ->andFilterWhere(['indexId' => $indexId])
            ->groupBy(['indexId']);
        $query = (new Query())
            ->from(['i' => IndexRecord::TABLE])
            ->innerJoin(['u' => $usage], '[[u.indexId]] = [[i.id]]')
            // Health is kept by URL: one observation answers for every target leading there.
            ->leftJoin(['h' => HealthRecord::TABLE], '[[h.urlHash]] = [[i.healthUrlHash]]');

        if ($criteria->type !== null) {
            $query->andWhere(['i.linkType' => $criteria->type]);
        }

        if ($criteria->health === InventoryCriteria::HEALTH_NONE) {
            $query->andWhere(['i.healthUrlHash' => null]);
        } elseif ($criteria->health === HealthState::UNKNOWN->value) {
            // Not checked yet is Unknown, never Healthy.
            $query->andWhere(['not', ['i.healthUrlHash' => null]])->andWhere(['or', ['h.id' => null], ['h.state' => HealthState::UNKNOWN->value]]);
        } elseif ($criteria->health !== null) {
            $query->andWhere(['h.state' => $criteria->health]);
        }

        if ($criteria->target !== null) {
            $query->andWhere(['i.targetStatus' => $criteria->target === InventoryCriteria::TARGET_FAILED ? null : $criteria->target]);
        }

        if ($criteria->search !== null) {
            $like = Craft::$app->getDb()->getIsPgsql() ? 'ilike' : 'like';
            $query->andWhere(['or',
                [$like, 'i.targetLabel', $criteria->search],
                [$like, 'i.resolvedUrl', $criteria->search],
                [$like, 'i.targetKey', $criteria->search],
                ['in', 'i.id', $this->usageQuery($criteria)->select(['indexId'])->andWhere([$like, 'label', $criteria->search])],
            ]);
        }

        return $query->select([
            'i.id', 'i.linkType', 'i.targetKey', 'i.targetLabel', 'i.resolvedUrl', 'i.targetSiteId', 'i.targetStatus', 'i.healthUrlHash',
            'u.usageCount', 'u.sourceCount', 'healthUrl' => 'h.url', 'h.state', 'h.dateChecked',
        ]);
    }

    /**
     * @return list<Expression>
     */
    private function inventoryOrder(InventoryCriteria $criteria): array
    {
        $dir = $criteria->dir === 'asc' ? 'ASC' : 'DESC';
        $rank = static function(string $column, array $values, string $otherwise): string {
            $cases = '';

            foreach (array_values($values) as $rank => $value) {
                $cases .= sprintf(" WHEN %s = '%s' THEN %d", $column, $value, $rank + 1);
            }

            return "CASE$cases ELSE $otherwise END";
        };

        return match ($criteria->sort) {
            'link' => [new Expression("COALESCE([[i.targetLabel]], [[i.resolvedUrl]], [[i.targetKey]]) $dir")],
            'type' => [new Expression("[[i.linkType]] $dir")],
            'usage' => [new Expression("[[u.usageCount]] $dir")],
            // Worst first: what needs attention, then what is fine, then what can't be checked.
            'health' => [new Expression(sprintf(
                'CASE WHEN [[i.healthUrlHash]] IS NULL THEN 9 WHEN [[h.state]] IS NULL THEN 5 ELSE %s END %s',
                $rank('[[h.state]]', ['broken', 'unavailable', 'blocked', 'redirect', 'unknown', 'healthy'], '5'),
                $dir,
            ))],
            // Never checked last either way, as it has no date to order by.
            'checked' => [new Expression('CASE WHEN [[h.dateChecked]] IS NULL THEN 1 ELSE 0 END'), new Expression("[[h.dateChecked]] $dir")],
            'target' => [new Expression($rank('[[i.targetStatus]]', ['missing', 'disabled', 'noUrl', 'resolved'], '0') . " $dir")],
            default => throw new LogicException("The inventory has no sort “{$criteria->sort}”."),
        };
    }

    /**
     * Usage the inventory counts: in the site and field filtered by, if any.
     */
    private function usageQuery(InventoryCriteria $criteria): Query
    {
        $query = (new Query())->from(UsageRecord::TABLE);

        if ($criteria->siteId !== null) {
            $query->andWhere(['siteId' => $criteria->siteId]);
        }

        if ($criteria->fieldId !== null) {
            $query->andWhere(['fieldId' => $criteria->fieldId]);
        }

        return $query;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<InventoryItem>
     */
    private function inventoryItems(array $rows, InventoryCriteria $criteria): array
    {
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);

        if ($ids === []) {
            return [];
        }

        // The labels links give each target, most used first.
        $labels = [];

        foreach ($this->usageQuery($criteria)
            ->select(['indexId', 'label', 'uses' => 'COUNT(*)'])
            ->andWhere(['indexId' => $ids])
            ->andWhere(['not', ['label' => null]])
            ->groupBy(['indexId', 'label'])
            ->orderBy(['uses' => SORT_DESC, 'label' => SORT_ASC])
            ->all() as $row) {
            $labels[(int)$row['indexId']][] = (string)$row['label'];
        }

        $sites = [];

        foreach ($this->usageQuery($criteria)->select(['indexId', 'siteId'])->distinct()->andWhere(['indexId' => $ids])->all() as $row) {
            // Usage of a deleted site stays until Craft deletes it for good; said to be deleted.
            $sites[(int)$row['indexId']][] = Craft::$app->getSites()->getSiteById((int)$row['siteId'], true)?->getName() ?? Craft::t('smart-links', 'Site {id} (deleted)', ['id' => $row['siteId']]);
        }

        $sources = $this->firstSources($ids, $criteria);
        $types = SmartLinks::getInstance()->getLinkTypes()->getTypeSet();
        $items = [];

        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $resolvedUrl = $row['resolvedUrl'] !== null ? (string)$row['resolvedUrl'] : null;
            $checkUrl = self::healthUrl($resolvedUrl, $row['targetSiteId'] !== null ? (int)$row['targetSiteId'] : null);
            $health = null;
            $observed = false;

            if ($row['healthUrlHash'] !== null) {
                // The hash only stands in for the URL, so the row is the target's only if its URL
                // is the one this target's checks would request.
                $observed = $row['healthUrl'] !== null && $row['healthUrl'] === $checkUrl;
                $health = $observed ? (HealthState::tryFrom((string)$row['state']) ?? HealthState::UNKNOWN) : HealthState::UNKNOWN;
            }

            $siteNames = $sites[$id] ?? [];
            sort($siteNames);

            $items[] = new InventoryItem(
                id: $id,
                linkType: (string)$row['linkType'],
                // A type whose plugin is gone is named as such, never passed off as a name.
                typeName: $types->get((string)$row['linkType'])?->displayName() ?? Craft::t('smart-links', '{type} (not available)', ['type' => $row['linkType']]),
                targetKey: (string)$row['targetKey'],
                targetLabel: $row['targetLabel'] !== null ? (string)$row['targetLabel'] : null,
                resolvedUrl: $resolvedUrl,
                checkUrl: $checkUrl,
                targetStatus: $row['targetStatus'] !== null ? (string)$row['targetStatus'] : null,
                health: $health,
                dateChecked: $observed && $row['dateChecked'] !== null ? DateTimeHelper::toDateTime($row['dateChecked']) ?: null : null,
                usageCount: (int)$row['usageCount'],
                sourceCount: (int)$row['sourceCount'],
                label: $labels[$id][0] ?? null,
                labelCount: count($labels[$id] ?? []),
                source: $sources[$id] ?? null,
                siteNames: $siteNames,
            );
        }

        return $items;
    }

    /**
     * The first place each target is used (its earliest indexed usage), shown as the element the
     * link is in, or the element that one is nested in, linked to its edit page if the current
     * user may view it. Elements are loaded per type and site, not one by one.
     *
     * @param list<int> $indexIds
     * @return array<int, array{label: string, url: string|null}>
     */
    private function firstSources(array $indexIds, InventoryCriteria $criteria): array
    {
        $first = $this->usageQuery($criteria)->select(['id' => 'MIN([[id]])'])->andWhere(['indexId' => $indexIds])->groupBy(['indexId']);
        $usages = (new Query())
            ->select(['u.indexId', 'u.elementId', 'u.siteId', 'e.type'])
            ->from(['u' => UsageRecord::TABLE])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[u.elementId]]')
            ->where(['u.id' => $first])
            ->all();

        $wanted = [];

        foreach ($usages as $usage) {
            $wanted[(string)$usage['type']][(int)$usage['siteId']][] = (int)$usage['elementId'];
        }

        $elements = [];

        foreach ($wanted as $type => $bySite) {
            if (!is_subclass_of($type, ElementInterface::class)) {
                continue;
            }

            foreach ($bySite as $siteId => $elementIds) {
                foreach ($type::find()->id($elementIds)->siteId($siteId)->status(null)->all() as $element) {
                    $elements["$element->id/$siteId"] = $element;
                }
            }
        }

        $user = Craft::$app->getUser()->getIdentity();
        $sources = [];

        foreach ($usages as $usage) {
            $element = $elements[$usage['elementId'] . '/' . $usage['siteId']] ?? null;

            // Indexed, but not loadable now (trashed since, or its element type is gone): said so.
            if ($element === null) {
                $sources[(int)$usage['indexId']] = [
                    'label' => Craft::t('smart-links', 'Element {id} (can’t be loaded)', ['id' => $usage['elementId']]),
                    'url' => null,
                ];

                continue;
            }

            $root = ElementHelper::rootElement($element);
            $sources[(int)$usage['indexId']] = [
                'label' => $root->getUiLabel(),
                'url' => $user !== null && Craft::$app->getElements()->canView($root, $user) ? $root->getCpEditUrl() : null,
            ];
        }

        return $sources;
    }

    // Health URLs

    /**
     * The URL a health check of a resolved URL would request: the URL itself when it is an
     * absolute http(s) URL, or a root-relative one on the host of its site's base URL. Null for
     * anything else (`mailto:`, `tel:`, an anchor), and for a root-relative URL without a site
     * that has an absolute base URL.
     */
    public static function healthUrl(?string $resolvedUrl, ?int $siteId): ?string
    {
        if ($resolvedUrl === null) {
            return null;
        }

        try {
            $url = CanonicalUrl::parse($resolvedUrl);

            if (!$url->isAbsolute()) {
                $baseUrl = $siteId !== null ? Craft::$app->getSites()->getSiteById($siteId, true)?->getBaseUrl() : null;
                $base = $baseUrl !== null ? CanonicalUrl::parse($baseUrl) : null;

                if ($base === null || !$base->isAbsolute()) {
                    return null;
                }

                $url = $url->onOriginOf($base);
            }
        } catch (InvalidArgumentException) {
            // Not an http(s) URL, so there is nothing a health check could request.
            return null;
        }

        return $url->healthUrl();
    }

    // Internals

    /**
     * Holds the index's lock while `$write` runs.
     *
     * @template T
     * @param callable(): T $write
     * @return T
     * @throws IndexLockedException if another process held the lock for longer than
     * {@see $lockTimeout}.
     */
    private function locked(callable $write): mixed
    {
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire(self::LOCK, $this->lockTimeout)) {
            throw new IndexLockedException(sprintf('The link index is being written by another process, which held it for more than %d seconds. Nothing was written; try again.', $this->lockTimeout));
        }

        try {
            return $write();
        } finally {
            $mutex->release(self::LOCK);
        }
    }

    /**
     * Reads a batch of elements, then removes the targets they no longer use.
     *
     * @param list<int> $ids
     * @return array{0: int|null, 1: int} As {@see rebuildBatch()}.
     */
    private function indexBatch(array $ids, int $limit, IndexingResult $result): array
    {
        $this->resolved = [];
        $affected = [];

        foreach ($ids as $id) {
            array_push($affected, ...$this->indexElement($id, true, $result));
        }

        $this->pruneTargets($affected, $result);

        return [count($ids) < $limit ? null : (int)end($ids), count($ids)];
    }

    /**
     * Reads an element's content in every site it is a source in, and removes its rows in the
     * sites where it no longer is one, all under the index's lock.
     *
     * @param bool $refreshTargets Whether to resolve again every target it points at, rather than
     * only those never resolved.
     * @return list<int> Index rows that lost a usage, and may now be unused.
     */
    private function indexElement(int $elementId, bool $refreshTargets, IndexingResult $result): array
    {
        return $this->locked(function() use ($elementId, $refreshTargets, $result): array {
            $versions = $this->sourceQuery()
                ->select(['es.siteId', 'e.dateUpdated'])
                ->andWhere(['es.elementId' => $elementId])
                ->pairs();

            $indexedSites = array_map('intval', (new Query())
                ->select(['siteId'])
                ->from(SourceRecord::TABLE)
                ->where(['elementId' => $elementId])
                ->column());

            $affected = [];

            foreach (array_diff($indexedSites, array_map('intval', array_keys($versions))) as $siteId) {
                array_push($affected, ...$this->removeSource($elementId, $siteId, $result));
            }

            foreach ($versions as $siteId => $version) {
                array_push($affected, ...$this->indexSource($elementId, (int)$siteId, (string)$version, $refreshTargets, $result));
            }

            return $affected;
        });
    }

    /**
     * Reads one element site and replaces its usage rows with what it holds now.
     *
     * A value that cannot be read (e.g. its link type's plugin is disabled) is not taken to hold
     * no links: its usage rows stay as they were, and the source records how many such values it
     * has, so the index is reported as not up to date with it rather than passed off as current.
     *
     * @return list<int> Index rows that lost a usage.
     */
    private function indexSource(int $elementId, int $siteId, string $version, bool $refreshTargets, IndexingResult $result): array
    {
        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId, ['status' => null]);

        if ($element === null) {
            // A source in the database that its element type cannot load, e.g. its plugin is
            // missing. Left as it was, so it is reported as not indexed.
            $result->addProblem(sprintf('Element %d could not be loaded in site %d, so its links were not read.', $elementId, $siteId));

            return [];
        }

        $usages = [];
        $kept = [];

        // Content of a draft or revision, e.g. a Matrix entry of a draft, is read but holds no
        // links: they count once the draft is applied.
        if (ElementHelper::rootElementIfCanonical($element) !== null) {
            foreach (self::smartLinkFields($element->getFieldLayout()) as $field) {
                $layoutElementUid = (string)$field->layoutElement?->uid;
                $value = $element->getFieldValue((string)$field->handle);

                if ($value instanceof InvalidLinkValue) {
                    $kept[] = $layoutElementUid;
                    $result->unreadableValues++;
                    $result->addProblem(sprintf('The “%s” field of element %d in site %d can’t be read, so its links were kept as last indexed.', $field->handle, $elementId, $siteId));

                    continue;
                }

                // The field's own type promises one or the other.
                if (!$value instanceof LinkCollection) {
                    throw new LogicException(sprintf('The “%s” field of element %d gave a %s, not links.', $field->handle, $elementId, get_debug_type($value)));
                }

                foreach ($value->links as $position => $link) {
                    $indexId = $this->indexLink($link, $element, $siteId, $refreshTargets, $result);

                    if ($indexId !== null) {
                        $usages["$layoutElementUid/$link->uid"] = [
                            'indexId' => $indexId,
                            'fieldId' => (int)$field->id,
                            'layoutElementUid' => $layoutElementUid,
                            'linkUid' => $link->uid,
                            'sortOrder' => $position + 1,
                            'label' => $link->label,
                        ];
                    }
                }
            }
        }

        $affected = [];

        Craft::$app->getDb()->transaction(function() use ($elementId, $siteId, $version, $usages, $kept, $result, &$affected): void {
            $affected = $this->replaceUsages($elementId, $siteId, $usages, $kept, $result);

            Db::upsert(SourceRecord::TABLE, [
                'elementId' => $elementId,
                'siteId' => $siteId,
                'elementDateUpdated' => $version,
                'unreadableValues' => count($kept),
                'dateIndexed' => Db::prepareDateForDb(new DateTime()),
            ]);
        });

        $result->sources++;

        return $affected;
    }

    /**
     * The target row of one link, created and resolved if it is new.
     */
    private function indexLink(LinkValue $link, ElementInterface $element, int $siteId, bool $refreshTargets, IndexingResult $result): ?int
    {
        $type = SmartLinks::getInstance()->getLinkTypes()->getType($link->type);

        // Only a readable value gets here, and its types are registered.
        if ($type === null) {
            throw new LogicException("The link type “{$link->type}” of a readable value is not registered.");
        }

        try {
            $identity = $type->targetIdentity($link->data, (int)$element->id, $siteId);
        } catch (InvalidArgumentException $exception) {
            $result->addProblem(sprintf('Link %s of element %d in site %d has no target identity: %s', $link->uid, $element->id, $siteId, $exception->getMessage()));

            return null;
        }

        $record = $this->target($identity);
        $result->links++;

        if (($refreshTargets && !isset($this->resolved[$record->id])) || $record->targetStatus === null) {
            $this->resolveTarget($record, $link, $siteId, $result);
        }

        return $record->id;
    }

    /**
     * Records where a target leads now, worked out from one link that points at it, resolved in
     * the site it appears in. The target is what the author selected, so the link's own URL
     * suffix and attributes are left out: they belong to that occurrence, not to the target.
     */
    private function resolveTarget(IndexRecord $record, LinkValue $link, int $siteId, IndexingResult $result): void
    {
        $this->resolved[$record->id] = true;
        $url = null;
        $label = null;
        $status = null;

        try {
            $resolved = SmartLinks::getInstance()->getLinks()->resolve(new LinkValue($link->uid, $link->type, $link->data), $siteId);
            [$url, $label, $status] = [$resolved->url, $resolved->defaultLabel, $resolved->status->value];
        } catch (LinkResolutionException $exception) {
            $result->addProblem(sprintf('The target %s could not be resolved: %s', $record->targetKey, $exception->getMessage()));
        }

        // Kept whole or not at all: a database would cut a longer one short, or refuse it.
        if (($url !== null && strlen($url) > self::MAX_TEXT_BYTES) || ($label !== null && strlen($label) > self::MAX_TEXT_BYTES)) {
            $result->addProblem(sprintf('The target %s resolved to a URL or name too long to index.', $record->targetKey));
            [$url, $label, $status] = [null, null, null];
        }

        $healthUrl = self::healthUrl($url, $record->targetSiteId);

        $record->resolvedUrl = $url;
        $record->targetLabel = $label;
        $record->targetStatus = $status;
        $record->healthUrlHash = $healthUrl !== null ? hash('sha256', $healthUrl) : null;

        if (!$record->save(false)) {
            throw new LogicException("The index row for {$record->targetKey} could not be saved.");
        }
    }

    /**
     * Resolves again the targets that point at elements, each through one link that uses it.
     *
     * @param list<int> $elementIds
     */
    private function refreshTargetsOf(array $elementIds, IndexingResult $result): void
    {
        $this->locked(function() use ($elementIds, $result): void {
            $firstUsages = (new Query())
                ->select(['indexId', 'id' => 'MIN([[id]])'])
                ->from(UsageRecord::TABLE)
                ->where(['indexId' => (new Query())->select(['id'])->from(IndexRecord::TABLE)->where(['targetElementId' => $elementIds])])
                ->groupBy(['indexId']);

            $usages = (new Query())
                ->select(['u.indexId', 'u.elementId', 'u.siteId', 'u.layoutElementUid', 'u.linkUid'])
                ->from(['u' => UsageRecord::TABLE])
                ->innerJoin(['f' => $firstUsages], '[[f.id]] = [[u.id]]')
                ->all();

            foreach ($usages as $usage) {
                $record = IndexRecord::findOne((int)$usage['indexId']);
                $element = Craft::$app->getElements()->getElementById((int)$usage['elementId'], null, (int)$usage['siteId'], ['status' => null]);
                $link = $record !== null && $element !== null ? self::linkIn($element, (string)$usage['layoutElementUid'], (string)$usage['linkUid']) : null;

                // The usage no longer matches its content: the target is resolved again when that
                // content is read (its own update, or a rebuild, which status calls for meanwhile).
                if ($record === null || $link === null) {
                    $result->addProblem(sprintf('Target %d was not resolved again: link %s of element %d in site %d is no longer as indexed.', $usage['indexId'], $usage['linkUid'], $usage['elementId'], $usage['siteId']));

                    continue;
                }

                $this->resolveTarget($record, $link, (int)$usage['siteId'], $result);
            }
        });
    }

    private static function linkIn(ElementInterface $element, string $layoutElementUid, string $linkUid): ?LinkValue
    {
        foreach (self::smartLinkFields($element->getFieldLayout()) as $field) {
            if ($field->layoutElement?->uid !== $layoutElementUid) {
                continue;
            }

            $value = $element->getFieldValue((string)$field->handle);

            foreach ($value instanceof LinkCollection ? $value->links : [] as $link) {
                if ($link->uid === $linkUid) {
                    return $link;
                }
            }
        }

        return null;
    }

    /**
     * Makes an element site's usage rows exactly `$usages`, writing only what changed, apart
     * from rows of the layout elements in `$kept`, which stay as they are.
     *
     * @param array<string, array<string, mixed>> $usages By layout element and link UID.
     * @param list<string> $kept
     * @return list<int> Index rows that lost a usage.
     */
    private function replaceUsages(int $elementId, int $siteId, array $usages, array $kept, IndexingResult $result): array
    {
        /** @var list<UsageRecord> $existing */
        $existing = UsageRecord::find()->where(['elementId' => $elementId, 'siteId' => $siteId])->all();
        $affected = [];
        $removed = [];

        foreach ($existing as $record) {
            $key = "$record->layoutElementUid/$record->linkUid";
            $wanted = $usages[$key] ?? null;

            if ($wanted === null) {
                if (!in_array($record->layoutElementUid, $kept, true)) {
                    $removed[] = $record->id;
                    $affected[] = (int)$record->indexId;
                }

                continue;
            }

            unset($usages[$key]);

            if ((int)$record->indexId === $wanted['indexId'] && (int)$record->fieldId === $wanted['fieldId'] && (int)$record->sortOrder === $wanted['sortOrder'] && $record->label === $wanted['label']) {
                continue;
            }

            if ((int)$record->indexId !== $wanted['indexId']) {
                $affected[] = (int)$record->indexId;
            }

            $record->setAttributes(['indexId' => $wanted['indexId'], 'fieldId' => $wanted['fieldId'], 'sortOrder' => $wanted['sortOrder'], 'label' => $wanted['label']], false);

            if (!$record->save(false)) {
                throw new LogicException("A usage row of element $elementId in site $siteId could not be saved.");
            }
        }

        if ($removed !== []) {
            $result->removedUsages += Db::delete(UsageRecord::TABLE, ['id' => $removed]);
        }

        foreach ($usages as $usage) {
            $record = new UsageRecord();
            $record->setAttributes(['elementId' => $elementId, 'siteId' => $siteId] + $usage, false);

            if (!$record->save(false)) {
                throw new LogicException("A usage row of element $elementId in site $siteId could not be saved.");
            }
        }

        return $affected;
    }

    /**
     * @return list<int> Index rows that lost a usage.
     */
    private function removeSource(int $elementId, int $siteId, IndexingResult $result): array
    {
        $condition = ['elementId' => $elementId, 'siteId' => $siteId];
        $affected = array_map('intval', (new Query())->select(['indexId'])->from(UsageRecord::TABLE)->where($condition)->column());

        Craft::$app->getDb()->transaction(static function() use ($condition, $result): void {
            $result->removedUsages += Db::delete(UsageRecord::TABLE, $condition);
            $result->removedSources += Db::delete(SourceRecord::TABLE, $condition);
        });

        return $affected;
    }

    /**
     * Removes the given targets that nothing uses any more. Their health rows stay: health is
     * kept by URL, and other targets may share it.
     *
     * @param list<int> $indexIds
     */
    private function pruneTargets(array $indexIds, IndexingResult $result): void
    {
        if ($indexIds === []) {
            return;
        }

        $this->locked(static function() use ($indexIds, $result): void {
            $result->removedTargets += Db::delete(IndexRecord::TABLE, ['and',
                ['id' => array_values(array_unique($indexIds))],
                ['not exists', self::usageOf(IndexRecord::TABLE . '.[[id]]')],
            ]);
        });
    }

    private static function usageOf(string $indexIdColumn): Query
    {
        return (new Query())->from(['u' => UsageRecord::TABLE])->where("[[u.indexId]] = $indexIdColumn");
    }

    /**
     * Whether the index holds anything about an element: rows for it as a source, or targets that
     * point at it.
     */
    private function isIndexed(int $elementId): bool
    {
        return SourceRecord::find()->where(['elementId' => $elementId])->exists()
            || IndexRecord::find()->where(['targetElementId' => $elementId])->exists();
    }

    /**
     * The source row aliased `$alias`, among the sources there are now.
     */
    private function sourceFor(string $alias): Query
    {
        return $this->sourceQuery()->andWhere("[[es.elementId]] = [[$alias.elementId]] AND [[es.siteId]] = [[$alias.siteId]]");
    }

    /**
     * Every element site that is a source: in a field layout with a Smart Link field, neither a
     * draft, a revision nor in the trash, and in a site that is not deleted. Aliased `es`
     * (elements_sites) and `e` (elements).
     */
    private function sourceQuery(): Query
    {
        $layouts = $this->sourceLayouts();
        $byType = [];

        foreach (array_keys($layouts) as $layoutId) {
            $type = Craft::$app->getFields()->getLayoutById($layoutId)?->type;

            if (in_array($type, self::LAYOUT_BY_TYPE, true)) {
                $byType[] = $type;
            }
        }

        return (new Query())
            ->from(['es' => Table::ELEMENTS_SITES])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[es.elementId]]')
            // Craft deletes a site softly, and its element sites only when it collects garbage.
            ->innerJoin(['si' => Table::SITES], '[[si.id]] = [[es.siteId]]')
            ->where(['or',
                ['e.fieldLayoutId' => array_keys($layouts) ?: [0]],
                ['and', ['e.fieldLayoutId' => null], ['e.type' => $byType ?: ['']]],
            ])
            ->andWhere([
                'e.draftId' => null,
                'e.revisionId' => null,
                'e.dateDeleted' => null,
                'si.dateDeleted' => null,
            ]);
    }

    /**
     * The field layouts that hold a Smart Link field, by ID, with those fields as placed there.
     *
     * @return array<int, list<SmartLinkField>>
     */
    private function sourceLayouts(): array
    {
        $layouts = [];

        foreach (Craft::$app->getFields()->getAllLayouts() as $layout) {
            $fields = self::smartLinkFields($layout);

            if ($fields !== [] && $layout->id !== null) {
                $layouts[(int)$layout->id] = $fields;
            }
        }

        return $layouts;
    }

    /**
     * @return list<SmartLinkField>
     */
    private static function smartLinkFields(?FieldLayout $layout): array
    {
        if ($layout === null) {
            return [];
        }

        return array_values(array_filter($layout->getCustomFields(), static fn($field): bool => $field instanceof SmartLinkField));
    }

    /**
     * A layout's Smart Link fields as placed in it: each layout element and its field.
     */
    private static function layoutSignature(FieldLayout $layout): string
    {
        $placed = array_map(static fn(SmartLinkField $field): string => $field->layoutElement?->uid . ':' . $field->uid, self::smartLinkFields($layout));
        sort($placed);

        return implode(',', $placed);
    }

    /**
     * {@see layoutSignature()} of a layout as stored, read from its stored config rather than a
     * layout object that may already hold the changes being saved.
     */
    private function storedLayoutSignature(int $layoutId): string
    {
        $config = (new Query())->select(['config'])->from(Table::FIELDLAYOUTS)->where(['id' => $layoutId])->scalar();
        $config = is_string($config) ? Json::decodeIfJson($config) : $config;
        $placed = [];

        foreach (is_array($config) ? ($config['tabs'] ?? []) : [] as $tab) {
            foreach ($tab['elements'] ?? [] as $element) {
                if (($element['type'] ?? null) !== CustomField::class || !isset($element['fieldUid'], $element['uid'])) {
                    continue;
                }

                if (Craft::$app->getFields()->getFieldByUid((string)$element['fieldUid']) instanceof SmartLinkField) {
                    $placed[] = $element['uid'] . ':' . $element['fieldUid'];
                }
            }
        }

        sort($placed);

        return implode(',', $placed);
    }

    private function target(TargetIdentity $identity): IndexRecord
    {
        $record = $this->findTarget($identity->hash()) ?? $this->insertTarget($identity);

        if ($record->targetKey !== $identity->key()) {
            throw new TargetHashCollisionException($identity->hash(), $record->targetKey, $identity->key());
        }

        return $record;
    }

    protected function findTarget(string $targetHash): ?IndexRecord
    {
        return IndexRecord::findOne(['targetHash' => $targetHash]);
    }

    /**
     * Inserts the row, or returns the row another process inserted under the same hash after
     * {@see findTarget()} found none. The caller still compares its key.
     *
     * Inside a caller's REPEATABLE READ transaction, the other process's row can be invisible to
     * the re-read. The unique violation is then rethrown rather than guessed around, and the
     * caller can retry in a fresh transaction.
     */
    private function insertTarget(TargetIdentity $identity): IndexRecord
    {
        $record = new IndexRecord();
        $record->targetKey = $identity->key();
        $record->targetHash = $identity->hash();
        $record->linkType = $identity->type;
        $record->targetElementId = isset($identity->components['elementId']) ? (int)$identity->components['elementId'] : null;
        $record->targetSiteId = isset($identity->components['siteId']) ? (int)$identity->components['siteId'] : null;

        try {
            // Its own transaction, or a savepoint in the caller's, so a refused insert is rolled
            // back without leaving the caller's transaction unusable.
            Craft::$app->getDb()->transaction(static function() use ($record): void {
                if (!$record->insert()) {
                    throw new LogicException('The index row could not be inserted: ' . implode(' ', $record->getFirstErrors()));
                }
            });
        } catch (IntegrityException $exception) {
            // The row has no foreign keys and every required column is set, so only the unique
            // hash can refuse it. If no row holds that hash, the refusal is something else.
            return $this->findTarget($identity->hash()) ?? throw $exception;
        }

        return $record;
    }
}
