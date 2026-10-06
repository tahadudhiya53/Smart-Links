<?php

namespace Tahadudhiya\SmartLinks\Tests\_support;

use Craft;
use craft\elements\Entry;
use craft\enums\PropagationMethod;
use craft\events\RegisterComponentTypesEvent;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\App;
use craft\helpers\Json;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use PHPUnit\Framework\Assert;
use Tahadudhiya\SmartLinks\controllers\FieldController;
use Tahadudhiya\SmartLinks\fields\LinkEditor;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;
use Tahadudhiya\SmartLinks\models\InvalidLinkValue;
use Tahadudhiya\SmartLinks\models\LinkCollection;
use Tahadudhiya\SmartLinks\services\Links;
use Tahadudhiya\SmartLinks\services\LinkTypes;
use Tahadudhiya\SmartLinks\services\Presets;
use Tahadudhiya\SmartLinks\SmartLinks;
use Tahadudhiya\SmartLinks\Tests\_support\linktypes\FakeEmailType;
use Tahadudhiya\SmartLinks\Tests\_support\linktypes\FakeEntryType;
use Tahadudhiya\SmartLinks\Tests\_support\linktypes\FakeUrlType;
use yii\base\Event;
use yii\db\Transaction;
use yii\web\Response;

/**
 * Real Craft content for Smart Links fields: a second site, presets in project config, fields in
 * a real entry type's layout, and a section in both sites. Link types are the test-only types,
 * registered through the real registration event in place of the built-in ones, unless a test
 * case asks for the built-in ones ({@see usesTestTypes()}).
 *
 * Everything is created inside one database transaction that is rolled back afterwards, project
 * config never writes YAML, and Craft's services are started afresh, so the host project is left
 * as it was.
 */
trait FieldFixture
{
    /** Multiple links of three types, one allowed preset, at most three, translated per site. */
    protected const LINKS = 'smartLinksTestLinks';

    /** The same field placed in the layout a second time. */
    protected const LINKS_AGAIN = 'smartLinksTestLinksAgain';

    /** One URL link, the same in every site. */
    protected const SINGLE = 'smartLinksTestSingle';

    /** Any number of URL links, no presets: a destination with fewer allowed types. */
    protected const URL_ONLY = 'smartLinksTestUrlOnly';

    /** URL and email links, starting with two default links. */
    protected const DEFAULTS = 'smartLinksTestDefaults';

    protected const SECTION = 'smartLinksTestPages';

    /** A preset the links field allows. */
    protected const PRIMARY = '6a3e9b1c-2f4d-4e8a-9c7b-1d2e3f4a5b6c';

    /** A preset that exists, but that no field allows. */
    protected const SECONDARY = '7b4f0c2d-3a5e-4f9b-8d8c-2e3f4a5b6c7d';

    /** A preset UID that no preset has. */
    protected const GONE = '8c5a1d3e-4b6f-4a0c-9e9d-3f4a5b6c7d8e';

    private static Transaction $transaction;
    private static bool $writeYaml;
    protected static int $primarySiteId;
    protected static int $secondSiteId;
    protected static EntryType $entryType;
    protected static Section $section;

    /** @var array<string, SmartLinkField> The fields as placed in the layout, by handle. */
    protected static array $instances = [];

    /** @var array<string, \yii\base\Component> Components swapped out for a web request. */
    private array $consoleComponents = [];

    /**
     * The test-only types stand in for the built-in types of the same handles, so they replace
     * every registered type rather than joining them.
     */
    public static function registerTestTypes(RegisterComponentTypesEvent $event): void
    {
        $event->types = [FakeUrlType::class, FakeEntryType::class, FakeEmailType::class];
    }

    /**
     * Whether the fixture's link types are the test-only ones, rather than the built-in ones.
     */
    protected static function usesTestTypes(): bool
    {
        return true;
    }

    /**
     * More fields for the test entry type's layout, created inside the fixture's transaction.
     *
     * @return list<CustomField>
     */
    protected static function extraLayoutFields(): array
    {
        return [];
    }

