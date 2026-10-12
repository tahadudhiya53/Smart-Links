<?php

namespace Tahadudhiya\SmartLinks\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use craft\elements\User;
use craft\errors\FieldNotFoundException;
use craft\events\PopulateElementEvent;
use craft\fieldlayoutelements\CustomField;
use InvalidArgumentException;
use LogicException;
use Tahadudhiya\SmartLinks\enums\StaleUsage;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\errors\TargetHashCollisionException;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;
use Tahadudhiya\SmartLinks\linktypes\UrlLinkData;
use Tahadudhiya\SmartLinks\linktypes\UrlLinkType;
use Tahadudhiya\SmartLinks\models\LinkUsage;
use Tahadudhiya\SmartLinks\models\TargetIdentity;
use Tahadudhiya\SmartLinks\models\UsageCriteria;
use Tahadudhiya\SmartLinks\models\UsagePage;
use Tahadudhiya\SmartLinks\records\IndexRecord;
use Tahadudhiya\SmartLinks\records\SourceRecord;
use Tahadudhiya\SmartLinks\records\UsageRecord;
use Tahadudhiya\SmartLinks\SmartLinks;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Where links are used: every occurrence of a link target in Smart Links field content, with the
 * element, site and field holding it.
 *
 * Reads what the link index recorded and nothing else; the index alone writes it. A target is
 * found by its identity, never by where it happens to lead: links to an element are found by the
 * element they name, and links to a URL by the URL's canonical form. An entry link and a URL link
 * that both lead to the same page are different targets, as are two URLs that differ only in
 * their fragment.
 *
 * Occurrences are read a page at a time. The elements holding a page's occurrences are loaded per
 * element type and site, and what they are nested in level by level the same way, so a page takes
 * the same few queries however many elements it shows.
 */
class Usage extends Component
{
    /**
     * Every occurrence of one link target. A target the index holds but nothing uses any more
     * (until the next pruning removes it) has none.
     *
     * @throws InvalidArgumentException if the index has no such target.
     */
    public function ofTarget(int $indexId, ?UsageCriteria $criteria = null): UsagePage
    {
        if (!IndexRecord::find()->where(['id' => $indexId])->exists()) {
            throw new InvalidArgumentException("The link index has no target $indexId.");
        }

        return $this->page(['u.indexId' => $indexId], $criteria ?? new UsageCriteria());
    }

    /**
     * Every occurrence of a link that names an element as its target: element links to it (in
     * the given site's version of it, if a site is given), and anchors into it. Found by the
     * element reference the link holds, never by its URL, so a URL link to the element's page is
     * not one of them. The element may since have been deleted: links naming it are still found.
     *
     * @param int|null $siteId Only links to this site's version of the element. Links to an
     * element that has no versions by site (a user) are found in every site.
     */
    public function ofElement(int $elementId, ?int $siteId = null, ?UsageCriteria $criteria = null): UsagePage
    {
        $subject = ['i.targetElementId' => $elementId];

        if ($siteId !== null) {
            $subject = ['and', $subject, ['or', ['i.targetSiteId' => $siteId], ['i.targetSiteId' => null]]];
        }

        return $this->page($subject, $criteria ?? new UsageCriteria());
    }

    /**
     * Every occurrence of a URL link to a URL, compared in canonical form: `HTTPS://Example.com:443/a`
     * finds links to `https://example.com/a`. Only URL links are found, never other links that
     * lead there, and the fragment counts (`/a#b` is not `/a`). A path on the site (`/about`)
     * means something different in each site: it is looked up in the given site, or in every site.
     *
     * @param int|null $siteId The site a path is on; ignored for an absolute URL.
     * @throws InvalidArgumentException if the URL is not one a URL link can hold, or is an anchor
     * (which points into the page it is on: look that page up with {@see ofElement()}).
     * @throws TargetHashCollisionException if another target is stored under one of the URL's hashes.
     */
    public function ofUrl(string $url, ?int $siteId = null, ?UsageCriteria $criteria = null): UsagePage
    {
        $type = SmartLinks::getInstance()->getLinkTypes()->getType('url');

        if (!$type instanceof UrlLinkType) {
            throw new InvalidArgumentException('There is no URL link type to look URLs up with.');
        }

        try {
            $data = $type->normalizeData(['url' => $url]);
        } catch (LinkValidationException $exception) {
            throw new InvalidArgumentException('“' . $url . '” is not a URL a link can hold: ' . $exception->getMessage(), 0, $exception);
        }

        if (!$data instanceof UrlLinkData || $data->canonical === null) {
            throw new InvalidArgumentException('An anchor points into the page it is on; look up links to that page instead.');
        }

        // The URL link type's own identity of the URL: one for an absolute URL, one per site for a path.
        $identities = [];

        foreach ($data->canonical->isAbsolute() ? [null] : ($siteId !== null ? [$siteId] : Craft::$app->getSites()->getAllSiteIds(true)) as $site) {
            $identity = TargetIdentity::forUrl($type->handle(), $data->canonical, $site !== null ? (int)$site : null);
            $identities[$identity->hash()] = $identity->key();
        }

        // The hash finds the row; its key confirms it is this URL's. Another target under the same
        // hash is a collision the index refuses to store, so finding one is reported, not skipped.
        $indexIds = [];

        foreach (IndexRecord::find()->select(['id', 'targetHash', 'targetKey'])->where(['targetHash' => array_keys($identities)])->asArray()->all() as $row) {
            $expected = $identities[$row['targetHash']];

            if ($row['targetKey'] !== $expected) {
                throw new TargetHashCollisionException((string)$row['targetHash'], (string)$row['targetKey'], $expected);
            }

            $indexIds[] = (int)$row['id'];
        }

        return $this->page(['u.indexId' => $indexIds], $criteria ?? new UsageCriteria());
    }

