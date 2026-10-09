<?php

namespace Tahadudhiya\SmartLinks\Tests\integration;

use Craft;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\models\GqlSchema;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;
use Tahadudhiya\SmartLinks\models\LinkCollection;
use Tahadudhiya\SmartLinks\services\Presets;
use Tahadudhiya\SmartLinks\SmartLinks;
use Tahadudhiya\SmartLinks\Tests\_support\ElementFixture;
use Tahadudhiya\SmartLinks\Tests\_support\FieldFixture;

/**
 * The Smart Links field in Craft's GraphQL API, through Craft's own GraphQL service: schemas,
 * queries and mutations as a GraphQL client sends them, against real content in two sites.
 */
final class GraphqlTest extends TestCase
{
    use FieldFixture;
    use ElementFixture;

    /** Any number of links of every built-in type. */
    private const ALL = 'smartLinksTestGqlAll';

    /** The fields a query asks of every link. */
    private const LINK_FIELDS = 'uid type label urlSuffix presetUid target rel title class id ariaLabel download downloadFilename customAttributes { name value } status url text external html';

    private const DATA_FIELDS = '__typename
        ... on SmartLinkData_url { url }
        ... on SmartLinkData_email { address subject body }
        ... on SmartLinkData_tel { number }
        ... on SmartLinkData_sms { number body }
        ... on SmartLinkData_social { network account }
        ... on SmartLinkData_embed { provider mediaId embedUrl }
        ... on SmartLinkData_entry { elementId siteId }
        ... on SmartLinkData_category { elementId siteId }
        ... on SmartLinkData_asset { elementId siteId }
        ... on SmartLinkData_user { elementId siteId }';

    private static bool $caching;

    protected static function usesTestTypes(): bool
    {
        return false;
    }

    protected static function extraLayoutFields(): array
    {
        $handles = SmartLinks::getInstance()->getLinkTypes()->getTypeSet()->handles();

        return [new CustomField(self::createField(self::ALL, ['types' => $handles, 'translationMethod' => SmartLinkField::TRANSLATION_METHOD_SITE]))];
    }

    public static function setUpBeforeClass(): void
    {
        self::setUpFixture();
        self::setUpElementFixture();

        $general = Craft::$app->getConfig()->getGeneral();
        self::$caching = $general->enableGraphqlCaching;
        $general->enableGraphqlCaching = false;

        // Craft builds one schema definition per process, which later queries reuse; scopes are
        // applied as each query is resolved. So it is built once, for the widest schema here, and
        // each test's schema then decides what that test's queries may read.
        Craft::$app->getGql()->flushCaches();
        Craft::$app->getGql()->getSchemaDef(self::schema(products: true, mutations: true));
    }

    public static function tearDownAfterClass(): void
    {
        Craft::$app->getConfig()->getGeneral()->enableGraphqlCaching = self::$caching;
        self::tearDownFixture();
        self::tearDownElementFixture();
        Craft::$app->getGql()->flushCaches();
    }

