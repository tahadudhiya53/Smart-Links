<?php

// Content for the control panel test (tests/cp/run.mjs), in the host project's real database,
// because the control panel is served by the web server, not this process.
//
//   php fixture.php setup               creates it, and prints what the test needs, as JSON
//   php fixture.php inspect <id> <site> prints an entry's stored links and relations in a site
//   php fixture.php delete <id>         hard-deletes an element
//   php fixture.php impersonate <id>    prints a one-hour sign-in URL for a user
//   php fixture.php log-mark            prints how long Craft's log files are now
//   php fixture.php log-errors <mark>   prints the errors and warnings logged since the mark
//   php fixture.php teardown            removes all of it, and says whether the project is as it was
//
// Everything created is recorded in .build/state.json, and project config is never written to
// YAML. Setup snapshots the stored project config and the YAML files; teardown removes every record
// it made, checks the database no longer has any of them, and compares both snapshots, so a run
// leaves the project exactly as it found it.
//
// Craft stores project config changes when a request ends (Application::EVENT_AFTER_REQUEST),
// and this script runs no request, so every command ends the way a request does. Without that,
// records Craft creates at once (sections, fields…) would have no project config behind them, and
// removing them through project config would do nothing.

use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\PropagationMethod;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fs\Local;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Volume;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;
use Tahadudhiya\SmartLinks\SmartLinks;

require __DIR__ . '/../integration-bootstrap.php';

const STATE = __DIR__ . '/.build/state.json';
const TEMPLATE = '_smart-links-cp-test';

$app = Craft::$app;
$app->getProjectConfig()->writeYamlAutomatically = false;
$command = $argv[1] ?? '';

/**
 * @param array<string, mixed> $state
 */
