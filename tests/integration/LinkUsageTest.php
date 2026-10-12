<?php

namespace Tahadudhiya\SmartLinks\Tests\integration;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\PropagationMethod;
use craft\events\RegisterUrlRulesEvent;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\web\TemplateResponseBehavior;
use craft\web\UrlManager;
use craft\web\View;
use DateTime;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SmartLinks\controllers\LinksController;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;
use Tahadudhiya\SmartLinks\enums\StaleUsage;
use Tahadudhiya\SmartLinks\errors\TargetHashCollisionException;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;
use Tahadudhiya\SmartLinks\jobs\RebuildIndex;
use Tahadudhiya\SmartLinks\jobs\UpdateIndex;
use Tahadudhiya\SmartLinks\linktypes\ProductLinkType;
use Tahadudhiya\SmartLinks\models\CanonicalUrl;
use Tahadudhiya\SmartLinks\models\InventoryCriteria;
use Tahadudhiya\SmartLinks\models\LinkCollection;
use Tahadudhiya\SmartLinks\models\LinkUsage;
use Tahadudhiya\SmartLinks\models\TargetIdentity;
use Tahadudhiya\SmartLinks\models\UsageCriteria;
use Tahadudhiya\SmartLinks\models\UsagePage;
use Tahadudhiya\SmartLinks\records\HealthRecord;
use Tahadudhiya\SmartLinks\records\IndexRecord;
use Tahadudhiya\SmartLinks\records\SourceRecord;
use Tahadudhiya\SmartLinks\records\UsageRecord;
use Tahadudhiya\SmartLinks\services\Index;
use Tahadudhiya\SmartLinks\services\Usage;
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
use yii\web\NotFoundHttpException;

/**
 * Where links are used, against real Craft content in every site of the host: every occurrence of
 * a target with the element, type, field placement, site and owners holding it; lookups by
 * element reference and by canonical URL; drafts, revisions, autosaves, duplicates, propagation,
 * deleted and unloadable sources, deleted targets, fields renamed, moved, deleted or changed;
 * occurrences the index has not caught up with; pages, filters, query counts; and who may see it.
 *
 * Each test runs in a savepoint of the fixture's transaction. Index jobs that saves queue are
 * caught as they are pushed and run here, as the queue would run them.
 */
final class LinkUsageTest extends TestCase
{
    use FieldFixture;
    use ElementFixture;

    /** A Matrix field whose nested entries have the URL-only Smart Links field, and itself. */
    private const BLOCKS = 'smartLinksUsageBlocks';

    /** A Smart Links field allowing every element link type. */
    private const ELEMENTS = 'smartLinksUsageElements';

    private const BLOCK_TYPE = 'smartLinksUsageBlock';

    private ?Transaction $savepoint = null;

    /** @var list<\yii\queue\JobInterface> */
    private array $pushed = [];

    protected static function usesTestTypes(): bool
    {
        return false;
    }

    protected static function extraLayoutFields(): array
    {
        $types = ['entry', 'category', 'asset', 'user', 'url'];

        if (ProductLinkType::isAvailable()) {
            $types[] = 'commerce-product';
        }

        $elements = self::createField(self::ELEMENTS, ['types' => $types]);
        $urlOnly = Craft::$app->getFields()->getFieldByHandle(self::URL_ONLY);
        self::assertNotNull($urlOnly);

        $blockType = new EntryType(['name' => 'Smart Links usage block', 'handle' => self::BLOCK_TYPE, 'hasTitleField' => false, 'titleFormat' => 'Block']);
        $blockType->setFieldLayout(self::layoutOf([new CustomField($urlOnly)]));
        self::assertTrue(Craft::$app->getEntries()->saveEntryType($blockType), Json::encode($blockType->getErrors()));
        $matrix = Craft::$app->getFields()->createField(['type' => Matrix::class, 'name' => 'Usage blocks', 'handle' => self::BLOCKS, 'entryTypes' => [$blockType]]);
        self::assertTrue(Craft::$app->getFields()->saveField($matrix), Json::encode($matrix->getErrors()));

        // Blocks nest in blocks, so an occurrence can be several owners deep.
        $blockType->setFieldLayout(self::layoutOf([new CustomField($urlOnly), new CustomField($matrix)]));
        self::assertTrue(Craft::$app->getEntries()->saveEntryType($blockType), Json::encode($blockType->getErrors()));

        return [new CustomField($elements), new CustomField($matrix)];
    }

    public static function setUpBeforeClass(): void
    {
        self::setUpFixture();
        self::setUpElementFixture();
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownFixture();
        self::tearDownElementFixture();
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
        self::forgetRequestedSite();

        if ($this->savepoint?->getIsActive()) {
            $this->savepoint->rollBack();
        }
    }

    public function catchJob(PushEvent $event): void
    {
        $this->pushed[] = $event->job;
    }

    // Occurrences and where they are

    public function testEveryOccurrenceOfATargetIsListedWithWhereItIs(): void
    {
        $about = self::page('usage-about');
        $home = self::page('usage-home', [
            self::LINKS => [
                ['type' => 'entry', 'data' => ['elementId' => $about->id], 'label' => 'About us'],
                ['type' => 'url', 'data' => ['url' => 'https://example.com/usage-elsewhere']],
                ['type' => 'entry', 'data' => ['elementId' => $about->id]],
            ],
            // The same field placed in the layout again, under another handle.
            self::LINKS_AGAIN => [['type' => 'entry', 'data' => ['elementId' => $about->id], 'label' => 'Again']],
        ]);
        $this->runPushed();
        Craft::$app->getUser()->setIdentity(self::author());

        $indexId = $this->targetId("entry?elementId=$about->id&siteId=" . self::$primarySiteId);
        $page = $this->usage()->ofTarget($indexId);
        $value = $this->reload($home)->getFieldValue(self::LINKS);
        $again = $this->reload($home)->getFieldValue(self::LINKS_AGAIN);
        self::assertInstanceOf(LinkCollection::class, $value);
        self::assertInstanceOf(LinkCollection::class, $again);

        // Two links in one field and one in its second placement: three occurrences, of one target.
        self::assertSame(3, $page->total);
        $byHandle = [];

        foreach ($page->items as $occurrence) {
            $byHandle[$occurrence->fieldHandle][] = $occurrence;
            self::assertSame((int)$home->id, $occurrence->elementId);
            self::assertSame(Entry::class, $occurrence->elementType);
            self::assertSame(Entry::displayName(), $occurrence->elementTypeName);
            self::assertSame(self::$primarySiteId, $occurrence->siteId);
            self::assertSame(Craft::$app->getSites()->getSiteById(self::$primarySiteId)?->getName(), $occurrence->siteName);
            self::assertSame((int)self::$instances[self::LINKS]->id, $occurrence->fieldId);
            self::assertSame($indexId, $occurrence->indexId);
            self::assertSame('entry', $occurrence->linkType);
            self::assertSame((int)$home->id, (int)$occurrence->element?->id);
            self::assertSame([['label' => 'usage-home', 'url' => $home->getCpEditUrl(), 'via' => null]], $occurrence->path);
            self::assertSame($home->getCpEditUrl(), $occurrence->editUrl());
            self::assertNull($occurrence->stale);
        }

        [$first, $third] = $byHandle[self::LINKS];
        self::assertSame([1, 3], [$first->position, $third->position]);
        self::assertSame(['About us', null], [$first->label, $third->label]);
        self::assertSame([$value->links[0]->uid, $value->links[2]->uid], [$first->linkUid, $third->linkUid]);
        $layoutElementUid = (string)self::$instances[self::LINKS]->layoutElement?->uid;
        self::assertSame($layoutElementUid, $first->layoutElementUid);
        self::assertSame("$home->id:" . self::$primarySiteId . ":$layoutElementUid:{$value->links[0]->uid}", $first->key());

        // The second placement: the same field, its own handle and layout element.
        [$placed] = $byHandle[self::LINKS_AGAIN];
        self::assertSame('Again', $placed->label);
        self::assertSame($again->links[0]->uid, $placed->linkUid);
        self::assertSame((string)self::$instances[self::LINKS_AGAIN]->layoutElement?->uid, $placed->layoutElementUid);
        self::assertSame(self::LINKS, $placed->recordedFieldHandle);

        // The link's value was propagated: in the other site it is an occurrence of that site's
        // version of the entry, another target.
        $secondSite = $this->usage()->ofTarget($this->targetId("entry?elementId=$about->id&siteId=" . self::$secondSiteId));
        self::assertSame([self::$secondSiteId], array_values(array_unique(array_map(static fn(LinkUsage $usage): int => $usage->siteId, $secondSite->items))));
        $copied = array_values(array_filter($secondSite->items, static fn(LinkUsage $usage): bool => $usage->layoutElementUid === $layoutElementUid && $usage->position === 1))[0];
        self::assertSame($first->linkUid, $copied->linkUid);
        self::assertNotSame($first->key(), $copied->key());
    }