    protected static function setUpFixture(): void
    {
        if (!Craft::$app->getPlugins()->getPlugin('smart-links') instanceof SmartLinks) {
            Assert::fail('Smart Links must be installed and enabled in the host project.');
        }

        $projectConfig = Craft::$app->getProjectConfig();
        self::$writeYaml = $projectConfig->writeYamlAutomatically;
        $projectConfig->writeYamlAutomatically = false;

        if (static::usesTestTypes()) {
            Event::on(LinkTypes::class, LinkTypes::EVENT_REGISTER_LINK_TYPES, [self::class, 'registerTestTypes']);
        }

        self::resetPluginServices();

        self::$transaction = Craft::$app->getDb()->beginTransaction();

        // Presets are configuration: defined where Smart Links reads them.
        $projectConfig->set(Presets::CONFIG_KEY . '.' . self::PRIMARY, ['name' => 'Primary CTA']);
        $projectConfig->set(Presets::CONFIG_KEY . '.' . self::SECONDARY, ['name' => 'Secondary CTA']);

        self::$primarySiteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        self::$secondSiteId = self::createSite();

        $links = self::createField(self::LINKS, ['types' => ['url', 'entry', 'email'], 'presets' => [self::PRIMARY], 'maxLinks' => 3, 'translationMethod' => SmartLinkField::TRANSLATION_METHOD_SITE]);
        $single = self::createField(self::SINGLE, ['types' => ['url'], 'multiple' => false, 'translationMethod' => SmartLinkField::TRANSLATION_METHOD_NONE]);
        $urlOnly = self::createField(self::URL_ONLY, ['types' => ['url']]);
        $defaults = self::createField(self::DEFAULTS, [
            'types' => ['url', 'email'],
            'presets' => [self::PRIMARY],
            'defaultLinksInput' => ['links' => [
                ['type' => 'url', 'data' => ['url' => ['url' => 'https://example.com/start']], 'label' => 'Start here', 'presetUid' => self::PRIMARY],
                ['type' => 'email', 'data' => ['email' => ['address' => 'hello@example.com']]],
            ]],
        ]);

        self::$entryType = self::createEntryType([
            new CustomField($links),
            new CustomField($links, ['handle' => self::LINKS_AGAIN]),
            new CustomField($single),
            new CustomField($urlOnly),
            new CustomField($defaults),
            ...static::extraLayoutFields(),
        ]);
        self::$section = self::createSection(self::$entryType);

        foreach ([self::LINKS, self::LINKS_AGAIN, self::SINGLE, self::URL_ONLY, self::DEFAULTS] as $handle) {
            $instance = self::$entryType->getFieldLayout()->getFieldByHandle($handle);
            Assert::assertInstanceOf(SmartLinkField::class, $instance);
            self::$instances[$handle] = $instance;
        }
    }

    protected static function tearDownFixture(): void
    {
        if (isset(self::$transaction) && self::$transaction->getIsActive()) {
            self::$transaction->rollBack();
        }

        Event::off(LinkTypes::class, LinkTypes::EVENT_REGISTER_LINK_TYPES, [self::class, 'registerTestTypes']);
        self::resetPluginServices();

        // Craft's services remember the rolled-back site, fields and section; start them afresh.
        $components = Craft::$app->getComponents();

        foreach (['fields', 'entries', 'sites', 'elements', 'gql'] as $id) {
            Craft::$app->set($id, $components[$id]);
        }

        Craft::$app->getProjectConfig()->reset();
        Craft::$app->getProjectConfig()->writeYamlAutomatically = self::$writeYaml;

        // Craft holds the project config lock from the first change until the changes are saved
        // when a request ends. None ends here, and the rolled-back changes must not be saved, so
        // the lock is released as that save would release it; otherwise any other process (e.g.
        // a test's subprocess) waits for it. The flag is the service's own record of holding it.
        $projectConfig = Craft::$app->getProjectConfig();
        Craft::$app->getMutex()->release(\craft\services\ProjectConfig::MUTEX_NAME);
        (fn() => $this->_locked = false)->call($projectConfig);
        Craft::$app->getIsMultiSite(true);
        Craft::$app->getIsMultiSite(true, true);
    }

    protected static function resetPluginServices(): void
    {
        $plugin = SmartLinks::getInstance();
        $plugin->set('linkTypes', LinkTypes::class);
        $plugin->set('links', Links::class);
    }