function save(array $state): void
{
    FileHelper::writeToFile(STATE, Json::encode($state, JSON_PRETTY_PRINT));
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * Project config as Craft has stored it, without the time of its last change, which every change
 * sets. Read from the database, not this process's copy, so unsaved changes cannot hide.
 */
function configSnapshot(): string
{
    $rows = (new Query())->select(['path', 'value'])->from(Table::PROJECTCONFIG)->where(['not', ['path' => 'dateModified']])->orderBy(['path' => SORT_ASC])->all();

    return hash('sha256', Json::encode($rows));
}

/**
 * Ends the command as Craft ends a request, so its project config changes are stored.
 */
function endRequest(): void
{
    Craft::$app->trigger(\yii\base\Application::EVENT_AFTER_REQUEST);
}

/**
 * The records a run created that the database still has, besides elements, which are checked by
 * element ID.
 *
 * @param list<array{string, int|string}> $created
 * @return list<array{string, int|string}>
 */
function remaining(array $created): array
{
    $tables = ['section' => Table::SECTIONS, 'entryType' => Table::ENTRYTYPES, 'field' => Table::FIELDS, 'categoryGroup' => Table::CATEGORYGROUPS, 'volume' => Table::VOLUMES, 'element' => Table::ELEMENTS, 'productType' => '{{%commerce_producttypes}}', 'fieldLayout' => Table::FIELDLAYOUTS, 'structure' => Table::STRUCTURES, 'gqlSchema' => Table::GQLSCHEMAS, 'gqlToken' => Table::GQLTOKENS];
    $left = [];

    // Drafts and revisions of the run's entries are elements too, though the run never recorded them.
    $elementIds = array_column(array_filter($created, static fn(array $item): bool => $item[0] === 'element'), 1);

    foreach ((new Query())->select(['id'])->from(Table::ELEMENTS)->where(['canonicalId' => $elementIds])->column() as $id) {
        $left[] = ['derived element', (int)$id];
    }

    // Nested entries belong to the run's entries and must go with them. Their own entry rows are
    // checked: the owners table is emptied by the owner's deletion even if a nested entry stayed.
    foreach ((new Query())->select(['id'])->from(Table::ENTRIES)->where(['primaryOwnerId' => $elementIds])->column() as $id) {
        $left[] = ['nested element', (int)$id];
    }

    foreach ($created as [$kind, $id]) {
        if (isset($tables[$kind]) && (Craft::$app->getDb()->getTableSchema($tables[$kind]) !== null) && (new Query())->from($tables[$kind])->where(['id' => $id])->exists()) {
            $left[] = [$kind, $id];
        }
    }

    return $left;
}

function yamlSnapshot(): string
{
    $files = FileHelper::findFiles(Craft::$app->getPath()->getProjectConfigPath(), ['only' => ['*.yaml']]);
    sort($files);

    return hash('sha256', implode("\n", array_map(static fn(string $file): string => $file . ':' . hash_file('sha256', $file), $files)));
}

function check(bool $ok, string $what, mixed $errors = null): void
{
    if (!$ok) {
        fail("Could not $what: " . Json::encode($errors));
    }
}

function removeDirectory(string $directory): bool
{
    if (is_dir($directory)) {
        FileHelper::removeDirectory($directory);
    }

    return !is_dir($directory);
}

/**
 * @param array<string, mixed> $config
 */
function make(string $class, array $config): mixed
{
    return new $class($config);
}

function call(mixed $object, string $method): mixed
{
    return $object->$method();
}

function savedElement(mixed $element, string $what): Element
{
    check(Craft::$app->getElements()->saveElement($element), "save $what", $element->getErrors());

    return $element;
}

switch ($command) {
    case 'setup':
        if (is_file(STATE)) {
            fail('A previous run was not torn down. Run `php tests/cp/fixture.php teardown` first.');
        }

        $sites = $app->getSites();
        $primary = $sites->getPrimarySite();
        $second = array_values(array_filter($sites->getAllSites(), static fn($site) => !$site->primary))[0] ?? null;

        if ($second === null) {
            fail('The control panel test needs a second site.');
        }

        $state = ['config' => configSnapshot(), 'yaml' => yamlSnapshot(), 'created' => []];
        $record = static function(string $kind, int|string $id) use (&$state): void {
            $state['created'][] = [$kind, $id];
            save($state);
        };

        // A template every site renders the links with, and a public folder for files.
        $templatePath = $app->getPath()->getSiteTemplatesPath() . '/' . TEMPLATE . '.twig';
        check(!file_exists($templatePath), 'create the test template, which already exists');
        FileHelper::writeToFile($templatePath, <<<'TWIG'
{% set links = craft.app.plugins.getPlugin('smart-links').links %}
<!DOCTYPE html>
<html><body>
<ul id="smartlinks-cp-render">
{% for link in (entry ?? null).smartLinksCpLinks ?? [] %}
    <li data-type="{{ link.type }}">{{ links.html(link, entry.siteId) }}</li>
{% endfor %}
</ul>
</body></html>
TWIG);
        $record('file', $templatePath);
        $filesPath = Craft::getAlias('@webroot') . '/smart-links-cp-test';
        FileHelper::createDirectory($filesPath);
        $record('directory', $filesPath);

        $fs = new Local(['name' => 'Smart Links CP files', 'handle' => 'smartLinksCpFiles', 'path' => $filesPath, 'hasUrls' => true, 'url' => '@web/smart-links-cp-test']);
        check($app->getFs()->saveFilesystem($fs), 'save the filesystem', $fs->getErrors());
        $record('fs', $fs->handle);
        $volume = new Volume(['name' => 'Smart Links CP files', 'handle' => 'smartLinksCpFiles']);
        $volume->setFsHandle($fs->handle);
        check($app->getVolumes()->saveVolume($volume), 'save the volume', $volume->getErrors());
        $record('volume', (int)$volume->id);
        $record('fieldLayout', (int)$volume->getFieldLayout()->id);

        $group = new CategoryGroup(['name' => 'Smart Links CP topics', 'handle' => 'smartLinksCpTopics']);
        $group->setSiteSettings(array_map(static fn($site) => new CategoryGroup_SiteSettings(['siteId' => $site->id, 'hasUrls' => true, 'uriFormat' => 'smart-links-cp-topic/{slug}', 'template' => TEMPLATE]), $sites->getAllSites()));
        $group->setFieldLayout(new FieldLayout(['type' => Category::class]));
        check($app->getCategories()->saveGroup($group), 'save the category group', $group->getErrors());
        $record('categoryGroup', (int)$group->id);
        $record('fieldLayout', (int)$group->getFieldLayout()->id);
        $record('structure', (int)$group->structureId);

        $handles = SmartLinks::getInstance()->getLinkTypes()->getTypeSet()->handles();
        $fields = [];

        foreach ([
            'smartLinksCpLinks' => ['types' => $handles, 'translationMethod' => SmartLinkField::TRANSLATION_METHOD_SITE],
            'smartLinksCpOne' => ['types' => ['url', 'entry'], 'multiple' => false],
        ] as $handle => $settings) {
            $field = $app->getFields()->createField(['type' => SmartLinkField::class, 'name' => $handle, 'handle' => $handle] + $settings);
            check($app->getFields()->saveField($field), "save the field $handle", $field->getErrors());
            $record('field', (int)$field->id);
            $fields[$handle] = new CustomField($field, ['uid' => \craft\helpers\StringHelper::UUID()]);
        }

        // A Matrix field whose nested entries, edited inline as blocks, have a Smart Links field.
        $blockType = new EntryType(['name' => 'Smart Links CP block', 'handle' => 'smartLinksCpBlock', 'hasTitleField' => false, 'titleFormat' => 'Block']);
        $blockLayout = new FieldLayout(['type' => Entry::class]);
        $blockLayout->setTabs([['name' => 'Content', 'elements' => [new CustomField($app->getFields()->getFieldByHandle('smartLinksCpOne'))]]]);
        $blockType->setFieldLayout($blockLayout);
        check($app->getEntries()->saveEntryType($blockType), 'save the block entry type', $blockType->getErrors());
        $record('entryType', (int)$blockType->id);
        $record('fieldLayout', (int)$blockType->getFieldLayout()->id);
        $matrix = $app->getFields()->createField(['type' => \craft\fields\Matrix::class, 'name' => 'smartLinksCpBlocks', 'handle' => 'smartLinksCpBlocks', 'viewMode' => 'blocks', 'entryTypes' => [$blockType]]);
        check($app->getFields()->saveField($matrix), 'save the Matrix field', $matrix->getErrors());
        $record('field', (int)$matrix->id);
        $fields['smartLinksCpBlocks'] = new CustomField($matrix, ['uid' => \craft\helpers\StringHelper::UUID()]);

        $entryType = new EntryType(['name' => 'Smart Links CP page', 'handle' => 'smartLinksCpPage']);
        $layout = new FieldLayout(['type' => Entry::class]);
        $layout->setTabs([['name' => 'Content', 'elements' => array_merge([new EntryTitleField()], array_values($fields))]]);
        // Element cards show the links field's preview, as the control panel renders it.
        $layout->setCardView(['layoutElement:' . $fields['smartLinksCpLinks']->uid]);
        $entryType->setFieldLayout($layout);
        check($app->getEntries()->saveEntryType($entryType), 'save the entry type', $entryType->getErrors());
        $record('entryType', (int)$entryType->id);
        $record('fieldLayout', (int)$entryType->getFieldLayout()->id);

        $sections = [];

        foreach ([
            'smartLinksCpPages' => [$primary, $second],
            'smartLinksCpPrivate' => [$primary],
        ] as $handle => $sectionSites) {
            $section = new Section([
                'name' => $handle,
                'handle' => $handle,
                'type' => Section::TYPE_CHANNEL,
                'enableVersioning' => true,
                'propagationMethod' => PropagationMethod::All,
                'siteSettings' => array_map(static fn($site) => new Section_SiteSettings(['siteId' => $site->id, 'hasUrls' => true, 'uriFormat' => "$handle/{slug}", 'template' => TEMPLATE]), $sectionSites),
            ]);
            $section->setEntryTypes([$entryType]);
            check($app->getEntries()->saveSection($section), "save the section $handle", $section->getErrors());
            $record('section', (int)$section->id);
            $sections[$handle] = $section;
        }

        $entry = static function(string $section, string $title) use ($sections, $entryType, $primary, $record): Entry {
            $entry = new Entry(['sectionId' => $sections[$section]->id, 'typeId' => $entryType->id, 'siteId' => $primary->id, 'title' => $title, 'slug' => str_replace(' ', '-', strtolower($title))]);
            savedElement($entry, "the entry $title");
            $record('element', (int)$entry->id);

            return $entry;
        };

        $page = $entry('smartLinksCpPages', 'CP page');
        $graphqlEntry = $entry('smartLinksCpPages', 'CP graphql');
        $target = $entry('smartLinksCpPages', 'CP target');
        $doomed = $entry('smartLinksCpPages', 'CP doomed');
        $private = $entry('smartLinksCpPrivate', 'Private launch plan');
        $disabled = $entry('smartLinksCpPages', 'CP disabled secret');
        $disabled->enabled = false;
        savedElement($disabled, 'the disabled entry');

        $category = savedElement(new Category(['groupId' => $group->id, 'title' => 'CP topic', 'slug' => 'cp-topic', 'siteId' => $primary->id]), 'the category');
        $record('element', (int)$category->id);

        $temp = tempnam(sys_get_temp_dir(), 'smart-links-cp');
        file_put_contents($temp, '%PDF-1.4 test');
        $asset = new Asset();
        $asset->tempFilePath = $temp;
        $asset->setFilename('cp-brochure.pdf');
        $asset->newFolderId = $app->getAssets()->getRootFolderByVolumeId((int)$volume->id)?->id;
        $asset->volumeId = $volume->id;
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);
        savedElement($asset, 'the asset');
        $record('element', (int)$asset->id);

        $product = null;
        $commerce = $app->getPlugins()->getPlugin('commerce');
        $productType = null;

        // Commerce is named only as strings, so the test runs, and is analysed, without it.
        if ($commerce !== null) {
            $siteSettings = [];

            foreach ($sites->getAllSites() as $site) {
                $siteSettings[$site->id] = make('craft\\commerce\\models\\ProductTypeSite', ['siteId' => $site->id, 'hasUrls' => true, 'uriFormat' => 'smart-links-cp-shop/{slug}', 'template' => TEMPLATE]);
            }

            $productType = make('craft\\commerce\\models\\ProductType', ['name' => 'Smart Links CP goods', 'handle' => 'smartLinksCpGoods', 'hasDimensions' => false]);
            $productType->setSiteSettings($siteSettings);
            check(call($commerce, 'getProductTypes')->saveProductType($productType), 'save the product type', $productType->getErrors());
            $record('productType', (int)$productType->id);
            $record('fieldLayout', (int)$productType->fieldLayoutId);
            $record('fieldLayout', (int)$productType->variantFieldLayoutId);
            $product = make('craft\\commerce\\elements\\Product', ['typeId' => $productType->id, 'title' => 'CP mug', 'slug' => 'cp-mug', 'siteId' => $primary->id]);
            $product->setVariants([make('craft\\commerce\\elements\\Variant', ['sku' => 'SL-CP-' . strtoupper(bin2hex(random_bytes(3))), 'isDefault' => true, 'basePrice' => 10])]);
            savedElement($product, 'the product');
            $record('element', (int)$product->id);
        }

        // An author who may edit the test pages in the primary site, and nothing else.
        $author = new User(['username' => 'smartlinks-cp-author-' . bin2hex(random_bytes(3)), 'email' => 'smartlinks-cp-' . bin2hex(random_bytes(3)) . '@example.test', 'active' => true]);
        $authorId = null;

        if ($app->getElements()->saveElement($author)) {
            $authorId = (int)$author->id;
            $record('element', $authorId);
            $uid = $sections['smartLinksCpPages']->uid;
            $app->getUserPermissions()->saveUserPermissions($authorId, ['accessCp', 'accessSite', "viewEntries:$uid", "saveEntries:$uid", "viewPeerEntries:$uid", "savePeerEntries:$uid", 'editSite:' . $primary->uid]);
        }

        $admin = User::find()->admin()->status(null)->one() ?? fail('There is no admin user.');

        // A GraphQL schema that may read and save the test pages, and read what they link to, but
        // not the private section; and a token for it, for requests over HTTP.
        $scope = [
            'sites.' . $primary->uid . ':read',
            'sites.' . $second->uid . ':read',
            'sections.' . $sections['smartLinksCpPages']->uid . ':read',
            'sections.' . $sections['smartLinksCpPages']->uid . ':save',
            'entrytypes.' . $entryType->uid . ':read',
            'categorygroups.' . $group->uid . ':read',
            'volumes.' . $volume->uid . ':read',
            'usergroups.everyone:read',
        ];

        if ($productType !== null) {
            $scope[] = 'productTypes.' . $productType->uid . ':read';
        }

        $schema = new \craft\models\GqlSchema(['name' => 'Smart Links CP test', 'scope' => $scope]);
        check($app->getGql()->saveSchema($schema), 'save the GraphQL schema', $schema->getErrors());
        $record('gqlSchema', (int)$schema->id);
        $accessToken = bin2hex(random_bytes(16));
        $token = new \craft\models\GqlToken(['name' => 'Smart Links CP test', 'accessToken' => $accessToken, 'enabled' => true, 'schemaId' => $schema->id]);
        check($app->getGql()->saveToken($token), 'save the GraphQL token', $token->getErrors());
        $record('gqlToken', (int)$token->id);

        // Store the project config now; the run checks it was stored, so teardown can undo it.
        endRequest();
        $configStored = configSnapshot() !== $state['config'];

        echo Json::encode([
            'primarySite' => ['id' => (int)$primary->id, 'handle' => $primary->handle, 'name' => $primary->getName()],
            'secondSite' => ['id' => (int)$second->id, 'handle' => $second->handle, 'name' => $second->getName()],
            'handles' => $handles,
            'adminId' => (int)$admin->id,
            'authorId' => $authorId,
            'page' => ['id' => (int)$page->id, 'editUrl' => $page->getCpEditUrl(), 'url' => $page->getUrl()],
            'graphqlEntry' => ['id' => (int)$graphqlEntry->id],
            'gqlToken' => $accessToken,
            'privateEditSection' => 'smartLinksCpPrivate',
            'target' => ['id' => (int)$target->id, 'title' => $target->title, 'url' => $target->getUrl()],
            'doomed' => ['id' => (int)$doomed->id, 'title' => $doomed->title],
            'private' => ['id' => (int)$private->id, 'title' => $private->title],
            'disabled' => ['id' => (int)$disabled->id, 'title' => $disabled->title],
            'indexUrl' => UrlHelper::cpUrl('content/entries/smartLinksCpPages'),
            'category' => ['id' => (int)$category->id, 'title' => $category->title, 'url' => $category->getUrl()],
            'asset' => ['id' => (int)$asset->id, 'title' => $asset->title, 'url' => $asset->getUrl()],
            'product' => $product !== null ? ['id' => (int)$product->id, 'title' => $product->title, 'url' => $product->getUrl()] : null,
            'cpUrl' => UrlHelper::cpUrl(),
            'configStored' => $configStored,
            // The element index sources the picker opens on, for each element type.
            'sources' => [
                'entry' => 'section:' . $sections['smartLinksCpPages']->uid,
                'privateEntry' => 'section:' . $sections['smartLinksCpPrivate']->uid,
                'category' => 'group:' . $group->uid,
                'asset' => 'volume:' . $volume->uid,
                'user' => '*',
                'commerce-product' => $product !== null ? 'productType:' . $productType->uid : null,
            ],
        ]);
        break;

    case 'inspect':
        $id = (int)($argv[2] ?? 0);
        $site = $app->getSites()->getSiteByHandle($argv[3] ?? '') ?? fail('Unknown site.');
        $serializer = SmartLinks::getInstance()->getLinks()->getSerializer();
        $describe = static function(?Entry $entry) use ($serializer): ?array {
            if ($entry === null) {
                return null;
            }

            $values = [];

            foreach (['smartLinksCpLinks', 'smartLinksCpOne'] as $handle) {
                $value = $entry->getFieldValue($handle);
                $values[$handle] = $value instanceof \Tahadudhiya\SmartLinks\models\LinkCollection ? $serializer->serialize($value) : ['invalid' => true];
            }

            $values['relations'] = array_map('intval', (new Query())->select('targetId')->from(Table::RELATIONS)->where(['sourceId' => $entry->id, 'sourceSiteId' => $entry->siteId])->orderBy(['fieldId' => SORT_ASC, 'sortOrder' => SORT_ASC])->column());

            return $values;
        };

        echo Json::encode([
            'canonical' => $describe(Entry::find()->id($id)->siteId($site->id)->status(null)->one()),
            // Its Matrix field's nested entries, each with its own single link.
            'nested' => array_map(
                static fn(Entry $nested): mixed => ($value = $nested->getFieldValue('smartLinksCpOne')) instanceof \Tahadudhiya\SmartLinks\models\LinkCollection ? $serializer->serialize($value) : ['invalid' => true],
                Entry::find()->ownerId($id)->field('smartLinksCpBlocks')->siteId($site->id)->status(null)->all(),
            ),
            'provisional' => $describe(Entry::find()->draftOf($id)->provisionalDrafts()->siteId($site->id)->status(null)->one()),
            'revisions' => (int)Entry::find()->revisionOf($id)->siteId($site->id)->status(null)->count(),
        ]);
        break;

    case 'delete':
        $element = $app->getElements()->getElementById((int)($argv[2] ?? 0)) ?? fail('No such element.');
        check($app->getElements()->deleteElement($element, true), 'delete the element');
        endRequest();
        echo Json::encode(['deleted' => (int)$element->id]);
        break;

    case 'impersonate':
        $user = User::find()->id((int)($argv[2] ?? 0))->status(null)->one() ?? fail('No such user.');
        $token = $app->getTokens()->createToken(['users/impersonate-with-token', ['userId' => $user->id, 'prevUserId' => $user->id]], 1, new DateTime('+1 hour'));
        check(is_string($token), 'create a sign-in token');
        endRequest();
        echo Json::encode(['url' => UrlHelper::urlWithToken(UrlHelper::cpUrl(), $token)]);
        break;

    case 'log-mark':
        $files = glob($app->getPath()->getLogPath() . '/*.log') ?: [];
        echo Json::encode(array_combine($files, array_map('filesize', $files)));
        break;

    case 'log-errors':
        $mark = Json::decode($argv[2] ?? '{}');
        $found = [];

        foreach (glob($app->getPath()->getLogPath() . '/*.log') ?: [] as $file) {
            $handle = fopen($file, 'r');
            fseek($handle, (int)($mark[$file] ?? 0));

            while (($line = fgets($handle)) !== false) {
                if (preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d \[\w+\.(ERROR|WARNING)\]/', $line)) {
                    $found[] = substr(trim($line), 0, 400);
                }
            }

            fclose($handle);
        }

        echo Json::encode($found);
        break;

    case 'teardown':
        if (!is_file(STATE)) {
            fail('There is nothing to tear down.');
        }

        $state = Json::decode((string)file_get_contents(STATE));
        $leftovers = [];

        // Undone in reverse, so nothing is deleted before what depends on it.
        foreach (array_reverse($state['created']) as [$kind, $id]) {
            try {
                $done = match ($kind) {
                    'element' => ($element = $app->getElements()->getElementById((int)$id, null, '*') ?? Entry::find()->id((int)$id)->status(null)->trashed(null)->drafts(null)->revisions(null)->one()) === null
                        || $app->getElements()->deleteElement($element, true),
                    'section' => ($section = $app->getEntries()->getSectionById((int)$id)) === null || $app->getEntries()->deleteSection($section),
                    'entryType' => ($entryType = $app->getEntries()->getEntryTypeById((int)$id)) === null || $app->getEntries()->deleteEntryType($entryType),
                    'field' => ($field = $app->getFields()->getFieldById((int)$id)) === null || $app->getFields()->deleteField($field),
                    'categoryGroup' => $app->getCategories()->getGroupById((int)$id) === null || $app->getCategories()->deleteGroupById((int)$id),
                    'volume' => ($volume = $app->getVolumes()->getVolumeById((int)$id)) === null || $app->getVolumes()->deleteVolume($volume),
                    'fs' => ($fs = $app->getFs()->getFilesystemByHandle((string)$id)) === null || $app->getFs()->removeFilesystem($fs),
                    'gqlToken' => $app->getGql()->getTokenById((int)$id) === null || $app->getGql()->deleteTokenById((int)$id),
                    'gqlSchema' => ($schema = $app->getGql()->getSchemaById((int)$id)) === null || $app->getGql()->deleteSchema($schema),
                    'productType' => ($commerce = $app->getPlugins()->getPlugin('commerce')) === null || call($commerce, 'getProductTypes')->deleteProductTypeById((int)$id),
                    'file' => !file_exists((string)$id) || unlink((string)$id),
                    'directory' => removeDirectory((string)$id),
                    // Removed with their owners, and purged below.
                    'fieldLayout', 'structure' => true,
                };
            } catch (Throwable $exception) {
                $done = false;
                fwrite(STDERR, "$kind $id: {$exception->getMessage()}\n");
            }

            if (!$done) {
                $leftovers[] = [$kind, $id];
            }
        }

        endRequest();

        // Craft soft-deletes these, keeping their rows until its garbage collection runs; the run
        // removes its own for good, through Craft's records, so it leaves nothing behind.
        $records = [
            'section' => \craft\records\Section::class,
            'entryType' => \craft\records\EntryType::class,
            'field' => \craft\records\Field::class,
            'categoryGroup' => \craft\records\CategoryGroup::class,
            'volume' => \craft\records\Volume::class,
            'fieldLayout' => \craft\records\FieldLayout::class,
            'structure' => \craft\records\Structure::class,
        ];

        foreach ($state['created'] as [$kind, $id]) {
            if (isset($records[$kind])) {
                $records[$kind]::findWithTrashed()->where(['id' => (int)$id])->one()?->delete();
            }
        }

        $result = [
            'leftovers' => array_merge($leftovers, remaining($state['created'])),
            'configRestored' => configSnapshot() === $state['config'],
            'yamlUnchanged' => yamlSnapshot() === $state['yaml'],
        ];
        $leftovers = $result['leftovers'];

        if ($leftovers === [] && $result['configRestored'] && $result['yamlUnchanged']) {
            unlink(STATE);
        }

        echo Json::encode($result);
        exit($leftovers === [] && $result['configRestored'] && $result['yamlUnchanged'] ? 0 : 1);

    default:
        fail('Usage: php fixture.php setup|inspect <id> <site>|delete <id>|impersonate <userId>|teardown');
}