    /**
     * @param array<mixed> $subject A condition on the index (`i`) or usage (`u`) rows.
     */
    private function page(array $subject, UsageCriteria $criteria): UsagePage
    {
        $query = (new Query())
            ->from(['u' => UsageRecord::TABLE])
            ->innerJoin(['i' => IndexRecord::TABLE], '[[i.id]] = [[u.indexId]]')
            ->where($subject)
            ->andFilterWhere(['u.siteId' => $criteria->siteId, 'u.fieldId' => $criteria->fieldId]);

        $total = (int)(clone $query)->count();
        $rows = $query
            ->select([
                'u.id', 'u.indexId', 'u.elementId', 'u.siteId', 'u.fieldId', 'u.layoutElementUid', 'u.linkUid', 'u.sortOrder', 'u.label',
                'i.targetKey', 'i.linkType', 'elementType' => 'e.type', 'elementVersion' => 'e.dateUpdated',
                'elementTrashed' => 'e.dateDeleted', 'inSite' => 'es.id', 'siteDeleted' => 'si.dateDeleted',
                'indexedVersion' => 's.elementDateUpdated', 's.unreadableValues',
            ])
            // Usage goes with its element and site (foreign keys), so their rows are there.
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[u.elementId]]')
            ->innerJoin(['si' => Table::SITES], '[[si.id]] = [[u.siteId]]')
            ->leftJoin(['es' => Table::ELEMENTS_SITES], '[[es.elementId]] = [[u.elementId]] AND [[es.siteId]] = [[u.siteId]]')
            ->leftJoin(['s' => SourceRecord::TABLE], '[[s.elementId]] = [[u.elementId]] AND [[s.siteId]] = [[u.siteId]]')
            ->orderBy(['u.elementId' => SORT_ASC, 'u.siteId' => SORT_ASC, 'u.fieldId' => SORT_ASC, 'u.layoutElementUid' => SORT_ASC, 'u.sortOrder' => SORT_ASC, 'u.id' => SORT_ASC])
            ->offset(($criteria->page - 1) * UsageCriteria::PAGE_SIZE)
            ->limit(UsageCriteria::PAGE_SIZE)
            ->all();