    private static function createSite(): int
    {
        $sites = Craft::$app->getSites();
        $site = new Site([
            'groupId' => $sites->getPrimarySite()->groupId,
            'name' => 'Smart Links second site',
            'handle' => 'smartLinksTestSecond',
            'language' => 'de-DE',
            'hasUrls' => true,
            'baseUrl' => 'https://second.example.test/',
            'primary' => false,
        ]);

        Assert::assertTrue($sites->saveSite($site), Json::encode($site->getErrors()));

        // Element queries only constrain sites once Craft knows it is multi-site.
        Craft::$app->getIsMultiSite(true);
        Craft::$app->getIsMultiSite(true, true);

        return (int)$site->id;
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected static function createField(string $handle, array $settings): SmartLinkField
    {
        $field = Craft::$app->getFields()->createField(['type' => SmartLinkField::class, 'name' => $handle, 'handle' => $handle] + $settings);
        Assert::assertInstanceOf(SmartLinkField::class, $field);
        Assert::assertTrue(Craft::$app->getFields()->saveField($field), Json::encode($field->getErrors()));

        $saved = Craft::$app->getFields()->getFieldByHandle($handle);
        Assert::assertInstanceOf(SmartLinkField::class, $saved);

        return $saved;
    }

    /**
     * @param list<CustomField> $fields
     */
    private static function createEntryType(array $fields): EntryType
    {
        $entryType = new EntryType(['name' => 'Smart Links test page', 'handle' => 'smartLinksTestPage']);
        $layout = new FieldLayout(['type' => Entry::class]);
        $layout->setTabs([['name' => 'Content', 'elements' => array_merge([new EntryTitleField()], $fields)]]);
        $entryType->setFieldLayout($layout);

        Assert::assertTrue(Craft::$app->getEntries()->saveEntryType($entryType), Json::encode($entryType->getErrors()));

        return $entryType;
    }

    private static function createSection(EntryType $entryType): Section
    {
        $siteSettings = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteSettings[$site->id] = new Section_SiteSettings(['siteId' => $site->id, 'hasUrls' => true, 'uriFormat' => 'smart-links-test/{slug}', 'template' => '_smart-links-test']);
        }

        $section = new Section([
            'name' => 'Smart Links test pages',
            'handle' => self::SECTION,
            'type' => Section::TYPE_CHANNEL,
            'siteSettings' => $siteSettings,
            // Propagated to every site, so each site's own copy of a value can be shown to exist.
            'propagationMethod' => PropagationMethod::All,
        ]);
        $section->setEntryTypes([$entryType]);

        Assert::assertTrue(Craft::$app->getEntries()->saveSection($section), Json::encode($section->getErrors()));

        return $section;
    }

    // Content

    /**
     * Links as an author enters them, read by the link core, as code that builds links does
     * before setting them: a field value is only ever set from that or from stored content.
     *
     * @param list<array<string, mixed>> $links
     */
    protected static function entered(array $links): LinkCollection|InvalidLinkValue
    {
        $result = SmartLinks::getInstance()->getLinks()->getNormalizer()->normalize($links);

        return $result->value instanceof LinkCollection ? $result->value : InvalidLinkValue::fromInput($links, $result->errors);
    }

    /**
     * A saved entry, in the primary site unless another is given.
     *
     * @param array<string, mixed> $values Field values by handle; arrays are entered links.
     */
    protected static function savedEntry(string $slug, array $values = [], bool $enabled = true): Entry
    {
        $entry = new Entry();
        $entry->sectionId = (int)self::$section->id;
        $entry->typeId = (int)self::$entryType->id;
        $entry->siteId = self::$primarySiteId;
        $entry->title = $slug;
        $entry->slug = $slug;
        $entry->enabled = $enabled;

        foreach ($values as $handle => $value) {
            $entry->setFieldValue($handle, is_array($value) ? self::entered($value) : $value);
        }

        Assert::assertTrue(Craft::$app->getElements()->saveElement($entry), Json::encode($entry->getErrors()));

        return $entry;
    }

    /**
     * The link editor Craft renders for a field instance of an entry, in the `fields` namespace
     * an element editor uses.
     */
    protected static function editorFor(Entry $entry, string $handle): LinkEditor
    {
        $field = $entry->getFieldLayout()?->getFieldByHandle($handle);
        Assert::assertInstanceOf(SmartLinkField::class, $field);
        $view = Craft::$app->getView();
        $namespace = $view->getNamespace();
        $view->setNamespace('fields');

        try {
            return LinkEditor::forField($field, $entry);
        } finally {
            $view->setNamespace($namespace);
        }
    }