    public function testANestedOccurrenceShowsEveryOwnerOutermostFirst(): void
    {
        $page = self::page('usage-nest');
        $block = $this->block($page, []);
        $inner = $this->block($block, [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-nested']]]);
        $this->runPushed();
        $primary = new UsageCriteria(siteId: self::$primarySiteId);

        Craft::$app->getUser()->setIdentity(self::author());
        $occurrence = $this->usage()->ofUrl('https://example.com/usage-nested', null, $primary)->items[0];

        self::assertSame((int)$inner->id, $occurrence->elementId);
        self::assertSame(self::URL_ONLY, $occurrence->fieldHandle);
        // The page, its block, and the block in that: each in the field of the one before.
        self::assertSame(['usage-nest', 'Block', 'Block'], array_column($occurrence->path, 'label'));
        self::assertSame([null, 'Usage blocks', 'Usage blocks'], array_column($occurrence->path, 'via'));
        self::assertSame($page->getCpEditUrl(), $occurrence->path[0]['url']);
        self::assertNotNull($occurrence->editUrl());

        // Someone who may not view them sees where it is, and no way into any of it.
        Craft::$app->getUser()->setIdentity($this->user(['accessCp']));
        $hidden = $this->usage()->ofUrl('https://example.com/usage-nested', null, $primary)->items[0];
        self::assertSame(['usage-nest', 'Block', 'Block'], array_column($hidden->path, 'label'));
        self::assertSame([null, null, null], array_column($hidden->path, 'url'));
        self::assertNull($hidden->editUrl());

        // An owner that can't be loaded (its row naming a type that can't load it) is said to be
        // so; no other owner is put in its place, and nothing fails.
        Db::update(Table::ELEMENTS, ['type' => Category::class], ['id' => $block->id]);
        Craft::$app->getElements()->invalidateAllCaches();
        Craft::$app->getUser()->setIdentity(self::author());
        $orphan = $this->usage()->ofUrl('https://example.com/usage-nested', null, $primary)->items[0];
        self::assertSame(["Owner $block->id (can’t be loaded)", 'Block'], array_column($orphan->path, 'label'));
        self::assertNull($orphan->path[0]['url']);

        // An owner of a type that is not available (its plugin removed): Craft can't create what is
        // nested in it, at any depth, so it is said to be so, and nothing fails.
        Db::update(Table::ELEMENTS, ['type' => Entry::class], ['id' => $block->id]);
        Db::update(Table::ELEMENTS, ['type' => 'Gone\\Plugin\\Element'], ['id' => $page->id]);
        Craft::$app->getElements()->invalidateAllCaches();
        $unavailable = $this->usage()->ofUrl('https://example.com/usage-nested', null, $primary)->items[0];
        self::assertSame(StaleUsage::SOURCE_OWNER_UNAVAILABLE, $unavailable->stale);
        self::assertSame([[], null], [$unavailable->path, $unavailable->element]);
    }

    // Finding a target by the element it names

    public function testEveryElementTypeIsFoundByTheElementItselfAndNeverByItsUrl(): void
    {
        $category = self::savedCategory('usage-category');
        $asset = self::savedAsset(self::$publicVolume, 'usage.pdf');
        $user = User::find()->status(null)->one();
        self::assertInstanceOf(User::class, $user);
        $pinned = self::page('usage-pinned');
        $disabled = self::savedEntry('usage-disabled', [self::DEFAULTS => new LinkCollection()], enabled: false);
        $links = [
            'category' => ['type' => 'category', 'data' => ['elementId' => $category->id]],
            'asset' => ['type' => 'asset', 'data' => ['elementId' => $asset->id]],
            'user' => ['type' => 'user', 'data' => ['elementId' => $user->id]],
            'pinned' => ['type' => 'entry', 'data' => ['elementId' => $pinned->id, 'siteId' => self::$secondSiteId]],
            'disabled' => ['type' => 'entry', 'data' => ['elementId' => $disabled->id]],
        ];
        $targets = ['category' => $category, 'asset' => $asset, 'user' => $user, 'pinned' => $pinned, 'disabled' => $disabled];

        if (self::$productTypeId !== null) {
            $product = self::savedProduct('Usage product', true);
            $links['product'] = ['type' => 'commerce-product', 'data' => ['elementId' => $product->id]];
            $targets['product'] = $product;
        }

        $source = self::page('usage-elements', [self::ELEMENTS => array_values($links)]);
        $this->runPushed();

        foreach ($targets as $kind => $target) {
            $found = $this->usage()->ofElement((int)$target->id);
            // The field's value is the same in every site: one occurrence per site, all this link.
            self::assertSame(self::siteCount(), $found->total, $kind);
            self::assertSame([(int)$source->id], self::elementIds($found), $kind);
            self::assertSame([$links[$kind]['type']], array_values(array_unique(array_map(static fn(LinkUsage $usage): string => $usage->linkType, $found->items))), $kind);
        }

        // A user has one version, whichever site the link is in or asks about.
        foreach (self::siteIds() as $siteId) {
            self::assertSame(self::siteCount(), $this->usage()->ofElement((int)$user->id, $siteId)->total);
        }

        // A category has a version per site: linked from each site, each site's own.
        self::assertSame([self::$secondSiteId], array_map(static fn(LinkUsage $usage): int => $usage->siteId, $this->usage()->ofElement((int)$category->id, self::$secondSiteId)->items));
        // Its second-site version has no URL (its group has pages in the primary site only), and is found all the same.
        self::assertSame(ResolutionStatus::NO_URL->value, IndexRecord::findOne(['targetKey' => "category?elementId=$category->id&siteId=" . self::$secondSiteId])?->targetStatus);

        // Pinned to the second site, from content in every site: the target's site is not the source's.
        $toSecond = $this->usage()->ofElement((int)$pinned->id, self::$secondSiteId);
        self::assertSame(self::siteCount(), $toSecond->total);
        self::assertSame(self::siteIds(), array_map(static fn(LinkUsage $usage): int => $usage->siteId, $toSecond->items));
        self::assertSame(0, $this->usage()->ofElement((int)$pinned->id, self::$primarySiteId)->total);

        // A disabled target is a target all the same.
        self::assertSame(ResolutionStatus::DISABLED->value, IndexRecord::findOne(['targetKey' => "entry?elementId=$disabled->id&siteId=" . self::$primarySiteId])?->targetStatus);

        // None of these is a URL link, whatever URL it leads to now.
        $resolved = (new Query())->select(['resolvedUrl'])->from(IndexRecord::TABLE)->where(['targetElementId' => array_map(static fn($target): int => (int)$target->id, array_values($targets))])->andWhere(['not', ['resolvedUrl' => null]])->column();
        self::assertNotEmpty($resolved);

        foreach ($resolved as $url) {
            self::assertSame(0, $this->usage()->ofUrl((string)$url)->total, (string)$url);
        }

        // A new slug moves where it leads, not what the link names.
        $renamed = $this->reload($pinned);
        $before = (string)IndexRecord::findOne(['targetKey' => "entry?elementId=$pinned->id&siteId=" . self::$secondSiteId])?->resolvedUrl;
        $secondVersion = Entry::find()->id($pinned->id)->siteId(self::$secondSiteId)->status(null)->one();
        self::assertNotNull($secondVersion);
        $secondVersion->slug = 'usage-pinned-moved';
        self::assertTrue(Craft::$app->getElements()->saveElement($secondVersion), Json::encode($secondVersion->getErrors()));
        $this->runPushed();
        self::assertSame(self::siteCount(), $this->usage()->ofElement((int)$renamed->id, self::$secondSiteId)->total);
        self::assertNotSame($before, IndexRecord::findOne(['targetKey' => "entry?elementId=$pinned->id&siteId=" . self::$secondSiteId])?->resolvedUrl);
    }

    public function testAnEntryLinkAndAUrlLinkToItsPageAreDifferentTargets(): void
    {
        $about = self::page('usage-target');
        $aboutUrl = $this->reload($about)->getUrl();
        self::assertNotNull($aboutUrl);
        $byEntry = self::page('usage-by-entry', [self::LINKS => [['type' => 'entry', 'data' => ['elementId' => $about->id]]]]);
        $byUrl = self::page('usage-by-url', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => $aboutUrl]]]]);
        $this->runPushed();

        // Both lead to the same page, and are still different targets.
        $entryTarget = IndexRecord::findOne(['targetKey' => "entry?elementId=$about->id&siteId=" . self::$primarySiteId]);
        $urlTarget = IndexRecord::findOne(['targetKey' => 'url?url=' . rawurlencode(CanonicalUrl::parse($aboutUrl)->toString())]);
        self::assertNotNull($entryTarget);
        self::assertNotNull($urlTarget);
        self::assertSame($entryTarget->resolvedUrl, $urlTarget->resolvedUrl);
        self::assertNotSame($entryTarget->id, $urlTarget->id);

        self::assertSame([(int)$byEntry->id], self::elementIds($this->usage()->ofElement((int)$about->id)));
        self::assertSame([(int)$byUrl->id], self::elementIds($this->usage()->ofUrl($aboutUrl)));
    }

    public function testAnAnchorIsFoundByThePageItPointsInto(): void
    {
        $page = self::page('usage-anchor', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => '#team']]]]);
        $this->runPushed();

        $byElement = $this->usage()->ofElement((int)$page->id, self::$primarySiteId);
        self::assertSame(1, $byElement->total);
        self::assertSame('url', $byElement->items[0]->linkType);
        self::assertSame((int)$page->id, $byElement->items[0]->elementId);

        $this->expectException(InvalidArgumentException::class);
        $this->usage()->ofUrl('#team');
    }

    // Finding a target by URL

    public function testUrlsAreFoundInCanonicalFormAndOnlyEquivalentSpellingsMatch(): void
    {
        $stored = [
            'https://example.com/canon/a', 'https://example.com/canon/a/', 'https://example.com/canon//a', 'https://example.com/canon/A',
            'https://example.com/canon/q?b=2&a=1', 'https://example.com/canon/q?', 'https://example.com/canon/q', 'https://example.com/canon/f#one',
            'https://example.com/canon/%2F', 'http://example.com/canon/a', 'https://example.com:8443/canon/a', '/canon/page#one',
        ];
        $entry = self::page('usage-canonical', [self::URL_ONLY => array_map(static fn(string $url): array => ['type' => 'url', 'data' => ['url' => $url]], $stored)]);
        $this->runPushed();
        $value = $this->reload($entry)->getFieldValue(self::URL_ONLY);
        self::assertInstanceOf(LinkCollection::class, $value);
        $primary = new UsageCriteria(siteId: self::$primarySiteId);
        $siteUrl = rtrim((string)Craft::$app->getSites()->getSiteById(self::$primarySiteId)?->getBaseUrl(), '/');

        // Each lookup, and which stored link (by position) it must find, if any.
        $lookups = [
            'HTTPS://EXAMPLE.COM/canon/a' => 0,
            'https://example.com:443/canon/a' => 0,
            'https://example.com/canon/./x/../a' => 0,
            'https://example.com/canon/%61' => 0,
            'https://example.com/canon/a/' => 1,
            'https://example.com/canon//a' => 2,
            'https://example.com/canon/A' => 3,
            'https://example.com/canon/q?b=2&a=1' => 4,
            'https://example.com/canon/q?a=1&b=2' => null,
            'https://example.com/canon/q?' => 5,
            'https://example.com/canon/q' => 6,
            'https://example.com/canon/f#one' => 7,
            'https://example.com/canon/f#two' => null,
            'https://example.com/canon/f' => null,
            'https://example.com/canon/%2f' => 8,
            'https://example.com/canon/' => null,
            'HTTP://example.com:80/canon/a' => 9,
            'https://example.com:08443/canon/a' => 10,
            '/canon/page#one' => 11,
            '/canon/page#two' => null,
            '/canon/page' => null,
            // The path on the primary site's host is not the path: a link means what it says.
            $siteUrl . '/canon/page#one' => null,
        ];

        foreach ($lookups as $lookup => $position) {
            $found = $this->usage()->ofUrl((string)$lookup, null, $primary);
            self::assertSame($position === null ? [] : [$value->links[$position]->uid], array_map(static fn(LinkUsage $usage): string => $usage->linkUid, $found->items), (string)$lookup);
        }

        // A path is one target per site; an absolute URL is one target for every site.
        $everywhere = $this->usage()->ofUrl('/canon/page#one');
        self::assertCount(self::siteCount(), array_unique(array_map(static fn(LinkUsage $usage): int => $usage->indexId, $everywhere->items)));
        self::assertSame([self::$secondSiteId], array_map(static fn(LinkUsage $usage): int => $usage->siteId, $this->usage()->ofUrl('/canon/page#one', self::$secondSiteId)->items));
        $absolute = $this->usage()->ofUrl('https://example.com/canon/a');
        self::assertSame(self::siteCount(), $absolute->total);
        self::assertCount(1, array_unique(array_map(static fn(LinkUsage $usage): int => $usage->indexId, $absolute->items)));
    }

    #[DataProvider('urlsNoLinkHolds')]
    public function testAUrlNoLinkCanHoldIsRefused(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->usage()->ofUrl($url);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function urlsNoLinkHolds(): array
    {
        return [
            'empty' => [''],
            'an anchor' => ['#team'],
            'javascript' => ['javascript:alert(1)'],
            'an email address' => ['mailto:hello@example.com'],
            'credentials' => ['https://user:secret@example.com/'],
            'a relative path' => ['usage-path'],
            'protocol-relative' => ['//example.com/x'],
            'an ambiguous host' => ['http://127.1/'],
            'a control character' => ["https://example.com/a\x01b"],
            'a C1 control character' => ["https://example.com/a\u{85}b"],
            'a backslash' => ['https://example.com\\evil.com/'],
            'malformed percent-encoding' => ['https://example.com/%zz'],
            'an invalid port' => ['https://example.com:99999/'],
        ];
    }

    public function testAHashHoldingAnotherTargetIsReportedNeverTakenForTheUrl(): void
    {
        $page = self::page('usage-collision');
        $this->runPushed();
        $identity = TargetIdentity::forUrl('url', CanonicalUrl::parse('https://example.com/usage-collides'), null);
        // Another target stored under the URL's hash, as only a SHA-256 collision could store it.
        $impostor = new IndexRecord(['targetKey' => 'url?url=' . rawurlencode('https://example.com/someone-else'), 'targetHash' => $identity->hash(), 'linkType' => 'url']);
        self::assertTrue($impostor->save(false));
        $usage = new UsageRecord(['indexId' => $impostor->id, 'elementId' => $page->id, 'siteId' => self::$primarySiteId, 'fieldId' => self::$instances[self::URL_ONLY]->id, 'layoutElementUid' => (string)self::$instances[self::URL_ONLY]->layoutElement?->uid, 'linkUid' => StringHelper::UUID(), 'sortOrder' => 1]);
        self::assertTrue($usage->save(false));

        $this->expectException(TargetHashCollisionException::class);
        $this->usage()->ofUrl('https://example.com/usage-collides');
    }

    // Content's lifecycle

    public function testDraftsAutosavesAndRevisionsAreNeverOccurrencesAndRevertingIsFollowed(): void
    {
        $entry = self::page('usage-draft', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-live']]]]);
        $block = $this->block($entry, [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-live-block']]]);
        $this->runPushed();
        $revisionId = Craft::$app->getRevisions()->createRevision($this->reload($entry), force: true);

        // A later version, saved: the revision holds the earlier links, and is not where they are used.
        $this->save($entry, [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-later']]]]);
        $this->runPushed();
        self::assertSame(0, $this->usage()->ofUrl('https://example.com/usage-live')->total);
        // Nor its copy of the nested entry.
        self::assertSame([(int)$block->id], self::elementIds($this->usage()->ofUrl('https://example.com/usage-live-block')));

        // A draft, a provisional draft (an autosave) and a nested entry of the draft: none counts.
        $draft = Craft::$app->getDrafts()->createDraft($this->reload($entry), 1);
        $draft->setFieldValue(self::URL_ONLY, self::entered([['type' => 'url', 'data' => ['url' => 'https://example.com/usage-draft-only']]]));
        self::assertTrue(Craft::$app->getElements()->saveElement($draft));
        $draftBlock = $this->block($draft, [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-draft-block']]]);
        $autosave = Craft::$app->getDrafts()->createDraft($this->reload($entry), 1, provisional: true);
        $autosave->setFieldValue(self::URL_ONLY, self::entered([['type' => 'url', 'data' => ['url' => 'https://example.com/usage-autosaved']]]));
        self::assertTrue(Craft::$app->getElements()->saveElement($autosave, false));
        $this->runPushed();
        $this->index()->rebuild();

        foreach (['usage-draft-only', 'usage-draft-block', 'usage-autosaved'] as $path) {
            self::assertSame(0, $this->usage()->ofUrl("https://example.com/$path")->total, $path);
        }

        // Drafts, autosaves and revisions are not sources at all. (An entry nested in a draft is not a
        // draft itself: it is read, and recorded as holding no links.)
        self::assertSame([], array_map('intval', (new Query())->select(['elementId'])->from(SourceRecord::TABLE)->where(['elementId' => [$draft->id, $autosave->id, $revisionId]])->column()));
        self::assertNotNull(SourceRecord::findOne(['elementId' => $draftBlock->id]));
        self::assertFalse(UsageRecord::find()->where(['elementId' => $draftBlock->id])->exists());

        // Applied, the draft's links are the entry's own.
        Craft::$app->getDrafts()->applyDraft($draft);
        $this->runPushed();
        self::assertSame([(int)$entry->id], self::elementIds($this->usage()->ofUrl('https://example.com/usage-draft-only')));
        self::assertSame(0, $this->usage()->ofUrl('https://example.com/usage-later')->total);

        // Reverted to the revision: its links are live again, and the draft's are gone.
        $revision = Entry::find()->revisions()->id($revisionId)->status(null)->one();
        self::assertNotNull($revision);
        Craft::$app->getRevisions()->revertToRevision($revision, 1);
        $this->runPushed();
        self::assertSame([(int)$entry->id], self::elementIds($this->usage()->ofUrl('https://example.com/usage-live')));
        self::assertSame(0, $this->usage()->ofUrl('https://example.com/usage-draft-only')->total);

        // Nothing counted is anything but canonical.
        $ids = array_map('intval', (new Query())->select(['elementId'])->distinct()->from(UsageRecord::TABLE)->column());
        self::assertSame(0, (int)(new Query())->from(Table::ELEMENTS)->where(['id' => $ids])->andWhere(['or', ['not', ['draftId' => null]], ['not', ['revisionId' => null]]])->count());
    }

    public function testDuplicatesAndPropagatedCopiesAreOccurrencesOfTheirOwn(): void
    {
        $entry = self::page('usage-original', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-duplicated']]]]);
        $this->runPushed();
        $copy = Craft::$app->getElements()->duplicateElement($this->reload($entry), ['slug' => 'usage-copy', 'title' => 'usage-copy']);
        $changed = Craft::$app->getElements()->duplicateElement($this->reload($entry), ['slug' => 'usage-changed-copy', 'title' => 'usage-changed-copy']);
        $this->save($changed, [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-elsewhere']]]]);
        $this->runPushed();

        $page = $this->usage()->ofUrl('https://example.com/usage-duplicated');
        // The original and the unchanged copy, each in every site: every one once.
        self::assertSame(2 * self::siteCount(), $page->total);
        self::assertCount($page->total, array_unique(array_map(static fn(LinkUsage $usage): string => $usage->key(), $page->items)));
        self::assertSame([(int)$entry->id, (int)$copy->id], self::elementIds($page));
        // Craft copies the value, link UIDs included: the same link, in other places.
        self::assertCount(1, array_unique(array_map(static fn(LinkUsage $usage): string => $usage->linkUid, $page->items)));
        self::assertSame(self::siteIds(), array_values(array_unique(array_map(static fn(LinkUsage $usage): int => $usage->siteId, $page->items))));
        self::assertSame([(int)$changed->id], self::elementIds($this->usage()->ofUrl('https://example.com/usage-elsewhere')));
    }

    public function testAnOccurrenceIsTheSameThroughEditsAndRebuildsAndANewLinkIsANewOne(): void
    {
        $entry = self::page('usage-identity', [self::LINKS => [
            ['type' => 'url', 'data' => ['url' => 'https://example.com/usage-before'], 'label' => 'One'],
            ['type' => 'url', 'data' => ['url' => 'https://example.com/usage-stays']],
        ]]);
        $this->runPushed();
        $primary = new UsageCriteria(siteId: self::$primarySiteId);
        $before = $this->usage()->ofUrl('https://example.com/usage-before', null, $primary)->items[0];
        $value = $this->reload($entry)->getFieldValue(self::LINKS);
        self::assertInstanceOf(LinkCollection::class, $value);

        // Moved after the other link, relabelled and pointed elsewhere.
        $edited = $this->reload($entry);
        $edited->setFieldValue(self::LINKS, self::entered([
            ['uid' => $value->links[1]->uid, 'type' => 'url', 'data' => ['url' => 'https://example.com/usage-stays']],
            ['uid' => $value->links[0]->uid, 'type' => 'url', 'data' => ['url' => 'https://example.com/usage-after'], 'label' => 'Renamed'],
        ]));
        self::assertTrue(Craft::$app->getElements()->saveElement($edited), Json::encode($edited->getErrors()));
        $this->runPushed();

        $after = $this->usage()->ofUrl('https://example.com/usage-after', null, $primary)->items[0];
        self::assertSame($before->key(), $after->key());
        self::assertSame($before->id, $after->id);
        self::assertSame([2, 'Renamed'], [$after->position, $after->label]);
        self::assertNotSame($before->indexId, $after->indexId);
        // The field is translated per site: only this site's link changed.
        self::assertSame(0, $this->usage()->ofUrl('https://example.com/usage-before', null, $primary)->total);
        self::assertSame(self::siteCount() - 1, $this->usage()->ofUrl('https://example.com/usage-before')->total);

        // Indexed again and again, by duplicate jobs and rebuilds: the same occurrences, kept, not made again.
        $rows = $this->usageRows();

        for ($i = 0; $i < 10; $i++) {
            (new UpdateIndex(['elementIds' => [(int)$entry->id, (int)$entry->id]]))->execute(Craft::$app->getQueue());
        }

        $this->index()->rebuild();
        $this->index()->rebuild();
        self::assertSame($rows, $this->usageRows());

        // Removed, then the same URL added back as a new link: a new occurrence.
        $this->save($entry, [self::LINKS => [['uid' => $value->links[1]->uid, 'type' => 'url', 'data' => ['url' => 'https://example.com/usage-stays']]]]);
        $this->save($entry, [self::LINKS => [
            ['uid' => $value->links[1]->uid, 'type' => 'url', 'data' => ['url' => 'https://example.com/usage-stays']],
            ['type' => 'url', 'data' => ['url' => 'https://example.com/usage-after'], 'label' => 'Renamed'],
        ]]);
        $this->runPushed();
        $again = $this->usage()->ofUrl('https://example.com/usage-after', null, $primary)->items;
        self::assertCount(1, $again);
        self::assertNotSame($after->key(), $again[0]->key());
        self::assertNotSame($after->linkUid, $again[0]->linkUid);
    }

    public function testASourceDeletedOrRestoredAndATargetDeletedAreFollowed(): void
    {
        $target = self::page('usage-gone-target');
        $source = self::page('usage-gone-source', [self::LINKS => [['type' => 'entry', 'data' => ['elementId' => $target->id]]]]);
        $this->runPushed();
        $key = "entry?elementId=$target->id&siteId=" . self::$primarySiteId;
        $indexId = $this->targetId($key);

        // Trashed, before its update runs: still listed as last indexed, said to be in the trash,
        // and nothing about it now is made up.
        self::assertTrue(Craft::$app->getElements()->deleteElement($source));
        $occurrence = $this->usage()->ofTarget($indexId)->items[0];
        self::assertSame(StaleUsage::SOURCE_TRASHED, $occurrence->stale);
        self::assertNull($occurrence->element);
        self::assertSame([], $occurrence->path);
        self::assertNull($occurrence->fieldHandle);
        self::assertSame(self::LINKS, $occurrence->recordedFieldHandle);

        // Once it runs, the occurrence is gone; restored, it is back as it was.
        $this->runPushed();
        self::assertSame(0, $this->usage()->ofElement((int)$target->id)->total);
        $trashed = Entry::find()->id($source->id)->trashed()->status(null)->one();
        self::assertNotNull($trashed);
        self::assertTrue(Craft::$app->getElements()->restoreElement($trashed));
        $this->runPushed();
        $restored = $this->usage()->ofElement((int)$target->id, self::$primarySiteId)->items;
        self::assertCount(1, $restored);
        self::assertNull($restored[0]->stale);

        // The target deleted: the links that name it are still found by it, and it is said to be gone.
        self::assertTrue(Craft::$app->getElements()->deleteElement($target));
        $this->runPushed();
        self::assertSame(self::siteCount(), $this->usage()->ofElement((int)$target->id)->total);
        self::assertSame(ResolutionStatus::MISSING->value, $this->index()->inventoryItem($this->targetId($key))?->targetStatus);
    }

    public function testASourceTakenOutOfOneSiteOrThatCannotBeLoadedSaysWhy(): void
    {
        $section = new Section([
            'name' => 'Smart Links usage chosen sites',
            'handle' => 'smartLinksUsageChosenSites',
            'type' => Section::TYPE_CHANNEL,
            'propagationMethod' => PropagationMethod::Custom,
            'siteSettings' => array_map(static fn(int $siteId): Section_SiteSettings => new Section_SiteSettings(['siteId' => $siteId, 'hasUrls' => true, 'uriFormat' => 'smart-links-usage/{slug}', 'template' => '_smart-links-test']), [self::$primarySiteId => self::$primarySiteId, self::$secondSiteId => self::$secondSiteId]),
        ]);
        $section->setEntryTypes([self::$entryType]);

        try {
            self::assertTrue(Craft::$app->getEntries()->saveSection($section), Json::encode($section->getErrors()));
            $entry = new Entry(['sectionId' => $section->id, 'typeId' => self::$entryType->id, 'siteId' => self::$primarySiteId, 'title' => 'usage-sites', 'slug' => 'usage-sites']);
            $entry->setEnabledForSite([self::$primarySiteId => true, self::$secondSiteId => true]);
            $entry->setFieldValue(self::URL_ONLY, self::entered([['type' => 'url', 'data' => ['url' => 'https://example.com/usage-sites']]]));
            $entry->setFieldValue(self::DEFAULTS, new LinkCollection());
            self::assertTrue(Craft::$app->getElements()->saveElement($entry), Json::encode($entry->getErrors()));
            $this->runPushed();
            $states = fn(): array => array_map(static fn(LinkUsage $usage): array => [$usage->siteId, $usage->stale], $this->usage()->ofUrl('https://example.com/usage-sites')->items);
            self::assertSame([[self::$primarySiteId, null], [self::$secondSiteId, null]], $states());

            // Taken out of the second site: said so there until its update runs, then gone there only.
            $second = Entry::find()->id($entry->id)->siteId(self::$secondSiteId)->status(null)->one();
            self::assertNotNull($second);
            Craft::$app->getElements()->deleteElementForSite($second);
            self::assertSame([[self::$primarySiteId, null], [self::$secondSiteId, StaleUsage::SOURCE_NOT_IN_SITE]], $states());
            $this->runPushed();
            self::assertSame([[self::$primarySiteId, null]], $states());

            // Put back in the second site: there again.
            $back = $this->reload($entry);
            $back->setEnabledForSite([self::$primarySiteId => true, self::$secondSiteId => true]);
            self::assertTrue(Craft::$app->getElements()->saveElement($back), Json::encode($back->getErrors()));
            $this->runPushed();
            self::assertSame([[self::$primarySiteId, null], [self::$secondSiteId, null]], $states());

            // Its row naming a type that is not there (its plugin removed): said so, not passed off as deleted.
            Db::update(Table::ELEMENTS, ['type' => 'Gone\\Plugin\\Element'], ['id' => $entry->id]);
            $unavailable = $this->usage()->ofUrl('https://example.com/usage-sites')->items[0];
            self::assertSame(StaleUsage::SOURCE_TYPE_UNAVAILABLE, $unavailable->stale);
            self::assertSame(['Gone\\Plugin\\Element', null], [$unavailable->elementType, $unavailable->elementTypeName]);

            // A type that is there, which can't load it: said so.
            Db::update(Table::ELEMENTS, ['type' => Category::class], ['id' => $entry->id]);
            Craft::$app->getElements()->invalidateAllCaches();
            self::assertSame(StaleUsage::SOURCE_UNLOADABLE, $this->usage()->ofUrl('https://example.com/usage-sites')->items[0]->stale);
        } finally {
            $this->resetCraftServices();
        }
    }

    public function testASiteDeletedLeavesEveryOtherSitesOccurrencesAndComesBackRestored(): void
    {
        self::page('usage-site-deleted', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-site-deleted']]]]);
        $this->runPushed();
        $sites = fn(): array => array_map(static fn(LinkUsage $usage): array => [$usage->siteId, $usage->stale, $usage->siteName !== null], $this->usage()->ofUrl('https://example.com/usage-site-deleted')->items);
        $others = array_values(array_diff(self::siteIds(), [self::$secondSiteId]));

        try {
            self::assertTrue(Craft::$app->getSites()->deleteSite(Craft::$app->getSites()->getSiteById(self::$secondSiteId)));
            $this->resetCraftServices();
            // Until the rebuild: shown, said to be of a deleted site, and named as nothing else.
            self::assertContains([self::$secondSiteId, StaleUsage::SITE_DELETED, false], $sites());
            $this->runPushed();
            self::assertSame(array_map(static fn(int $siteId): array => [$siteId, null, true], $others), $sites());

            self::assertTrue(Craft::$app->getSites()->restoreSiteById(self::$secondSiteId));
            Craft::$app->getSites()->refreshSites();
            $restored = Craft::$app->getSites()->getSiteById(self::$secondSiteId);
            self::assertNotNull($restored);
            self::assertTrue(Craft::$app->getSites()->saveSite($restored), Json::encode($restored->getErrors()));
            $this->resetCraftServices();
            $this->runPushed();
            self::assertSame(array_map(static fn(int $siteId): array => [$siteId, null, true], self::siteIds()), $sites());
        } finally {
            $this->resetCraftServices();
        }
    }

    // Fields

    public function testARenamedFieldIsShownByItsNewNameWithoutIndexingAgain(): void
    {
        self::page('usage-renamed', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-renamed']]]]);
        $this->runPushed();
        $primary = new UsageCriteria(siteId: self::$primarySiteId);
        $before = $this->usage()->ofUrl('https://example.com/usage-renamed', null, $primary)->items[0];
        $fields = Craft::$app->getFields();

        try {
            $field = $fields->getFieldByHandle(self::URL_ONLY);
            self::assertInstanceOf(SmartLinkField::class, $field);
            $field->name = 'Renamed links';
            $field->handle = 'smartLinksUsageRenamed';
            self::assertTrue($fields->saveField($field), Json::encode($field->getErrors()));
            $this->resetCraftServices();

            // Content is kept by layout element, not handle: nothing to index again.
            self::assertSame([], $this->runPushed());
            $after = $this->usage()->ofUrl('https://example.com/usage-renamed', null, $primary)->items[0];
            self::assertSame(['smartLinksUsageRenamed', 'Renamed links'], [$after->fieldHandle, $after->fieldName]);
            self::assertSame([$before->id, $before->key()], [$after->id, $after->key()]);
            self::assertNull($after->stale);
        } finally {
            $this->resetCraftServices();
        }
    }

    public function testAFieldMovedToANewPlaceLeavesItsOldPlaceSaidToBeGone(): void
    {
        $entry = self::page('usage-moved', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-moved']]]]);
        $this->runPushed();
        $old = (string)self::$instances[self::URL_ONLY]->layoutElement?->uid;

        try {
            // The field taken out and placed again: a new place, which holds no content yet.
            $this->saveLayout(static function(array $elements) use ($old): array {
                $moved = array_values(array_filter($elements, static fn(array $element): bool => ($element['uid'] ?? null) === $old))[0];
                $kept = array_values(array_filter($elements, static fn(array $element): bool => ($element['uid'] ?? null) !== $old));

                return [...$kept, ['uid' => StringHelper::UUID()] + $moved];
            });
            $this->resetCraftServices();

            $occurrence = $this->usage()->ofUrl('https://example.com/usage-moved', null, new UsageCriteria(siteId: self::$primarySiteId))->items[0];
            self::assertSame(StaleUsage::FIELD_REMOVED, $occurrence->stale);
            self::assertSame($old, $occurrence->layoutElementUid);
            // The new place is not taken for the old one.
            self::assertNull($occurrence->fieldHandle);
            self::assertSame(self::URL_ONLY, $occurrence->recordedFieldHandle);

            self::assertContains(RebuildIndex::class, array_map('get_class', $this->runPushed()));
            self::assertSame(0, $this->usage()->ofUrl('https://example.com/usage-moved')->total);
            self::assertNotNull(SourceRecord::findOne(['elementId' => $entry->id]));
        } finally {
            $this->resetCraftServices();
        }
    }

    public function testAFieldDeletedOrMadeAnotherTypeIsSaidToBeSoUntilTheRebuildTakesItsOccurrences(): void
    {
        self::page('usage-field-gone', [
            self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-field-gone']]],
            self::SINGLE => [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-field-plain']]],
        ]);
        $this->runPushed();
        $fields = Craft::$app->getFields();

        try {
            // Turned into a plain text field: no Smart Links field is shown in its place.
            $single = $fields->getFieldByHandle(self::SINGLE);
            self::assertNotNull($single);
            $plain = $fields->createField(['type' => PlainText::class, 'id' => $single->id, 'uid' => $single->uid, 'name' => 'Plain now', 'handle' => $single->handle]);
            self::assertTrue($fields->saveField($plain), Json::encode($plain->getErrors()));
            $this->resetCraftServices();
            $changed = $this->usage()->ofUrl('https://example.com/usage-field-plain')->items[0];
            self::assertSame(StaleUsage::FIELD_CHANGED_TYPE, $changed->stale);
            self::assertSame([null, null, 'Plain now'], [$changed->fieldHandle, $changed->fieldName, $changed->recordedFieldName]);

            $field = Craft::$app->getFields()->getFieldByHandle(self::URL_ONLY);
            self::assertInstanceOf(SmartLinkField::class, $field);
            self::assertTrue(Craft::$app->getFields()->deleteField($field));
            $this->resetCraftServices();
            $deleted = $this->usage()->ofUrl('https://example.com/usage-field-gone')->items[0];
            self::assertSame(StaleUsage::FIELD_DELETED, $deleted->stale);
            self::assertSame([(int)$field->id, null, null, null], [$deleted->fieldId, $deleted->fieldHandle, $deleted->fieldName, $deleted->recordedFieldName]);
            self::assertNotNull($deleted->element);

            self::assertContains(RebuildIndex::class, array_map('get_class', $this->runPushed()));
            self::assertSame(0, $this->usage()->ofUrl('https://example.com/usage-field-gone')->total);
            self::assertSame(0, $this->usage()->ofUrl('https://example.com/usage-field-plain')->total);
        } finally {
            $this->resetCraftServices();
        }
    }

    // The index catching up

    public function testAnOccurrenceTheIndexHasNotCaughtUpWithSaysWhy(): void
    {
        $entry = self::page('usage-stale', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-stale']]]]);
        $this->runPushed();
        $states = fn(): array => array_map(static fn(LinkUsage $usage): ?StaleUsage => $usage->stale, $this->usage()->ofUrl('https://example.com/usage-stale')->items);
        self::assertSame(array_fill(0, self::siteCount(), null), $states());

        // Saved since it was read (as a save does, by its date), its update not run yet.
        Db::update(Table::ELEMENTS, ['dateUpdated' => Db::prepareDateForDb(new DateTime('+1 minute'))], ['id' => $entry->id]);
        self::assertSame(array_fill(0, self::siteCount(), StaleUsage::SAVED_SINCE), $states());
        $this->index()->updateElements([(int)$entry->id]);
        self::assertSame(array_fill(0, self::siteCount(), null), $states());

        // Never read: the same.
        Db::delete(SourceRecord::TABLE, ['elementId' => $entry->id, 'siteId' => self::$primarySiteId]);
        self::assertSame(StaleUsage::SAVED_SINCE, $this->usage()->ofUrl('https://example.com/usage-stale', null, new UsageCriteria(siteId: self::$primarySiteId))->items[0]->stale);
        $this->index()->updateElements([(int)$entry->id]);

        // A value that can't be read in one site keeps its links there, said to be as last read.
        $uid = (string)self::$instances[self::URL_ONLY]->layoutElement?->uid;
        $content = Json::decode((string)(new Query())->select('content')->from(Table::ELEMENTS_SITES)->where(['elementId' => $entry->id, 'siteId' => self::$primarySiteId])->scalar());
        $content[$uid] = ['version' => 9, 'links' => []];
        Db::update(Table::ELEMENTS_SITES, ['content' => $content], ['elementId' => $entry->id, 'siteId' => self::$primarySiteId]);
        Craft::$app->getElements()->invalidateCachesForElement($entry);
        $this->index()->updateElements([(int)$entry->id]);

        $page = $this->usage()->ofUrl('https://example.com/usage-stale');
        self::assertSame(self::siteCount(), $page->total);

        foreach ($page->items as $occurrence) {
            self::assertSame($occurrence->siteId === self::$primarySiteId ? StaleUsage::UNREADABLE : null, $occurrence->stale);
        }
    }

    public function testAPassThatFailsAfterWritingUsageLeavesEachSiteAsItWasAndARetryConverges(): void
    {
        $entry = self::page('usage-failing', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-failing-old']]]]);
        $this->runPushed();
        // Craft dates saves to the second: a later save, so its version differs.
        sleep(1);
        $this->save($entry, [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-failing-new']]]]);
        $version = (string)(new Query())->select(['dateUpdated'])->from(Table::ELEMENTS)->where(['id' => $entry->id])->scalar();
        // A failure once usage rows are written, before the source row that vouches for them is:
        // the second site's pass fails, after the first site's has finished.
        $inserts = 0;
        $fail = static function() use (&$inserts): void {
            if (++$inserts === 2) {
                throw new \RuntimeException('Injected failure after a usage row was written.');
            }
        };
        Event::on(UsageRecord::class, UsageRecord::EVENT_AFTER_INSERT, $fail);

        try {
            $this->runPushed();
            self::fail('The injected failure did not happen.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Injected failure after a usage row was written.', $exception->getMessage());
        } finally {
            Event::off(UsageRecord::class, UsageRecord::EVENT_AFTER_INSERT, $fail);
        }

        // Each site is all old or all new, and its source row says which: never new rows vouched
        // for as old, or old rows as new.
        $sites = [];

        foreach (self::siteIds() as $siteId) {
            $usages = (new Query())->select(['i.targetKey'])->from(['u' => UsageRecord::TABLE])->innerJoin(['i' => IndexRecord::TABLE], '[[i.id]] = [[u.indexId]]')->where(['u.elementId' => $entry->id, 'u.siteId' => $siteId])->column();
            $read = SourceRecord::findOne(['elementId' => $entry->id, 'siteId' => $siteId])?->elementDateUpdated === $version;
            self::assertSame([$read ? 'url?url=' . rawurlencode('https://example.com/usage-failing-new') : 'url?url=' . rawurlencode('https://example.com/usage-failing-old')], $usages, "site $siteId");
            $sites[] = $read;
        }

        // The first site read finished; every other did not.
        self::assertSame(1, count(array_filter($sites)));
        self::assertGreaterThan(0, $this->index()->status()->unindexedSources);

        // The lock was let go, and the job run again finishes what it started.
        (new UpdateIndex(['elementIds' => [(int)$entry->id]]))->execute(Craft::$app->getQueue());
        self::assertSame(self::siteCount(), $this->usage()->ofUrl('https://example.com/usage-failing-new')->total);
        self::assertSame(0, $this->usage()->ofUrl('https://example.com/usage-failing-old')->total);
        self::assertSame(array_fill(0, self::siteCount(), null), array_map(static fn(LinkUsage $usage): ?StaleUsage => $usage->stale, $this->usage()->ofUrl('https://example.com/usage-failing-new')->items));
    }

    public function testARebuildBatchThatFailsIsRetriedAndACleanupStoppedPartWayIsFinishedByTheNext(): void
    {
        $entry = self::page('usage-rebuild-failing', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-rebuild-failing']]]]);
        $this->runPushed();
        Db::delete(UsageRecord::TABLE, ['elementId' => $entry->id]);
        Db::delete(SourceRecord::TABLE, ['elementId' => $entry->id]);
        $fail = static function(): void {
            throw new \RuntimeException('Injected failure while a batch was being written.');
        };
        $job = new RebuildIndex(['startedAt' => Db::prepareDateForDb(new DateTime()), 'afterElementId' => (int)$entry->id - 1, 'batchSize' => 1]);
        Event::on(UsageRecord::class, UsageRecord::EVENT_BEFORE_INSERT, $fail);

        try {
            $job->execute(Craft::$app->getQueue());
            self::fail('The injected failure did not happen.');
        } catch (\RuntimeException) {
            // Nothing of the batch written, and no next batch queued.
            self::assertSame(0, $this->usage()->ofUrl('https://example.com/usage-rebuild-failing')->total);
            self::assertSame([], $this->pushed);
        } finally {
            Event::off(UsageRecord::class, UsageRecord::EVENT_BEFORE_INSERT, $fail);
        }

        // Retried, as the queue retries a failed job: the batch is written, and the rebuild goes on.
        $job->execute(Craft::$app->getQueue());
        self::assertSame(self::siteCount(), $this->usage()->ofUrl('https://example.com/usage-rebuild-failing')->total);

        // A cleanup stopped after its first step (usage of what is no longer a source removed, the
        // source rows not yet): the next finish completes it, and nothing current is touched.
        $orphan = self::page('usage-cleanup', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-cleanup']]]]);
        $this->runPushed();
        Db::update(Table::ELEMENTS, ['dateDeleted' => Db::prepareDateForDb(new DateTime())], ['id' => $orphan->id]);
        Db::delete(UsageRecord::TABLE, ['elementId' => $orphan->id]);
        self::assertNotNull(SourceRecord::findOne(['elementId' => $orphan->id]));
        $before = $this->usage()->ofUrl('https://example.com/usage-rebuild-failing')->items;
        $this->index()->finishRebuild(new \Tahadudhiya\SmartLinks\models\IndexingResult());
        self::assertNull(SourceRecord::findOne(['elementId' => $orphan->id]));
        self::assertNull(IndexRecord::findOne(['targetKey' => 'url?url=' . rawurlencode('https://example.com/usage-cleanup')]));
        self::assertEquals($before, $this->usage()->ofUrl('https://example.com/usage-rebuild-failing')->items);
    }

    // Pages and filters

    #[DataProvider('occurrenceCounts')]
    public function testEveryOccurrenceIsOnExactlyOnePageInOrder(int $count): void
    {
        $url = "https://example.com/usage-pages-$count";
        $entry = self::page("usage-pages-$count", [self::URL_ONLY => array_fill(0, $count, ['type' => 'url', 'data' => ['url' => $url]])]);
        $this->runPushed();
        $pages = (int)max(1, ceil($count / UsageCriteria::PAGE_SIZE));
        $seen = [];

        for ($number = 1; $number <= $pages + 1; $number++) {
            $page = $this->usage()->ofUrl($url, null, new UsageCriteria(siteId: self::$primarySiteId, page: $number));
            self::assertSame($count, $page->total);
            self::assertSame($pages, $page->pageCount());
            $expected = $number <= $pages ? min(UsageCriteria::PAGE_SIZE, $count - ($number - 1) * UsageCriteria::PAGE_SIZE) : 0;
            self::assertCount($expected, $page->items, "page $number");
            self::assertSame($expected === 0 ? [0, 0] : [($number - 1) * UsageCriteria::PAGE_SIZE + 1, ($number - 1) * UsageCriteria::PAGE_SIZE + $expected], [$page->first(), $page->last()]);

            foreach ($page->items as $occurrence) {
                $seen[] = [$occurrence->position, $occurrence->key()];
            }
        }

        // Every occurrence once, in the order of the field.
        self::assertSame($count === 0 ? [] : range(1, $count), array_column($seen, 0));
        self::assertCount($count, array_unique(array_column($seen, 1)));
        // A filter nothing matches: an empty page, with its filter.
        self::assertSame(0, $this->usage()->ofUrl($url, null, new UsageCriteria(fieldId: (int)self::$instances[self::LINKS]->id))->total);
        self::assertSame($count === 0 ? [] : [(int)$entry->id], self::elementIds($this->usage()->ofUrl($url)));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function occurrenceCounts(): array
    {
        $cases = [];

        foreach ([0, 1, 49, 50, 51, 100, 101] as $count) {
            $cases["$count occurrences"] = [$count];
        }

        return $cases;
    }

    public function testPageLinksKeepTheFiltersAndAPagePastTheEndSaysSo(): void
    {
        self::page('usage-page-links', [
            self::URL_ONLY => array_fill(0, 2 * UsageCriteria::PAGE_SIZE + 1, ['type' => 'url', 'data' => ['url' => 'https://example.com/usage-page-links']]),
        ]);
        $this->runPushed();
        $indexId = $this->targetId('url?url=' . rawurlencode('https://example.com/usage-page-links'));
        $viewer = $this->user(['accessCp', 'accessPlugin-smart-links', SmartLinks::PERMISSION_VIEW_INVENTORY, SmartLinks::PERMISSION_VIEW_USAGE]);
        $site = (string)Craft::$app->getSites()->getSiteById(self::$secondSiteId)?->handle;
        $filters = ['sourceSite' => $site, 'source' => self::URL_ONLY];

        [$html] = $this->rendered($this->runUsageAction($indexId, $filters + ['page' => '2'], $viewer));
        self::assertSame(1, preg_match('#class="page-link prev-page" rel="prev" href="([^"]+)"#', $html, $prev));
        self::assertSame(1, preg_match('#class="page-link next-page" rel="next" href="([^"]+)"#', $html, $next));
        parse_str((string)parse_url(html_entity_decode($prev[1]), PHP_URL_QUERY), $prevQuery);
        parse_str((string)parse_url(html_entity_decode($next[1]), PHP_URL_QUERY), $nextQuery);
        self::assertSame($filters, array_intersect_key($prevQuery, $filters + ['page' => true]));
        self::assertSame($filters + ['page' => '3'], array_intersect_key($nextQuery, $filters + ['page' => true]));
        self::assertStringContainsString('51–100 of 101 occurrences', $html);

        [$html] = $this->rendered($this->runUsageAction($indexId, $filters + ['page' => '9'], $viewer));
        self::assertStringContainsString('id="smartlinks-usage-past-end"', $html);
        self::assertStringContainsString('There is no page 9: there are 101 occurrences on 3 pages.', $html);
        self::assertStringNotContainsString('No links to this target', $html);
    }

    public function testCriteriaAreReadStrictly(): void
    {
        $site = (string)Craft::$app->getSites()->getSiteById(self::$secondSiteId)?->handle;
        $criteria = UsageCriteria::fromParams(['sourceSite' => $site, 'source' => self::LINKS, 'page' => '3']);

        self::assertSame([self::$secondSiteId, (int)self::$instances[self::LINKS]->id, 3], [$criteria->siteId, $criteria->fieldId, $criteria->page]);
        self::assertSame(['sourceSite' => $site, 'source' => self::LINKS, 'page' => 3], $criteria->params());
        self::assertSame(['sourceSite' => $site, 'source' => self::LINKS], $criteria->params(['page' => null]));
        self::assertTrue($criteria->isFiltered());
        self::assertSame([], UsageCriteria::fromParams([])->params());
        // Craft's own `site` is the control panel's, not a filter.
        self::assertNull(UsageCriteria::fromParams(['site' => $site])->siteId);
        self::assertSame(999999999, UsageCriteria::fromParams(['page' => '999999999'])->page);
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('malformedCriteria')]
    public function testMalformedCriteriaAreRefused(array $params): void
    {
        $this->expectException(InvalidArgumentException::class);
        UsageCriteria::fromParams($params);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedCriteria(): array
    {
        return [
            'page 0' => [['page' => '0']],
            'a negative page' => [['page' => '-1']],
            'a decimal page' => [['page' => '1.5']],
            'a page too large' => [['page' => '1000000000']],
            'a page with a sign' => [['page' => '+2']],
            'a page with a space' => [['page' => ' 2']],
            'a word for a page' => [['page' => 'two']],
            'a list for a page' => [['page' => ['1']]],
            'an unknown site' => [['sourceSite' => 'nowhere']],
            'a list for a site' => [['sourceSite' => ['a']]],
            'an unknown field' => [['source' => 'nothing']],
            'a list for a field' => [['source' => ['a']]],
            'a field of another type' => [['source' => self::BLOCKS]],
        ];
    }

    public function testAFieldDeletedSinceIsNoLongerAFilter(): void
    {
        try {
            $field = Craft::$app->getFields()->getFieldByHandle(self::SINGLE);
            self::assertInstanceOf(SmartLinkField::class, $field);
            self::assertTrue(Craft::$app->getFields()->deleteField($field));
            $this->resetCraftServices();

            $this->expectException(InvalidArgumentException::class);
            UsageCriteria::fromParams(['source' => self::SINGLE]);
        } finally {
            $this->resetCraftServices();
        }
    }

    // Performance

    public function testAPageIsReadInABoundedNumberOfQueriesWhateverHowManySourcesItHas(): void
    {
        $counts = [];

        foreach ([10, 50] as $sources) {
            $url = "https://example.com/usage-queries-$sources";
            $link = [['type' => 'url', 'data' => ['url' => $url]]];

            for ($i = 0; $i < $sources / 2; $i++) {
                $page = self::page("usage-queries-$sources-$i", [self::URL_ONLY => $link]);
                $this->block($page, $link);
            }

            $this->runPushed();
            Craft::$app->getUser()->setIdentity(self::author());
            Craft::$app->getElements()->invalidateAllCaches();
            $memory = memory_get_usage();
            [$queries, $page] = $this->queriesDuring(fn(): UsagePage => $this->usage()->ofUrl($url, null, new UsageCriteria(siteId: self::$primarySiteId)));
            self::assertCount($sources, $page->items);
            self::assertSame(['Block'], array_values(array_unique(array_map(static fn(LinkUsage $usage): string => $usage->path[count($usage->path) - 1]['label'], array_filter($page->items, static fn(LinkUsage $usage): bool => count($usage->path) === 2)))));
            $counts[$sources] = ['queries' => $queries, 'memory' => memory_get_usage() - $memory];
        }

        fwrite(STDERR, sprintf("\nUsage page queries: 10 sources %d, 50 sources %d; memory %d KB, %d KB\n", $counts[10]['queries'], $counts[50]['queries'], intdiv($counts[10]['memory'], 1024), intdiv($counts[50]['memory'], 1024)));
        // Five times the sources, half of them nested: the same queries, none per occurrence or owner.
        self::assertSame($counts[10]['queries'], $counts[50]['queries']);
        self::assertLessThanOrEqual(12, $counts[50]['queries']);
    }

    // Who may see it

    public function testTheUsagePageNeedsControlPanelAccessAndBothPermissions(): void
    {
        self::page('usage-page', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-page']]]]);
        $this->runPushed();
        $indexId = $this->targetId('url?url=' . rawurlencode('https://example.com/usage-page'));
        $cp = ['accessCp', 'accessPlugin-smart-links'];

        foreach ([
            'no control panel access' => [SmartLinks::PERMISSION_VIEW_INVENTORY, SmartLinks::PERMISSION_VIEW_USAGE],
            'the section only' => $cp,
            'the inventory only' => [...$cp, SmartLinks::PERMISSION_VIEW_INVENTORY],
            'usage only' => [...$cp, SmartLinks::PERMISSION_VIEW_USAGE],
            'rebuilding only' => [...$cp, SmartLinks::PERMISSION_REBUILD_INDEX],
            'inventory and rebuilding' => [...$cp, SmartLinks::PERMISSION_VIEW_INVENTORY, SmartLinks::PERMISSION_REBUILD_INDEX],
        ] as $who => $permissions) {
            try {
                $this->runUsageAction($indexId, [], $this->user($permissions));
                self::fail("Allowed with $who.");
            } catch (ForbiddenHttpException) {
                self::addToAssertionCount(1);
            }
        }

        [, $variables] = $this->rendered($this->runUsageAction($indexId, [], $this->user([...$cp, SmartLinks::PERMISSION_VIEW_INVENTORY, SmartLinks::PERMISSION_VIEW_USAGE])));
        self::assertSame(self::siteCount(), $variables['usage']->total);
    }

    public function testSourcesLinkToTheirEditPagesOnlyForThoseWhoMayViewThem(): void
    {
        $viewable = self::page('usage-viewable', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-viewable']]]]);
        $private = new Entry(['sectionId' => self::$primaryOnly->id, 'typeId' => self::$entryType->id, 'siteId' => self::$primarySiteId, 'title' => 'usage-private', 'slug' => 'usage-private']);
        $private->setFieldValue(self::URL_ONLY, self::entered([['type' => 'url', 'data' => ['url' => 'https://example.com/usage-viewable']]]));
        $private->setFieldValue(self::DEFAULTS, new LinkCollection());
        self::assertTrue(Craft::$app->getElements()->saveElement($private), Json::encode($private->getErrors()));
        $this->runPushed();
        $indexId = $this->targetId('url?url=' . rawurlencode('https://example.com/usage-viewable'));
        // The test section's author, who has no rights to the other section.
        $viewer = $this->user(['accessPlugin-smart-links', SmartLinks::PERMISSION_VIEW_INVENTORY, SmartLinks::PERMISSION_VIEW_USAGE, ...self::author()->grantedPermissions]);

        [$html, $variables] = $this->rendered($this->runUsageAction($indexId, ['sourceSite' => Craft::$app->getSites()->getPrimarySite()->handle], $viewer));
        $byElement = [];

        foreach ($variables['usage']->items as $occurrence) {
            $byElement[$occurrence->elementId] = $occurrence->editUrl();
        }

        self::assertSame([(int)$viewable->id => $viewable->getCpEditUrl(), (int)$private->id => null], $byElement);
        self::assertStringContainsString(htmlspecialchars((string)$viewable->getCpEditUrl()), $html);
        self::assertStringNotContainsString(htmlspecialchars((string)$private->getCpEditUrl()), $html);
        // Named, as the inventory names it, for people who may see all content.
        self::assertStringContainsString('usage-private', $html);
    }

    public function testATargetThatIsNotThereIsNotFoundAndAMalformedRequestIsRefused(): void
    {
        $viewer = $this->user(['accessCp', 'accessPlugin-smart-links', SmartLinks::PERMISSION_VIEW_INVENTORY, SmartLinks::PERMISSION_VIEW_USAGE]);
        $notFound = function(int|string $indexId, string $message) use ($viewer): void {
            try {
                $this->runUsageAction($indexId, [], $viewer);
                self::fail("Target $indexId was found.");
            } catch (NotFoundHttpException $exception) {
                self::assertSame($message, $exception->getMessage(), (string)$indexId);
            }
        };

        $notFound((int)IndexRecord::find()->max('id') + 1000, 'There is no such link target.');
        $notFound(-1, 'There is no such link target.');
        $notFound(0, 'There is no such link target.');

        // In the index, used by nothing (until the next pruning removes it).
        $unused = $this->index()->targetId(TargetIdentity::forUrl('url', CanonicalUrl::parse('https://example.com/usage-unused'), null));
        $notFound($unused, 'No link uses this target any more.');
        self::assertSame(0, $this->usage()->ofTarget($unused)->total);

        foreach (['abc', '1.5', ''] as $malformed) {
            try {
                $this->runUsageAction($malformed, [], $viewer);
                self::fail("Target “{$malformed}” was accepted.");
            } catch (BadRequestHttpException) {
                self::addToAssertionCount(1);
            }
        }

        foreach ([['page' => '0'], ['sourceSite' => 'nowhere'], ['source' => self::BLOCKS], ['page' => ['2']]] as $params) {
            try {
                $this->runUsageAction($unused, $params, $viewer);
                self::fail('Accepted ' . Json::encode($params));
            } catch (BadRequestHttpException) {
                self::addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->usage()->ofTarget((int)IndexRecord::find()->max('id') + 1000);
    }

    public function testContentIsShownAsTextWhereverItAppears(): void
    {
        $markup = '<img src=x onerror=alert(1)>';
        $page = self::savedEntry('usage-markup', [self::DEFAULTS => new LinkCollection()]);
        $page->title = "$markup title";
        self::assertTrue(Craft::$app->getElements()->saveElement($page));
        $this->block($page, [['type' => 'url', 'data' => ['url' => 'https://example.com/<script>alert(1)</script>'], 'label' => "$markup label"]]);
        $this->runPushed();
        $fields = Craft::$app->getFields();

        try {
            $field = $fields->getFieldByHandle(self::URL_ONLY);
            self::assertInstanceOf(SmartLinkField::class, $field);
            $field->name = "$markup field";
            self::assertTrue($fields->saveField($field), Json::encode($field->getErrors()));
            $site = Craft::$app->getSites()->getSiteById(self::$secondSiteId);
            self::assertNotNull($site);
            $site->setName("$markup site");
            self::assertTrue(Craft::$app->getSites()->saveSite($site), Json::encode($site->getErrors()));
            $this->resetCraftServices();

            $indexId = $this->targetId('url?url=' . rawurlencode('https://example.com/%3Cscript%3Ealert(1)%3C/script%3E'));
            $viewer = $this->user(['accessCp', 'accessPlugin-smart-links', SmartLinks::PERMISSION_VIEW_INVENTORY, SmartLinks::PERMISSION_VIEW_USAGE, ...self::author()->grantedPermissions]);
            [$html] = $this->rendered($this->runUsageAction($indexId, [], $viewer));

            self::assertStringNotContainsString('<img', $html);
            self::assertStringNotContainsString('<script', $html);
            foreach (['title', 'label', 'field', 'site'] as $what) {
                self::assertStringContainsString(htmlspecialchars("$markup $what", ENT_QUOTES), $html, $what);
            }
        } finally {
            $this->resetCraftServices();
        }
    }

    public function testTheInventoryLeadsToWhereATargetIsUsedOnlyForThoseWhoMaySeeIt(): void
    {
        self::page('usage-inventory', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-inventory']]]]);
        $this->runPushed();
        $indexId = $this->targetId('url?url=' . rawurlencode('https://example.com/usage-inventory'));
        $base = ['accessCp', 'accessPlugin-smart-links', SmartLinks::PERMISSION_VIEW_INVENTORY];
        $link = '#/smart-links/links/' . $indexId . '[?"]#';

        [$html] = $this->renderedInventory($this->runInventoryAction(['search' => 'usage-inventory'], $this->user($base)));
        self::assertDoesNotMatchRegularExpression($link, $html);

        [$html] = $this->renderedInventory($this->runInventoryAction(['search' => 'usage-inventory'], $this->user([...$base, SmartLinks::PERMISSION_VIEW_USAGE])));
        self::assertMatchesRegularExpression($link, $html);
    }

    public function testLinksBetweenPagesKeepEverySiteForSomeoneWhoEditsASite(): void
    {
        self::page('usage-every-site', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://example.com/usage-every-site']]]]);
        $this->runPushed();
        // Craft adds the site a control panel page is showing to every control panel URL it builds,
        // for anyone who may edit a site: no filter may read that as its own.
        $editor = $this->user(['accessCp', 'accessPlugin-smart-links', SmartLinks::PERMISSION_VIEW_INVENTORY, SmartLinks::PERMISSION_VIEW_USAGE, ...self::author()->grantedPermissions]);
        // Which sites are editable is remembered per request: worked out afresh for this user.
        $this->resetCraftServices();
        self::forgetRequestedSite();

        [$html] = $this->renderedInventory($this->runInventoryAction(['search' => 'usage-every-site'], $editor));
        self::assertSame(1, preg_match('#href="([^"]*/smart-links/links/\d+[^"]*)"#', $html, $usageLink));
        self::assertSame(1, preg_match('#href="([^"]*/smart-links/links\?[^"]*sort=link[^"]*)"#', $html, $sortLink));
        parse_str((string)parse_url(html_entity_decode($usageLink[1]), PHP_URL_QUERY), $usageQuery);
        parse_str((string)parse_url(html_entity_decode($sortLink[1]), PHP_URL_QUERY), $sortQuery);

        // Craft's own parameter is there, and filters nothing.
        self::assertArrayHasKey('site', $usageQuery);
        self::assertNull(UsageCriteria::fromParams($usageQuery)->siteId, $usageLink[1]);
        self::assertNull(InventoryCriteria::fromParams($sortQuery)->siteId, $sortLink[1]);
    }

    public function testViewingUsageIsAPermissionNestedUnderTheInventory(): void
    {
        $groups = Craft::$app->getUserPermissions()->getAllPermissions();
        $group = array_values(array_filter($groups, static fn(array $group): bool => $group['heading'] === 'Smart Links'))[0] ?? null;

        self::assertNotNull($group);
        self::assertArrayHasKey(SmartLinks::PERMISSION_VIEW_USAGE, $group['permissions'][SmartLinks::PERMISSION_VIEW_INVENTORY]['nested']);

        // Each target's page has its own control panel URL.
        $event = new RegisterUrlRulesEvent();
        Event::trigger(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, $event);
        self::assertSame('smart-links/links/usage', $event->rules['smart-links/links/<indexId:\\d+>'] ?? null);
    }

    public function testReadingUsageWritesNothing(): void
    {
        $page = self::page('usage-read-only', [self::LINKS => [['type' => 'entry', 'data' => ['elementId' => self::page('usage-read-only-target')->id]]]]);
        $this->runPushed();
        $before = $this->snapshot();
        $content = (new Query())->from(Table::ELEMENTS_SITES)->where(['elementId' => $page->id])->orderBy('id')->all();

        $this->usage()->ofElement((int)$page->id);
        $this->usage()->ofTarget((int)IndexRecord::find()->max('id'));
        $this->usage()->ofUrl('https://example.com/anything');
        $this->rendered($this->runUsageAction((int)IndexRecord::find()->max('id'), [], $this->user(['accessCp', 'accessPlugin-smart-links', SmartLinks::PERMISSION_VIEW_INVENTORY, SmartLinks::PERMISSION_VIEW_USAGE])));

        self::assertSame($before, $this->snapshot());
        self::assertSame($content, (new Query())->from(Table::ELEMENTS_SITES)->where(['elementId' => $page->id])->orderBy('id')->all());
        self::assertSame([], $this->pushed);
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
     * A block in an entry's (or block's) Matrix field, holding the links given.
     *
     * @param list<array<string, mixed>> $links
     */
    private function block(Entry $owner, array $links): Entry
    {
        $block = new Entry([
            'typeId' => (int)Craft::$app->getEntries()->getEntryTypeByHandle(self::BLOCK_TYPE)?->id,
            'fieldId' => Craft::$app->getFields()->getFieldByHandle(self::BLOCKS)?->id,
            'ownerId' => $owner->id,
            'siteId' => self::$primarySiteId,
        ]);
        $block->setFieldValue(self::URL_ONLY, $links === [] ? new LinkCollection() : self::entered($links));
        self::assertTrue(Craft::$app->getElements()->saveElement($block), Json::encode($block->getErrors()));

        return $block;
    }

    /**
     * @param list<CustomField> $elements
     */
    private static function layoutOf(array $elements): FieldLayout
    {
        $layout = new FieldLayout(['type' => Entry::class]);
        $layout->setTabs([['name' => 'Links', 'elements' => $elements]]);

        return $layout;
    }

    /**
     * Saves the test page layout with its elements as `$edit` makes them, as Craft saves a layout:
     * with the entry type it belongs to.
     *
     * @param callable(list<array<string, mixed>>): list<array<string, mixed>> $edit
     */
    private function saveLayout(callable $edit): void
    {
        $entryType = Craft::$app->getEntries()->getEntryTypeById((int)self::$entryType->id);
        self::assertNotNull($entryType);
        $layout = $entryType->getFieldLayout();
        $config = $layout->getConfig() ?? [];
        $config['tabs'][0]['elements'] = $edit($config['tabs'][0]['elements']);
        $entryType->setFieldLayout(Craft::$app->getFields()->createLayout(['id' => $layout->id, 'uid' => $layout->uid, 'type' => Entry::class] + $config));
        self::assertTrue(Craft::$app->getEntries()->saveEntryType($entryType), Json::encode($entryType->getErrors()));
    }

    /**
     * Saves an element again with the given links, as an author would.
     *
     * @param array<string, list<array<string, mixed>>> $values
     */
    private function save(ElementInterface $element, array $values): void
    {
        $fresh = Craft::$app->getElements()->getElementById((int)$element->id, $element::class, self::$primarySiteId, ['status' => null]);
        self::assertNotNull($fresh);

        foreach ($values as $handle => $links) {
            $fresh->setFieldValue($handle, self::entered($links));
        }

        self::assertTrue(Craft::$app->getElements()->saveElement($fresh), Json::encode($fresh->getErrors()));
    }

    /**
     * @return list<int>
     */
    private static function siteIds(): array
    {
        return array_map('intval', Craft::$app->getSites()->getAllSiteIds(true));
    }

    private static function siteCount(): int
    {
        return count(self::siteIds());
    }

    /**
     * The distinct elements holding a page's occurrences, in order.
     *
     * @return list<int>
     */
    private static function elementIds(UsagePage $page): array
    {
        return array_values(array_unique(array_map(static fn(LinkUsage $usage): int => $usage->elementId, $page->items)));
    }

    private function usage(): Usage
    {
        return SmartLinks::getInstance()->getUsage();
    }

    private function index(): Index
    {
        return SmartLinks::getInstance()->getIndex();
    }

    private function targetId(string $key): int
    {
        $id = IndexRecord::findOne(['targetKey' => $key])?->id;
        self::assertNotNull($id, "No index row for $key.");

        return (int)$id;
    }

    private function reload(ElementInterface $entry): Entry
    {
        $reloaded = Entry::find()->id($entry->id)->siteId(self::$primarySiteId)->status(null)->one();
        self::assertInstanceOf(Entry::class, $reloaded);

        return $reloaded;
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
     * Starts Craft's services afresh after a test changed what they remember (fields, layouts,
     * sites), as a new request or queue process would.
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
     * Counts the database queries a read makes, by Craft's own query log.
     *
     * @template T
     * @param callable(): T $read
     * @return array{0: int, 1: T}
     */
    private function queriesDuring(callable $read): array
    {
        // Every statement goes through the connection's command class: one that counts them.
        $counting = new class() extends \craft\db\Command {
            public static int $statements = 0;

            protected function logQuery($category): array
            {
                self::$statements++;

                return parent::logQuery($category);
            }
        };
        $db = Craft::$app->getDb();
        $class = $db->commandClass;
        $db->commandClass = $counting::class;
        $counting::$statements = 0;

        try {
            $result = $read();
        } finally {
            $db->commandClass = $class;
        }

        return [$counting::$statements, $result];
    }

    /**
     * Usage rows of every test page, as stored, without the dates writes set.
     *
     * @return list<array<string, mixed>>
     */
    private function usageRows(): array
    {
        return array_map(static function(array $row): array {
            unset($row['dateUpdated']);

            return $row;
        }, (new Query())->from(UsageRecord::TABLE)->orderBy('id')->all());
    }

    /**
     * Every index row, as stored.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(): array
    {
        return [
            'index' => (new Query())->from(IndexRecord::TABLE)->orderBy('id')->all(),
            'usage' => (new Query())->from(UsageRecord::TABLE)->orderBy('id')->all(),
            'sources' => (new Query())->from(SourceRecord::TABLE)->orderBy('id')->all(),
            'health' => (new Query())->from(HealthRecord::TABLE)->orderBy('id')->all(),
        ];
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
     * @param array<string, mixed> $params The page's query string.
     */
    private function runUsageAction(int|string $indexId, array $params, TestUser $user): \yii\web\Response
    {
        $_GET = $params;

        return $this->runControllerAction(LinksController::class, 'links', 'usage', ['indexId' => $indexId] + $params, $user, method: 'GET', path: '/admin/actions/smart-links/links/', json: false);
    }

    /**
     * @param array<string, string> $params
     */
    private function runInventoryAction(array $params, TestUser $user): \yii\web\Response
    {
        $_GET = $params;

        return $this->runControllerAction(LinksController::class, 'links', 'index', $params, $user, method: 'GET', path: '/admin/actions/smart-links/links/', json: false);
    }

    /**
     * The usage table a page response shows, rendered from the page's own variables. The control
     * panel layout around it needs a web session, which tests do not have; the real control panel
     * suite renders the whole page.
     *
     * @return array{string, array<string, mixed>}
     */
    private function rendered(\yii\web\Response $response): array
    {
        return $this->renderedPart($response, 'smart-links/links/_usage', 'smart-links/links/_usageTable');
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function renderedInventory(\yii\web\Response $response): array
    {
        return $this->renderedPart($response, 'smart-links/links/_index', 'smart-links/links/_inventory');
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function renderedPart(\yii\web\Response $response, string $page, string $part): array
    {
        $behavior = $response->getBehavior(TemplateResponseBehavior::NAME);
        self::assertInstanceOf(TemplateResponseBehavior::class, $behavior);
        self::assertSame($page, $behavior->template);

        // The page compiles, with everything it extends and includes.
        Craft::$app->getView()->getTwig()->load($page);

        return [Craft::$app->getView()->renderTemplate($part, $behavior->variables, View::TEMPLATE_MODE_CP), $behavior->variables];
    }

    /**
     * Makes Craft work out afresh which site the control panel is showing, as a new request does.
     */
    private static function forgetRequestedSite(): void
    {
        (new \ReflectionProperty(Cp::class, '_requestedSite'))->setValue(null, null);
    }
}
