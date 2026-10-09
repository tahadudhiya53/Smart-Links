<?php

namespace Tahadudhiya\SmartLinks\Tests\integration;

use ArrayObject;
use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Address;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\PropagationMethod;
use craft\events\RegisterComponentTypesEvent;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\services\Gc;
use craft\web\TemplateResponseBehavior;
use craft\web\View;
use DateTime;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SmartLinks\console\controllers\SmartLinksController;
use Tahadudhiya\SmartLinks\controllers\LinksController;
use Tahadudhiya\SmartLinks\enums\HealthState;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;
use Tahadudhiya\SmartLinks\errors\IndexLockedException;
use Tahadudhiya\SmartLinks\events\RegisterSocialNetworksEvent;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;
use Tahadudhiya\SmartLinks\jobs\RebuildIndex;
use Tahadudhiya\SmartLinks\jobs\UpdateIndex;
use Tahadudhiya\SmartLinks\linktypes\EmailLinkType;
use Tahadudhiya\SmartLinks\linktypes\ProductLinkType;
use Tahadudhiya\SmartLinks\linktypes\social\SocialNetwork;
use Tahadudhiya\SmartLinks\linktypes\SocialLinkType;
use Tahadudhiya\SmartLinks\models\CanonicalUrl;
use Tahadudhiya\SmartLinks\models\IndexingResult;
use Tahadudhiya\SmartLinks\models\InventoryCriteria;
use Tahadudhiya\SmartLinks\models\InventoryPage;
use Tahadudhiya\SmartLinks\models\LinkCollection;
use Tahadudhiya\SmartLinks\models\TargetIdentity;
use Tahadudhiya\SmartLinks\records\HealthRecord;
use Tahadudhiya\SmartLinks\records\IndexRecord;
use Tahadudhiya\SmartLinks\records\SourceRecord;
use Tahadudhiya\SmartLinks\records\UsageRecord;
use Tahadudhiya\SmartLinks\services\Index;
use Tahadudhiya\SmartLinks\services\LinkTypes;
use Tahadudhiya\SmartLinks\SmartLinks;
use Tahadudhiya\SmartLinks\Tests\_support\ElementFixture;
use Tahadudhiya\SmartLinks\Tests\_support\FieldFixture;
use Tahadudhiya\SmartLinks\Tests\_support\TestUser;
use yii\base\Event;
use yii\db\Transaction;
use yii\queue\PushEvent;
use yii\queue\Queue;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

/**
 * The link index and the inventory read from it, against real Craft content in two sites: full
 * rebuilds, updates that follow saves, deletions and restores, targets that change, drafts,
 * unreadable values, stale rows, the inventory's search, filters, sorting and pages, and who may
 * see and rebuild it.
 *
 * Each test runs in a savepoint of the fixture's transaction, so its rows never reach another
 * test or the host project. Index jobs that saves queue are caught as they are pushed and run
 * here, rather than by the host's queue.
 */
final class LinkIndexTest extends TestCase
{
    use FieldFixture;
    use ElementFixture;

    /** A Matrix field whose nested entries have a Smart Links field. */
    private const BLOCKS = 'smartLinksTestBlocks';

    private ?Transaction $savepoint = null;

    /** @var list<\yii\queue\JobInterface> */
    private array $pushed = [];

    /** What the console controller last printed. */
    private string $consoleOut = '';

    /** @var array<string, mixed>|null The test page layout's config as first made. */
    private ?array $originalLayout = null;

    protected static function usesTestTypes(): bool
    {
        return false;
    }

    protected static function extraLayoutFields(): array
    {
        $blockType = new EntryType(['name' => 'Smart Links test block', 'handle' => 'smartLinksTestBlock', 'hasTitleField' => false, 'titleFormat' => 'Block']);
        $blockType->setFieldLayout(self::layoutWith(Entry::class));
        self::assertTrue(Craft::$app->getEntries()->saveEntryType($blockType), Json::encode($blockType->getErrors()));
        $matrix = Craft::$app->getFields()->createField(['type' => Matrix::class, 'name' => self::BLOCKS, 'handle' => self::BLOCKS, 'entryTypes' => [$blockType]]);
        self::assertTrue(Craft::$app->getFields()->saveField($matrix), Json::encode($matrix->getErrors()));

        return [new CustomField($matrix)];
    }

    public static function setUpBeforeClass(): void
    {
        self::setUpFixture();
        self::setUpElementFixture();

        // The URL-only field in the layouts of categories, assets, users and products too, which
        // keep a layout ID of their own (categories, assets, products) or none (users).
        $group = self::$categoryGroup;
        $group->setFieldLayout(self::layoutWith(Category::class));
        self::assertTrue(Craft::$app->getCategories()->saveGroup($group), Json::encode($group->getErrors()));
        self::$publicVolume->setFieldLayout(self::layoutWith(Asset::class));
        self::assertTrue(Craft::$app->getVolumes()->saveVolume(self::$publicVolume), Json::encode(self::$publicVolume->getErrors()));
        $userLayout = Craft::$app->getFields()->getLayoutByType(User::class);
        $userLayout->setTabs([...$userLayout->getTabs(), ...self::layoutWith(User::class)->getTabs()]);
        self::assertTrue(Craft::$app->getUsers()->saveLayout($userLayout), Json::encode($userLayout->getErrors()));

        if (self::$productTypeId !== null) {
            $productTypes = self::call(Craft::$app->getPlugins()->getPlugin('commerce'), 'getProductTypes');
            $productType = $productTypes->getProductTypeById(self::$productTypeId);
            $productType->setFieldLayout(self::layoutWith(ProductLinkType::PRODUCT_CLASS));
            self::assertTrue($productTypes->saveProductType($productType), Json::encode($productType->getErrors()));
        }

        // The host's users are sources now: indexed once here, so every test starts from an index
        // that is up to date.
        SmartLinks::getInstance()->getIndex()->rebuild();
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownFixture();
        self::tearDownElementFixture();
    }

    /**
     * A layout holding the URL-only Smart Links field.
     */
    private static function layoutWith(string $elementType): FieldLayout
    {
        $layout = new FieldLayout(['type' => $elementType]);
        $layout->setTabs([['name' => 'Links', 'elements' => [new CustomField(Craft::$app->getFields()->getFieldByHandle(self::URL_ONLY))]]]);

        return $layout;
    }

    protected function setUp(): void
    {
        $this->savepoint = Craft::$app->getDb()->beginTransaction();
        Event::on(Queue::class, Queue::EVENT_AFTER_PUSH, [$this, 'catchJob']);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        Event::off(Queue::class, Queue::EVENT_AFTER_PUSH, [$this, 'catchJob']);
        $this->restoreConsoleRequest();
        Craft::$app->getUser()->setIdentity(null);

        if ($this->savepoint?->getIsActive()) {
            $this->savepoint->rollBack();
        }
    }

    public function catchJob(PushEvent $event): void
    {
        $this->pushed[] = $event->job;
    }

    // Full rebuild

    public function testARebuildIndexesEveryLinkInEverySiteWithItsTarget(): void
    {
        $about = self::page('index-about');
        $home = self::page('index-home', [self::LINKS => [
            ['type' => 'url', 'data' => ['url' => 'https://example.com/Pricing?plan=pro#faq'], 'label' => 'Pricing', 'urlSuffix' => '?utm=x'],
            ['type' => 'entry', 'data' => ['elementId' => $about->id]],
            ['type' => 'email', 'data' => ['address' => 'Hello@Example.COM']],
        ]]);

        $result = $this->index()->rebuild();

        self::assertSame(0, $result->problemCount, implode("\n", $result->problems));
        // The value was propagated to every site, so each link occurs once per site.
        self::assertCount(3 * self::siteCount(), $this->usages($home));

        foreach (self::siteIds() as $siteId) {
            $rows = $this->usages($home, $siteId);
            self::assertSame([1, 2, 3], array_column($rows, 'sortOrder'));
            self::assertSame(['Pricing', null, null], array_column($rows, 'label'));
            self::assertSame((string)self::$instances[self::LINKS]->layoutElement?->uid, $rows[0]['layoutElementUid']);
        }

        // An absolute URL and an email address are the same target in every site; an entry is
        // linked in the site the link is in.
        $url = $this->target('url?url=' . rawurlencode('https://example.com/Pricing?plan=pro#faq'));
        self::assertSame(self::siteCount(), $this->usageCount($url['id']));
        // The target, not the occurrence: the link's URL suffix belongs to the link.
        self::assertSame('https://example.com/Pricing?plan=pro#faq', $url['resolvedUrl']);
        self::assertSame(ResolutionStatus::RESOLVED->value, $url['targetStatus']);
        self::assertSame(hash('sha256', 'https://example.com/Pricing?plan=pro'), $url['healthUrlHash']);

        foreach (self::siteIds() as $siteId) {
            $entry = $this->target("entry?elementId=$about->id&siteId=$siteId");
            self::assertSame(1, $this->usageCount($entry['id']));
            self::assertSame('index-about', $entry['targetLabel']);
            self::assertSame((string)$about->id, (string)$entry['targetElementId']);
            self::assertStringEndsWith('smart-links-test/index-about', (string)$entry['resolvedUrl']);
        }

        $email = $this->target('email?address=' . rawurlencode('Hello@example.com'));
        self::assertSame('mailto:Hello@example.com', $email['resolvedUrl']);
        // A mailto: URL has nothing a health check could request.
        self::assertNull($email['healthUrlHash']);

        // Every site of both entries was read, and is recorded as read in the version it had.
        foreach ([$about, $home] as $entry) {
            foreach (self::siteIds() as $siteId) {
                self::assertSame($this->elementVersion($entry), SourceRecord::findOne(['elementId' => $entry->id, 'siteId' => $siteId])?->elementDateUpdated);
            }
        }

        self::assertFalse($this->index()->status()->isStale());
    }

    public function testIndexingTheSameContentAgainChangesNothingAndAddsNothing(): void
    {
        $home = self::page('index-repeat', [
            self::LINKS => [['type' => 'url', 'data' => ['url' => 'https://example.com/a']], ['type' => 'url', 'data' => ['url' => 'https://example.com/a']]],
            self::LINKS_AGAIN => [['type' => 'url', 'data' => ['url' => 'https://example.com/a']]],
        ]);

        $this->index()->rebuild();
        $before = $this->snapshot();

        $this->index()->rebuild();
        $this->index()->updateElements([(int)$home->id, (int)$home->id]);
        $this->runPushed();

        // Same rows, same IDs, nothing rewritten.
        self::assertSame($before, $this->snapshot());
        // Two links to one URL in one value, and the field placed a second time with one more:
        // three occurrences of one target in each site.
        $target = $this->target('url?url=' . rawurlencode('https://example.com/a'));
        self::assertSame(3 * self::siteCount(), $this->usageCount($target['id']));
        self::assertSame(1, (int)IndexRecord::find()->where(['targetKey' => 'url?url=' . rawurlencode('https://example.com/a')])->count());
    }

    public function testARebuildRunsInBatchesOfElementsInIdOrder(): void
    {
        $entries = [self::page('index-batch-1', [self::SINGLE => [['type' => 'url', 'data' => ['url' => 'https://example.com/1']]]]),
            self::page('index-batch-2', [self::SINGLE => [['type' => 'url', 'data' => ['url' => 'https://example.com/2']]]]), ];
        $result = new IndexingResult();
        $before = (int)$entries[0]->id - 1;

        [$after, $count] = $this->index()->rebuildBatch($before, 1, $result);
        self::assertSame([(int)$entries[0]->id, 1], [$after, $count]);
        self::assertCount(self::siteCount(), $this->usages($entries[0]));
        self::assertCount(0, $this->usages($entries[1]));

        [$after] = $this->index()->rebuildBatch((int)$after, 1, $result);
        self::assertSame((int)$entries[1]->id, $after);
        self::assertCount(self::siteCount(), $this->usages($entries[1]));
    }

    public function testTheRebuildJobQueuesItsNextBatchAndTheLastOneFinishes(): void
    {
        $entry = self::page('index-job', [self::SINGLE => [['type' => 'url', 'data' => ['url' => 'https://example.com/job']]]]);
        $this->pushed = [];

        $this->index()->queueRebuild();
        $job = $this->rebuildJobs()[0] ?? null;
        self::assertInstanceOf(RebuildIndex::class, $job);
        self::assertNotSame('', $job->startedAt);

        // A full batch queues the next one, after its last element, once it is written.
        $this->pushed = [];
        (new RebuildIndex(['startedAt' => $job->startedAt, 'afterElementId' => (int)$entry->id - 1, 'batchSize' => 1]))->execute(Craft::$app->getQueue());
        $next = $this->rebuildJobs()[0] ?? null;
        self::assertInstanceOf(RebuildIndex::class, $next);
        self::assertSame([$job->startedAt, (int)$entry->id, 1, 1, false], [$next->startedAt, $next->afterElementId, $next->done, $next->batchSize, $next->finishing]);
        self::assertCount(self::siteCount(), $this->usages($entry));

        // The walk's last batch queues what is left behind it; that phase's last batch finishes
        // the rebuild, and only it removes what no source accounts for.
        $this->pushed = [];
        $last = (int)(new Query())->from(Table::ELEMENTS)->max('id');
        (new RebuildIndex(['startedAt' => $job->startedAt, 'afterElementId' => $last]))->execute(Craft::$app->getQueue());
        $finishing = $this->rebuildJobs()[0] ?? null;
        self::assertInstanceOf(RebuildIndex::class, $finishing);
        self::assertSame([true, 0, $job->startedAt], [$finishing->finishing, $finishing->afterElementId, $finishing->startedAt]);

        $unused = $this->index()->targetId(TargetIdentity::forUrl('url', CanonicalUrl::parse('https://example.com/unused-until-the-end'), null));
        $this->pushed = [];
        $finishing->execute(Craft::$app->getQueue());
        self::assertSame([], $this->pushed, 'The last batch finishes the rebuild rather than queueing another.');
        self::assertNull(IndexRecord::findOne($unused));
    }

    public function testARebuildRemovesRowsOfContentThatIsGoneAndKeepsTheRest(): void
    {
        $kept = self::page('index-kept', [self::SINGLE => [['type' => 'url', 'data' => ['url' => 'https://example.com/kept']]]]);
        $trashed = self::page('index-trashed', [self::SINGLE => [['type' => 'url', 'data' => ['url' => 'https://example.com/trashed']]]]);
        $this->index()->rebuild();

        // Trashed without Craft's events, as if the index had missed it.
        Db::update(Table::ELEMENTS, ['dateDeleted' => Db::prepareDateForDb(new DateTime())], ['id' => $trashed->id]);
        self::assertSame(self::siteCount(), $this->index()->status()->orphanedSources);

        $result = $this->index()->rebuild();

        self::assertSame([], $this->usages($trashed));
        self::assertNull(SourceRecord::findOne(['elementId' => $trashed->id]));
        self::assertNull(IndexRecord::findOne(['targetKey' => 'url?url=' . rawurlencode('https://example.com/trashed')]));
        self::assertCount(self::siteCount(), $this->usages($kept));
        self::assertSame(self::siteCount(), $result->removedSources);
        self::assertFalse($this->index()->status()->isStale());
    }

    // Following content

    public function testSavingContentQueuesAnUpdateThatFollowsIt(): void
    {
        $entry = self::page('index-follow', [self::URL_ONLY => [
            ['type' => 'url', 'data' => ['url' => 'https://example.com/stays']],
            ['type' => 'url', 'data' => ['url' => 'https://example.com/goes']],
        ]]);
        $jobs = $this->runPushed();

        self::assertCount(1, $jobs);
        self::assertInstanceOf(UpdateIndex::class, $jobs[0]);
        self::assertSame([(int)$entry->id], $jobs[0]->elementIds);
        self::assertCount(2 * self::siteCount(), $this->usages($entry));

        $reloaded = $this->reload($entry);
        $reloaded->setFieldValue(self::URL_ONLY, self::entered([
            ['type' => 'url', 'data' => ['url' => 'https://example.com/stays']],
            ['type' => 'url', 'data' => ['url' => 'https://example.com/new'], 'label' => 'New'],
        ]));
        self::assertTrue(Craft::$app->getElements()->saveElement($reloaded), Json::encode($reloaded->getErrors()));
        // One job for the save, however many sites it propagated to.
        self::assertCount(1, $this->runPushed());

        $urls = array_map(fn(array $row): string => $this->keyOf((int)$row['indexId']), $this->usages($entry, self::$primarySiteId));
        self::assertSame(['url?url=' . rawurlencode('https://example.com/stays'), 'url?url=' . rawurlencode('https://example.com/new')], $urls);
        // A target nothing uses any more is gone; its URL's health, if any, is not the index's.
        self::assertNull(IndexRecord::findOne(['targetKey' => 'url?url=' . rawurlencode('https://example.com/goes')]));
        self::assertFalse($this->index()->status()->isStale());
    }