    /**
     * What an editor's page sends to say which editor a request is for.
     *
     * @return array{context: string, destination: string}
     */
    protected static function editorParams(LinkEditor $editor): array
    {
        $context = $editor->context();
        Assert::assertNotNull($context);

        return ['context' => $context, 'destination' => Json::encode($editor->destination())];
    }

    /**
     * A control panel user Craft lets edit the test section's entries in every site.
     *
     * @param list<int>|null $siteIds The sites the user may edit; all when null.
     */
    protected static function author(?array $siteIds = null): TestUser
    {
        $user = new TestUser();
        $sections = self::$section->uid;
        $sites = array_map(
            static fn(int $siteId): string => 'editSite:' . Craft::$app->getSites()->getSiteById($siteId)?->uid,
            $siteIds ?? [self::$primarySiteId, self::$secondSiteId],
        );
        $user->grantedPermissions = array_merge($sites, ['accessCp', "viewEntries:$sections", "saveEntries:$sections", "viewPeerEntries:$sections", "savePeerEntries:$sections"]);

        return $user;
    }

    // Requests

    /**
     * Swaps in a control panel web request and response, as Craft builds them for a real request,
     * so controllers run through Craft's own access, CSRF and JSON handling. The user is the
     * console app's, which answers identity and permission checks the same way; a guest's
     * redirect to the login page needs a real web app, so the security suite tests that over HTTP.
     *
     * @param array<string, string> $headers
     */
    protected function useWebRequest(string $method = 'GET', string $path = '/admin/entries', array $headers = []): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['SCRIPT_FILENAME'] = '/var/www/html/web/index.php';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        unset($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_REQUESTED_WITH']);

        foreach ($headers as $name => $value) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        foreach (['request', 'response'] as $id) {
            $this->consoleComponents[$id] ??= Craft::$app->get($id);
        }

        Craft::$app->set('request', Craft::createObject(App::webRequestConfig()));
        Craft::$app->set('response', Craft::createObject(App::webResponseConfig()));
    }

    protected function restoreConsoleRequest(): void
    {
        foreach ($this->consoleComponents as $id => $component) {
            Craft::$app->set($id, $component);
        }

        $this->consoleComponents = [];
        $_COOKIE = [];
    }

    /**
     * @param string[] $permissions
     */
    protected function signIn(bool $admin, array $permissions = ['accessCp']): TestUser
    {
        $user = new TestUser();
        $user->admin = $admin;
        $user->grantedPermissions = $permissions;
        Craft::$app->getUser()->setIdentity($user);

        return $user;
    }

    /**
     * Runs a field controller action as a control panel AJAX request with a valid CSRF token,
     * as the link editor sends it.
     *
     * @param array<string, mixed> $params
     */
    protected function runFieldAction(string $action, array $params, ?TestUser $user = null, bool $withCsrf = true, string $method = 'POST', string $path = '/admin/actions/smart-links/field/'): Response
    {
        $headers = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

        // A CSRF token is issued in a cookie by one request and presented with the next.
        $this->useWebRequest($method, $path . $action, $headers);

        if ($user !== null) {
            Craft::$app->getUser()->setIdentity($user);
        }

        if ($withCsrf) {
            $request = Craft::$app->getRequest();
            $token = $request->getCsrfToken();
            $cookie = Craft::$app->getResponse()->getCookies()->get($request->csrfParam);
            Assert::assertNotNull($cookie);
            $_COOKIE[$request->csrfParam] = Craft::$app->getSecurity()->hashData(serialize([$request->csrfParam, $cookie->value]), $request->cookieValidationKey);
            $params[$request->csrfParam] = $token;

            $this->useWebRequest($method, $path . $action, $headers);

            if ($user !== null) {
                Craft::$app->getUser()->setIdentity($user);
            }
        }

        Craft::$app->getRequest()->setBodyParams($params);
        $controller = new FieldController('field', SmartLinks::getInstance());
        $response = $controller->runAction($action);
        Assert::assertInstanceOf(Response::class, $response);

        return $response;
    }
}