    public function testEveryTypeReadsAsItsLinkAndItsOwnData(): void
    {
        $target = self::savedEntry('gql-target');
        $category = self::savedCategory('gql-category');
        $asset = self::savedAsset(self::$publicVolume, 'gql.pdf');
        $user = User::find()->admin()->one();
        self::assertNotNull($user);

        $entry = self::savedEntry('gql-every-type', [self::ALL => [
            ['type' => 'url', 'data' => ['url' => 'HTTPS://Example.COM/docs'], 'label' => 'Docs', 'urlSuffix' => '?ref=gql', 'attributes' => ['target' => '_blank', 'class' => 'a b', 'custom' => ['data-track' => 'cta']]],
            ['type' => 'entry', 'data' => ['elementId' => (string)$target->id]],
            ['type' => 'category', 'data' => ['elementId' => (string)$category->id]],
            ['type' => 'asset', 'data' => ['elementId' => (string)$asset->id], 'attributes' => ['download' => '1', 'downloadFilename' => 'file.pdf']],
            ['type' => 'user', 'data' => ['elementId' => (string)$user->id]],
            ['type' => 'email', 'data' => ['address' => 'hello@example.com', 'subject' => 'Hi']],
            ['type' => 'tel', 'data' => ['number' => '+1 555 123 4567']],
            ['type' => 'sms', 'data' => ['number' => '+15551234567', 'body' => 'Hi']],
            ['type' => 'social', 'data' => ['network' => 'github', 'account' => 'craftcms']],
            ['type' => 'embed', 'data' => ['url' => 'https://youtu.be/dQw4w9WgXcQ']],
        ]]);

        $links = self::query(self::schema(), $entry, self::ALL)[self::ALL];
        self::assertCount(10, $links);

        // The link as authored, and as it renders.
        self::assertSame([
            'uid' => $entry->getFieldValue(self::ALL)->links[0]->uid,
            'type' => 'url', 'label' => 'Docs', 'urlSuffix' => '?ref=gql', 'presetUid' => null, 'target' => '_blank',
            'rel' => null, 'title' => null, 'class' => 'a b', 'id' => null, 'ariaLabel' => null, 'download' => false,
            'downloadFilename' => null, 'customAttributes' => [['name' => 'data-track', 'value' => 'cta']],
            'status' => 'resolved', 'url' => 'https://example.com/docs?ref=gql', 'text' => 'Docs', 'external' => true,
            'html' => '<a class="a b" href="https://example.com/docs?ref=gql" rel="noopener" target="_blank" data-track="cta">Docs</a>',
            'data' => ['__typename' => 'SmartLinkData_url', 'url' => 'https://example.com/docs'],
            'element' => null,
        ], $links[0]);

        // Each type's own data, in its stored form.
        self::assertSame(['__typename' => 'SmartLinkData_entry', 'elementId' => (int)$target->id, 'siteId' => null], $links[1]['data']);
        self::assertSame(['__typename' => 'SmartLinkData_email', 'address' => 'hello@example.com', 'subject' => 'Hi', 'body' => null], $links[5]['data']);
        self::assertSame(['__typename' => 'SmartLinkData_tel', 'number' => '+15551234567'], $links[6]['data']);
        self::assertSame(['__typename' => 'SmartLinkData_sms', 'number' => '+15551234567', 'body' => 'Hi'], $links[7]['data']);
        self::assertSame(['__typename' => 'SmartLinkData_social', 'network' => 'github', 'account' => 'craftcms'], $links[8]['data']);
        self::assertSame(['__typename' => 'SmartLinkData_embed', 'provider' => 'youtube', 'mediaId' => 'dQw4w9WgXcQ', 'embedUrl' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'], $links[9]['data']);

        // Where each leads, as the link core renders it.
        foreach ($entry->getFieldValue(self::ALL)->links as $index => $link) {
            self::assertSame(SmartLinks::getInstance()->getLinks()->render($link, self::$primarySiteId)?->href, $links[$index]['url'], $link->type);
        }

        // The elements, through Craft's own GraphQL element types.
        self::assertSame(['id' => (string)$target->id, '__typename' => 'smartLinksTestPage_Entry', 'title' => 'gql-target'], $links[1]['element']);
        self::assertSame((string)$category->id, $links[2]['element']['id']);
        self::assertSame((string)$asset->id, $links[3]['element']['id']);
        self::assertSame((string)$user->id, $links[4]['element']['id']);
        self::assertSame(['download' => true, 'downloadFilename' => 'file.pdf'], ['download' => $links[3]['download'], 'downloadFilename' => $links[3]['downloadFilename']]);
        // Users have no URLs of their own: the link says so, and shows the user only by element.
        self::assertSame(['status' => 'noUrl', 'url' => null, 'text' => null, 'html' => null], array_intersect_key($links[4], array_flip(['status', 'url', 'text', 'html'])));
    }

    public function testALinkedElementIsReturnedOnlyWhenTheSchemaMayReadIt(): void
    {
        $category = self::savedCategory('gql-scoped-category');
        $asset = self::savedAsset(self::$publicVolume, 'gql-scoped.pdf');
        $user = User::find()->admin()->one();
        self::assertNotNull($user);
        $entry = self::savedEntry('gql-scoped', [self::ALL => [
            ['type' => 'category', 'data' => ['elementId' => (string)$category->id]],
            ['type' => 'asset', 'data' => ['elementId' => (string)$asset->id]],
            ['type' => 'user', 'data' => ['elementId' => (string)$user->id]],
        ]]);

        // A schema that may read the entry, and nothing it links to.
        $links = self::query(self::schema(categories: false, assets: false, users: false), $entry, self::ALL)[self::ALL];

        foreach ($links as $link) {
            self::assertNull($link['element'], $link['type']);
        }

        // The link itself is the entry's content, which the schema may read.
        self::assertSame('resolved', $links[0]['status']);
        self::assertSame($category->getUrl(), $links[0]['url']);

        $links = self::query(self::schema(), $entry, self::ALL)[self::ALL];
        self::assertSame([(string)$category->id, (string)$asset->id, (string)$user->id], array_column(array_column($links, 'element'), 'id'));
    }

    public function testATargetThatLeadsNowhereShowsItsStatusAndNothingAboutTheTarget(): void
    {
        $disabled = self::savedEntry('gql-disabled-secret', enabled: false);
        $trashed = self::savedEntry('gql-trashed');
        $deleted = self::savedEntry('gql-deleted');
        $deletedId = (int)$deleted->id;
        $primaryOnly = new Entry(['sectionId' => self::$primaryOnly->id, 'typeId' => self::$entryType->id, 'siteId' => self::$primarySiteId, 'title' => 'gql-primary-only', 'slug' => 'gql-primary-only']);
        self::assertTrue(Craft::$app->getElements()->saveElement($primaryOnly));

        $entry = self::savedEntry('gql-nowhere', [self::ALL => [
            ['type' => 'entry', 'data' => ['elementId' => (string)$disabled->id]],
            ['type' => 'entry', 'data' => ['elementId' => (string)$trashed->id]],
            ['type' => 'entry', 'data' => ['elementId' => (string)$deletedId]],
            ['type' => 'entry', 'data' => ['elementId' => (string)$primaryOnly->id]],
        ]]);
        Craft::$app->getElements()->deleteElement($trashed);
        Craft::$app->getElements()->deleteElement($deleted, true);

        $expected = static fn(string $status): array => ['status' => $status, 'url' => null, 'text' => null, 'html' => null, 'element' => null];
        $pick = static fn(array $link): array => array_intersect_key($link, array_flip(['status', 'url', 'text', 'html', 'element']));

        $links = self::query(self::schema(), $entry, self::ALL)[self::ALL];
        self::assertSame([$expected('disabled'), $expected('missing'), $expected('missing'), ['status' => 'resolved'] + array_slice($pick($links[3]), 1)], array_map($pick, $links));
        self::assertNotNull($links[3]['element']);

        // In the second site, the entry that is only in the primary site leads nowhere.
        $links = self::query(self::schema(), $entry, self::ALL, self::$secondSiteId)[self::ALL];
        self::assertSame($expected('missing'), $pick($links[3]));

        // Nothing in the answer names the disabled entry.
        self::assertStringNotContainsString('gql-disabled-secret', Json::encode($links));
    }

    public function testLinksFollowTheSiteTheyAreReadInUnlessASiteIsChosen(): void
    {
        $target = self::savedEntry('gql-site-target');
        $inSecond = Entry::find()->id($target->id)->siteId(self::$secondSiteId)->one();
        self::assertNotNull($inSecond);
        $entry = self::savedEntry('gql-site-aware', [self::ALL => [
            ['type' => 'entry', 'data' => ['elementId' => (string)$target->id]],
            ['type' => 'entry', 'data' => ['elementId' => (string)$target->id, 'siteId' => (string)self::$secondSiteId]],
        ]]);

        $primary = self::query(self::schema(), $entry, self::ALL)[self::ALL];
        $second = self::query(self::schema(), $entry, self::ALL, self::$secondSiteId)[self::ALL];

        self::assertSame([$target->getUrl(), $inSecond->getUrl()], array_column($primary, 'url'));
        self::assertSame([$inSecond->getUrl(), $inSecond->getUrl()], array_column($second, 'url'));
        self::assertSame(['siteId' => self::$secondSiteId], array_intersect_key($primary[1]['data'], ['siteId' => true]));
    }

    public function testASingleLinkFieldIsOneLinkOrNullAndAMultipleOneIsAList(): void
    {
        $with = self::savedEntry('gql-single', [self::SINGLE => [['type' => 'url', 'data' => ['url' => 'https://example.com/one']]]]);
        $without = self::savedEntry('gql-single-empty');

        self::assertSame('https://example.com/one', self::query(self::schema(), $with, self::SINGLE)[self::SINGLE]['url']);
        self::assertNull(self::query(self::schema(), $without, self::SINGLE)[self::SINGLE]);
        self::assertSame([], self::query(self::schema(), $without, self::ALL)[self::ALL]);
    }

    public function testAStoredValueThatCannotBeReadIsAnErrorNotAnEmptyList(): void
    {
        $entry = self::savedEntry('gql-unreadable', [self::ALL => [['type' => 'url', 'data' => ['url' => 'https://example.com/']]]]);
        $field = self::$entryType->getFieldLayout()->getFieldByHandle(self::ALL);
        self::assertInstanceOf(SmartLinkField::class, $field);

        // Content in a format no Smart Links version wrote.
        $content = Json::decode((string)(new \craft\db\Query())->select(['content'])->from(Table::ELEMENTS_SITES)->where(['elementId' => $entry->id, 'siteId' => self::$primarySiteId])->scalar());
        $content[$field->layoutElement?->uid] = ['version' => 9, 'links' => []];
        Db::update(Table::ELEMENTS_SITES, ['content' => $content], ['elementId' => $entry->id, 'siteId' => self::$primarySiteId]);

        $result = self::execute(self::schema(), self::entryQuery($entry, self::ALL));

        self::assertNull($result['data']['entry'][self::ALL] ?? null);
        self::assertSame(['The value of the “' . self::ALL . '” field can’t be read.'], array_column($result['errors'] ?? [], 'message'));
    }

    public function testAMutationSavesLinksByTheSameRulesAsTheEditor(): void
    {
        $target = self::savedEntry('gql-mutation-target');
        $entry = self::savedEntry('gql-mutation');
        $mutation = 'mutation($id: ID, $links: [SmartLinkInput!]) { save_' . self::SECTION . '_smartLinksTestPage_Entry(id: $id, ' . self::ALL . ': $links) { id } }';
        $save = static fn(?array $links): array => self::execute(self::schema(mutations: true), $mutation, ['id' => (string)$entry->id, 'links' => $links]);

        $result = $save([
            ['type' => 'url', 'data' => '{"url": "https://Example.com/a"}', 'label' => 'A', 'target' => '_blank', 'customAttributes' => [['name' => 'data-x', 'value' => '1']]],
            ['type' => 'entry', 'data' => Json::encode(['elementId' => (int)$target->id])],
            ['type' => 'email', 'data' => '{"address": "hi@example.com"}'],
        ]);
        self::assertArrayNotHasKey('errors', $result, Json::encode(array_map(static fn(array $error): array => array_intersect_key($error, ['message' => 1, 'debugMessage' => 1, 'file' => 1, 'line' => 1]), $result['errors'] ?? [])));

        $saved = Entry::find()->id($entry->id)->one()?->getFieldValue(self::ALL);
        self::assertInstanceOf(LinkCollection::class, $saved);
        self::assertSame(['url', 'entry', 'email'], array_map(static fn($link): string => $link->type, $saved->links));
        self::assertSame(['url' => 'https://example.com/a'], $saved->links[0]->data->toArray());
        self::assertSame(['data-x' => '1'], $saved->links[0]->attributes->custom);
        // The entry link is a relation, as from the editor.
        self::assertSame([(int)$entry->id], array_map('intval', Entry::find()->relatedTo($target)->ids()));

        // Whatever the editor would refuse is refused, and nothing is saved.
        foreach ([
            [['type' => 'url', 'data' => '{"url": "javascript:alert(1)"}']],
            [['type' => 'url', 'data' => 'not json']],
            [['type' => 'no-such-type', 'data' => '{}']],
            [['type' => 'entry', 'data' => '{"elementId": ' . (int)self::savedCategory('gql-not-an-entry')->id . '}']],
            [['type' => 'url', 'data' => '{"url": "https://example.com/", "extra": 1}']],
        ] as $refused) {
            $result = $save($refused);
            self::assertNotEmpty($result['errors'] ?? [], Json::encode($refused));
            self::assertCount(3, Entry::find()->id($entry->id)->one()?->getFieldValue(self::ALL) ?? [], Json::encode($refused));
        }

        // An empty list is no links. (Craft's mutation resolver fails on a null input object
        // argument before any field sees it, so clearing is done with an empty list.)
        $result = $save([]);
        self::assertArrayNotHasKey('errors', $result, Json::encode(array_column($result['errors'] ?? [], 'message')));
        self::assertCount(0, Entry::find()->id($entry->id)->one()?->getFieldValue(self::ALL) ?? [1]);
        self::assertSame([], array_map('intval', Entry::find()->relatedTo($target)->ids()));
    }

    public function testAMutationCannotGiveALinkADisabledPresetButKeepsOneALinkHas(): void
    {
        $entry = self::savedEntry('gql-preset', [self::LINKS => [['type' => 'url', 'data' => ['url' => 'https://example.com/kept'], 'presetUid' => self::PRIMARY]]]);
        $kept = $entry->getFieldValue(self::LINKS)->links[0]->uid;
        $mutation = 'mutation($id: ID, $links: [SmartLinkInput!]) { save_' . self::SECTION . '_smartLinksTestPage_Entry(id: $id, ' . self::LINKS . ': $links) { id } }';
        $save = static fn(array $links): array => self::execute(self::schema(mutations: true), $mutation, ['id' => (string)$entry->id, 'links' => $links]);
        $projectConfig = Craft::$app->getProjectConfig();
        $projectConfig->set(Presets::CONFIG_KEY . '.' . self::PRIMARY . '.enabled', false);

        try {
            // A new link given the disabled preset is refused, and nothing changes.
            $result = $save([
                ['uid' => $kept, 'type' => 'url', 'data' => '{"url": "https://example.com/kept"}', 'presetUid' => self::PRIMARY],
                ['type' => 'url', 'data' => '{"url": "https://example.com/new"}', 'presetUid' => self::PRIMARY],
            ]);
            self::assertStringContainsString('disabled', Json::encode($result['errors'] ?? []));
            self::assertCount(1, Entry::find()->id($entry->id)->one()?->getFieldValue(self::LINKS) ?? []);

            // The link that has it keeps it through an edit.
            $result = $save([['uid' => $kept, 'type' => 'url', 'data' => '{"url": "https://example.com/kept"}', 'label' => 'Kept', 'presetUid' => self::PRIMARY]]);
            self::assertArrayNotHasKey('errors', $result, Json::encode($result['errors'] ?? []));
            $link = Entry::find()->id($entry->id)->one()?->getFieldValue(self::LINKS)->links[0];
            self::assertSame(['Kept', self::PRIMARY], [$link?->label, $link?->presetUid]);
        } finally {
            $projectConfig->set(Presets::CONFIG_KEY . '.' . self::PRIMARY . '.enabled', true);
        }
    }

    public function testAMutationCannotChangeWhatAPresetLocksNorUseOneTheFieldDoesNotAllow(): void
    {
        // Without the fixture's default links, which were made with Primary CTA too and would be
        // refused by its lock as well: every refusal here is the mutated field's.
        $entry = self::savedEntry('gql-preset-lock', [self::DEFAULTS => []]);
        $mutation = 'mutation($id: ID, $links: [SmartLinkInput!]) { save_' . self::SECTION . '_smartLinksTestPage_Entry(id: $id, ' . self::LINKS . ': $links) { id } }';
        $save = static fn(array $links): array => self::execute(self::schema(mutations: true), $mutation, ['id' => (string)$entry->id, 'links' => $links]);
        $projectConfig = Craft::$app->getProjectConfig();
        $projectConfig->set(Presets::CONFIG_KEY . '.' . self::PRIMARY . '.attributes', ['target' => '_blank']);
        $projectConfig->set(Presets::CONFIG_KEY . '.' . self::PRIMARY . '.locked', ['target']);

        try {
            foreach ([
                'a locked setting changed' => [['type' => 'url', 'data' => '{"url": "https://example.com/"}', 'presetUid' => self::PRIMARY, 'target' => '_top'], 'locked by the'],
                'a locked setting left out' => [['type' => 'url', 'data' => '{"url": "https://example.com/"}', 'presetUid' => self::PRIMARY], 'locked by the'],
                'a preset the field does not allow' => [['type' => 'url', 'data' => '{"url": "https://example.com/"}', 'presetUid' => self::SECONDARY], 'not allowed in this field'],
                'a preset that does not exist' => [['type' => 'url', 'data' => '{"url": "https://example.com/"}', 'presetUid' => self::GONE], 'no longer exists'],
                'a preset UID that is not one' => [['type' => 'url', 'data' => '{"url": "https://example.com/"}', 'presetUid' => 'primary'], 'not a valid UID'],
            ] as $case => [$link, $refusal]) {
                $result = $save([$link]);
                self::assertStringContainsString($refusal, Json::encode($result['errors'] ?? [], JSON_UNESCAPED_UNICODE), $case);
                self::assertCount(0, Entry::find()->id($entry->id)->one()?->getFieldValue(self::LINKS) ?? [1], $case);
            }

            $result = $save([['type' => 'url', 'data' => '{"url": "https://example.com/"}', 'presetUid' => self::PRIMARY, 'target' => '_blank']]);
            self::assertArrayNotHasKey('errors', $result, Json::encode($result['errors'] ?? []));
        } finally {
            $projectConfig->remove(Presets::CONFIG_KEY . '.' . self::PRIMARY . '.attributes');
            $projectConfig->remove(Presets::CONFIG_KEY . '.' . self::PRIMARY . '.locked');
        }
    }

    public function testACommerceProductLinkReadsThroughCommercesOwnResolver(): void
    {
        if (self::$productTypeId === null) {
            self::markTestSkipped('Craft Commerce is not installed and enabled in this run.');
        }

        $product = self::savedProduct('GraphQL mug', true);
        $hidden = self::savedProduct('GraphQL hidden hat', false);
        $entry = self::savedEntry('gql-product', [self::ALL => [
            ['type' => 'commerce-product', 'data' => ['elementId' => (string)$product->id]],
            ['type' => 'commerce-product', 'data' => ['elementId' => (string)$hidden->id]],
        ]]);
        $query = self::entryQuery($entry, self::ALL, '... on SmartLinkData_commerce_product { elementId }');

        $links = self::execute(self::schema(products: true), $query)['data']['entry'][self::ALL];
        self::assertSame(['resolved', 'disabled'], array_column($links, 'status'));
        self::assertSame($product->getUrl(), $links[0]['url']);
        self::assertSame((string)$product->id, $links[0]['element']['id']);
        self::assertNull($links[1]['element']);

        // Without the product type in the schema, the product itself is not returned.
        $links = self::execute(self::schema(), $query)['data']['entry'][self::ALL];
        self::assertNull($links[0]['element']);
        self::assertSame($product->getUrl(), $links[0]['url']);
    }

    // Helpers

    /**
     * A schema that may read the test section in both sites and, unless told otherwise, every
     * kind of element links point at.
     */
    private static function schema(bool $categories = true, bool $assets = true, bool $users = true, bool $products = false, bool $mutations = false): GqlSchema
    {
        $sites = Craft::$app->getSites();
        $scope = [
            'sites.' . $sites->getSiteById(self::$primarySiteId)?->uid . ':read',
            'sites.' . $sites->getSiteById(self::$secondSiteId)?->uid . ':read',
            'sections.' . self::$section->uid . ':read',
            'sections.' . self::$primaryOnly->uid . ':read',
            'entrytypes.' . self::$entryType->uid . ':read',
        ];

        if ($categories) {
            $scope[] = 'categorygroups.' . self::$categoryGroup->uid . ':read';
        }

        if ($assets) {
            $scope[] = 'volumes.' . self::$publicVolume->uid . ':read';
        }

        if ($users) {
            $scope[] = 'usergroups.everyone:read';
        }

        if ($products && self::$productTypeId !== null) {
            $productType = self::call(Craft::$app->getPlugins()->getPlugin('commerce'), 'getProductTypes')->getProductTypeById(self::$productTypeId);
            $scope[] = 'productTypes.' . $productType->uid . ':read';
        }

        if ($mutations) {
            array_push($scope, 'sections.' . self::$section->uid . ':save', 'sections.' . self::$section->uid . ':create');
        }

        return new GqlSchema(['name' => 'Smart Links test', 'scope' => $scope]);
    }

    private static function entryQuery(Entry $entry, string $handle, string $extraData = '', ?int $siteId = null): string
    {
        $site = Craft::$app->getSites()->getSiteById($siteId ?? self::$primarySiteId)?->handle;

        return sprintf(
            '{ entry(id: %d, site: "%s") { ... on smartLinksTestPage_Entry { %s { %s data { %s %s } element { id __typename ... on EntryInterface { title } } } } } }',
            $entry->id,
            $site,
            $handle,
            self::LINK_FIELDS,
            self::DATA_FIELDS,
            $extraData,
        );
    }

    /**
     * @return array<string, mixed> The entry's fields.
     */
    private static function query(GqlSchema $schema, Entry $entry, string $handle, ?int $siteId = null): array
    {
        $result = self::execute($schema, self::entryQuery($entry, $handle, siteId: $siteId));
        self::assertArrayNotHasKey('errors', $result, Json::encode($result['errors'] ?? null));
        self::assertIsArray($result['data']['entry'] ?? null, Json::encode($result));

        return $result['data']['entry'];
    }

    /**
     * @param array<string, mixed>|null $variables
     * @return array<string, mixed>
     */
    private static function execute(GqlSchema $schema, string $query, ?array $variables = null): array
    {
        return Craft::$app->getGql()->executeQuery($schema, $query, $variables, debugMode: true);
    }
}