    public function testDraftsAndRevisionsAreNeitherSourcesNorTargets(): void
    {
        $entry = self::page('index-draft', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/live']]]]);
        $this->runPushed();

        $draft = Craft::$app->getDrafts()->createDraft($this->reload($entry), Craft::$app->getUser()->getId() ?? 1);
        $draft->setFieldValue(self::URL_ONLY, self::entered([['type' => 'url', 'data' => ['url' => 'https://example.com/draft-only']]]));
        self::assertTrue(Craft::$app->getElements()->saveElement($draft));

        // Saving the draft queues nothing, and indexing its ID indexes nothing.
        self::assertSame([], $this->runPushed());
        $this->index()->updateElements([(int)$draft->id]);
        self::assertSame([], $this->usages($draft));
        self::assertNull(IndexRecord::findOne(['targetKey' => 'url?url=' . rawurlencode('https://example.com/draft-only')]));

        // Its links count once it is applied, which saves the canonical entry.
        Craft::$app->getDrafts()->applyDraft($draft);
        $this->runPushed();
        self::assertNotNull(IndexRecord::findOne(['targetKey' => 'url?url=' . rawurlencode('https://example.com/draft-only')]));
        self::assertNull(IndexRecord::findOne(['targetKey' => 'url?url=' . rawurlencode('https://example.com/live')]));
    }

    public function testDisabledContentIsIndexed(): void
    {
        // Its links are still in content, and will be live once it is enabled.
        $entry = self::savedEntry('index-disabled', [self::DEFAULTS => new LinkCollection(), self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/disabled-source']]]], enabled: false);
        $this->runPushed();

        self::assertCount(self::siteCount(), $this->usages($entry));
    }

    public function testASourceIsLinkedOnlyForThoseWhoMayViewIt(): void
    {
        $entry = self::page('index-viewable', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/viewable']]]]);
        $this->index()->rebuild();

        Craft::$app->getUser()->setIdentity($this->user(['accessCp', SmartLinks::PERMISSION_VIEW_INVENTORY]));
        $source = $this->item($this->inventory(['search' => 'viewable']), 'https://example.com/viewable')->source;
        self::assertSame(['label' => 'index-viewable', 'url' => null], $source);

        Craft::$app->getUser()->setIdentity(self::author());
        $source = $this->item($this->inventory(['search' => 'viewable']), 'https://example.com/viewable')->source;
        self::assertSame($entry->getCpEditUrl(), $source['url'] ?? null);
    }

    public function testDeletingASourceRemovesItsUsageAndRestoringItBringsItBack(): void
    {
        $entry = self::page('index-delete', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/only-here']]]]);
        $this->runPushed();
        $key = 'url?url=' . rawurlencode('https://example.com/only-here');
        self::assertNotNull(IndexRecord::findOne(['targetKey' => $key]));

        self::assertTrue(Craft::$app->getElements()->deleteElement($entry));
        $this->runPushed();

        self::assertSame([], $this->usages($entry));
        self::assertNull(SourceRecord::findOne(['elementId' => $entry->id]));
        self::assertNull(IndexRecord::findOne(['targetKey' => $key]));

        $trashed = Entry::find()->id($entry->id)->trashed()->status(null)->one();
        self::assertNotNull($trashed);
        self::assertTrue(Craft::$app->getElements()->restoreElement($trashed));
        $this->runPushed();

        self::assertCount(self::siteCount(), $this->usages($entry));
        self::assertNotNull(IndexRecord::findOne(['targetKey' => $key]));

        // Deleted for good, its rows go with it, and so does the target only it used. (A category,
        // which has no revisions: PostgreSQL refuses Craft's hard delete of an entry with revisions
        // inside a transaction, as this test's is, Smart Links or not.)
        $category = $this->save(self::savedCategory('index-delete-category'), [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/only-in-category']]]]);
        $this->runPushed();
        self::assertNotSame([], $this->usages($category));
        self::assertTrue(Craft::$app->getElements()->deleteElement($category, true));
        $this->runPushed();
        self::assertSame([], $this->usages($category));
        self::assertNull(IndexRecord::findOne(['targetKey' => 'url?url=' . rawurlencode('https://example.com/only-in-category')]));
    }

    public function testATargetThatChangesIsResolvedAgain(): void
    {
        $target = self::page('index-target');
        $source = self::page('index-source', [self::LINKS => [['type' => 'entry', 'data' => ['elementId' => $target->id]]]]);
        $this->runPushed();
        $key = "entry?elementId=$target->id&siteId=" . self::$primarySiteId;
        self::assertStringEndsWith('/index-target', (string)$this->target($key)['resolvedUrl']);

        // A new URI, and then disabled: the target's own saves are what change where it leads.
        $moved = $this->reload($target);
        $moved->slug = 'index-target-moved';
        $moved->title = 'Moved';
        self::assertTrue(Craft::$app->getElements()->saveElement($moved));
        $this->runPushed();
        self::assertStringEndsWith('/index-target-moved', (string)$this->target($key)['resolvedUrl']);
        self::assertSame('Moved', $this->target($key)['targetLabel']);

        $disabled = $this->reload($target);
        $disabled->enabled = false;
        self::assertTrue(Craft::$app->getElements()->saveElement($disabled));
        $this->runPushed();
        self::assertSame(ResolutionStatus::DISABLED->value, $this->target($key)['targetStatus']);
        self::assertNull($this->target($key)['resolvedUrl']);
        self::assertNull($this->target($key)['healthUrlHash']);

        // Deleted, it is missing, and still listed: the link to it is what needs fixing.
        self::assertTrue(Craft::$app->getElements()->deleteElement($this->reload($target, enabledOnly: false)));
        $this->runPushed();
        self::assertSame(ResolutionStatus::MISSING->value, $this->target($key)['targetStatus']);
        self::assertCount(1, $this->usages($source, self::$primarySiteId));
    }

    public function testATargetsElementGoneWithoutItsEventsIsReportedAsOutdated(): void
    {
        $target = self::page('index-outdated');
        self::page('index-outdated-source', [self::LINKS => [['type' => 'entry', 'data' => ['elementId' => $target->id]]]]);
        $this->index()->rebuild();

        Db::update(Table::ELEMENTS, ['dateDeleted' => Db::prepareDateForDb(new DateTime())], ['id' => $target->id]);

        // Every site's version of the target was recorded as leading somewhere.
        self::assertSame(self::siteCount(), $this->index()->status()->outdatedTargets);
        $this->index()->rebuild();
        self::assertSame(0, $this->index()->status()->outdatedTargets);
        self::assertSame(ResolutionStatus::MISSING->value, $this->target("entry?elementId=$target->id&siteId=" . self::$primarySiteId)['targetStatus']);
    }

    public function testAValueThatCannotBeReadKeepsItsLinksAsLastIndexed(): void
    {
        $entry = self::page('index-unreadable', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/kept-as-is']]]]);
        $this->index()->rebuild();
        $before = $this->usages($entry);

        // Content no link type can read, as when a link type's plugin has been removed.
        $uid = (string)self::$instances[self::URL_ONLY]->layoutElement?->uid;
        $content = Json::decode((string)(new Query())->select('content')->from(Table::ELEMENTS_SITES)->where(['elementId' => $entry->id, 'siteId' => self::$primarySiteId])->scalar());
        $content[$uid] = ['version' => 1, 'links' => [['uid' => StringHelper::UUID(), 'type' => 'gone-type', 'data' => ['x' => 1]]]];
        // Craft encodes a JSON column's value itself.
        Db::update(Table::ELEMENTS_SITES, ['content' => $content], ['elementId' => $entry->id, 'siteId' => self::$primarySiteId]);
        Craft::$app->getElements()->invalidateCachesForElement($entry);

        $result = $this->index()->updateElements([(int)$entry->id]);

        self::assertSame(1, $result->unreadableValues);
        self::assertSame(1, $result->problemCount);
        self::assertSame($before, $this->usages($entry));
    }

    // Stale index detection

    public function testEveryKindOfDifferenceFromContentIsReported(): void
    {
        $entry = self::page('index-stale', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/stale']]]]);
        $this->index()->rebuild();
        self::assertFalse($this->index()->status()->isStale());

        // Saved without the index hearing of it.
        Db::update(Table::ELEMENTS, ['dateUpdated' => Db::prepareDateForDb(new DateTime('+1 minute'))], ['id' => $entry->id]);
        self::assertSame(self::siteCount(), $this->index()->status()->unindexedSources);

        // A field no longer in the layout, and a target nothing uses.
        $usage = $this->usages($entry, self::$primarySiteId)[0];
        Db::update(UsageRecord::TABLE, ['layoutElementUid' => StringHelper::UUID()], ['id' => $usage['id']]);
        $unused = $this->index()->targetId(TargetIdentity::forUrl('url', CanonicalUrl::parse('https://example.com/unused'), null));

        $status = $this->index()->status();
        self::assertSame(1, $status->orphanedUsages);
        self::assertSame(1, $status->unusedTargets);
        self::assertSame(self::siteCount() + 2, $status->staleCount());

        $this->index()->rebuild();
        self::assertFalse($this->index()->status()->isStale());
        self::assertNull(IndexRecord::findOne($unused));
    }

    public function testAnElementNeverIndexedIsReportedUntilItIs(): void
    {
        // Saved while nothing indexed it: its update job never ran.
        $entry = self::page('index-never', [self::SINGLE => [['type' => 'url', 'data' => ['url' => 'https://example.com/never']]]]);
        $this->pushed = [];

        self::assertSame(self::siteCount(), $this->index()->status()->unindexedSources);
        $this->index()->updateElements([(int)$entry->id]);
        self::assertSame(0, $this->index()->status()->unindexedSources);
    }

    // Multisite

    public function testEachSitesValueIsIndexedForThatSite(): void
    {
        $about = self::page('index-sites-about');
        $entry = self::page('index-sites', [self::LINKS => [['type' => 'entry', 'data' => ['elementId' => $about->id]]]]);

        // The field is translated per site: the second site links elsewhere.
        $second = Entry::find()->id($entry->id)->siteId(self::$secondSiteId)->status(null)->one();
        self::assertNotNull($second);
        $second->setFieldValue(self::LINKS, self::entered([['type' => 'url', 'data' => ['url' => '/kontakt'], 'label' => 'Kontakt']]));
        self::assertTrue(Craft::$app->getElements()->saveElement($second));
        $this->runPushed();

        self::assertSame(["entry?elementId=$about->id&siteId=" . self::$primarySiteId], array_map(fn(array $row): string => $this->keyOf((int)$row['indexId']), $this->usages($entry, self::$primarySiteId)));

        // A root-relative URL is the second site's path, checked on the second site's host.
        $relative = $this->target('url?siteId=' . self::$secondSiteId . '&url=' . rawurlencode('/kontakt'));
        self::assertSame([$relative['id']], array_map(static fn(array $row): int => (int)$row['indexId'], $this->usages($entry, self::$secondSiteId)));
        self::assertSame(hash('sha256', 'https://second.example.test/kontakt'), $relative['healthUrlHash']);

        // The inventory filtered by site counts that site's usage only.
        $second = $this->inventory(['sourceSite' => 'smartLinksTestSecond', 'search' => 'kontakt']);
        self::assertSame(1, $second->total);
        self::assertSame(['Kontakt'], array_map(static fn($item) => $item->label, $second->items));
        self::assertSame(0, $this->inventory(['sourceSite' => Craft::$app->getSites()->getPrimarySite()->handle, 'search' => 'kontakt'])->total);
    }

    public function testARootRelativeUrlIsCheckedOnItsSitesHost(): void
    {
        self::assertSame('https://second.example.test/a/b?c#', Index::healthUrl('/a/b?c#', self::$secondSiteId) . '#');
        self::assertSame('https://example.com/x', Index::healthUrl('https://example.com/x#y', null));
        self::assertNull(Index::healthUrl('/a', null));
        self::assertNull(Index::healthUrl('mailto:a@example.com', self::$primarySiteId));
        self::assertNull(Index::healthUrl('#anchor', self::$primarySiteId));
        self::assertNull(Index::healthUrl(null, self::$primarySiteId));
    }

    // The inventory

    public function testTheInventoryIsPaginated(): void
    {
        $links = [];

        for ($i = 1; $i <= InventoryCriteria::PAGE_SIZE + 1; $i++) {
            $links[] = ['type' => 'url', 'data' => ['url' => sprintf('https://example.com/paged-%02d', $i)]];
        }

        self::page('index-paged', [self::URL_ONLY => $links]);
        $this->index()->rebuild();

        $first = $this->inventory(['search' => 'paged-', 'sort' => 'link']);
        $second = $this->inventory(['search' => 'paged-', 'sort' => 'link', 'page' => '2']);

        self::assertSame(InventoryCriteria::PAGE_SIZE + 1, $first->total);
        self::assertSame(2, $first->pageCount());
        self::assertCount(InventoryCriteria::PAGE_SIZE, $first->items);
        self::assertSame([1, InventoryCriteria::PAGE_SIZE], [$first->first(), $first->last()]);
        self::assertSame('https://example.com/paged-01', $first->items[0]->resolvedUrl);
        self::assertSame(['https://example.com/paged-51'], array_map(static fn($item) => $item->resolvedUrl, $second->items));
        self::assertSame([51, 51], [$second->first(), $second->last()]);

        $descending = $this->inventory(['search' => 'paged-', 'sort' => 'link', 'dir' => 'desc']);
        self::assertSame('https://example.com/paged-51', $descending->items[0]->resolvedUrl);
        self::assertSame([], $this->inventory(['search' => 'paged-', 'page' => '3'])->items);
    }

    public function testTheInventoryIsSearchedFilteredAndSorted(): void
    {
        $about = self::page('index-inv-about');
        self::page('index-inv-one', [self::LINKS => [
            ['type' => 'url', 'data' => ['url' => 'https://inv.example.com/shared'], 'label' => 'Shared one'],
            ['type' => 'entry', 'data' => ['elementId' => $about->id], 'label' => 'About us'],
            ['type' => 'email', 'data' => ['address' => 'inv@example.com']],
        ]]);
        self::page('index-inv-two', [self::URL_ONLY => [
            ['type' => 'url', 'data' => ['url' => 'https://inv.example.com/shared'], 'label' => 'Shared two'],
            ['type' => 'url', 'data' => ['url' => 'https://inv.example.com/broken']],
        ]]);
        $this->index()->rebuild();

        // Health is the URL's: one observation answers for every target leading there.
        $broken = CanonicalUrl::parse('https://inv.example.com/broken');
        $health = new HealthRecord();
        $health->setAttributes(['url' => $broken->healthUrl(), 'urlHash' => $broken->healthUrlHash(), 'state' => HealthState::BROKEN->value, 'statusCode' => 404, 'finalUrl' => $broken->healthUrl(), 'dateChecked' => Db::prepareDateForDb(new DateTime())], false);
        self::assertTrue($health->save(false));

        // Search reads target URLs, names and keys, and the labels links give them.
        self::assertSame(['https://inv.example.com/shared'], $this->urls(['search' => 'shared two']));
        // An entry is a target in each site it is linked in.
        self::assertSame(self::siteCount(), $this->inventory(['search' => 'index-inv-about'])->total);
        self::assertSame(1, $this->inventory(['search' => 'INV@EXAMPLE'])->total);

        $all = $this->inventory(['search' => 'inv']);
        $shared = $this->item($all, 'https://inv.example.com/shared');
        // Two links in every site.
        self::assertSame(2 * self::siteCount(), $shared->usageCount);
        self::assertSame(2, $shared->sourceCount);
        self::assertSame(2, $shared->labelCount);
        self::assertSame(HealthState::UNKNOWN, $shared->health, 'Not checked yet is Unknown, never Healthy.');
        self::assertNull($shared->dateChecked);
        self::assertEqualsCanonicalizing(array_map(static fn($site) => $site->getName(), Craft::$app->getSites()->getAllSites()), $shared->siteNames);
        self::assertNotNull($shared->source);

        $brokenItem = $this->item($all, 'https://inv.example.com/broken');
        self::assertSame(HealthState::BROKEN, $brokenItem->health);
        self::assertNotNull($brokenItem->dateChecked);
        self::assertNull($this->item($all, 'mailto:inv@example.com')->health, 'A mailto: URL can’t be checked.');

        // Filters.
        self::assertSame(['mailto:inv@example.com'], $this->urls(['search' => 'inv', 'type' => 'email']));
        self::assertSame(['https://inv.example.com/broken'], $this->urls(['search' => 'inv', 'health' => 'broken']));
        // Nothing a check could request: an email address, and an entry's URL in a site whose base
        // URL is not absolute (which some of the host's sites have).
        $none = $this->urls(['search' => 'inv', 'health' => 'none']);
        self::assertContains('mailto:inv@example.com', $none);
        self::assertSame([], array_filter($none, static fn(?string $url): bool => str_starts_with((string)$url, 'http')));
        self::assertContains('https://inv.example.com/shared', $this->urls(['search' => 'inv', 'health' => 'unknown']));
        self::assertNotContains('https://inv.example.com/broken', $this->urls(['search' => 'inv', 'health' => 'unknown']));
        self::assertSame([], $this->urls(['search' => 'inv', 'target' => 'missing']));
        // Two URLs, an email address, and the entry in each site.
        self::assertCount(3 + self::siteCount(), $this->urls(['search' => 'inv', 'target' => 'resolved']));

        // A source field counts its own usage only, and leaves out targets used elsewhere only.
        $urlOnly = $this->inventory(['search' => 'inv', 'source' => self::URL_ONLY]);
        self::assertSame(2, $urlOnly->total);
        self::assertSame(self::siteCount(), $this->item($urlOnly, 'https://inv.example.com/shared')->usageCount);
        self::assertSame(['Shared two'], [$this->item($urlOnly, 'https://inv.example.com/shared')->label]);

        // Sorting.
        self::assertSame('https://inv.example.com/shared', $this->inventory(['search' => 'inv'])->items[0]->resolvedUrl, 'Most used first by default.');
        self::assertSame('https://inv.example.com/broken', $this->inventory(['search' => 'inv', 'sort' => 'health'])->items[0]->resolvedUrl, 'Worst health first.');
        self::assertSame('mailto:inv@example.com', $this->inventory(['search' => 'inv', 'sort' => 'health', 'dir' => 'desc'])->items[0]->resolvedUrl);
        self::assertSame('https://inv.example.com/broken', $this->inventory(['search' => 'inv', 'sort' => 'checked'])->items[0]->resolvedUrl);
        self::assertSame('email', $this->inventory(['search' => 'inv', 'sort' => 'type'])->items[0]->linkType);
        self::assertSame('url', $this->inventory(['search' => 'inv', 'sort' => 'type', 'dir' => 'desc'])->items[0]->linkType);
    }

    public function testAnObservationOfAnotherUrlUnderTheSameHashIsNotShown(): void
    {
        self::page('index-collision', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/collided']]]]);
        $this->index()->rebuild();
        $url = CanonicalUrl::parse('https://example.com/collided');

        // What a SHA-256 collision would leave: another URL's observation under this URL's hash.
        $health = new HealthRecord();
        $health->setAttributes(['url' => 'https://example.com/other', 'urlHash' => $url->healthUrlHash(), 'state' => HealthState::BROKEN->value, 'statusCode' => 404, 'finalUrl' => 'https://example.com/other', 'dateChecked' => Db::prepareDateForDb(new DateTime())], false);
        self::assertTrue($health->save(false));

        $item = $this->item($this->inventory(['search' => 'collided']), 'https://example.com/collided');
        self::assertSame(HealthState::UNKNOWN, $item->health);
        self::assertNull($item->dateChecked);
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('malformedCriteria')]
    public function testMalformedInventoryParametersAreRefused(array $params): void
    {
        $this->expectException(InvalidArgumentException::class);
        InventoryCriteria::fromParams($params);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedCriteria(): array
    {
        return [
            'unknown site' => [['sourceSite' => 'noSuchSite']],
            'malformed type' => [['type' => 'Not A Handle']],
            'not a Smart Links field' => [['source' => 'title']],
            'unknown health' => [['health' => 'great']],
            'unknown target state' => [['target' => 'fine']],
            'unknown sort' => [['sort' => 'targetKey']],
            'unknown direction' => [['dir' => 'up']],
            'page zero' => [['page' => '0']],
            'page not a number' => [['page' => '2abc']],
            'array instead of text' => [['search' => ['a']]],
            'search too long' => [['search' => str_repeat('a', InventoryCriteria::MAX_SEARCH + 1)]],
        ];
    }

    public function testCriteriaWriteBackOnlyWhatDiffersFromTheDefaults(): void
    {
        $criteria = InventoryCriteria::fromParams(['search' => '0', 'sort' => 'usage', 'dir' => 'desc', 'page' => '1', 'health' => '']);

        self::assertSame('0', $criteria->search, 'A search for “0” is a search.');
        self::assertSame(['search' => '0'], $criteria->params());
        self::assertSame(['search' => '0', 'sort' => 'link', 'page' => 2], $criteria->params(['sort' => 'link', 'page' => 2]));
        self::assertTrue($criteria->isFiltered());
        self::assertFalse(InventoryCriteria::fromParams([])->isFiltered());
    }

    // Who may see and rebuild it

    public function testTheInventoryNeedsItsPermission(): void
    {
        self::page('index-page', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/<b>listed</b>'], 'label' => '<script>alert(1)</script>']]]);
        $this->index()->rebuild();

        $this->assertForbidden(fn() => $this->runLinksAction('index', [], $this->user(['accessCp', 'accessPlugin-smart-links'])));

        [$html, $variables] = $this->rendered($this->runLinksAction('index', ['search' => 'listed'], $this->user(['accessCp', 'accessPlugin-smart-links', SmartLinks::PERMISSION_VIEW_INVENTORY])));

        self::assertStringContainsString('id="smartlinks-inventory"', $html);
        self::assertStringContainsString('https://example.com/%3Cb%3Elisted%3C/b%3E', $html);
        // Labels are content, shown as text.
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        // Rebuilding is a permission of its own.
        self::assertFalse($variables['canRebuild']);

        $this->expectException(BadRequestHttpException::class);
        $this->runLinksAction('index', ['sort' => 'nonsense'], $this->user(['accessCp', 'accessPlugin-smart-links', SmartLinks::PERMISSION_VIEW_INVENTORY]));
    }

    public function testRebuildingNeedsItsOwnPermissionAPostAndACsrfToken(): void
    {
        $viewer = $this->user(['accessCp', 'accessPlugin-smart-links', SmartLinks::PERMISSION_VIEW_INVENTORY]);
        $rebuilder = $this->user(['accessCp', 'accessPlugin-smart-links', SmartLinks::PERMISSION_VIEW_INVENTORY, SmartLinks::PERMISSION_REBUILD_INDEX]);
        $onlyRebuild = $this->user(['accessCp', 'accessPlugin-smart-links', SmartLinks::PERMISSION_REBUILD_INDEX]);

        $this->assertForbidden(fn() => $this->runLinksAction('rebuild', [], $viewer, 'POST'));
        $this->assertForbidden(fn() => $this->runLinksAction('rebuild', [], $onlyRebuild, 'POST'));
        self::assertCount(0, $this->rebuildJobs());

        try {
            $this->runLinksAction('rebuild', [], $rebuilder, 'POST', withCsrf: false);
            self::fail('A rebuild without a CSRF token was accepted.');
        } catch (BadRequestHttpException) {
            self::assertCount(0, $this->rebuildJobs());
        }

        try {
            $this->runLinksAction('rebuild', [], $rebuilder, 'GET');
            self::fail('A rebuild by GET was accepted.');
        } catch (\yii\web\MethodNotAllowedHttpException|BadRequestHttpException) {
            self::assertCount(0, $this->rebuildJobs());
        }

        $response = $this->runLinksAction('rebuild', [], $rebuilder, 'POST');
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->rebuildJobs());

        // Whoever may rebuild is offered it.
        self::assertTrue($this->rendered($this->runLinksAction('index', [], $rebuilder))[1]['canRebuild']);
    }

    public function testTheSubnavShowsTheInventoryToThoseWhoMayViewIt(): void
    {
        $this->useWebRequest();
        Craft::$app->getUser()->setIdentity($this->user(['accessCp', 'accessPlugin-smart-links', SmartLinks::PERMISSION_VIEW_INVENTORY]));
        self::assertSame(['overview', 'links'], array_keys(SmartLinks::getInstance()->getCpNavItem()['subnav'] ?? []));

        Craft::$app->getUser()->setIdentity($this->user(['accessCp', 'accessPlugin-smart-links']));
        self::assertArrayNotHasKey('subnav', SmartLinks::getInstance()->getCpNavItem() ?? []);
    }

    public function testTheInventoryPermissionsAreRegisteredWithRebuildingNestedUnderViewing(): void
    {
        $groups = Craft::$app->getUserPermissions()->getAllPermissions();
        $group = array_values(array_filter($groups, static fn(array $group): bool => $group['heading'] === 'Smart Links'))[0] ?? null;

        self::assertNotNull($group);
        self::assertArrayHasKey(SmartLinks::PERMISSION_VIEW_INVENTORY, $group['permissions']);
        self::assertSame([SmartLinks::PERMISSION_VIEW_USAGE, SmartLinks::PERMISSION_REBUILD_INDEX], array_keys($group['permissions'][SmartLinks::PERMISSION_VIEW_INVENTORY]['nested']));
    }

    // The command line

    public function testTheConsoleCommandsReportAndQueue(): void
    {
        self::assertSame(SmartLinksController::class, Craft::$app->controllerMap['smartlinks'] ?? null);
        self::page('index-console', [self::SINGLE => [['type' => 'url', 'data' => ['url' => 'https://example.com/console']]]]);
        $this->pushed = [];

        $controller = $this->console();
        self::assertSame(1, $controller->runAction('index-status'));
        self::assertStringContainsString('Element sites saved since they were read, or never read: ' . self::siteCount(), $this->consoleOut);

        $controller = $this->console();
        self::assertSame(0, $controller->runAction('reindex'));
        self::assertCount(1, $this->rebuildJobs());
        self::assertStringContainsString('queued', $this->consoleOut);

        $this->index()->rebuild();
        $controller = $this->console();
        self::assertSame(0, $controller->runAction('index-status'));
        self::assertStringContainsString('up to date', $this->consoleOut);
    }

    // A rebuild beside the updates that follow content

    public function testARebuildNeverOverwritesAnUpdateMadeWhileItRuns(): void
    {
        // A: read by the rebuild, then saved and updated, then the rebuild goes on and finishes.
        $a = self::page('index-race-a', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/race-old']]]]);
        $b = self::page('index-race-b', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/race-b']]]]);
        $this->index()->rebuild();
        $this->pushed = [];

        $startedAt = $this->rebuildStart();
        $result = new IndexingResult();
        $this->index()->rebuildBatch((int)$a->id - 1, 1, $result);

        $this->save($a, [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/race-new']]]]);
        $this->runPushed();

        $this->index()->rebuildBatch((int)$a->id, Index::BATCH_SIZE, $result);
        $this->finishRebuild($startedAt, $result);

        self::assertSame(['https://example.com/race-new'], $this->linkedUrls($a));
        self::assertSame(['https://example.com/race-b'], $this->linkedUrls($b));
        self::assertNull(IndexRecord::findOne(['targetKey' => 'url?url=' . rawurlencode('https://example.com/race-old')]));

        // C: saved after the rebuild passed it, and updated before it finishes: the finish keeps
        // the newer reading, and reads nothing again that needs it.
        $startedAt = $this->rebuildStart();
        $this->index()->rebuildBatch(0, Index::BATCH_SIZE, $result);
        $this->save($a, [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/race-newer']]]]);
        $this->runPushed();
        $this->finishRebuild($startedAt, $result);

        self::assertSame(['https://example.com/race-newer'], $this->linkedUrls($a));
        self::assertFalse($this->index()->status()->isStale());
    }

    public function testAnElementSavedDuringARebuildWhoseUpdateHasNotRunIsReadByTheFinish(): void
    {
        $a = self::page('index-race-late', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/late-old']]]]);
        $this->index()->rebuild();

        $startedAt = $this->rebuildStart();
        $result = new IndexingResult();
        $this->index()->rebuildBatch(0, Index::BATCH_SIZE, $result);
        // Saved after the walk passed it, a moment later (Craft dates a save to the second, so a
        // save in the very second it was read is told apart only by its own update); that update
        // is still waiting in the queue.
        sleep(1);
        $this->save($a, [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/late-new']]]]);
        $waiting = $this->pushed;
        $this->pushed = [];
        $this->finishRebuild($startedAt, $result);

        self::assertSame(['https://example.com/late-new'], $this->linkedUrls($a));

        // And the update, when it runs, changes nothing.
        $before = $this->snapshot();
        $this->pushed = $waiting;
        $this->runPushed();
        self::assertSame($before, $this->snapshot());
    }

    public function testAnElementDeletedDuringARebuildLeavesNothingBehind(): void
    {
        foreach (['update runs first' => true, 'update still waiting' => false] as $case => $runUpdate) {
            $a = self::page("index-race-gone-$runUpdate", [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => "https://example.com/gone-$runUpdate"]]]]);
            $this->index()->rebuild();

            $startedAt = $this->rebuildStart();
            $result = new IndexingResult();
            $this->index()->rebuildBatch((int)$a->id - 1, 1, $result);
            self::assertTrue(Craft::$app->getElements()->deleteElement($a));

            if ($runUpdate) {
                $this->runPushed();
            }

            $this->pushed = [];
            $this->index()->rebuildBatch((int)$a->id, Index::BATCH_SIZE, $result);
            $this->finishRebuild($startedAt, $result);

            self::assertSame([], $this->usages($a), $case);
            self::assertNull(SourceRecord::findOne(['elementId' => $a->id]), $case);
            self::assertNull(IndexRecord::findOne(['targetKey' => 'url?url=' . rawurlencode("https://example.com/gone-$runUpdate")]), $case);
        }
    }

    public function testAnElementRestoredOrAddedBehindARebuildIsKept(): void
    {
        $a = self::page('index-race-restored', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/restored']]]]);
        self::assertTrue(Craft::$app->getElements()->deleteElement($a));
        $this->runPushed();
        self::assertSame([], $this->usages($a));

        foreach (['update runs first' => true, 'update still waiting' => false] as $case => $runUpdate) {
            // D: restored behind the rebuild's cursor.
            $startedAt = $this->rebuildStart();
            $result = new IndexingResult();
            $this->index()->rebuildBatch(0, Index::BATCH_SIZE, $result);
            $trashed = Entry::find()->id($a->id)->trashed()->status(null)->one();

            if ($trashed !== null) {
                self::assertTrue(Craft::$app->getElements()->restoreElement($trashed));
            }

            if ($runUpdate) {
                $this->runPushed();
            }

            $this->pushed = [];
            $this->finishRebuild($startedAt, $result);
            self::assertCount(self::siteCount(), $this->usages($a), $case);
        }

        // E: a source added while the rebuild runs, behind or ahead of its cursor, its update
        // still waiting: the walk or the finish reads it.
        $startedAt = $this->rebuildStart();
        $result = new IndexingResult();
        $this->index()->rebuildBatch(0, Index::BATCH_SIZE, $result);
        $added = self::page('index-race-added', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/added']]]]);
        $this->pushed = [];
        $this->finishRebuild($startedAt, $result);
        self::assertCount(self::siteCount(), $this->usages($added));
        self::assertFalse($this->index()->status()->isStale());
    }

    public function testEveryTargetRowOfAChangedElementIsResolvedAgain(): void
    {
        // F: one entry linked from several pages, in every site, by sites of its own and pinned.
        $target = self::page('index-many-target');
        $sources = [];

        for ($i = 1; $i <= 3; $i++) {
            $sources[] = self::page("index-many-source-$i", [self::LINKS => [
                ['type' => 'entry', 'data' => ['elementId' => $target->id]],
                ['type' => 'entry', 'data' => ['elementId' => $target->id, 'siteId' => self::$secondSiteId]],
            ]]);
        }

        $this->runPushed();
        // In the primary site first, the one the slug is changed in.
        $keys = array_map(static fn(int $siteId): string => "entry?elementId=$target->id&siteId=$siteId", [self::$primarySiteId, ...array_values(array_diff(self::siteIds(), [self::$primarySiteId]))]);
        self::assertCount(self::siteCount(), IndexRecord::find()->where(['targetKey' => $keys])->all());

        $moved = $this->reload($target);
        $moved->slug = 'index-many-moved';
        self::assertTrue(Craft::$app->getElements()->saveElement($moved));
        $this->runPushed();
        $this->assertTargetsLeadWhereTheElementIs($target, $keys);
        self::assertStringEndsWith('/index-many-moved', (string)$this->target($keys[0])['resolvedUrl']);

        // Its URI changed on its own (as Craft does when a parent moves): the same.
        $moved = $this->reload($target);
        $moved->slug = 'index-many-again';
        Craft::$app->getElements()->updateElementSlugAndUri($moved, true, false);
        $this->runPushed();
        $this->assertTargetsLeadWhereTheElementIs($target, $keys);
        self::assertStringEndsWith('/index-many-again', (string)$this->target($keys[0])['resolvedUrl']);

        self::assertCount(3, array_filter($sources, fn(Entry $source): bool => count($this->usages($source)) === 2 * self::siteCount()));
    }

    public function testTwoRebuildsRunningAtOnceEndAsOneWould(): void
    {
        $entries = [];

        for ($i = 1; $i <= 4; $i++) {
            $entries[] = self::page("index-two-$i", [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => "https://example.com/two-$i"]], ['type' => 'url', 'data' => ['url' => 'https://example.com/two-shared']]]]);
        }

        $this->index()->rebuild();
        $expected = $this->snapshot(withDates: false);
        $this->pushed = [];

        // Two chains, their batches interleaved, content changing between them.
        $x = new RebuildIndex(['startedAt' => $this->rebuildStart(), 'batchSize' => 1]);
        $y = new RebuildIndex(['startedAt' => $x->startedAt, 'batchSize' => 2]);
        $this->save($entries[2], [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/two-changed']]]]);
        $updates = array_values(array_filter($this->pushedJobs(), static fn($job): bool => $job instanceof UpdateIndex));
        $this->pushed = [];
        $chains = [$x, $y];

        while (($job = array_shift($chains)) !== null) {
            $job->execute(Craft::$app->getQueue());
            $next = array_values(array_filter($this->pushedJobs(), static fn($pushed): bool => $pushed instanceof RebuildIndex));
            $this->pushed = [];
            array_push($chains, ...$next);
        }

        foreach ($updates as $update) {
            $update->execute(Craft::$app->getQueue());
        }

        // What one rebuild of the same content gives.
        $after = $this->snapshot(withDates: false);
        $this->index()->rebuild();
        self::assertSame($this->snapshot(withDates: false), $after);
        self::assertNotSame($expected, $after);
        self::assertSame(['https://example.com/two-changed'], $this->linkedUrls($entries[2]));
        self::assertFalse($this->index()->status()->isStale());
    }

    // The index's lock

    public function testNothingRemovesTargetsWhileAnElementIsBeingIndexed(): void
    {
        $entry = self::page('index-lock-inside', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/lock-inside']]]]);
        $refusals = new ArrayObject();

        // A removal attempted between a target's creation and the usage that points at it, as a
        // second process would: it cannot run while the pass holds the lock.
        $index = new class($refusals) extends Index {
            public function __construct(private readonly ArrayObject $refusals)
            {
                parent::__construct();
            }

            protected function findTarget(string $targetHash): ?IndexRecord
            {
                try {
                    $this->pruneUnusedTargets();
                    $this->refusals[] = 'pruned';
                } catch (IndexLockedException) {
                    $this->refusals[] = 'refused';
                }

                return parent::findTarget($targetHash);
            }
        };
        $index->lockTimeout = 0;

        $index->updateElements([(int)$entry->id]);

        self::assertNotEmpty($refusals->getArrayCopy());
        self::assertSame(['refused'], array_values(array_unique($refusals->getArrayCopy())));
        self::assertCount(self::siteCount(), $this->usages($entry));
        self::assertSame([], array_filter(array_column($this->usages($entry), 'indexId'), static fn($id): bool => IndexRecord::findOne((int)$id) === null));
    }

    public function testAnotherProcessHoldingTheLockStopsEveryWriteUntilItIsDone(): void
    {
        $entry = self::page('index-lock-held', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/lock-held']]]]);
        $this->index()->rebuild();
        $unused = $this->index()->targetId(TargetIdentity::forUrl('url', CanonicalUrl::parse('https://example.com/lock-unused'), null));
        $this->save($entry, [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/lock-changed']]]]);
        $update = array_values(array_filter($this->pushed, static fn($job): bool => $job instanceof UpdateIndex))[0] ?? null;
        self::assertInstanceOf(UpdateIndex::class, $update);
        $this->pushed = [];
        $before = $this->snapshot();

        // This connection keeps a lock it released until its transaction ends; give it up, so the
        // other process can take it.
        $mutex = Craft::$app->getMutex();
        self::assertInstanceOf(\craft\mutex\Mutex::class, $mutex);
        $mutex->releaseQueuedLocks();
        $process = proc_open([PHP_BINARY, __DIR__ . '/../_support/hold-index-lock.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 30);
        self::assertSame("held\n", fgets($pipes[1]));
        $index = $this->index();
        $index->lockTimeout = 1;

        // Its first batch the changed page and IDs of no element; its second the rest.
        $others = range((int)$entry->id + 1, (int)$entry->id + Index::BATCH_SIZE + 5);
        $longUpdate = new UpdateIndex(['elementIds' => array_merge([(int)$entry->id], $others)]);

        try {
            foreach ([
                'an update' => fn() => $update->execute(Craft::$app->getQueue()),
                'an update of more than one batch' => fn() => $longUpdate->execute(Craft::$app->getQueue()),
                'a rebuild batch' => fn() => (new RebuildIndex(['startedAt' => $this->rebuildStart()]))->execute(Craft::$app->getQueue()),
                'a rebuild’s remainder' => fn() => (new RebuildIndex(['startedAt' => $this->rebuildStart(), 'finishing' => true]))->execute(Craft::$app->getQueue()),
                'a rebuild’s finish' => fn() => $index->finishRebuild(new IndexingResult()),
                'a layout walk' => fn() => (new RebuildIndex(['fieldLayoutId' => (int)(new Query())->select('fieldLayoutId')->from(Table::ELEMENTS)->where(['id' => $entry->id])->scalar()]))->execute(Craft::$app->getQueue()),
                'creating a target' => fn() => $index->targetId(TargetIdentity::forUrl('url', CanonicalUrl::parse('https://example.com/lock-new-target'), null)),
                'removing unused targets' => fn() => $index->pruneUnusedTargets(),
            ] as $write => $attempt) {
                try {
                    $attempt();
                    self::fail("$write ran while another process held the index's lock.");
                } catch (IndexLockedException $exception) {
                    // Nothing written, no next batch queued, and the job is run again later.
                    self::assertSame($before, $this->snapshot(), $write);
                    self::assertSame([], $this->pushed, $write);
                    self::assertTrue($update->canRetry(1, $exception), $write);
                }
            }
        } finally {
            fwrite($pipes[0], "release\n");
            fclose($pipes[0]);
            $released = fgets($pipes[1]);
            proc_close($process);
            $index->lockTimeout = 60;
        }

        self::assertSame("released\n", $released);

        // Retried once the lock is free, the same jobs complete; the long one queues its rest once.
        $update->execute(Craft::$app->getQueue());
        self::assertSame(['https://example.com/lock-changed'], $this->linkedUrls($entry));
        $longUpdate->execute(Craft::$app->getQueue());
        self::assertSame([array_slice($others, Index::BATCH_SIZE - 1)], array_map(static fn($job) => $job->elementIds, array_values(array_filter($this->pushedJobs(), static fn($job): bool => $job instanceof UpdateIndex))));
        $index->pruneUnusedTargets();
        self::assertNull(IndexRecord::findOne($unused));
    }

    public function testOnlyLockContentionIsRetriedAndNotForever(): void
    {
        $job = new UpdateIndex();
        $locked = new IndexLockedException('held');

        self::assertTrue($job->canRetry(1, $locked));
        self::assertTrue((new RebuildIndex())->canRetry(UpdateIndex::$lockedAttempts - 1, $locked));
        self::assertFalse($job->canRetry(UpdateIndex::$lockedAttempts, $locked));
        self::assertFalse($job->canRetry(1, new \RuntimeException('anything else')));
        self::assertSame((int)Craft::$app->getQueue()->ttr, $job->getTtr());
    }

    public function testAWriteThatFailsReleasesTheLock(): void
    {
        $entry = self::page('index-lock-failure', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/lock-failure']]]]);
        $failing = new class() extends Index {
            protected function findTarget(string $targetHash): ?IndexRecord
            {
                throw new \RuntimeException('The database went away.');
            }
        };

        try {
            $failing->updateElements([(int)$entry->id]);
            self::fail('The failing write did not fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The database went away.', $exception->getMessage());
        }

        // Released: the next write takes the lock at once, and writes.
        $index = $this->index();
        $index->lockTimeout = 0;

        try {
            $index->updateElements([(int)$entry->id]);
        } finally {
            $index->lockTimeout = 60;
        }

        self::assertSame(['https://example.com/lock-failure'], $this->linkedUrls($entry));
    }

    // Idempotence

    public function testRepeatingAnyIndexingChangesNothing(): void
    {
        $entry = self::page('index-idempotent', [
            self::LINKS => [['type' => 'url', 'data' => ['url' => 'https://example.com/same'], 'label' => 'Same'], ['type' => 'email', 'data' => ['address' => 'same@example.com']]],
            self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/same']]],
        ]);
        $this->runPushed();
        $once = $this->snapshot();
        $update = new UpdateIndex(['elementIds' => [(int)$entry->id]]);
        $startedAt = $this->rebuildStart();

        for ($i = 0; $i < 10; $i++) {
            $this->index()->updateElements([(int)$entry->id, (int)$entry->id]);
        }

        $update->execute(Craft::$app->getQueue());
        $update->execute(Craft::$app->getQueue());
        $result = new IndexingResult();
        $this->index()->rebuildBatch((int)$entry->id - 1, 1, $result);
        $this->index()->rebuildBatch((int)$entry->id - 1, 1, $result);
        $this->finishRebuild($startedAt, $result);
        $this->finishRebuild($startedAt, $result);

        self::assertSame($once, $this->snapshot());

        // Exactly the occurrences content holds, each once, and each target once.
        $occurrences = array_map(static fn(array $row): string => "{$row['siteId']}/{$row['layoutElementUid']}/{$row['linkUid']}", $this->usages($entry));
        self::assertSame(array_values(array_unique($occurrences)), $occurrences);
        self::assertCount(3 * self::siteCount(), $occurrences);
        $keys = array_values(array_unique(array_map(fn(array $row): string => $this->keyOf((int)$row['indexId']), $this->usages($entry))));
        sort($keys);
        self::assertSame(['email?address=same%40example.com', 'url?url=' . rawurlencode('https://example.com/same')], $keys);
    }

    public function testAnUpdateJobReadsAtMostOneBatchAndQueuesTheRest(): void
    {
        $ids = range(1, Index::BATCH_SIZE + 5);

        (new UpdateIndex(['elementIds' => array_merge($ids, $ids)]))->execute(Craft::$app->getQueue());

        $rest = $this->pushed[0] ?? null;
        self::assertInstanceOf(UpdateIndex::class, $rest);
        self::assertSame(range(Index::BATCH_SIZE + 1, Index::BATCH_SIZE + 5), $rest->elementIds);

        $this->expectException(InvalidArgumentException::class);
        $this->index()->updateElements(range(1, Index::BATCH_SIZE + 1));
    }

    // Deletion, restore and structure

    public function testRemovingAnElementFromOneSiteRemovesOnlyThatSitesRows(): void
    {
        // A section whose entries are in the sites their authors choose: taken out of one site,
        // an entry stays out of it.
        $section = new Section([
            'name' => 'Smart Links test chosen sites',
            'handle' => 'smartLinksTestChosenSites',
            'type' => Section::TYPE_CHANNEL,
            'propagationMethod' => PropagationMethod::Custom,
            'siteSettings' => array_map(static fn(int $siteId): Section_SiteSettings => new Section_SiteSettings(['siteId' => $siteId, 'hasUrls' => true, 'uriFormat' => 'smart-links-chosen/{slug}', 'template' => '_smart-links-test']), [self::$primarySiteId => self::$primarySiteId, self::$secondSiteId => self::$secondSiteId]),
        ]);
        $section->setEntryTypes([self::$entryType]);

        try {
            self::assertTrue(Craft::$app->getEntries()->saveSection($section), Json::encode($section->getErrors()));
            $entry = static function(string $slug, array $values) use ($section): Entry {
                $entry = new Entry(['sectionId' => $section->id, 'typeId' => self::$entryType->id, 'siteId' => self::$primarySiteId, 'title' => $slug, 'slug' => $slug]);
                $entry->setEnabledForSite([self::$primarySiteId => true, self::$secondSiteId => true]);

                foreach ($values + [self::DEFAULTS => []] as $handle => $links) {
                    $entry->setFieldValue($handle, $links === [] ? new LinkCollection() : self::entered($links));
                }

                self::assertTrue(Craft::$app->getElements()->saveElement($entry), Json::encode($entry->getErrors()));

                return $entry;
            };
            $target = $entry('index-site-target', []);
            $source = $entry('index-site-source', [self::LINKS => [['type' => 'entry', 'data' => ['elementId' => $target->id]]]]);
            $this->runPushed();
            self::assertCount(2, $this->usages($source));

            $second = Entry::find()->id($source->id)->siteId(self::$secondSiteId)->status(null)->one();
            self::assertNotNull($second);
            Craft::$app->getElements()->deleteElementForSite($second);
            $this->runPushed();

            self::assertSame([], $this->usages($source, self::$secondSiteId));
            self::assertNull(SourceRecord::findOne(['elementId' => $source->id, 'siteId' => self::$secondSiteId]));
            self::assertCount(1, $this->usages($source, self::$primarySiteId));
            self::assertNull(IndexRecord::findOne(['targetKey' => "entry?elementId=$target->id&siteId=" . self::$secondSiteId]), 'Nothing links to it in that site any more.');

            // The target taken out of a site its link is pinned to: the link is kept, and leads
            // nowhere there.
            $this->save($source, [self::LINKS => [['type' => 'entry', 'data' => ['elementId' => $target->id, 'siteId' => self::$secondSiteId]]]]);
            $this->runPushed();
            $pinned = "entry?elementId=$target->id&siteId=" . self::$secondSiteId;
            self::assertSame(ResolutionStatus::RESOLVED->value, $this->target($pinned)['targetStatus']);
            $targetSecond = Entry::find()->id($target->id)->siteId(self::$secondSiteId)->status(null)->one();
            self::assertNotNull($targetSecond);
            Craft::$app->getElements()->deleteElementForSite($targetSecond);
            $this->runPushed();
            self::assertSame(ResolutionStatus::MISSING->value, $this->target($pinned)['targetStatus']);

            // A section given another site, its entries then propagated there alone, saving only
            // the new site's copies, as Craft's `resave --propagate-to` and its propagation job do:
            // each copy's update reads it there.
            $growing = new Section([
                'name' => 'Smart Links test growing',
                'handle' => 'smartLinksTestGrowing',
                'type' => Section::TYPE_CHANNEL,
                'propagationMethod' => PropagationMethod::All,
                'siteSettings' => [self::$primarySiteId => new Section_SiteSettings(['siteId' => self::$primarySiteId, 'hasUrls' => true, 'uriFormat' => 'smart-links-growing/{slug}', 'template' => '_smart-links-test'])],
            ]);
            $growing->setEntryTypes([self::$entryType]);
            self::assertTrue(Craft::$app->getEntries()->saveSection($growing), Json::encode($growing->getErrors()));
            $grown = new Entry(['sectionId' => $growing->id, 'typeId' => self::$entryType->id, 'siteId' => self::$primarySiteId, 'title' => 'index-growing', 'slug' => 'index-growing']);
            $grown->setFieldValue(self::URL_ONLY, self::entered([['type' => 'url', 'data' => ['url' => 'https://example.com/growing']]]));
            $grown->setFieldValue(self::DEFAULTS, new LinkCollection());
            self::assertTrue(Craft::$app->getElements()->saveElement($grown));
            $this->runPushed();
            self::assertCount(1, $this->usages($grown));

            $growing = Craft::$app->getEntries()->getSectionById((int)$growing->id);
            self::assertNotNull($growing);
            $growing->setSiteSettings([
                self::$primarySiteId => new Section_SiteSettings(['siteId' => self::$primarySiteId, 'hasUrls' => true, 'uriFormat' => 'smart-links-growing/{slug}', 'template' => '_smart-links-test']),
                self::$secondSiteId => new Section_SiteSettings(['siteId' => self::$secondSiteId, 'hasUrls' => true, 'uriFormat' => 'smart-links-growing/{slug}', 'template' => '_smart-links-test']),
            ]);
            self::assertTrue(Craft::$app->getEntries()->saveSection($growing), Json::encode($growing->getErrors()));
            // Craft's own resave of the section's entries is left in the queue: only the copy saved
            // by propagating it is the change.
            $this->pushed = [];
            Craft::$app->getElements()->propagateElement($this->reload($grown), self::$secondSiteId, false);

            self::assertNotSame([], array_filter($this->runPushed(), static fn($job): bool => $job instanceof UpdateIndex));
            self::assertCount(1, $this->usages($grown, self::$secondSiteId));
        } finally {
            $this->resetCraftServices();
        }
    }

    public function testGarbageCollectionRemovesTargetsItsDeletionsLeftUnusedAndNoHealth(): void
    {
        // A category, which has no revisions (see the hard delete above).
        $entry = $this->save(self::savedCategory('index-gc'), [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/collected']]]]);
        $this->runPushed();
        $url = CanonicalUrl::parse('https://example.com/collected');
        $health = new HealthRecord();
        $health->setAttributes(['url' => $url->healthUrl(), 'urlHash' => $url->healthUrlHash(), 'state' => HealthState::HEALTHY->value, 'statusCode' => 200, 'finalUrl' => $url->healthUrl(), 'dateChecked' => Db::prepareDateForDb(new DateTime())], false);
        self::assertTrue($health->save(false));

        // What Craft's garbage collection does to an element trashed long ago: delete its row, with
        // no element events.
        Db::delete(Table::ELEMENTS, ['id' => $entry->id]);
        self::assertSame([], $this->usages($entry), 'Usage goes with its source (cascade).');
        self::assertNotNull(IndexRecord::findOne(['targetKey' => 'url?url=' . rawurlencode('https://example.com/collected')]));

        Craft::$app->getGc()->trigger(Gc::EVENT_RUN);

        self::assertNull(IndexRecord::findOne(['targetKey' => 'url?url=' . rawurlencode('https://example.com/collected')]));
        self::assertNotNull(HealthRecord::findOne($health->id), 'Health is kept by URL, not by target.');
    }

    public function testSmartLinkFieldsLeavingOrJoiningALayoutAreFollowed(): void
    {
        $entry = self::page('index-layout', [
            self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/layout-url-only']]],
            self::SINGLE => [['type' => 'url', 'data' => ['url' => 'https://example.com/layout-single']]],
        ]);
        $this->runPushed();
        $layout = self::$entryType->getFieldLayout();
        $urlOnly = (string)self::$instances[self::URL_ONLY]->layoutElement?->uid;
        $content = $this->craftDigest([(int)$entry->id]);

        try {
            // The URL-only field taken out of the layout: its usage goes, the other field's stays.
            $this->saveLayoutWithout($layout, [$urlOnly]);
            // The jobs run in a queue process of their own, which knows Craft's layouts afresh.
            $this->resetCraftServices();
            $jobs = $this->runPushed();
            self::assertSame([RebuildIndex::class], array_values(array_unique(array_map('get_class', $jobs))));
            self::assertSame([], array_filter($this->usages($entry), static fn(array $row): bool => $row['layoutElementUid'] === $urlOnly));
            self::assertCount(self::siteCount(), $this->usages($entry));
            self::assertNull(IndexRecord::findOne(['targetKey' => 'url?url=' . rawurlencode('https://example.com/layout-url-only')]));

            // Put back: its links, which content still holds, are indexed again without a save.
            $this->saveLayoutWithout($layout, []);
            $this->resetCraftServices();
            $this->runPushed();
            self::assertCount(2 * self::siteCount(), $this->usages($entry));

            // Every Smart Links field taken out: the entry is a source no more.
            $this->saveLayoutWithout($layout, array_values(array_map(static fn($field): string => (string)$field->layoutElement?->uid, self::$instances)));
            $this->resetCraftServices();
            $this->runPushed();
            self::assertSame([], $this->usages($entry));
            self::assertNull(SourceRecord::findOne(['elementId' => $entry->id]));
            self::assertFalse($this->index()->status()->isStale());

            // A layout saved with its Smart Links fields unchanged queues nothing.
            $this->saveLayoutWithout($layout, array_values(array_map(static fn($field): string => (string)$field->layoutElement?->uid, self::$instances)));
            self::assertSame([], $this->rebuildJobs());
        } finally {
            $this->resetCraftServices();
        }

        // Content itself never changed.
        self::assertSame($content, $this->craftDigest([(int)$entry->id]));
    }

    public function testAFieldDeletedOrNoLongerASmartLinksFieldTakesItsUsageWithIt(): void
    {
        $entry = self::page('index-field', [
            self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/field-url-only']]],
            self::SINGLE => [['type' => 'url', 'data' => ['url' => 'https://example.com/field-single']]],
        ]);
        $this->runPushed();
        $fields = Craft::$app->getFields();

        try {
            // Turned into another kind of field: a rebuild is queued, and takes its usage out.
            $single = $fields->getFieldByHandle(self::SINGLE);
            self::assertNotNull($single);
            $plain = $fields->createField(['type' => PlainText::class, 'id' => $single->id, 'uid' => $single->uid, 'name' => $single->name, 'handle' => $single->handle]);
            self::assertTrue($fields->saveField($plain), Json::encode($plain->getErrors()));
            self::assertCount(1, $this->rebuildJobs());
            // The rebuild runs in a queue process of its own, which knows Craft's fields afresh.
            $this->resetCraftServices();
            $this->runPushed();
            self::assertSame([], array_filter($this->usages($entry), static fn(array $row): bool => (int)$row['fieldId'] === (int)$single->id));
            self::assertNull(IndexRecord::findOne(['targetKey' => 'url?url=' . rawurlencode('https://example.com/field-single')]));

            // Deleted: the same.
            $fields = Craft::$app->getFields();
            $urlOnly = $fields->getFieldByHandle(self::URL_ONLY);
            self::assertInstanceOf(SmartLinkField::class, $urlOnly);
            self::assertTrue($fields->deleteField($urlOnly));
            self::assertCount(1, $this->rebuildJobs());
            $this->resetCraftServices();
            $this->runPushed();
            self::assertSame([], $this->usages($entry));
            self::assertNull(IndexRecord::findOne(['targetKey' => 'url?url=' . rawurlencode('https://example.com/field-url-only')]));
            self::assertFalse($this->index()->status()->isStale());
        } finally {
            $this->resetCraftServices();
        }
    }

    // Unreadable and unresolvable content

    /**
     * @param array<string, mixed> $stored
     */
    #[DataProvider('unreadableValues')]
    public function testAValueThatCannotBeReadIsReportedAndKeepsItsLinks(array|string $stored): void
    {
        $entry = self::page('index-unreadable-' . md5(Json::encode($stored)), [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/kept-whatever-happens']]]]);
        $this->index()->rebuild();
        $before = $this->usages($entry);
        $this->storeRaw($entry, self::URL_ONLY, $stored);

        foreach ([1, 2] as $time) {
            $result = $this->index()->updateElements([(int)$entry->id]);

            self::assertSame(1, $result->unreadableValues, "reading $time");
            self::assertSame($before, $this->usages($entry), "reading $time");
            self::assertSame(1, (int)SourceRecord::findOne(['elementId' => $entry->id, 'siteId' => self::$primarySiteId])?->unreadableValues);
            self::assertSame(1, $this->index()->status()->unreadableSources);
        }

        // Readable again: reported no more.
        $this->save($entry, [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/readable-again']]]]);
        $this->runPushed();
        self::assertSame(0, $this->index()->status()->unreadableSources);
        self::assertSame(['https://example.com/readable-again'], $this->linkedUrls($entry, self::$primarySiteId));
    }

    /**
     * @return array<string, array{array<string, mixed>|string}>
     */
    public static function unreadableValues(): array
    {
        return [
            'a link type that is not registered' => [['version' => 1, 'links' => [['uid' => '0b3c8e1a-6f1e-4c55-9e3c-2b9a4f7d1e60', 'type' => 'gone-type', 'data' => ['x' => 1]]]]],
            'a format version that does not exist' => [['version' => 9, 'links' => []]],
            'not a stored value at all' => ['links'],
        ];
    }

    public function testALinkTypeRemovedSinceKeepsItsLinksAsLastIndexed(): void
    {
        $entry = self::page('index-type-removed', [self::LINKS => [['type' => 'email', 'data' => ['address' => 'removed@example.com']], ['type' => 'url', 'data' => ['url' => 'https://example.com/type-removed']]]]);
        $this->index()->rebuild();
        $before = $this->usages($entry);
        $remove = static function(RegisterComponentTypesEvent $event): void {
            $event->types = array_values(array_diff($event->types, [EmailLinkType::class]));
        };
        Event::on(LinkTypes::class, LinkTypes::EVENT_REGISTER_LINK_TYPES, $remove);
        self::resetPluginServices();

        try {
            $this->reload($entry);
            $result = $this->index()->updateElements([(int)$entry->id]);
            self::assertSame(self::siteCount(), $result->unreadableValues);
            self::assertSame($before, $this->usages($entry));
            self::assertSame(self::siteCount(), $this->index()->status()->unreadableSources);
        } finally {
            Event::off(LinkTypes::class, LinkTypes::EVENT_REGISTER_LINK_TYPES, $remove);
            self::resetPluginServices();
        }
    }

    public function testATargetThatCannotBeResolvedIsRecordedAsSuchEveryTime(): void
    {
        // A network whose profile URLs are not canonical: resolving its links fails.
        $register = static function(RegisterSocialNetworksEvent $event): void {
            $event->networks[] = new SocialNetwork('brokennet', 'Broken network', '[a-z]+', 'http://BROKEN.example/{account}');
        };
        Event::on(SocialLinkType::class, SocialLinkType::EVENT_REGISTER_NETWORKS, $register);
        self::resetPluginServices();

        try {
            // Stored as content (the fixture's fields offer no social links), as a field that does
            // would store it.
            $entry = self::page('index-unresolvable');
            $this->storeRaw($entry, self::URL_ONLY, ['version' => 1, 'links' => [['uid' => '3f1d2c4b-5a6e-4f70-8b91-a2b3c4d5e6f7', 'type' => 'social', 'data' => ['network' => 'brokennet', 'account' => 'someone']]]]);
            $this->pushed = [];

            foreach ([1, 2] as $time) {
                $result = $this->index()->rebuild();
                $target = $this->target('social?account=someone&network=brokennet');
                self::assertNull($target['targetStatus'], "rebuild $time");
                self::assertNull($target['resolvedUrl'], "rebuild $time");
                self::assertGreaterThanOrEqual(1, $result->problemCount, "rebuild $time");
                self::assertCount(1, $this->usages($entry), "rebuild $time");
            }

            self::assertSame(1, $this->inventory(['target' => 'failed', 'search' => 'brokennet'])->total);
        } finally {
            Event::off(SocialLinkType::class, SocialLinkType::EVENT_REGISTER_NETWORKS, $register);
            self::resetPluginServices();
        }
    }

    // Element types and places a Smart Links field can be

    public function testEveryKindOfSourceIsIndexedInItsOwnSitesWithItsOwnIdentity(): void
    {
        $url = static fn(string $path): array => [['type' => 'url', 'data' => ['url' => "https://example.com/kind-$path"]]];
        $sources = [];
        $paths = ['category' => 'category', 'asset' => 'asset', 'user' => 'user', 'product' => 'product', 'nested entry' => 'block'];

        $category = self::savedCategory('index-kind-category');
        $sources['category'] = $this->save($category, [self::URL_ONLY => $url('category')]);
        $asset = self::savedAsset(self::$publicVolume, 'index-kind.pdf');
        $sources['asset'] = $this->save($asset, [self::URL_ONLY => $url('asset')]);
        $user = new User(['username' => 'smartlinks-index-' . bin2hex(random_bytes(3)), 'email' => 'smartlinks-index-' . bin2hex(random_bytes(3)) . '@example.test']);
        $user->setFieldValue(self::URL_ONLY, self::entered($url('user')));
        self::assertTrue(Craft::$app->getElements()->saveElement($user), Json::encode($user->getErrors()));
        $sources['user'] = $user;

        if (self::$productTypeId !== null) {
            $product = self::savedProduct('Index kind product', true);
            $sources['product'] = $this->save($product, [self::URL_ONLY => $url('product')]);
        }

        // A Matrix entry: its own element, nested in a page.
        $page = self::page('index-kind-page');
        $block = new Entry(['typeId' => self::blockTypeId(), 'fieldId' => Craft::$app->getFields()->getFieldByHandle(self::BLOCKS)?->id, 'ownerId' => $page->id, 'siteId' => self::$primarySiteId]);
        $block->setFieldValue(self::URL_ONLY, self::entered($url('block')));
        self::assertTrue(Craft::$app->getElements()->saveElement($block), Json::encode($block->getErrors()));
        $sources['nested entry'] = $block;

        $this->runPushed();
        $this->index()->rebuild();

        foreach ($sources as $kind => $element) {
            $rows = $this->usages($element);
            $sites = array_map('intval', (new Query())->select(['siteId'])->from(Table::ELEMENTS_SITES)->where(['elementId' => $element->id])->column());
            sort($sites);

            self::assertNotSame([], $rows, $kind);
            // One occurrence per site the element is in, of the URL-only field as placed in its
            // own layout, with the link's own UID, pointing at its own target.
            self::assertSame($sites, array_map('intval', array_column($rows, 'siteId')), $kind);
            self::assertSame([(string)$element->getFieldLayout()?->getFieldByHandle(self::URL_ONLY)?->layoutElement?->uid], array_values(array_unique(array_column($rows, 'layoutElementUid'))), $kind);
            self::assertSame([(int)Craft::$app->getFields()->getFieldByHandle(self::URL_ONLY)?->id], array_values(array_unique(array_map('intval', array_column($rows, 'fieldId')))), $kind);
            $value = $element->getFieldValue(self::URL_ONLY);
            self::assertInstanceOf(LinkCollection::class, $value);
            self::assertSame([$value->links[0]->uid], array_values(array_unique(array_column($rows, 'linkUid'))), $kind);
            self::assertSame(['url?url=' . rawurlencode('https://example.com/kind-' . $paths[$kind])], array_values(array_unique(array_map(fn(array $row): string => $this->keyOf((int)$row['indexId']), $rows))), $kind);
        }

        // The nested entry is shown by the page it is in.
        Craft::$app->getUser()->setIdentity($this->user(['accessCp', SmartLinks::PERMISSION_VIEW_INVENTORY]));
        self::assertSame('index-kind-page', $this->item($this->inventory(['search' => 'kind-block']), 'https://example.com/kind-block')->source['label'] ?? null);

        // A nested entry of a draft is not a source of links.
        $draft = Craft::$app->getDrafts()->createDraft($this->reload($page), 1);
        $draftBlock = new Entry(['typeId' => self::blockTypeId(), 'fieldId' => Craft::$app->getFields()->getFieldByHandle(self::BLOCKS)?->id, 'ownerId' => $draft->id, 'siteId' => self::$primarySiteId]);
        $draftBlock->setFieldValue(self::URL_ONLY, self::entered($url('draft-block')));
        self::assertTrue(Craft::$app->getElements()->saveElement($draftBlock), Json::encode($draftBlock->getErrors()));
        $this->runPushed();
        $this->index()->updateElements([(int)$draftBlock->id]);
        self::assertSame([], $this->usages($draftBlock));
        self::assertNull(IndexRecord::findOne(['targetKey' => 'url?url=' . rawurlencode('https://example.com/kind-draft-block')]));
    }

    // Stale detection

    public function testEachDifferenceFromContentIsReportedOnItsOwn(): void
    {
        $target = self::page('index-stale-target');
        $entry = self::page('index-stale-each', [self::LINKS => [['type' => 'entry', 'data' => ['elementId' => $target->id]], ['type' => 'url', 'data' => ['url' => '/stale-path']]]]);
        $cases = [
            'source never indexed' => [fn() => Db::delete(SourceRecord::TABLE, ['elementId' => $entry->id]), ['unindexedSources' => self::siteCount(), 'orphanedUsages' => 2 * self::siteCount()]],
            'source saved since' => [fn() => Db::update(Table::ELEMENTS, ['dateUpdated' => Db::prepareDateForDb(new DateTime('+1 minute'))], ['id' => $entry->id]), ['unindexedSources' => self::siteCount()]],
            'source trashed' => [fn() => Db::update(Table::ELEMENTS, ['dateDeleted' => Db::prepareDateForDb(new DateTime())], ['id' => $entry->id]), ['orphanedSources' => self::siteCount()]],
            'source no longer has a Smart Links field' => [fn() => Db::update(Table::ELEMENTS, ['fieldLayoutId' => null], ['id' => $entry->id]), ['orphanedSources' => self::siteCount()]],
            'usage of a field no longer in the layout' => [fn() => Db::update(UsageRecord::TABLE, ['layoutElementUid' => StringHelper::UUID()], ['elementId' => $entry->id, 'siteId' => self::$primarySiteId]), ['orphanedUsages' => 2]],
            'a target nothing uses' => [fn() => $this->index()->targetId(TargetIdentity::forUrl('url', CanonicalUrl::parse('https://example.com/stale-unused'), null)), ['unusedTargets' => 1]],
            // The target is a page too, so a source that is gone as well.
            'a target element gone' => [fn() => Db::update(Table::ELEMENTS, ['dateDeleted' => Db::prepareDateForDb(new DateTime())], ['id' => $target->id]), ['orphanedSources' => self::siteCount(), 'outdatedTargets' => self::siteCount()]],
            'a target site gone' => [fn() => Db::update(Table::SITES, ['dateDeleted' => Db::prepareDateForDb(new DateTime())], ['id' => self::$secondSiteId]), ['orphanedSources' => 2, 'outdatedTargets' => 2]],
            'a value that cannot be read' => [fn() => $this->storeRaw($entry, self::LINKS, ['version' => 9]), ['unreadableSources' => 1]],
            // Without the update that saving the field queues: its usage no longer belongs to a
            // Smart Links field.
            'a Smart Links field changed into another field' => [function(): void {
                Db::update(Table::FIELDS, ['type' => PlainText::class, 'settings' => null], ['id' => self::$instances[self::LINKS]->id]);
                $this->resetCraftServices();
            }, ['orphanedUsages' => 2 * self::siteCount()]],
        ];

        foreach ($cases as $case => [$change, $expected]) {
            $savepoint = Craft::$app->getDb()->beginTransaction();

            try {
                $this->index()->rebuild();
                self::assertFalse($this->index()->status()->isStale(), "$case: fresh first");
                $change();

                if ($case === 'a value that cannot be read') {
                    $this->index()->updateElements([(int)$entry->id]);
                }

                $status = $this->index()->status();
                $counts = array_filter([
                    'unindexedSources' => $status->unindexedSources,
                    'unreadableSources' => $status->unreadableSources,
                    'orphanedSources' => $status->orphanedSources,
                    'orphanedUsages' => $status->orphanedUsages,
                    'unusedTargets' => $status->unusedTargets,
                    'outdatedTargets' => $status->outdatedTargets,
                ]);
                ksort($expected);
                ksort($counts);
                self::assertSame($expected, $counts, $case);

                // Fixed (an unreadable value by readable content), then indexed: current again.
                if ($case === 'a value that cannot be read') {
                    $this->storeRaw($entry, self::LINKS, null);
                }

                $this->index()->rebuild();
                self::assertFalse($this->index()->status()->isStale(), "$case: fixed");
            } finally {
                $savepoint->rollBack();
                $this->resetCraftServices();
            }
        }
    }

    public function testReadingTheInventoryNeverChangesTheIndex(): void
    {
        $entry = self::page('index-read-only', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/read-only']]]]);
        $this->index()->rebuild();
        // Stale on purpose: reading must report it, not mend it.
        Db::update(Table::ELEMENTS, ['dateUpdated' => Db::prepareDateForDb(new DateTime('+1 minute'))], ['id' => $entry->id]);
        $this->index()->targetId(TargetIdentity::forUrl('url', CanonicalUrl::parse('https://example.com/read-only-unused'), null));
        $before = $this->snapshot();
        $this->pushed = [];

        $this->index()->status();
        $this->index()->inventory(InventoryCriteria::fromParams(['search' => 'read-only', 'sort' => 'health']));
        $this->rendered($this->runLinksAction('index', ['search' => 'read-only'], $this->user(['accessCp', 'accessPlugin-smart-links', SmartLinks::PERMISSION_VIEW_INVENTORY])));

        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->index()->status()->isStale());
        self::assertSame([], $this->pushed, 'Nothing was queued either.');
    }

    // The inventory's filters and pages

    public function testFiltersCountTheSourcesSitesAndFieldsNotTheTargets(): void
    {
        $target = self::page('index-filter-target');
        // Linked from the primary site's content to the target's version in the second site, and
        // from two fields.
        $source = self::page('index-filter-source', [
            self::LINKS => [['type' => 'entry', 'data' => ['elementId' => $target->id, 'siteId' => self::$secondSiteId], 'label' => 'filter-pinned']],
            self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/filter-both']]],
            self::SINGLE => [['type' => 'url', 'data' => ['url' => 'https://example.com/filter-both']]],
        ]);
        $second = Entry::find()->id($source->id)->siteId(self::$secondSiteId)->status(null)->one();
        self::assertNotNull($second);
        $second->setFieldValue(self::LINKS, new LinkCollection());
        self::assertTrue(Craft::$app->getElements()->saveElement($second));
        $this->index()->rebuild();
        $primary = Craft::$app->getSites()->getPrimarySite()->handle;

        // The pinned link is used in the primary site's content, whatever site it leads into.
        self::assertSame(1, $this->inventory(['search' => 'filter-pinned', 'sourceSite' => $primary])->total);
        self::assertSame(0, $this->inventory(['search' => 'filter-pinned', 'sourceSite' => 'smartLinksTestSecond'])->total);
        $names = $this->inventory(['search' => 'filter-pinned'])->items[0]->siteNames;
        self::assertNotContains('Smart Links second site', $names);
        self::assertContains(Craft::$app->getSites()->getPrimarySite()->getName(), $names);

        // One target used by two fields in every site: each field counts its own usage.
        $all = $this->item($this->inventory(['search' => 'filter-both']), 'https://example.com/filter-both');
        $urlOnly = $this->item($this->inventory(['search' => 'filter-both', 'source' => self::URL_ONLY]), 'https://example.com/filter-both');
        $single = $this->item($this->inventory(['search' => 'filter-both', 'source' => self::SINGLE]), 'https://example.com/filter-both');
        $both = $this->item($this->inventory(['search' => 'filter-both', 'source' => self::URL_ONLY, 'sourceSite' => 'smartLinksTestSecond']), 'https://example.com/filter-both');
        self::assertSame([2 * self::siteCount(), self::siteCount(), self::siteCount(), 1], [$all->usageCount, $urlOnly->usageCount, $single->usageCount, $both->usageCount]);
        self::assertSame([1, 1, 1, 1], [$all->sourceCount, $urlOnly->sourceCount, $single->sourceCount, $both->sourceCount]);
        self::assertSame(['Smart Links second site'], $both->siteNames);
        self::assertSame(0, $this->inventory(['search' => 'filter-both', 'source' => self::LINKS])->total);
    }

    public function testPagesAreStableWhenEveryTargetSortsTheSame(): void
    {
        $links = [];

        for ($i = 1; $i <= InventoryCriteria::PAGE_SIZE + 3; $i++) {
            $links[] = ['type' => 'url', 'data' => ['url' => sprintf('https://example.com/tie-%02d', $i)]];
        }

        self::page('index-ties', [self::URL_ONLY => $links]);
        $this->index()->rebuild();

        foreach (['usage', 'type', 'health', 'checked', 'target'] as $sort) {
            $first = $this->inventory(['search' => 'tie-', 'sort' => $sort]);
            $second = $this->inventory(['search' => 'tie-', 'sort' => $sort, 'page' => '2']);
            $again = $this->inventory(['search' => 'tie-', 'sort' => $sort]);
            $ids = array_merge(array_map(static fn($item) => $item->id, $first->items), array_map(static fn($item) => $item->id, $second->items));

            // Every target once, across the pages, in the same order each time.
            self::assertCount(InventoryCriteria::PAGE_SIZE + 3, array_unique($ids), $sort);
            self::assertSame(array_map(static fn($item) => $item->id, $first->items), array_map(static fn($item) => $item->id, $again->items), $sort);
            self::assertSame([3, InventoryCriteria::PAGE_SIZE + 1, InventoryCriteria::PAGE_SIZE + 3], [count($second->items), $second->first(), $second->last()], $sort);
        }

        // Beyond the last page, and nothing matching: no items, the total still told.
        $beyond = $this->inventory(['search' => 'tie-', 'page' => '9']);
        self::assertSame([[], InventoryCriteria::PAGE_SIZE + 3, 0, 0], [$beyond->items, $beyond->total, $beyond->first(), $beyond->last()]);
        $none = $this->inventory(['search' => 'nothing-is-called-this']);
        self::assertSame([[], 0, 1], [$none->items, $none->total, $none->pageCount()]);
    }

    // Commands

    public function testRebuildingFromTheCommandLine(): void
    {
        $entry = self::page('index-command', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/command']]]]);
        $this->pushed = [];
        $before = $this->snapshot();

        // Queued: exactly one rebuild, and nothing indexed here.
        $controller = $this->console();
        self::assertSame(0, $controller->runAction('reindex'));
        self::assertCount(1, $this->rebuildJobs());
        self::assertCount(1, $this->pushed);
        self::assertSame($before, $this->snapshot());

        // Now: indexed here, and a success only when nothing went wrong.
        $controller = $this->console();
        $controller->now = true;
        self::assertSame(0, $controller->runAction('reindex', ['now' => true]));
        self::assertStringContainsString('Read ', $this->consoleOut);
        self::assertCount(self::siteCount(), $this->usages($entry));

        $this->storeRaw($entry, self::URL_ONLY, ['version' => 9]);
        $controller = $this->console();
        self::assertSame(1, $controller->runAction('reindex', ['now' => true]));
        self::assertStringContainsString('can’t be read', $this->consoleOut);

        $controller = $this->console();
        self::assertSame(1, $controller->runAction('index-status'));
        self::assertStringContainsString('Element sites with a value that can’t be read (links as last read): 1', $this->consoleOut);
    }

    // Update jobs, however they meet the content

    public function testUpdateJobsConvergeOnContentAsItIsWhenTheyRun(): void
    {
        $changed = self::page('index-job-changed', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/job-before']]]]);
        $deleted = self::page('index-job-deleted', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/job-deleted']]]]);
        $restored = self::page('index-job-restored', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/job-restored']]]]);
        self::assertTrue(Craft::$app->getElements()->deleteElement($restored));
        $this->runPushed();

        // Queued now, run later: by then one was changed, one deleted and one restored, and the
        // jobs overlap and repeat elements.
        $jobs = [
            new UpdateIndex(['elementIds' => [(int)$changed->id, (int)$deleted->id, (int)$changed->id]]),
            new UpdateIndex(['elementIds' => [(int)$deleted->id, (int)$restored->id]]),
            new UpdateIndex(['elementIds' => [(int)$restored->id, (int)$changed->id, (int)$restored->id]]),
        ];
        $this->save($changed, [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/job-after']]]]);
        self::assertTrue(Craft::$app->getElements()->deleteElement($deleted));
        $trashed = Entry::find()->id($restored->id)->trashed()->status(null)->one();
        self::assertNotNull($trashed);
        self::assertTrue(Craft::$app->getElements()->restoreElement($trashed));
        $this->pushed = [];

        foreach ($jobs as $job) {
            $job->execute(Craft::$app->getQueue());
        }

        self::assertSame(['https://example.com/job-after'], $this->linkedUrls($changed));
        self::assertSame([], $this->usages($deleted));
        self::assertSame(['https://example.com/job-restored'], $this->linkedUrls($restored));
        self::assertNull(IndexRecord::findOne(['targetKey' => 'url?url=' . rawurlencode('https://example.com/job-before')]));
        self::assertSame([], $this->pushed, 'Nothing more to queue.');

        // And what one fresh rebuild of the same content gives.
        $after = $this->snapshot(withDates: false);
        $this->index()->rebuild();
        self::assertSame($this->snapshot(withDates: false), $after);
        self::assertFalse($this->index()->status()->isStale());
    }

    // A rebuild beside changes it cannot see coming

    public function testElementsBecomingOrCeasingToBeSourcesBehindARebuildAreFollowed(): void
    {
        $joining = self::page('index-joining', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/joining']]]]);
        $leaving = self::page('index-leaving', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/leaving']]]]);
        $layoutId = (int)(new Query())->select('fieldLayoutId')->from(Table::ELEMENTS)->where(['id' => $joining->id])->scalar();
        self::assertGreaterThan(0, $layoutId);
        // Not a source to start with: in no layout with a Smart Links field, as far as Craft says.
        Db::update(Table::ELEMENTS, ['fieldLayoutId' => null], ['id' => $joining->id]);
        $this->index()->rebuild();
        self::assertSame([], $this->usages($joining));
        self::assertCount(self::siteCount(), $this->usages($leaving));

        // D and E: the walk passes both, then one becomes a source and the other stops being one,
        // with no save for an update to follow.
        $startedAt = $this->rebuildStart();
        $result = new IndexingResult();
        $this->index()->rebuildBatch(0, Index::BATCH_SIZE, $result);
        Db::update(Table::ELEMENTS, ['fieldLayoutId' => $layoutId], ['id' => $joining->id]);
        Db::update(Table::ELEMENTS, ['fieldLayoutId' => null], ['id' => $leaving->id]);
        $this->finishRebuild($startedAt, $result);

        self::assertCount(self::siteCount(), $this->usages($joining));
        self::assertSame([], $this->usages($leaving));
        self::assertNull(SourceRecord::findOne(['elementId' => $leaving->id]));
        self::assertNull(IndexRecord::findOne(['targetKey' => 'url?url=' . rawurlencode('https://example.com/leaving')]));
        self::assertFalse($this->index()->status()->isStale());
    }

    public function testATargetStaysWhileAnyLinkUsesItThroughARebuild(): void
    {
        // F: one URL linked from three pages in every site; one page drops it mid-rebuild.
        $shared = [['type' => 'url', 'data' => ['url' => 'https://example.com/shared-through']]];
        $pages = [self::page('index-through-1', [self::URL_ONLY => $shared]), self::page('index-through-2', [self::URL_ONLY => $shared]), self::page('index-through-3', [self::URL_ONLY => $shared])];
        $this->index()->rebuild();
        $key = 'url?url=' . rawurlencode('https://example.com/shared-through');
        $id = $this->target($key)['id'];

        $startedAt = $this->rebuildStart();
        $result = new IndexingResult();
        $this->index()->rebuildBatch((int)$pages[0]->id - 1, 1, $result);
        $this->save($pages[0], [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/shared-dropped']]]]);
        $this->runPushed();
        self::assertSame($id, $this->target($key)['id'], 'Still used by the other pages.');
        $this->index()->rebuildBatch((int)$pages[0]->id, Index::BATCH_SIZE, $result);
        $this->finishRebuild($startedAt, $result);

        self::assertSame($id, $this->target($key)['id']);
        self::assertSame(2 * self::siteCount(), $this->usageCount($id));

        // Once nothing uses it, it goes.
        foreach ([$pages[1], $pages[2]] as $page) {
            $this->save($page, [self::URL_ONLY => []]);
        }

        $this->runPushed();
        self::assertNull(IndexRecord::findOne($id));
    }

    public function testThreeRebuildsInterleavedEndAsOneWould(): void
    {
        $entries = [];

        for ($i = 1; $i <= 5; $i++) {
            $entries[] = self::page("index-three-$i", [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => "https://example.com/three-$i"]]]]);
        }

        $this->pushed = [];
        /** @var list<RebuildIndex> $chains */
        $chains = [
            new RebuildIndex(['startedAt' => $this->rebuildStart(), 'batchSize' => 1]),
            new RebuildIndex(['startedAt' => $this->rebuildStart(), 'batchSize' => 2]),
            new RebuildIndex(['startedAt' => $this->rebuildStart(), 'batchSize' => 3]),
        ];
        $round = 0;

        while (($job = array_shift($chains)) !== null) {
            $job->execute(Craft::$app->getQueue());
            array_push($chains, ...array_values(array_filter($this->pushedJobs(), static fn($pushed): bool => $pushed instanceof RebuildIndex)));
            $this->pushed = [];

            // Content changes between their batches, with updates run as they come.
            if (++$round === 3) {
                $this->save($entries[1], [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/three-changed']]]]);
                self::assertTrue(Craft::$app->getElements()->deleteElement($entries[3]));
                $this->runUpdates();
            }
        }

        $after = $this->snapshot(withDates: false);
        $this->index()->rebuild();
        self::assertSame($this->snapshot(withDates: false), $after);
        self::assertSame(['https://example.com/three-changed'], $this->linkedUrls($entries[1]));
        self::assertSame([], $this->usages($entries[3]));
        self::assertFalse($this->index()->status()->isStale());
    }

    public function testARebuildOfManyBatchesWithChangesBetweenEveryBatchEndsCurrent(): void
    {
        $entries = [];

        for ($i = 1; $i <= 7; $i++) {
            $entries[] = self::page("index-batches-$i", [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => "https://example.com/batches-$i"]]]]);
        }

        $this->index()->rebuild();
        $this->pushed = [];
        $job = new RebuildIndex(['startedAt' => $this->rebuildStart(), 'batchSize' => 2, 'afterElementId' => (int)$entries[0]->id - 1]);
        $changes = [
            // A second after the walk read it (Craft dates saves to the second; see the README).
            function() use ($entries): void {
                sleep(1);
                $this->save($entries[0], [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/batches-behind']]]]);
            },
            fn() => null,
            fn() => self::assertTrue(Craft::$app->getElements()->deleteElement($entries[5])),
            function() use (&$entries): void {
                $entries[] = self::page('index-batches-new', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/batches-new']]]]);
            },
            fn() => Db::update(Table::ELEMENTS, ['fieldLayoutId' => null], ['id' => $entries[2]->id]),
            fn() => self::assertTrue(Craft::$app->getElements()->restoreElement(Entry::find()->id($entries[5]->id)->trashed()->status(null)->one())),
        ];
        $batches = 0;

        // Only the rebuild's own jobs run: whatever the changes queue waits, as a busy queue might.
        while ($job !== null) {
            $job->execute(Craft::$app->getQueue());
            $batches++;
            ($changes[$batches - 1] ?? static fn() => null)();
            $job = array_values(array_filter($this->pushedJobs(), static fn($pushed): bool => $pushed instanceof RebuildIndex))[0] ?? null;
            $this->pushed = array_values(array_filter($this->pushedJobs(), static fn($pushed): bool => !$pushed instanceof RebuildIndex));
        }

        self::assertGreaterThanOrEqual(5, $batches, 'More than three batches, walk and remainder.');
        // The rebuild has finished: what stopped being a source is gone, whenever it stopped.
        self::assertSame([], $this->usages($entries[2]), 'No longer a source, once the rebuild finished.');
        // The updates the changes queued run after it, as the queue gets to them.
        $this->runPushed();
        self::assertSame(['https://example.com/batches-behind'], $this->linkedUrls($entries[0]));
        self::assertSame([], $this->usages($entries[2]), 'No longer a source.');
        self::assertSame(['https://example.com/batches-6'], $this->linkedUrls($entries[5]), 'Restored behind the cursor.');
        self::assertSame(['https://example.com/batches-new'], $this->linkedUrls(end($entries)));
        self::assertFalse($this->index()->status()->isStale());
    }

    public function testUpdatesJustBeforeOrAfterTheFinishLoseNothing(): void
    {
        $page = self::page('index-around-finish', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/around-1']]]]);
        $this->index()->rebuild();

        foreach (['before' => 'https://example.com/around-2', 'after' => 'https://example.com/around-3'] as $when => $url) {
            $startedAt = $this->rebuildStart();
            $result = new IndexingResult();
            $this->index()->rebuildBatch(0, Index::BATCH_SIZE, $result);
            [$after] = $this->index()->rebuildRemainder($startedAt, 0, Index::BATCH_SIZE, $result);
            self::assertNull($after);
            $this->save($page, [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => $url]]]]);

            if ($when === 'before') {
                $this->runUpdates();
                $this->index()->finishRebuild($result);
            } else {
                $this->index()->finishRebuild($result);
                $this->runUpdates();
            }

            self::assertSame([$url], $this->linkedUrls($page), $when);
            self::assertFalse($this->index()->status()->isStale(), $when);
        }
    }

    // Changes to where Smart Links fields are, through Craft's own services

    public function testLayoutsOfEveryKindAreFollowedWhenTheirSmartLinksFieldsChange(): void
    {
        $url = static fn(string $path): array => [['type' => 'url', 'data' => ['url' => "https://example.com/layout-$path"]]];
        $category = $this->save(self::savedCategory('index-layout-category'), [self::URL_ONLY => $url('category')]);
        $asset = $this->save(self::savedAsset(self::$publicVolume, 'index-layout.pdf'), [self::URL_ONLY => $url('asset')]);
        $user = new User(['username' => 'smartlinks-layout-' . bin2hex(random_bytes(3)), 'email' => 'smartlinks-layout-' . bin2hex(random_bytes(3)) . '@example.test']);
        $user->setFieldValue(self::URL_ONLY, self::entered($url('user')));
        self::assertTrue(Craft::$app->getElements()->saveElement($user));
        $page = self::page('index-layout-page');
        $block = new Entry(['typeId' => self::blockTypeId(), 'fieldId' => Craft::$app->getFields()->getFieldByHandle(self::BLOCKS)?->id, 'ownerId' => $page->id, 'siteId' => self::$primarySiteId]);
        $block->setFieldValue(self::URL_ONLY, self::entered($url('block')));
        self::assertTrue(Craft::$app->getElements()->saveElement($block));
        $sources = ['category' => $category, 'asset' => $asset, 'user' => $user, 'nested entry' => $block];
        // Each kind's layout as Craft gives it now, and how Craft saves it.
        $layouts = [
            'category' => [
                static fn(): FieldLayout => Craft::$app->getCategories()->getGroupById((int)self::$categoryGroup->id)->getFieldLayout(),
                static function(FieldLayout $layout): void {
                    $group = Craft::$app->getCategories()->getGroupById((int)self::$categoryGroup->id);
                    $group->setFieldLayout($layout);
                    self::assertTrue(Craft::$app->getCategories()->saveGroup($group));
                },
            ],
            'asset' => [
                static fn(): FieldLayout => Craft::$app->getVolumes()->getVolumeById((int)self::$publicVolume->id)->getFieldLayout(),
                static function(FieldLayout $layout): void {
                    $volume = Craft::$app->getVolumes()->getVolumeById((int)self::$publicVolume->id);
                    $volume->setFieldLayout($layout);
                    self::assertTrue(Craft::$app->getVolumes()->saveVolume($volume));
                },
            ],
            'user' => [
                static fn(): FieldLayout => Craft::$app->getFields()->getLayoutByType(User::class),
                static fn(FieldLayout $layout) => self::assertTrue(Craft::$app->getUsers()->saveLayout($layout)),
            ],
            'nested entry' => [
                static fn(): FieldLayout => Craft::$app->getEntries()->getEntryTypeById(self::blockTypeId())->getFieldLayout(),
                static function(FieldLayout $layout): void {
                    $blockType = Craft::$app->getEntries()->getEntryTypeById(self::blockTypeId());
                    $blockType->setFieldLayout($layout);
                    self::assertTrue(Craft::$app->getEntries()->saveEntryType($blockType));
                },
            ],
        ];
        if (self::$productTypeId !== null) {
            $sources['product'] = $this->save(self::savedProduct('Index layout product', true), [self::URL_ONLY => $url('product')]);
            $productTypes = static fn(): mixed => self::call(Craft::$app->getPlugins()->getPlugin('commerce'), 'getProductTypes');
            $layouts['product'] = [
                static fn(): FieldLayout => $productTypes()->getProductTypeById(self::$productTypeId)->getFieldLayout(),
                static function(FieldLayout $layout) use ($productTypes): void {
                    $productType = $productTypes()->getProductTypeById(self::$productTypeId);
                    $productType->setFieldLayout($layout);
                    self::assertTrue($productTypes()->saveProductType($productType));
                },
            ];
        }

        $urlOnlyUid = (string)Craft::$app->getFields()->getFieldByHandle(self::URL_ONLY)?->uid;
        $savers = [];

        foreach ($layouts as $kind => [$current, $save]) {
            // Saved without the field, then with it back in the very placement it had (Craft keys
            // content by placement: a new placement of the field starts with no values).
            $savers[$kind] = static function(bool $withField) use ($current, $save, $urlOnlyUid): void {
                static $original = [];
                $layout = $current();
                $original[$layout->id] ??= $layout->getConfig() ?? [];
                $config = $original[$layout->id];

                if (!$withField) {
                    foreach ($config['tabs'] as $i => $tab) {
                        $config['tabs'][$i]['elements'] = array_values(array_filter($tab['elements'], static fn(array $element): bool => ($element['fieldUid'] ?? null) !== $urlOnlyUid));
                    }
                }

                $save(Craft::$app->getFields()->createLayout(['id' => $layout->id, 'uid' => $layout->uid, 'type' => $layout->type] + $config));
            };
        }

        $this->runPushed();
        $this->index()->rebuild();

        try {
            foreach ($savers as $kind => $saver) {
                // Taken out: a walk of that layout, which leaves its elements without usage.
                $this->pushed = [];
                $saver(false);
                $jobs = $this->rebuildJobs();
                self::assertCount(1, $jobs, "$kind: one walk queued");
                self::assertNotNull($jobs[0]->fieldLayoutId, "$kind: of the layout, not a full rebuild");
                $this->resetCraftServices();
                $this->runPushed();
                self::assertSame([], $this->usages($sources[$kind]), "$kind: taken out");

                // Put back: its content's links again, without saving any element.
                $saver(true);
                $this->resetCraftServices();
                $this->runPushed();
                self::assertNotSame([], $this->usages($sources[$kind]), "$kind: put back");
                self::assertSame(["https://example.com/layout-" . ['category' => 'category', 'asset' => 'asset', 'user' => 'user', 'nested entry' => 'block', 'product' => 'product'][$kind]], $this->linkedUrls($sources[$kind]), $kind);
            }
        } finally {
            $this->resetCraftServices();
        }

        self::assertFalse($this->index()->status()->isStale());
    }

    public function testChangesThatMoveNoSmartLinksFieldQueueNothing(): void
    {
        $page = self::page('index-unmoved', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/unmoved']]]]);
        $this->index()->rebuild();
        $layout = Craft::$app->getEntries()->getEntryTypeById((int)self::$entryType->id)->getFieldLayout();
        self::assertNotNull($layout->id);
        $before = $this->snapshot();

        try {
            // The same fields in two tabs, a field renamed, a new Smart Links field: no index work.
            $this->pushed = [];
            $config = $layout->getConfig() ?? [];
            $elements = $config['tabs'][0]['elements'];
            $config['tabs'] = [['name' => 'First', 'elements' => array_slice($elements, 0, 2)], ['name' => 'Second', 'elements' => array_slice($elements, 2)]];
            $entryType = Craft::$app->getEntries()->getEntryTypeById((int)self::$entryType->id);
            $entryType->setFieldLayout(Craft::$app->getFields()->createLayout(['id' => $layout->id, 'uid' => $layout->uid, 'type' => Entry::class] + $config));
            self::assertTrue(Craft::$app->getEntries()->saveEntryType($entryType));
            $renamed = Craft::$app->getFields()->getFieldByHandle(self::URL_ONLY);
            $renamed->name = 'URL only, renamed';
            self::assertTrue(Craft::$app->getFields()->saveField($renamed));
            self::createField('smartLinksTestBrandNew', ['types' => ['url']]);

            self::assertSame([], $this->rebuildJobs());
            $this->resetCraftServices();
            $this->index()->updateElements([(int)$page->id]);
            self::assertSame($before['usage'], $this->snapshot()['usage']);
        } finally {
            $this->resetCraftServices();
        }
    }

    public function testAFieldTurnedIntoASmartLinksFieldIsIndexed(): void
    {
        $fields = Craft::$app->getFields();
        $plain = $fields->createField(['type' => PlainText::class, 'name' => 'Becomes links', 'handle' => 'smartLinksTestBecomesLinks']);
        self::assertTrue($fields->saveField($plain));
        $entryType = Craft::$app->getEntries()->getEntryTypeById((int)self::$entryType->id);
        $layout = $entryType->getFieldLayout();
        $tabs = $layout->getTabs();
        $tabs[0]->setElements([...$tabs[0]->getElements(), new CustomField($plain)]);
        $layout->setTabs($tabs);
        self::assertTrue(Craft::$app->getEntries()->saveEntryType($entryType));
        $this->resetCraftServices();
        $page = self::page('index-becomes', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/becomes']]]]);
        $this->runPushed();
        $this->pushed = [];

        try {
            $plain = $fields->getFieldByHandle('smartLinksTestBecomesLinks');
            $links = $fields->createField(['type' => SmartLinkField::class, 'id' => $plain->id, 'uid' => $plain->uid, 'name' => $plain->name, 'handle' => $plain->handle, 'types' => ['url']]);
            self::assertTrue($fields->saveField($links), Json::encode($links->getErrors()));
            self::assertCount(1, $this->rebuildJobs(), 'A rebuild, as the layouts holding it gained a Smart Links field.');
            $this->resetCraftServices();
            $this->runPushed();
            // Its old text value is no link value: kept as unreadable, never as no links.
            self::assertSame(['https://example.com/becomes'], $this->linkedUrls($page));
        } finally {
            $this->resetCraftServices();
        }
    }

    // Element types whose layout Craft finds otherwise

    public function testAddressesAreSourcesAndProductsWithoutALayoutAreNot(): void
    {
        $layout = Craft::$app->getAddresses()->getFieldLayout();
        $layout->setTabs([...$layout->getTabs(), ...self::layoutWith(Address::class)->getTabs()]);
        self::assertTrue(Craft::$app->getAddresses()->saveFieldLayout($layout));
        $this->resetCraftServices();

        try {
            $owner = new User(['username' => 'smartlinks-address-' . bin2hex(random_bytes(3)), 'email' => 'smartlinks-address-' . bin2hex(random_bytes(3)) . '@example.test']);
            self::assertTrue(Craft::$app->getElements()->saveElement($owner));
            $address = new Address(['ownerId' => $owner->id, 'countryCode' => 'US', 'title' => 'Index address']);
            $address->setFieldValue(self::URL_ONLY, self::entered([['type' => 'url', 'data' => ['url' => 'https://example.com/address']]]));
            self::assertTrue(Craft::$app->getElements()->saveElement($address), Json::encode($address->getErrors()));
            $this->runPushed();
            self::assertSame(['https://example.com/address'], $this->linkedUrls($address));

            if (self::$productTypeId !== null) {
                // A product with no layout ID of its own, as older ones have: not a source, and
                // so neither unread nor unreadable.
                $product = $this->save(self::savedProduct('Index layoutless', true), [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/layoutless']]]]);
                Db::update(Table::ELEMENTS, ['fieldLayoutId' => null], ['id' => $product->id]);
                $this->index()->rebuild();
                self::assertSame([], $this->usages($product));
                self::assertSame(0, $this->index()->status()->unindexedSources + $this->index()->status()->unreadableSources);
            }
        } finally {
            $this->resetCraftServices();
        }
    }

    // Unreadable values among readable ones

    public function testOnlyTheUnreadableValueKeepsItsLinksAndIsLogged(): void
    {
        $page = self::page('index-one-unreadable', [
            self::LINKS => [['type' => 'url', 'data' => ['url' => 'https://example.com/unreadable-kept']], ['type' => 'email', 'data' => ['address' => 'kept@example.com']]],
            self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/readable-old']]],
        ]);
        $this->index()->rebuild();
        $kept = array_values(array_filter($this->usages($page, self::$primarySiteId), fn(array $row): bool => $row['layoutElementUid'] === (string)self::$instances[self::LINKS]->layoutElement?->uid));
        // One link of the value broken: the whole value can't be read.
        $this->storeRaw($page, self::LINKS, ['version' => 1, 'links' => [['uid' => '5b0e9d7a-1c2f-4e3a-8b4c-6d7e8f9a0b1c', 'type' => 'url', 'data' => ['url' => 'https://example.com/fine']], ['uid' => '6c1f0e8b-2d3a-4f4b-9c5d-7e8f9a0b1c2d', 'type' => 'url', 'data' => ['url' => 'javascript:alert(1)']]]]);
        $content = Json::decode((string)(new Query())->select('content')->from(Table::ELEMENTS_SITES)->where(['elementId' => $page->id, 'siteId' => self::$primarySiteId])->scalar());
        $content[(string)self::$instances[self::URL_ONLY]->layoutElement?->uid] = ['version' => 1, 'links' => [['uid' => '7d2a1f9c-3e4b-4a5c-8d6e-8f9a0b1c2d3e', 'type' => 'url', 'data' => ['url' => 'https://example.com/readable-new']]]];
        Db::update(Table::ELEMENTS_SITES, ['content' => $content], ['elementId' => $page->id, 'siteId' => self::$primarySiteId]);
        // Craft's logger empties its buffer when it flushes; held for this one update.
        $logger = Craft::getLogger();
        $logger->flush();
        $interval = $logger->flushInterval;
        $logger->flushInterval = PHP_INT_MAX;
        $logged = count($logger->messages);

        try {
            $result = $this->index()->updateElements([(int)$page->id]);
            $messages = array_slice($logger->messages, $logged);
        } finally {
            $logger->flushInterval = $interval;
        }

        self::assertSame(1, $result->unreadableValues);
        self::assertSame(1, (int)SourceRecord::findOne(['elementId' => $page->id, 'siteId' => self::$primarySiteId])?->unreadableValues);
        self::assertSame($kept, array_values(array_filter($this->usages($page, self::$primarySiteId), fn(array $row): bool => $row['layoutElementUid'] === (string)self::$instances[self::LINKS]->layoutElement?->uid)));
        self::assertContains('https://example.com/readable-new', $this->linkedUrls($page));
        self::assertNotContains('https://example.com/readable-old', $this->linkedUrls($page));
        self::assertNotEmpty(array_filter($messages, static fn(array $message): bool => is_string($message[0]) && str_contains($message[0], 'can’t be read')));
        self::assertTrue($this->index()->status()->isStale());
    }

    // The inventory's pages at their edges

    #[DataProvider('pageSizes')]
    public function testEveryTargetIsOnExactlyOnePage(int $targets): void
    {
        $links = array_map(static fn(int $i): array => ['type' => 'url', 'data' => ['url' => sprintf('https://example.com/edge-%03d', $i)]], $targets === 0 ? [] : range(1, $targets));
        self::page('index-edges', [self::URL_ONLY => $links]);
        $this->index()->rebuild();
        $seen = [];
        $first = $this->inventory(['search' => 'example.com/edge-']);

        for ($page = 1; $page <= $first->pageCount(); $page++) {
            $items = $this->inventory(['search' => 'example.com/edge-', 'sort' => 'link', 'page' => (string)$page])->items;
            self::assertLessThanOrEqual(InventoryCriteria::PAGE_SIZE, count($items));
            array_push($seen, ...array_map(static fn($item) => $item->resolvedUrl, $items));
        }

        self::assertSame($targets, $first->total);
        self::assertSame(max(1, (int)ceil($targets / InventoryCriteria::PAGE_SIZE)), $first->pageCount());
        self::assertSame(array_map(static fn(array $link): string => $link['data']['url'], $links), $seen);
        self::assertSame([], $this->inventory(['search' => 'example.com/edge-', 'page' => (string)($first->pageCount() + 1)])->items);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function pageSizes(): array
    {
        return ['none' => [0], 'one' => [1], 'one page' => [50], 'one more' => [51], 'two pages' => [100], 'two and one' => [101], 'several' => [160]];
    }

    // The command line under contention

    public function testRebuildingNowFailsLoudlyWhileAnotherProcessWrites(): void
    {
        $mutex = Craft::$app->getMutex();
        self::assertInstanceOf(\craft\mutex\Mutex::class, $mutex);
        $mutex->releaseQueuedLocks();
        $process = proc_open([PHP_BINARY, __DIR__ . '/../_support/hold-index-lock.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 30);
        self::assertSame("held\n", fgets($pipes[1]));
        $this->index()->lockTimeout = 1;

        try {
            $controller = $this->console();
            $this->expectException(IndexLockedException::class);
            $controller->runAction('reindex', ['now' => true]);
        } finally {
            fwrite($pipes[0], "release\n");
            fclose($pipes[0]);
            fgets($pipes[1]);
            proc_close($process);
            $this->index()->lockTimeout = 60;
        }
    }

    public function testSitesChangedDeletedOrRestoredAreFollowed(): void
    {
        $page = self::page('index-site-saved', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => '/site-relative']]]]);
        $this->index()->rebuild();
        $sites = Craft::$app->getSites();
        $key = 'url?siteId=' . self::$secondSiteId . '&url=' . rawurlencode('/site-relative');

        try {
            // A new base URL: links into that site's paths are checked on its new host.
            $this->pushed = [];
            $site = $sites->getSiteById(self::$secondSiteId);
            self::assertNotNull($site);
            $site->setBaseUrl('https://moved.example.test/');
            self::assertTrue($sites->saveSite($site), Json::encode($site->getErrors()));
            self::assertCount(1, $this->rebuildJobs());
            $this->resetCraftServices();
            $this->runPushed();
            self::assertSame(hash('sha256', 'https://moved.example.test/site-relative'), $this->target($key)['healthUrlHash']);

            // Deleted: its rows go.
            $this->pushed = [];
            self::assertTrue(Craft::$app->getSites()->deleteSite(Craft::$app->getSites()->getSiteById(self::$secondSiteId)));
            self::assertCount(1, $this->rebuildJobs());
            $this->resetCraftServices();
            $this->runPushed();
            self::assertSame([], $this->usages($page, self::$secondSiteId));
            self::assertFalse($this->index()->status()->isStale());

            // Restored, as Craft restores a site re-added through project config, then saved: back.
            $this->pushed = [];
            self::assertTrue(Craft::$app->getSites()->restoreSiteById(self::$secondSiteId));
            Craft::$app->getSites()->refreshSites();
            $restored = Craft::$app->getSites()->getSiteById(self::$secondSiteId);
            self::assertNotNull($restored);
            self::assertTrue(Craft::$app->getSites()->saveSite($restored), Json::encode($restored->getErrors()));
            self::assertCount(1, $this->rebuildJobs());
            $this->resetCraftServices();
            $this->runPushed();
            self::assertCount(1, $this->usages($page, self::$secondSiteId));
            self::assertFalse($this->index()->status()->isStale());
        } finally {
            $this->resetCraftServices();
        }
    }

    // Helpers

    /**
     * A saved entry holding only the links given: the fixture's field with default links is left
     * empty.
     *
     * @param array<string, mixed> $values
     */
    private static function page(string $slug, array $values = []): Entry
    {
        return self::savedEntry($slug, $values + [self::DEFAULTS => new LinkCollection()]);
    }

    /**
     * The host's sites and the fixture's: test pages are in every one.
     *
     * @return list<int>
     */
    private static function siteIds(): array
    {
        return array_map('intval', Craft::$app->getSites()->getAllSiteIds());
    }

    private static function siteCount(): int
    {
        return count(self::siteIds());
    }

    /**
     * When a rebuild starting now starts, as the rebuild jobs record it.
     */
    private function rebuildStart(): string
    {
        return Db::prepareDateForDb(new DateTime());
    }

    /**
     * What a rebuild's jobs do once its walk is done: what is left behind it, then the finish.
     */
    private function finishRebuild(string $startedAt, IndexingResult $result): void
    {
        $after = 0;

        do {
            [$after] = $this->index()->rebuildRemainder($startedAt, $after, Index::BATCH_SIZE, $result);
        } while ($after !== null);

        $this->index()->finishRebuild($result);
    }

    /**
     * Saves an element again with the given links, as an author would.
     *
     * @param array<string, list<array<string, mixed>>> $values
     */
    private function save(ElementInterface $element, array $values): ElementInterface
    {
        $fresh = Craft::$app->getElements()->getElementById((int)$element->id, $element::class, $element->siteId ?? self::$primarySiteId, ['status' => null]);
        self::assertNotNull($fresh);

        foreach ($values as $handle => $links) {
            $fresh->setFieldValue($handle, self::entered($links));
        }

        self::assertTrue(Craft::$app->getElements()->saveElement($fresh), Json::encode($fresh->getErrors()));

        return $fresh;
    }

    /**
     * The URLs an element's links in a site lead to, in the order first used.
     *
     * @return list<string>
     */
    private function linkedUrls(ElementInterface $element, ?int $siteId = null): array
    {
        $urls = array_map(static fn(array $row): ?string => IndexRecord::findOne((int)$row['indexId'])?->resolvedUrl, $this->usages($element, $siteId ?? self::$primarySiteId));

        return array_values(array_unique(array_filter($urls, 'is_string')));
    }

    /**
     * Writes a field's stored value in the primary site directly, as content no Smart Links code
     * wrote, without saving the element.
     */
    private function storeRaw(ElementInterface $element, string $handle, mixed $stored): void
    {
        $uid = (string)self::$instances[$handle]->layoutElement?->uid;
        $content = Json::decode((string)(new Query())->select('content')->from(Table::ELEMENTS_SITES)->where(['elementId' => $element->id, 'siteId' => self::$primarySiteId])->scalar());
        $content[$uid] = $stored;
        // Craft encodes a JSON column's value itself.
        Db::update(Table::ELEMENTS_SITES, ['content' => $content], ['elementId' => $element->id, 'siteId' => self::$primarySiteId]);
        Craft::$app->getElements()->invalidateCachesForElement($element);
    }

    /**
     * Saves the test page layout as first made, without the given layout elements.
     *
     * @param list<string> $without Layout element UIDs.
     */
    private function saveLayoutWithout(FieldLayout $layout, array $without): void
    {
        $this->originalLayout ??= $layout->getConfig() ?? [];
        $config = $this->originalLayout;

        foreach ($config['tabs'] as $i => $tab) {
            $config['tabs'][$i]['elements'] = array_values(array_filter($tab['elements'], static fn(array $element): bool => !in_array($element['uid'] ?? null, $without, true)));
        }

        // Saved as Craft saves a layout: with the entry type it belongs to.
        $entryType = Craft::$app->getEntries()->getEntryTypeById((int)self::$entryType->id);
        self::assertNotNull($entryType);
        $entryType->setFieldLayout(Craft::$app->getFields()->createLayout(['id' => $layout->id, 'uid' => $layout->uid, 'type' => Entry::class] + $config));
        self::assertTrue(Craft::$app->getEntries()->saveEntryType($entryType), Json::encode($entryType->getErrors()));
    }

    /**
     * Starts Craft's services afresh after a test changed and rolled back what they remember
     * (layouts, fields, sites).
     */
    private function resetCraftServices(): void
    {
        $components = Craft::$app->getComponents();

        foreach (['fields', 'entries', 'sites', 'elements'] as $id) {
            Craft::$app->set($id, $components[$id]);
        }

        self::resetPluginServices();
    }

    /**
     * Craft's own rows for elements: what index code must never change.
     *
     * @param list<int> $elementIds
     */
    private function craftDigest(array $elementIds): string
    {
        return hash('sha256', Json::encode([
            (new Query())->from(Table::ELEMENTS)->where(['id' => $elementIds])->orderBy('id')->all(),
            (new Query())->from(Table::ELEMENTS_SITES)->where(['elementId' => $elementIds])->orderBy('id')->all(),
            (new Query())->from(Table::RELATIONS)->where(['sourceId' => $elementIds])->orderBy('id')->all(),
        ]));
    }

    /**
     * Each target row of an element leads where that element's version in that site does now.
     *
     * @param list<string> $keys
     */
    private function assertTargetsLeadWhereTheElementIs(Entry $element, array $keys): void
    {
        foreach ($keys as $key) {
            parse_str(substr($key, strpos($key, '?') + 1), $components);
            $now = Entry::find()->id($element->id)->siteId((int)$components['siteId'])->one();
            self::assertSame($now?->getUrl(), $this->target($key)['resolvedUrl'], $key);
        }
    }

    private static function blockTypeId(): int
    {
        return (int)Craft::$app->getEntries()->getEntryTypeByHandle('smartLinksTestBlock')?->id;
    }

    private function index(): Index
    {
        return SmartLinks::getInstance()->getIndex();
    }

    /**
     * Runs the index jobs pushed since the last call, as the queue would, in order, including any
     * they push.
     *
     * @return list<UpdateIndex|RebuildIndex>
     * @phpstan-impure
     */
    private function runPushed(): array
    {
        $ran = [];

        while (($job = array_shift($this->pushed)) !== null) {
            if ($job instanceof UpdateIndex || $job instanceof RebuildIndex) {
                $job->execute(Craft::$app->getQueue());
                $ran[] = $job;
            }
        }

        return $ran;
    }

    /**
     * Runs only the update jobs pushed so far, as a queue that has them first would.
     */
    private function runUpdates(): void
    {
        $updates = array_values(array_filter($this->pushedJobs(), static fn($job): bool => $job instanceof UpdateIndex));
        $this->pushed = array_values(array_filter($this->pushedJobs(), static fn($job): bool => !$job instanceof UpdateIndex));

        foreach ($updates as $update) {
            $update->execute(Craft::$app->getQueue());
        }
    }

    /**
     * Every job pushed since the last time the list was emptied.
     *
     * @return list<\yii\queue\JobInterface>
     */
    private function pushedJobs(): array
    {
        return $this->pushed;
    }

    /**
     * The rebuild jobs pushed since the last time the list was emptied.
     *
     * @return list<RebuildIndex>
     * @phpstan-impure
     */
    private function rebuildJobs(): array
    {
        return array_values(array_filter($this->pushed, static fn($job): bool => $job instanceof RebuildIndex));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function usages(ElementInterface $entry, ?int $siteId = null): array
    {
        return (new Query())
            ->select(['id', 'indexId', 'siteId', 'fieldId', 'layoutElementUid', 'linkUid', 'sortOrder', 'label'])
            ->from(UsageRecord::TABLE)
            ->where(['elementId' => $entry->id])
            ->andFilterWhere(['siteId' => $siteId])
            ->orderBy(['siteId' => SORT_ASC, 'layoutElementUid' => SORT_ASC, 'sortOrder' => SORT_ASC])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function target(string $key): array
    {
        $row = (new Query())->from(IndexRecord::TABLE)->where(['targetKey' => $key])->one();
        self::assertIsArray($row, "No index row for $key.");

        return $row;
    }

    private function keyOf(int $indexId): string
    {
        return (string)IndexRecord::findOne($indexId)?->targetKey;
    }

    private function usageCount(int|string $indexId): int
    {
        return (int)UsageRecord::find()->where(['indexId' => $indexId])->count();
    }

    private function elementVersion(Entry $entry): string
    {
        return (string)(new Query())->select('dateUpdated')->from(Table::ELEMENTS)->where(['id' => $entry->id])->scalar();
    }

    /**
     * Every index row this test's content produced, as stored.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(bool $withDates = true): array
    {
        $rows = [
            'index' => (new Query())->from(IndexRecord::TABLE)->orderBy('id')->all(),
            'usage' => (new Query())->from(UsageRecord::TABLE)->orderBy('id')->all(),
            'sources' => (new Query())->from(SourceRecord::TABLE)->orderBy('id')->all(),
            'health' => (new Query())->from(HealthRecord::TABLE)->orderBy('id')->all(),
        ];

        // What a rebuild rewrites as a matter of course: when it read each source.
        foreach ($rows['sources'] as $i => $row) {
            unset($rows['sources'][$i]['dateIndexed'], $rows['sources'][$i]['dateUpdated']);
        }

        if (!$withDates) {
            foreach ($rows as $table => $tableRows) {
                foreach ($tableRows as $i => $row) {
                    unset($rows[$table][$i]['dateCreated'], $rows[$table][$i]['dateUpdated']);
                }
            }
        }

        return $rows;
    }

    private function reload(Entry $entry, bool $enabledOnly = false): Entry
    {
        $query = Entry::find()->id($entry->id)->siteId(self::$primarySiteId);
        $reloaded = ($enabledOnly ? $query : $query->status(null))->one();
        self::assertInstanceOf(Entry::class, $reloaded);

        return $reloaded;
    }

    /**
     * @param array<string, string> $params
     */
    private function inventory(array $params): InventoryPage
    {
        return $this->index()->inventory(InventoryCriteria::fromParams($params));
    }

    /**
     * @param array<string, string> $params
     * @return list<string|null>
     */
    private function urls(array $params): array
    {
        return array_map(static fn($item) => $item->resolvedUrl, $this->inventory($params)->items);
    }

    private function item(InventoryPage $page, string $url): \Tahadudhiya\SmartLinks\models\InventoryItem
    {
        foreach ($page->items as $item) {
            if ($item->resolvedUrl === $url) {
                return $item;
            }
        }

        self::fail("$url is not on the page.");
    }

    /**
     * @param list<string> $permissions
     */
    private function user(array $permissions): TestUser
    {
        $user = new TestUser();
        $user->id = 1;
        $user->grantedPermissions = $permissions;

        return $user;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function runLinksAction(string $action, array $params, TestUser $user, string $method = 'GET', bool $withCsrf = true): \yii\web\Response
    {
        // The inventory reads its query string.
        $_GET = $method === 'GET' ? $params : [];

        // The inventory is a page; a rebuild is posted as Craft's forms post with JavaScript.
        return $this->runControllerAction(LinksController::class, 'links', $action, $params, $user, $withCsrf, $method, '/admin/actions/smart-links/links/', json: $action !== 'index');
    }

    /**
     * The inventory a page response shows: its table, rendered from the page's own variables.
     * The control panel layout around it needs a web session, which tests do not have.
     *
     * @return array{string, array<string, mixed>}
     */
    private function rendered(\yii\web\Response $response): array
    {
        $behavior = $response->getBehavior(TemplateResponseBehavior::NAME);
        self::assertInstanceOf(TemplateResponseBehavior::class, $behavior);
        self::assertSame('smart-links/links/_index', $behavior->template);

        // The page compiles, with everything it extends and includes.
        Craft::$app->getView()->getTwig()->load('smart-links/links/_index');

        return [Craft::$app->getView()->renderTemplate('smart-links/links/_inventory', $behavior->variables, View::TEMPLATE_MODE_CP), $behavior->variables];
    }

    private function assertForbidden(callable $request): void
    {
        try {
            $request();
            self::fail('The request was allowed.');
        } catch (ForbiddenHttpException) {
            self::addToAssertionCount(1);
        }
    }

    /**
     * The console controller, writing what it prints to {@see $consoleOut} rather than the
     * terminal.
     */
    private function console(): SmartLinksController
    {
        $this->consoleOut = '';
        $write = function(string $string): int {
            $this->consoleOut .= $string;

            return strlen($string);
        };

        return new class('smartlinks', Craft::$app, $write) extends SmartLinksController {
            public function __construct(string $id, \yii\base\Module $module, private \Closure $write)
            {
                parent::__construct($id, $module);
            }

            public function stdout($string): int
            {
                return ($this->write)($string);
            }

            public function stderr($string): int
            {
                return ($this->write)($string);
            }
        };
    }
}