        return new UsagePage($this->usages($rows), $total, $criteria->page, UsageCriteria::PAGE_SIZE);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<LinkUsage>
     */
    private function usages(array $rows): array
    {
        [$elements, $ownerUnavailable] = $this->elements($rows);
        $user = Craft::$app->getUser()->getIdentity();
        $usages = [];

        foreach ($rows as $row) {
            $elementId = (int)$row['elementId'];
            $siteId = (int)$row['siteId'];
            $fieldId = (int)$row['fieldId'];
            $layoutElementUid = (string)$row['layoutElementUid'];
            $elementType = (string)$row['elementType'];
            $typeAvailable = is_subclass_of($elementType, ElementInterface::class);
            $element = $elements["$elementId/$siteId"] ?? null;
            [$field, $fieldState] = $element !== null ? self::placement($element, $layoutElementUid) : [null, null];
            // The field the index recorded, by ID: named for what it is, never as the place it was in.
            $recorded = Craft::$app->getFields()->getFieldById($fieldId);

            $stale = match (true) {
                $row['siteDeleted'] !== null => StaleUsage::SITE_DELETED,
                !$typeAvailable => StaleUsage::SOURCE_TYPE_UNAVAILABLE,
                $row['elementTrashed'] !== null => StaleUsage::SOURCE_TRASHED,
                $row['inSite'] === null => StaleUsage::SOURCE_NOT_IN_SITE,
                isset($ownerUnavailable["$elementId/$siteId"]) => StaleUsage::SOURCE_OWNER_UNAVAILABLE,
                $element === null => StaleUsage::SOURCE_UNLOADABLE,
                $fieldState !== null => $fieldState,
                $row['indexedVersion'] === null || $row['indexedVersion'] !== $row['elementVersion'] => StaleUsage::SAVED_SINCE,
                (int)$row['unreadableValues'] > 0 => StaleUsage::UNREADABLE,
                default => null,
            };

            $usages[] = new LinkUsage(
                id: (int)$row['id'],
                indexId: (int)$row['indexId'],
                targetKey: (string)$row['targetKey'],
                linkType: (string)$row['linkType'],
                elementId: $elementId,
                elementType: $elementType,
                elementTypeName: $typeAvailable ? $elementType::displayName() : null,
                siteId: $siteId,
                // A site that is not deleted is one Craft has.
                siteName: $row['siteDeleted'] === null ? (Craft::$app->getSites()->getSiteById($siteId, true) ?? throw new LogicException("Site $siteId is not deleted, and Craft does not have it."))->getName() : null,
                fieldId: $fieldId,
                fieldHandle: $field?->handle,
                fieldName: $field?->name,
                recordedFieldName: $recorded?->name,
                recordedFieldHandle: $recorded?->handle,
                layoutElementUid: $layoutElementUid,
                linkUid: (string)$row['linkUid'],
                position: (int)$row['sortOrder'],
                label: $row['label'] !== null ? (string)$row['label'] : null,
                element: $element,
                path: $element !== null ? self::path($element, $user) : [],
                stale: $stale,
            );
        }

        return $usages;
    }

    /**
     * The elements holding the occurrences, each in the site of its occurrence, loaded per
     * element type and site. Trashed elements, elements gone from a site, and elements of a type
     * that is not available are not loaded; nor are elements nested in one of a type that is not
     * available, which Craft can't create.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{0: array<string, ElementInterface>, 1: array<string, true>} The elements, and
     * those nested in an element of a type that is not available, by element ID and site ID.
     */
    private function elements(array $rows): array
    {
        $wanted = [];

        foreach ($rows as $row) {
            $wanted[(int)$row['siteId']][(string)$row['elementType']][] = (int)$row['elementId'];
        }

        $elements = [];
        $ownerUnavailable = [];

        foreach ($wanted as $siteId => $byType) {
            foreach ($byType as $type => $elementIds) {
                [$loaded, $unavailable] = self::loadOfType($type, $siteId, $elementIds, []);

                foreach ($loaded as $element) {
                    $elements["$element->id/$siteId"] = $element;
                }

                foreach ($unavailable as $elementId) {
                    $ownerUnavailable["$elementId/$siteId"] = true;
                }
            }
        }

        return [$elements, $ownerUnavailable];
    }

    /**
     * Loads elements of one type in one site, in one query. Nested elements get their owners
     * first, loaded the same way (and theirs, level by level), and handed to Craft as it creates
     * each element, as Craft does for a query of one owner's elements: without them, Craft looks
     * each owner up on its own while creating the element.
     *
     * Craft can't create an element nested, at any depth, in an element of a type that is not
     * available (creating it reads its owner's type), so such elements are left out and named.
     *
     * @param list<int> $ids
     * @param array<int, true> $ancestors The elements these are owners of, and theirs: an owner
     * among them (a cycle) is not loaded again.
     * @return array{0: list<ElementInterface>, 1: list<int>} The elements, and the IDs of those
     * nested in an element of a type that is not available.
     */
    private static function loadOfType(string $type, int $siteId, array $ids, array $ancestors): array
    {
        // An element type whose plugin is gone can't be loaded; the occurrence says so.
        if (!is_subclass_of($type, ElementInterface::class)) {
            return [[], []];
        }

        $ids = array_values(array_unique($ids));
        $owners = [];
        $unavailable = [];

        if (is_subclass_of($type, NestedElementInterface::class)) {
            $ownerOf = [];

            foreach ($type::find()->id($ids)->siteId($siteId)->status(null)->asArray()->all() as $row) {
                if (!empty($row['primaryOwnerId'])) {
                    $ownerOf[(int)$row['id']] = (int)$row['primaryOwnerId'];
                }
            }

            $ownerIds = array_values(array_filter(array_unique($ownerOf), static fn(int $id): bool => !isset($ancestors[$id])));
            [$owners, $ownersUnavailable] = self::loadById($ownerIds, $siteId, $ancestors + array_fill_keys($ids, true));
            $unavailable = array_keys(array_filter($ownerOf, static fn(int $ownerId): bool => in_array($ownerId, $ownersUnavailable, true)));
            $ids = array_values(array_diff($ids, $unavailable));

            if ($ids === []) {
                return [[], $unavailable];
            }
        }

        $elementQuery = $type::find()->id($ids)->siteId($siteId)->status(null);

        if ($owners !== []) {
            $elementQuery->on(ElementQuery::EVENT_BEFORE_POPULATE_ELEMENT, static function(PopulateElementEvent $event) use ($owners): void {
                $owner = $owners[(int)($event->row['primaryOwnerId'] ?? 0)] ?? null;

                // A canonical nested element's owner is its primary owner.
                if ($owner !== null) {
                    $event->row['owner'] = $owner;
                    $event->row['primaryOwner'] = $owner;
                }
            });
        }

        return [$elementQuery->all(), $unavailable];
    }

    /**
     * Loads elements of any type in one site: their types in one query, then each type at once.
     *
     * @param list<int> $ids
     * @param array<int, true> $ancestors
     * @return array{0: array<int, ElementInterface>, 1: list<int>} The elements by ID, and the IDs
     * of those of a type that is not available, or nested in one.
     */
    private static function loadById(array $ids, int $siteId, array $ancestors): array
    {
        if ($ids === []) {
            return [[], []];
        }

        $byType = [];

        foreach ((new Query())->select(['type', 'id'])->from(Table::ELEMENTS)->where(['id' => $ids])->all() as $row) {
            $byType[(string)$row['type']][] = (int)$row['id'];
        }

        $loaded = [];
        $unavailable = [];

        foreach ($byType as $type => $typeIds) {
            if (!is_subclass_of($type, ElementInterface::class)) {
                array_push($unavailable, ...$typeIds);

                continue;
            }

            [$elements, $nestedUnavailable] = self::loadOfType($type, $siteId, $typeIds, $ancestors);
            array_push($unavailable, ...$nestedUnavailable);

            foreach ($elements as $element) {
                $loaded[(int)$element->id] = $element;
            }
        }

        return [$loaded, $unavailable];
    }

    /**
     * The Smart Links field in a place of an element's layout now, with that placement's name and
     * handle, or why there is none.
     *
     * @return array{0: SmartLinkField|null, 1: StaleUsage|null}
     */
    private static function placement(ElementInterface $element, string $layoutElementUid): array
    {
        $layoutElement = $element->getFieldLayout()?->getElementByUid($layoutElementUid);

        if (!$layoutElement instanceof CustomField) {
            return [null, StaleUsage::FIELD_REMOVED];
        }

        try {
            $field = $layoutElement->getField();
        } catch (FieldNotFoundException) {
            return [null, StaleUsage::FIELD_DELETED];
        }

        return $field instanceof SmartLinkField ? [$field, null] : [null, StaleUsage::FIELD_CHANGED_TYPE];
    }

    /**
     * An element and what it is nested in, outermost first. An owner that can't be loaded is
     * named as such, and the path stops there; an owner met twice ends it too.
     *
     * @return list<array{label: string, url: string|null, via: string|null}>
     */
    private static function path(ElementInterface $element, ?User $user): array
    {
        $path = [];
        $seen = [];
        $current = $element;

        while ($current !== null && !isset($seen[$current->id])) {
            $seen[$current->id] = true;
            $label = trim($current->getUiLabel());
            array_unshift($path, [
                'label' => $label !== '' ? $label : Craft::t('smart-links', '{type} {id}', ['type' => $current::displayName(), 'id' => $current->id]),
                'url' => $user !== null && Craft::$app->getElements()->canView($current, $user) ? $current->getCpEditUrl() : null,
                // The owner's field it is in.
                'via' => $current instanceof NestedElementInterface ? $current->getField()?->name : null,
            ]);

            if (!$current instanceof NestedElementInterface || $current->getOwnerId() === null) {
                break;
            }

            try {
                $owner = $current->getOwner();
            } catch (InvalidConfigException) {
                // Craft's own answer for an owner it can't load (it catches it the same way).
                $owner = null;
            }

            if ($owner === null) {
                array_unshift($path, ['label' => Craft::t('smart-links', 'Owner {id} (can’t be loaded)', ['id' => $current->getOwnerId()]), 'url' => null, 'via' => null]);
            }

            $current = $owner;
        }

        return $path;
    }
}
