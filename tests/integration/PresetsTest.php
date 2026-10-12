<?php

namespace Tahadudhiya\SmartLinks\Tests\integration;

use Craft;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Json;
use craft\models\ReadOnlyProjectConfigData;
use craft\web\TemplateResponseBehavior;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SmartLinks\controllers\PresetsController;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\fields\LinkForm;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;
use Tahadudhiya\SmartLinks\migrations\Install;
use Tahadudhiya\SmartLinks\models\LinkAttributes;
use Tahadudhiya\SmartLinks\models\LinkCollection;
use Tahadudhiya\SmartLinks\models\LinkPreset;
use Tahadudhiya\SmartLinks\models\ValidationError;
use Tahadudhiya\SmartLinks\services\Presets;
use Tahadudhiya\SmartLinks\SmartLinks;
use Tahadudhiya\SmartLinks\Tests\_support\FieldFixture;
use Tahadudhiya\SmartLinks\Tests\_support\TestUser;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Link presets end to end: their definitions in project config, the built-in ones, what a valid
 * preset is, what a preset asks of the links made with it, how the editor is told about presets,
 * and managing them in the control panel, with its permission.
 *
 * Presets are applied to a link when an author chooses one, in the editor; that is proven in the
 * browser suite. Here: nothing on the server ever applies a preset to a link, so a link's values
 * are its own.
 */
final class PresetsTest extends TestCase
{
    use FieldFixture;

    /** URL, email and entry links, with the presets below allowed. */
    private const PRESET_LINKS = 'smartLinksTestPresetLinks';

    /** For URL links: opens in a new window, which it locks. */
    private const EXTERNAL = 'e1a2b3c4-d5e6-4f70-8a9b-0c1d2e3f4a5b';

    /** For any link: download on, locked; a class. */
    private const DOWNLOAD = 'f2b3c4d5-e6f7-4a81-9b0c-1d2e3f4a5b6c';

    /** Allowed, but disabled. */
    private const RETIRED = 'a3c4d5e6-f7a8-4b92-8c1d-2e3f4a5b6c7d';

    protected static function usesTestTypes(): bool
    {
        return false;
    }

    protected static function extraLayoutFields(): array
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $projectConfig->set(Presets::CONFIG_KEY . '.' . self::EXTERNAL, ['name' => 'External', 'sortOrder' => 3, 'types' => ['url'], 'attributes' => ['target' => '_blank', 'rel' => ['external', 'noopener']], 'locked' => ['target']]);
        $projectConfig->set(Presets::CONFIG_KEY . '.' . self::DOWNLOAD, ['name' => 'Download', 'sortOrder' => 4, 'attributes' => ['download' => true, 'class' => ['file']], 'locked' => ['download']]);
        $projectConfig->set(Presets::CONFIG_KEY . '.' . self::RETIRED, ['name' => 'Retired', 'sortOrder' => 5, 'enabled' => false]);

        // Translated per site, so each site holds a value of its own.
        return [new CustomField(self::createField(self::PRESET_LINKS, ['types' => ['url', 'email', 'entry'], 'presets' => [self::EXTERNAL, self::DOWNLOAD, self::RETIRED], 'translationMethod' => SmartLinkField::TRANSLATION_METHOD_SITE]))];
    }

    public static function setUpBeforeClass(): void
    {
        self::setUpFixture();
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownFixture();
    }

    protected function tearDown(): void
    {
        $this->restoreConsoleRequest();
        Craft::$app->getUser()->setIdentity(null);
        Craft::$app->getConfig()->getGeneral()->allowAdminChanges = true;
        self::resetPluginServices();
    }

    private static function presets(): Presets
    {
        return SmartLinks::getInstance()->getPresets();
    }

    /**
     * The presets' UIDs, in the order they are offered, as project config holds them now.
     *
     * @return list<string>
     */
    private static function order(): array
    {
        return array_keys(self::presets()->getAllPresets());
    }

    /**
     * Runs a test's own preset changes, and takes them back afterwards.
     */
    private static function withPresets(callable $test): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $before = $projectConfig->get(Presets::CONFIG_KEY);

        try {
            $test();
        } finally {
            $projectConfig->set(Presets::CONFIG_KEY, $before);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, list<string>>
     */
    private static function errorsFor(array $input): array
    {
        // Everything the form posts, with nothing set but a name.
        $preset = self::presets()->presetFromInput($input + ['name' => 'Valid name', 'enabled' => '1', 'types' => '', 'urlSuffix' => '', 'attributes' => '', 'locked' => '']);
        self::assertFalse(self::presets()->savePreset($preset));

        return $preset->getErrors();
    }

    /**
     * @return list<array{string, Code}>
     */
    private static function problems(Entry $entry): array
    {
        $value = $entry->getFieldValue(self::PRESET_LINKS);
        self::assertInstanceOf(LinkCollection::class, $value);
        $field = $entry->getFieldLayout()?->getFieldByHandle(self::PRESET_LINKS);
        self::assertInstanceOf(SmartLinkField::class, $field);

        return array_map(static fn(ValidationError $error): array => [$error->path, $error->code], $field->linkProblems($value));
    }

    /**
     * @param array<string, mixed> $link
     */
    private static function entryWith(array $link): Entry
    {
        $entry = new Entry();
        $entry->sectionId = (int)self::$section->id;
        $entry->typeId = (int)self::$entryType->id;
        $entry->siteId = self::$primarySiteId;
        $entry->title = 'Presets';
        $entry->setFieldValue(self::PRESET_LINKS, self::entered([$link]));

        return $entry;
    }

    /**
     * The template a page response renders, and its variables.
     *
     * @return array{string, array<string, mixed>}
     */
    private static function page(Response $response): array
    {
        $behavior = $response->getBehavior(TemplateResponseBehavior::NAME);
        self::assertInstanceOf(TemplateResponseBehavior::class, $behavior);

        return [$behavior->template, $behavior->variables];
    }

    private function manager(): TestUser
    {
        return $this->signIn(false, ['accessCp', 'accessPlugin-smart-links', SmartLinks::PERMISSION_MANAGE_PRESETS]);
    }

    // Definitions in project config

    public function testPresetsAreReadFromProjectConfigInTheirOrder(): void
    {
        $presets = self::presets()->getAllPresets();

        self::assertSame([self::PRIMARY, self::SECONDARY, self::EXTERNAL, self::DOWNLOAD, self::RETIRED], array_keys($presets));
        self::assertSame('Primary CTA', $presets[self::PRIMARY]->name);
        self::assertTrue($presets[self::PRIMARY]->enabled);
        self::assertFalse($presets[self::RETIRED]->enabled);
        self::assertSame(['url'], $presets[self::EXTERNAL]->types);
        self::assertEquals(new LinkAttributes(target: '_blank', rel: ['external', 'noopener']), $presets[self::EXTERNAL]->linkAttributes);
        self::assertSame(['target'], $presets[self::EXTERNAL]->locked);
        self::assertNull(self::presets()->getPresetByUid(self::GONE));
    }

    /**
     * Each definition is valid but for one thing, which the refusal names.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function malformedPresetDefinitions(): array
    {
        $valid = ['name' => 'Valid', 'sortOrder' => 50];

        return [
            'a definition that is not one' => ['X', 'must be a preset definition'],
            'an unknown preset property' => [$valid + ['defaults' => ['target' => '_blank']], 'properties no preset has: defaults'],
            'no name' => [['sortOrder' => 50], 'needs a name'],
            'an empty name' => [['name' => '  ', 'sortOrder' => 50], 'needs a name'],
            'the name of another preset, in other letters' => [['name' => 'PRIMARY cta', 'sortOrder' => 50], 'has the name of'],
            'enabled that is not a boolean' => [$valid + ['enabled' => 'yes'], '.enabled'],
            'enabled as a number' => [$valid + ['enabled' => 1], '.enabled'],
            'no sort order' => [['name' => 'Valid'], '.sortOrder'],
            'a sort order that is text' => [['name' => 'Valid', 'sortOrder' => '1'], '.sortOrder'],
            'a sort order below 1' => [['name' => 'Valid', 'sortOrder' => 0], '.sortOrder'],
            'the place of another preset' => [['name' => 'Valid', 'sortOrder' => 1], 'place of another preset'],
            'types that are not handles' => [$valid + ['types' => ['Not A Handle']], '.types'],
            'a type named twice' => [$valid + ['types' => ['url', 'url']], '.types'],
            'types that are not a list' => [$valid + ['types' => ['a' => 'url']], '.types'],
            'a URL suffix that is not one' => [$valid + ['urlSuffix' => 'utm=1'], '.urlSuffix'],
            'a URL suffix that is not text' => [$valid + ['urlSuffix' => 5], '.urlSuffix'],
            'attributes not in the stored form' => [$valid + ['attributes' => ['download' => false]], '.attributes'],
            'custom attributes as a map' => [$valid + ['attributes' => ['custom' => ['data-x' => 'y']]], '.attributes'],
            'attributes that break the link rules' => [$valid + ['attributes' => ['target' => 'two words']], '.attributes'],
            'an attribute no link has' => [$valid + ['attributes' => ['onclick' => 'x']], '.attributes'],
            'an attribute that describes one link' => [$valid + ['attributes' => ['title' => 'Read more']], '.attributes'],
            'a download filename' => [$valid + ['attributes' => ['download' => true, 'downloadFilename' => 'a.pdf']], '.attributes'],
            'a lock on what cannot be locked' => [$valid + ['locked' => ['custom']], '.locked'],
            'a lock on no feature at all' => [$valid + ['locked' => ['href']], '.locked'],
            'a lock named twice' => [$valid + ['locked' => ['target', 'target']], '.locked'],
        ];
    }

    #[DataProvider('malformedPresetDefinitions')]
    public function testAMalformedDefinitionIsRefusedNotPartlyRead(mixed $definition, string $named): void
    {
        $uid = '9d6b2e4f-5c7a-4b1d-8f0e-4a5b6c7d8e9f';

        self::withPresets(function() use ($uid, $definition, $named): void {
            Craft::$app->getProjectConfig()->set(Presets::CONFIG_KEY . ".$uid", $definition);

            try {
                self::presets()->getAllPresets();
                self::fail('A malformed preset definition was read.');
            } catch (InvalidConfigException $exception) {
                self::assertStringContainsString($uid, $exception->getMessage());
                self::assertStringContainsString($named, $exception->getMessage());
            }
        });
    }

    public function testADefinitionValidOnItsOwnIsReadEvenIfItsTypesAreNotRegistered(): void
    {
        // Link types come and go with plugins; links made with the preset must stay readable, and
        // the problem is reported where it can be fixed: saving it, and on the presets page.
        $uid = '9d6b2e4f-5c7a-4b1d-8f0e-4a5b6c7d8e9f';

        self::withPresets(function() use ($uid): void {
            Craft::$app->getProjectConfig()->set(Presets::CONFIG_KEY . ".$uid", ['name' => 'Carrier pigeon', 'sortOrder' => 50, 'types' => ['carrier-pigeon', 'email'], 'attributes' => ['target' => '_blank']]);
            $preset = self::presets()->getPresetByUid($uid);
            self::assertNotNull($preset);
            self::assertFalse($preset->validate());
            self::assertSame(['“carrier-pigeon” is not an available link type.', 'Email links have no “target”, which this preset sets.'], $preset->getErrors('types'));

            [, $index] = self::page($this->runControllerAction(PresetsController::class, 'presets', 'index', [], $this->manager(), method: 'GET', json: false));
            self::assertSame(['“carrier-pigeon” is not an available link type.', 'Email links have no “target”, which this preset sets.'], $index['problems'][$uid] ?? null);
        });
    }

    public function testAPresetIsSavedToProjectConfigWithOnlyWhatItSets(): void
    {
        self::withPresets(function(): void {
            $preset = self::presets()->presetFromInput([
                'name' => 'Campaign',
                'enabled' => '1',
                'types' => ['url', 'entry'],
                'urlSuffix' => '?utm_source=site',
                'attributes' => [
                    'target' => '',
                    'rel' => 'Sponsored  nofollow',
                    'class' => '',
                    'download' => '',
                    // The table's rows, keyed as it posts them, with a blank one.
                    'custom' => ['row1' => ['name' => 'data-campaign', 'value' => 'autumn'], 'row2' => ['name' => '', 'value' => '']],
                ],
                'locked' => ['urlSuffix'],
            ]);

            self::assertTrue(self::presets()->savePreset($preset), Json::encode($preset->getErrors()));
            self::assertNotNull($preset->uid);

            self::assertSame([
                'attributes' => ['custom' => [['name' => 'data-campaign', 'value' => 'autumn']], 'rel' => ['sponsored', 'nofollow']],
                'enabled' => true,
                'locked' => ['urlSuffix'],
                'name' => 'Campaign',
                'sortOrder' => 6,
                'types' => ['url', 'entry'],
                'urlSuffix' => '?utm_source=site',
            ], Craft::$app->getProjectConfig()->get(Presets::CONFIG_KEY . ".{$preset->uid}"));

            // Read back exactly, last in order.
            $read = self::presets()->getAllPresets();
            self::assertSame($preset->uid, array_key_last($read));
            self::assertEquals(self::presets()->configFor($preset, 6), self::presets()->configFor($read[$preset->uid], 6));

            // Editing it keeps its UID and its place; turning everything off writes nothing for it.
            $edited = self::presets()->presetFromInput(['name' => 'Campaign links', 'enabled' => '', 'types' => '', 'urlSuffix' => '', 'attributes' => ['custom' => ''], 'locked' => ''], $read[$preset->uid]);
            self::assertTrue(self::presets()->savePreset($edited), Json::encode($edited->getErrors()));
            self::assertSame(['enabled' => false, 'name' => 'Campaign links', 'sortOrder' => 6], Craft::$app->getProjectConfig()->get(Presets::CONFIG_KEY . ".{$preset->uid}"));
            self::assertSame($preset->uid, array_key_last(self::presets()->getAllPresets()));
        });
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPresets(): array
    {
        return [
            'no name' => [['name' => '  '], 'name'],
            'the name of another preset, in other letters' => [['name' => 'primary cta'], 'name'],
            'an unregistered link type' => [['types' => ['url', 'carrier-pigeon']], 'types'],
            'a link type chosen twice' => [['types' => ['url', 'url']], 'types'],
            'types posted as something else' => [['types' => ['a' => ['url']]], 'types'],
            'a type without what the preset sets' => [['types' => ['email'], 'attributes' => ['target' => '_blank']], 'types'],
            'one of its types without what the preset sets' => [['types' => ['url', 'email'], 'attributes' => ['target' => '_blank']], 'types'],
            'a type without what the preset locks' => [['types' => ['tel'], 'locked' => ['download']], 'types'],
            'a URL suffix that is not one' => [['urlSuffix' => 'utm=1'], 'urlSuffix'],
            'a target that is not one' => [['attributes' => ['target' => 'two words']], 'target'],
            'a rel value that is not one' => [['attributes' => ['rel' => 'no/follow']], 'rel'],
            'a class named twice' => [['attributes' => ['class' => 'btn btn']], 'class'],
            'a custom attribute that is not data-* or aria-*' => [['attributes' => ['custom' => [['name' => 'onclick', 'value' => 'x']]]], 'custom'],
            'a custom attribute row without a name' => [['attributes' => ['custom' => [['name' => '', 'value' => 'x']]]], 'custom'],
            'download that is not on or off' => [['attributes' => ['download' => 'maybe']], 'download'],
            'enabled that is not on or off' => [['enabled' => 'maybe'], 'enabled'],
            'a lock on what cannot be locked' => [['locked' => ['custom']], 'locked'],
            'a lock named twice' => [['locked' => ['target', 'target']], 'locked'],
            // A setting not posted at all would otherwise clear what the preset has.
            'types left out' => [['types' => null], 'types'],
            'locks left out' => [['locked' => null], 'locked'],
            'the URL suffix left out' => [['urlSuffix' => null], 'urlSuffix'],
            'the defaults left out' => [['attributes' => null], 'linkAttributes'],
            'enabled left out' => [['enabled' => null], 'enabled'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('invalidPresets')]
    public function testAnInvalidPresetIsRefusedAtTheSettingItIsAbout(array $input, string $attribute): void
    {
        $before = Craft::$app->getProjectConfig()->get(Presets::CONFIG_KEY);
        $errors = self::errorsFor($input);

        self::assertArrayHasKey($attribute, $errors, Json::encode($errors));
        self::assertSame($before, Craft::$app->getProjectConfig()->get(Presets::CONFIG_KEY), 'An invalid preset was written.');
    }

    public function testWhatDescribesOneLinkCannotBeAPresetDefault(): void
    {
        // The form has no input for these; code could still try.
        $preset = new LinkPreset(['name' => 'One link', 'linkAttributes' => new LinkAttributes(title: 'Read more', id: 'cta', ariaLabel: 'Read more', download: true, downloadFilename: 'a.pdf')]);

        self::assertFalse($preset->validate());
        // The form has no setting for them, so they are reported with the defaults as a whole.
        self::assertSame([
            '“title” describes one link, so a preset can’t set it.',
            '“id” describes one link, so a preset can’t set it.',
            '“ariaLabel” describes one link, so a preset can’t set it.',
            '“downloadFilename” describes one link, so a preset can’t set it.',
        ], $preset->getErrors('linkAttributes'));
    }

    public function testPresetsAreReorderedAndDeleted(): void
    {
        self::withPresets(function(): void {
            $order = [self::RETIRED, self::PRIMARY, self::DOWNLOAD, self::SECONDARY, self::EXTERNAL];
            self::presets()->reorderPresets($order);
            self::assertSame($order, array_keys(self::presets()->getAllPresets()));

            try {
                self::presets()->reorderPresets([self::PRIMARY, self::SECONDARY]);
                self::fail('An order without every preset was applied.');
            } catch (\InvalidArgumentException) {
                self::assertSame($order, self::order());
            }

            $preset = self::presets()->getPresetByUid(self::SECONDARY);
            self::assertNotNull($preset);
            self::presets()->deletePreset($preset);
            self::assertNull(self::presets()->getPresetByUid(self::SECONDARY));
            self::assertNull(Craft::$app->getProjectConfig()->get(Presets::CONFIG_KEY . '.' . self::SECONDARY));
        });
    }

    // Built-in presets

    public function testTheBuiltInPresetsAreValidDefinitionsForTheBuiltInTypes(): void
    {
        $builtIn = Install::builtInPresets();

        self::assertSame(['Primary CTA', 'Secondary CTA', 'Text Link', 'External Link', 'Download Link', 'Social Link'], array_map(static fn(LinkPreset $preset): string => $preset->name, $builtIn));

        self::withPresets(function() use ($builtIn): void {
            Craft::$app->getProjectConfig()->remove(Presets::CONFIG_KEY);

            foreach ($builtIn as $preset) {
                self::assertTrue($preset->validate(), $preset->name . ': ' . Json::encode($preset->getErrors()));
                self::assertTrue(self::presets()->savePreset($preset, false));
            }

            // Real definitions: read back as saved, in order, and changeable like any other.
            $read = self::presets()->getAllPresets();
            self::assertSame(array_map(static fn(LinkPreset $preset): ?string => $preset->uid, $builtIn), array_keys($read));

            foreach ($builtIn as $preset) {
                self::assertEquals(self::presets()->configFor($preset), self::presets()->configFor($read[(string)$preset->uid]));
            }

            $download = $read[(string)$builtIn[4]->uid];
            self::assertSame(['asset', 'url'], $download->types);
            self::assertTrue($download->linkAttributes->download);
            self::assertSame(['download'], $download->locked);
        });
    }

    public function testAFreshInstallAddsTheBuiltInPresets(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        self::withPresets(function() use ($projectConfig): void {
            $projectConfig->remove(Presets::CONFIG_KEY);
            // No project config files say anything about Smart Links, as on a site that has
            // never had it: Craft's own cache of them is swapped for an empty one.
            (fn() => $this->_externalConfig = new ReadOnlyProjectConfigData([], $this))->call($projectConfig);

            try {
                (fn() => $this->installPresets())->call(new Install());
            } finally {
                (fn() => $this->_externalConfig = null)->call($projectConfig);
            }

            $installed = self::presets()->getAllPresets();
            self::assertSame(['Primary CTA', 'Secondary CTA', 'Text Link', 'External Link', 'Download Link', 'Social Link'], array_values(array_map(static fn(LinkPreset $preset): string => $preset->name, $installed)));

            foreach ($installed as $preset) {
                self::assertTrue($preset->validate(), $preset->name . ': ' . Json::encode($preset->getErrors()));
            }

            // Installing again, with them there, adds nothing.
            (fn() => $this->_externalConfig = new ReadOnlyProjectConfigData([], $this))->call($projectConfig);

            try {
                (fn() => $this->installPresets())->call(new Install());
            } finally {
                (fn() => $this->_externalConfig = null)->call($projectConfig);
            }

            self::assertSame(array_keys($installed), array_keys(self::presets()->getAllPresets()));
        });
    }

    public function testInstallingFromProjectConfigAddsNoPresetsOfItsOwn(): void
    {
        // The host project's config holds the plugin, as another environment's does when the
        // plugin is installed by applying it: the presets come with that config.
        self::assertNotNull(Craft::$app->getProjectConfig()->get('plugins.smart-links', true));

        self::withPresets(function(): void {
            Craft::$app->getProjectConfig()->remove(Presets::CONFIG_KEY);
            (fn() => $this->installPresets())->call(new Install());
            self::assertNull(Craft::$app->getProjectConfig()->get(Presets::CONFIG_KEY));
        });
    }

    // What a preset asks of links made with it

    public function testALinkOfATypeThePresetIsNotForIsRefused(): void
    {
        $entry = self::entryWith(['type' => 'email', 'data' => ['address' => 'hello@example.com'], 'presetUid' => self::EXTERNAL]);

        self::assertSame([['links[0].presetUid', Code::NOT_SUPPORTED]], self::problems($entry));
        self::assertFalse(Craft::$app->getElements()->saveElement($entry));
        self::assertSame(['Link 1: The “External” preset is not for Email links.'], $entry->getErrors(self::PRESET_LINKS));
    }

    /**
     * @return array<string, array{array<string, mixed>, list<array{string, Code}>}>
     */
    public static function lockCases(): array
    {
        $url = ['type' => 'url', 'data' => ['url' => 'https://example.com/']];

        return [
            'the locked value' => [$url + ['presetUid' => self::EXTERNAL, 'attributes' => ['target' => '_blank']], []],
            'what is not locked changed' => [$url + ['presetUid' => self::EXTERNAL, 'attributes' => ['target' => '_blank', 'rel' => 'nofollow', 'class' => 'x']], []],
            'a locked value changed' => [$url + ['presetUid' => self::EXTERNAL, 'attributes' => ['target' => '_top']], [['links[0].attributes.target', Code::INVALID]]],
            'a locked value left out' => [$url + ['presetUid' => self::EXTERNAL], [['links[0].attributes.target', Code::INVALID]]],
            'a locked flag turned off' => [$url + ['presetUid' => self::DOWNLOAD, 'attributes' => ['class' => 'file']], [['links[0].attributes.download', Code::INVALID]]],
            // An entry link has no download at all, so a lock on it does not apply.
            'a lock on what the type does not have' => [['type' => 'entry', 'data' => ['elementId' => 999999999], 'presetUid' => self::DOWNLOAD], []],
            'the same lock without a preset' => [$url + ['attributes' => ['target' => '_top']], []],
        ];
    }

    /**
     * @param array<string, mixed> $link
     * @param list<array{string, Code}> $expected
     */
    #[DataProvider('lockCases')]
    public function testALockedSettingMustStayAsThePresetSetsIt(array $link, array $expected): void
    {
        self::assertSame($expected, self::problems(self::entryWith($link)));
    }

    public function testALockComparesWordListsAsSets(): void
    {
        self::withPresets(function(): void {
            $preset = self::presets()->getPresetByUid(self::EXTERNAL);
            self::assertNotNull($preset);
            $preset->locked = ['target', 'rel'];
            self::assertTrue(self::presets()->savePreset($preset), Json::encode($preset->getErrors()));

            $url = ['type' => 'url', 'data' => ['url' => 'https://example.com/'], 'presetUid' => self::EXTERNAL];
            self::assertSame([], self::problems(self::entryWith($url + ['attributes' => ['target' => '_blank', 'rel' => 'noopener external']])));
            self::assertSame([['links[0].attributes.rel', Code::INVALID]], self::problems(self::entryWith($url + ['attributes' => ['target' => '_blank', 'rel' => 'noopener']])));
        });
    }

    // A preset's lifecycle: what links made with it, and new links, may do as it changes

    /**
     * Saves an entry as Craft does, and gives what its links field refused.
     *
     * @return list<string>
     */
    private static function refusals(Entry $entry): array
    {
        return Craft::$app->getElements()->saveElement($entry) ? [] : $entry->getErrors(self::PRESET_LINKS);
    }

    /**
     * What the editor posts for an entry's links, read as Craft reads a request.
     *
     * @param list<array<string, mixed>> $links Each with `uid`, `type`, `url`, and optionally
     * `presetUid`, `label` and `attributes`.
     */
    private static function posted(Entry $entry, array $links): void
    {
        $field = $entry->getFieldLayout()?->getFieldByHandle(self::PRESET_LINKS);
        self::assertInstanceOf(SmartLinkField::class, $field);
        $form = [];

        foreach ($links as $index => $link) {
            $form["link$index"] = array_filter([
                'uid' => $link['uid'] ?? '',
                'type' => $link['type'],
                'data' => [$link['type'] => $link['type'] === 'email' ? ['address' => 'hello@example.com'] : ['url' => $link['url'] ?? 'https://example.com/']],
                'label' => $link['label'] ?? '',
                'presetUid' => $link['presetUid'] ?? '',
                'attributes' => $link['attributes'] ?? [],
            ], static fn(mixed $value): bool => $value !== []);
        }

        $entry->setFieldValue(self::PRESET_LINKS, $field->normalizeValueFromRequest(['links' => $form], $entry));
    }

    private static function stored(Entry $entry): LinkCollection
    {
        $reloaded = Craft::$app->getEntries()->getEntryById((int)$entry->id, self::$primarySiteId);
        self::assertNotNull($reloaded);
        $value = $reloaded->getFieldValue(self::PRESET_LINKS);
        self::assertInstanceOf(LinkCollection::class, $value);

        return $value;
    }

    private static function setEnabled(string $uid, bool $enabled): void
    {
        Craft::$app->getProjectConfig()->set(Presets::CONFIG_KEY . ".$uid.enabled", $enabled);
    }

    /**
     * An entry saved with one link made with External, while External was enabled.
     *
     * @return array{Entry, string} The entry, and its link's UID.
     */
    private static function madeWithExternal(string $slug): array
    {
        $entry = self::savedEntry($slug, [self::PRESET_LINKS => [['type' => 'url', 'data' => ['url' => 'https://example.com/made'], 'presetUid' => self::EXTERNAL, 'attributes' => ['target' => '_blank']]]]);

        return [$entry, self::stored($entry)->links[0]->uid];
    }

    public function testAnEnabledPresetCanBeGivenToANewLink(): void
    {
        $entry = self::entryWith(['type' => 'url', 'data' => ['url' => 'https://example.com/'], 'presetUid' => self::EXTERNAL, 'attributes' => ['target' => '_blank']]);

        self::assertSame([], self::refusals($entry));
        self::assertSame(self::EXTERNAL, self::stored($entry)->links[0]->presetUid);
    }

    public function testADisabledPresetCannotBeGivenToALinkHoweverItArrives(): void
    {
        $refused = 'Link 1: The “Retired” preset is disabled, so no link can be given it. Links that already have it keep it.';

        // A new element's link, set in code.
        $entry = self::entryWith(['type' => 'url', 'data' => ['url' => 'https://example.com/'], 'presetUid' => self::RETIRED]);
        self::assertSame([$refused], self::refusals($entry));
        self::assertNull($entry->id);

        // A crafted form post: a new link with it, then a link that never had it given it.
        $saved = self::savedEntry('preset-forged', [self::PRESET_LINKS => [['type' => 'url', 'data' => ['url' => 'https://example.com/own']]]]);
        $uid = self::stored($saved)->links[0]->uid;

        self::posted($saved, [['type' => 'url', 'presetUid' => self::RETIRED]]);
        self::assertSame([$refused], self::refusals($saved));
        self::posted($saved, [['uid' => $uid, 'type' => 'url', 'url' => 'https://example.com/own', 'presetUid' => self::RETIRED]]);
        self::assertSame([$refused], self::refusals($saved));

        // Publishing validates as live content: refused there too.
        $saved->setScenario(Entry::SCENARIO_LIVE);
        self::assertFalse($saved->validate());

        self::assertNull(self::stored($saved)->links[0]->presetUid, 'A refused preset was stored.');
    }

    public function testALinkKeepsAPresetDisabledAfterItWasGiven(): void
    {
        self::withPresets(function(): void {
            [$entry, $uid] = self::madeWithExternal('preset-kept-disabled');
            self::setEnabled(self::EXTERNAL, false);

            // Read, rendered and shown as it was, with its preset named for what it is now.
            $value = self::stored($entry);
            self::assertSame(self::EXTERNAL, $value->links[0]->presetUid);
            self::assertSame('<a href="https://example.com/made" rel="noopener" target="_blank">https://example.com/made</a>', (string)SmartLinks::getInstance()->getLinks()->html($value->links[0], self::$primarySiteId));
            $reloaded = Craft::$app->getEntries()->getEntryById((int)$entry->id, self::$primarySiteId);
            self::assertNotNull($reloaded);
            $html = $reloaded->getFieldLayout()?->getFieldByHandle(self::PRESET_LINKS)?->getInputHtml($value, $reloaded) ?? '';
            self::assertStringContainsString('External (disabled)', $html);
            self::assertStringNotContainsString('is disabled, so', $html);

            // Edited without changing its preset.
            self::posted($reloaded, [['uid' => $uid, 'type' => 'url', 'url' => 'https://example.com/made', 'label' => 'Edited', 'presetUid' => self::EXTERNAL, 'attributes' => ['target' => '_blank']]]);
            self::assertSame([], self::refusals($reloaded));
            self::assertSame(['Edited', self::EXTERNAL], [self::stored($entry)->links[0]->label, self::stored($entry)->links[0]->presetUid]);

            // Craft's own copies keep it: a draft, applying it, and a duplicate.
            $draft = Craft::$app->getDrafts()->createDraft($reloaded);
            self::assertSame(self::EXTERNAL, $draft->getFieldValue(self::PRESET_LINKS)->links[0]->presetUid);
            Craft::$app->getDrafts()->applyDraft($draft);
            $duplicate = Craft::$app->getElements()->duplicateElement($reloaded);
            self::assertSame(self::EXTERNAL, $duplicate->getFieldValue(self::PRESET_LINKS)->links[0]->presetUid);

            // Another disabled preset is refused; an enabled one is taken.
            self::posted($reloaded, [['uid' => $uid, 'type' => 'url', 'url' => 'https://example.com/made', 'presetUid' => self::RETIRED]]);
            self::assertSame(['Link 1: The “Retired” preset is disabled, so no link can be given it. Links that already have it keep it.'], self::refusals($reloaded));
            self::posted($reloaded, [['uid' => $uid, 'type' => 'url', 'url' => 'https://example.com/made', 'presetUid' => self::DOWNLOAD, 'attributes' => ['download' => '1']]]);
            self::assertSame([], self::refusals($reloaded));
            self::assertSame(self::DOWNLOAD, self::stored($entry)->links[0]->presetUid);

            // Once the link has left it, it cannot be given it back while it is disabled.
            self::posted($reloaded, [['uid' => $uid, 'type' => 'url', 'url' => 'https://example.com/made', 'presetUid' => self::EXTERNAL, 'attributes' => ['target' => '_blank']]]);
            self::assertSame(['Link 1: The “External” preset is disabled, so no link can be given it. Links that already have it keep it.'], self::refusals($reloaded));

            // A second link in the same value is a new link.
            self::posted($reloaded, [
                ['uid' => $uid, 'type' => 'url', 'url' => 'https://example.com/made', 'presetUid' => self::DOWNLOAD, 'attributes' => ['download' => '1']],
                ['type' => 'url', 'presetUid' => self::EXTERNAL, 'attributes' => ['target' => '_blank']],
            ]);
            self::assertSame(['Link 2: The “External” preset is disabled, so no link can be given it. Links that already have it keep it.'], self::refusals($reloaded));
        });
    }

    public function testOnlyTheOccurrenceThatHadADisabledPresetKeepsIt(): void
    {
        self::withPresets(function(): void {
            $refused = ['Link 1: The “External” preset is disabled, so no link can be given it. Links that already have it keep it.'];
            $external = ['type' => 'url', 'url' => 'https://example.com/made', 'presetUid' => self::EXTERNAL, 'attributes' => ['target' => '_blank']];

            // A link given External in the primary site only, while it was enabled.
            $entry = self::savedEntry('preset-provenance');
            self::posted($entry, [$external]);
            self::assertSame([], self::refusals($entry));
            $uid = self::stored($entry)->links[0]->uid;
            $other = self::savedEntry('preset-provenance-other');
            self::setEnabled(self::EXTERNAL, false);

            // The same element in another site never had it, so cannot claim it by the UID.
            $second = Craft::$app->getEntries()->getEntryById((int)$entry->id, self::$secondSiteId);
            self::assertNotNull($second);
            self::assertSame([], iterator_to_array($second->getFieldValue(self::PRESET_LINKS)));
            self::posted($second, [['uid' => $uid] + $external]);
            self::assertSame($refused, self::refusals($second));

            // Nor can another element, with the UID it knows.
            self::posted($other, [['uid' => $uid] + $external]);
            self::assertSame($refused, self::refusals($other));

            // A draft of the element keeps it, for that occurrence only. Craft saves drafts with
            // essentials only (as its autosave does), and a new link is refused even then: were
            // it stored in the draft, the draft's value would vouch for it when published.
            $canonical = Craft::$app->getEntries()->getEntryById((int)$entry->id, self::$primarySiteId);
            self::assertNotNull($canonical);
            $draft = Craft::$app->getDrafts()->createDraft($canonical);
            self::assertSame(Entry::SCENARIO_ESSENTIALS, $draft->getScenario());
            self::posted($draft, [['uid' => $uid, 'label' => 'In the draft'] + $external]);
            self::assertSame([], self::refusals($draft));
            self::posted($draft, [['uid' => $uid] + $external, $external]);
            self::assertSame(['Link 2: The “External” preset is disabled, so no link can be given it. Links that already have it keep it.'], self::refusals($draft));
            $storedDraft = Entry::find()->id($draft->id)->drafts()->siteId(self::$primarySiteId)->status(null)->one();
            self::assertCount(1, $storedDraft?->getFieldValue(self::PRESET_LINKS) ?? []);

            // Publishing the draft, with what it holds, keeps the link's preset.
            $published = Craft::$app->getDrafts()->applyDraft($storedDraft);
            self::assertSame(['In the draft', self::EXTERNAL], [self::stored($published)->links[0]->label, self::stored($published)->links[0]->presetUid]);
            $canonical = Craft::$app->getEntries()->getEntryById((int)$entry->id, self::$primarySiteId);
            self::assertNotNull($canonical);

            // A revision, and reverting to it, keep what was stored.
            // A revision of the entry as it is now (forced: otherwise Craft gives the last one);
            // Craft returns the revision element's ID.
            $revision = Entry::find()->id(Craft::$app->getRevisions()->createRevision($canonical, force: true))->revisions()->siteId(self::$primarySiteId)->status(null)->one();
            self::assertNotNull($revision);
            self::assertSame(self::EXTERNAL, $revision->getFieldValue(self::PRESET_LINKS)->links[0]->presetUid);
            $creator = \craft\elements\User::find()->admin()->status(null)->one();
            self::assertNotNull($creator);
            Craft::$app->getRevisions()->revertToRevision($revision, (int)$creator->id);
            self::assertSame(self::EXTERNAL, self::stored($entry)->links[0]->presetUid);

            // A duplicate of the element is a copy of what was stored, which it keeps; a link
            // added to the copy is new.
            $duplicate = Craft::$app->getElements()->duplicateElement($canonical);
            self::posted($duplicate, [['uid' => $uid] + $external]);
            self::assertSame([], self::refusals($duplicate));
            self::posted($duplicate, [['uid' => $uid] + $external, $external]);
            self::assertSame(['Link 2: The “External” preset is disabled, so no link can be given it. Links that already have it keep it.'], self::refusals($duplicate));
        });
    }

    public function testARecreatedPresetDoesNotReviveAnOldUid(): void
    {
        self::withPresets(function(): void {
            [$entry] = self::madeWithExternal('preset-recreated');
            $external = self::presets()->getPresetByUid(self::EXTERNAL);
            self::assertNotNull($external);
            $definition = self::presets()->configFor($external, 3);
            self::presets()->deletePreset($external);

            // The same definition, saved again, is another preset.
            $again = self::presets()->presetFromInput(['name' => 'External', 'enabled' => '1', 'types' => ['url'], 'urlSuffix' => '', 'attributes' => ['target' => '_blank', 'rel' => 'external noopener'], 'locked' => ['target']]);
            self::assertTrue(self::presets()->savePreset($again), Json::encode($again->getErrors()));
            self::assertNotSame(self::EXTERNAL, $again->uid);
            self::assertSame($definition['attributes'], self::presets()->configFor($again)['attributes']);

            $reloaded = Craft::$app->getEntries()->getEntryById((int)$entry->id, self::$primarySiteId);
            self::assertNotNull($reloaded);
            self::assertSame([['links[0].presetUid', Code::INVALID]], self::problems($reloaded));
            self::assertSame(['Link 1: The preset this link was made with no longer exists.'], self::refusals($reloaded));
        });
    }

    public function testCopyingALinkWithADisabledPresetAndPastingItIsRefused(): void
    {
        self::withPresets(function(): void {
            [$entry, $uid] = self::madeWithExternal('preset-copied');
            self::setEnabled(self::EXTERNAL, false);
            $editor = self::editorFor($entry, self::PRESET_LINKS);

            // Copying is reading; it is the paste (and so a duplicate, which is both) that adds a
            // new link, all of it or none.
            $copy = $this->runFieldAction('copy', self::editorParams($editor) + ['links' => ['link0' => [
                'uid' => $uid, 'type' => 'url', 'data' => ['url' => ['url' => 'https://example.com/made']], 'presetUid' => self::EXTERNAL, 'attributes' => ['target' => '_blank'],
            ]]], self::author());
            self::assertSame(200, $copy->getStatusCode(), Json::encode($copy->data));
            $clipboard = Json::decode($copy->data['clipboard']);
            $clipboard['links'][] = ['type' => 'url', 'data' => ['url' => 'https://example.com/fine']];

            $paste = $this->runFieldAction('paste', self::editorParams(self::editorFor($entry, self::PRESET_LINKS)) + ['clipboard' => Json::encode($clipboard), 'count' => '1', 'operation' => '1'], self::author());
            self::assertSame(400, $paste->getStatusCode());
            self::assertStringContainsString('The “External” preset is disabled', Json::encode($paste->data, JSON_UNESCAPED_UNICODE));
            self::assertArrayNotHasKey('links', $paste->data);
        });
    }

    public function testFieldDefaultsAreTakenAsTheyAreNotFilledFromTheirPreset(): void
    {
        self::withPresets(function(): void {
            // The default link was authored with Primary CTA; Primary CTA now sets a class and a
            // target. New elements still start with the default link exactly as authored.
            Craft::$app->getProjectConfig()->set(Presets::CONFIG_KEY . '.' . self::PRIMARY . '.attributes', ['class' => ['cta'], 'target' => '_blank']);
            $field = Craft::$app->getFields()->getFieldByHandle(self::DEFAULTS);
            self::assertInstanceOf(SmartLinkField::class, $field);
            $defaults = $field->getDefaultValue();
            self::assertInstanceOf(LinkCollection::class, $defaults);
            self::assertSame(self::PRIMARY, $defaults->links[0]->presetUid);
            self::assertEquals(new LinkAttributes(), $defaults->links[0]->attributes);
        });
    }

    public function testAKeptDisabledPresetStillHoldsTheFieldsTypesAndLocks(): void
    {
        self::withPresets(function(): void {
            [$entry, $uid] = self::madeWithExternal('preset-kept-rules');
            self::setEnabled(self::EXTERNAL, false);
            $reloaded = Craft::$app->getEntries()->getEntryById((int)$entry->id, self::$primarySiteId);
            self::assertNotNull($reloaded);

            // Its locked target cannot be changed, nor left out.
            self::posted($reloaded, [['uid' => $uid, 'type' => 'url', 'url' => 'https://example.com/made', 'presetUid' => self::EXTERNAL, 'attributes' => ['target' => '_top']]]);
            self::assertSame(['Link 1: “target” is locked by the “External” preset, so it must stay as the preset sets it.'], self::refusals($reloaded));
            self::posted($reloaded, [['uid' => $uid, 'type' => 'url', 'url' => 'https://example.com/made', 'presetUid' => self::EXTERNAL]]);
            self::assertSame(['Link 1: “target” is locked by the “External” preset, so it must stay as the preset sets it.'], self::refusals($reloaded));

            // It is still only for URL links.
            self::posted($reloaded, [['uid' => $uid, 'type' => 'email', 'presetUid' => self::EXTERNAL]]);
            self::assertSame(['Link 1: The “External” preset is not for Email links.'], self::refusals($reloaded));

            // A disabled preset the field does not allow is refused as not allowed, first.
            self::setEnabled(self::SECONDARY, false);
            self::posted($reloaded, [['uid' => $uid, 'type' => 'url', 'url' => 'https://example.com/made', 'presetUid' => self::SECONDARY]]);
            self::assertSame(['Link 1: The “Secondary CTA” preset is not allowed in this field.'], self::refusals($reloaded));

            self::assertSame([self::EXTERNAL, '_blank'], [self::stored($entry)->links[0]->presetUid, self::stored($entry)->links[0]->attributes->target]);
        });
    }

    public function testDefaultLinksKeepADisabledPresetButNewElementsCannotTakeIt(): void
    {
        self::withPresets(function(): void {
            self::setEnabled(self::PRIMARY, false);
            $field = Craft::$app->getFields()->getFieldByHandle(self::DEFAULTS);
            self::assertInstanceOf(SmartLinkField::class, $field);

            // The field's own default links keep it: its settings still save.
            self::assertTrue($field->validate(['defaultLinks']), Json::encode($field->getErrors()));

            // A new default link cannot be given it.
            $copy = new SmartLinkField(['name' => 'Copy', 'handle' => 'smartLinksTestDefaultsCopy', 'uid' => $field->uid, 'types' => ['url', 'email'], 'presets' => [self::PRIMARY], 'defaultLinksInput' => ['links' => [
                ['type' => 'url', 'data' => ['url' => ['url' => 'https://example.com/new']], 'presetUid' => self::PRIMARY],
            ]]]);
            self::assertFalse($copy->validate(['defaultLinks']));
            self::assertStringContainsString('is disabled, so no link can be given it', Json::encode($copy->getErrors('defaultLinks')));

            // A new element starts with new copies of the default links, so they cannot have it:
            // the author is asked to choose another preset, rather than it being dropped.
            $entry = new Entry(['sectionId' => self::$section->id, 'typeId' => self::$entryType->id, 'siteId' => self::$primarySiteId, 'title' => 'From defaults']);
            self::assertFalse(Craft::$app->getElements()->saveElement($entry));
            self::assertStringContainsString('The “Primary CTA” preset is disabled', Json::encode($entry->getErrors(self::DEFAULTS)));
            self::assertSame(self::PRIMARY, $entry->getFieldValue(self::DEFAULTS)->links[0]->presetUid);
        });
    }

    public function testALinkWhosePresetIsDeletedIsKeptAndReported(): void
    {
        self::withPresets(function(): void {
            [$entry] = self::madeWithExternal('preset-deleted');
            $external = self::presets()->getPresetByUid(self::EXTERNAL);
            self::assertNotNull($external);
            self::presets()->deletePreset($external);

            $value = self::stored($entry);
            self::assertSame(self::EXTERNAL, $value->links[0]->presetUid, 'The preset UID was removed.');
            self::assertSame(['https://example.com/made', '_blank'], [$value->links[0]->data->toArray()['url'] ?? null, $value->links[0]->attributes->target]);
            self::assertNotNull(SmartLinks::getInstance()->getLinks()->html($value->links[0], self::$primarySiteId));
            self::assertSame([['links[0].presetUid', Code::INVALID]], self::problems(Craft::$app->getEntries()->getEntryById((int)$entry->id, self::$primarySiteId) ?? new Entry()));
        });
    }

    public function testADisabledPresetIsNotOfferedNorOnePresetForNoneOfTheFieldsTypes(): void
    {
        self::withPresets(function(): void {
            // A preset for social links only, which this field (URL, email, entry) does not offer.
            Craft::$app->getProjectConfig()->set(Presets::CONFIG_KEY . '.' . self::SECONDARY . '.types', ['social']);
            $entry = self::savedEntry('preset-offered');
            $editor = self::editorFor($entry, self::PRESET_LINKS);

            self::assertSame([self::EXTERNAL, self::DOWNLOAD], $editor->presets);
            self::assertSame([self::EXTERNAL, self::DOWNLOAD, self::RETIRED], $editor->allowedPresets);

            // And a field cannot allow a preset that is for none of its link types.
            $field = new SmartLinkField(['name' => 'Social only', 'handle' => 'smartLinksTestSocialOnly', 'types' => ['url'], 'presets' => [self::SECONDARY]]);
            self::assertFalse($field->validate(['presets']));
            self::assertSame(['The “Secondary CTA” preset is not for any link type this field allows.'], $field->getErrors('presets'));
        });
    }

    public function testNothingOnTheServerAppliesAPresetToALink(): void
    {
        self::withPresets(function(): void {
            // A link made with a preset, with nothing set: its value is what it holds, not the
            // preset's defaults, whether it is saved, read back or rendered.
            $entry = self::savedEntry('preset-precedence', [self::PRESET_LINKS => [
                ['type' => 'url', 'data' => ['url' => 'https://example.com/'], 'presetUid' => self::DOWNLOAD, 'attributes' => ['download' => '1']],
            ]]);
            $stored = (new \craft\db\Query())->select(['content'])->from(\craft\db\Table::ELEMENTS_SITES)->where(['elementId' => $entry->id, 'siteId' => self::$primarySiteId])->scalar();
            $field = $entry->getFieldLayout()?->getFieldByHandle(self::PRESET_LINKS);
            self::assertInstanceOf(SmartLinkField::class, $field);
            $content = Json::decode((string)$stored)[$field->layoutElement?->uid] ?? null;
            self::assertSame(['download' => true], $content['links'][0]['attributes'] ?? null);

            // Changing the preset changes no link made with it.
            $preset = self::presets()->getPresetByUid(self::DOWNLOAD);
            self::assertNotNull($preset);
            $preset->linkAttributes = new LinkAttributes(class: ['other'], download: true);
            self::assertTrue(self::presets()->savePreset($preset));

            $reloaded = Craft::$app->getEntries()->getEntryById((int)$entry->id, self::$primarySiteId);
            self::assertNotNull($reloaded);
            $link = $reloaded->getFieldValue(self::PRESET_LINKS)->links[0];
            self::assertSame([], $link->attributes->class);
            self::assertSame('<a href="https://example.com/" download>https://example.com/</a>', (string)SmartLinks::getInstance()->getLinks()->html($link, self::$primarySiteId));
        });
    }

    /**
     * @return array<string, array{array<string, mixed>, string|null}>
     */
    public static function pastes(): array
    {
        $url = ['type' => 'url', 'data' => ['url' => 'https://example.com/']];

        return [
            'an enabled preset, as it sets it' => [$url + ['presetUid' => self::EXTERNAL, 'attributes' => ['target' => '_blank']], null],
            'a locked setting changed' => [$url + ['presetUid' => self::EXTERNAL, 'attributes' => ['target' => '_top']], 'locked by the “External” preset'],
            'a disabled preset' => [$url + ['presetUid' => self::RETIRED], 'The “Retired” preset is disabled'],
            'a preset for other link types' => [['type' => 'email', 'data' => ['address' => 'a@example.com'], 'presetUid' => self::EXTERNAL], 'not for Email links'],
            'a preset the field does not allow' => [$url + ['presetUid' => self::PRIMARY], 'not allowed in this field'],
            'a preset that does not exist' => [$url + ['presetUid' => self::GONE], 'no longer exists'],
            'a UID that is not one' => [$url + ['presetUid' => 'primary'], 'not a valid UID'],
        ];
    }

    /**
     * @param array<string, mixed> $link
     */
    #[DataProvider('pastes')]
    public function testAPasteIsHeldToEveryPresetRule(array $link, ?string $refusal): void
    {
        $entry = self::savedEntry('preset-paste');
        $params = self::editorParams(self::editorFor($entry, self::PRESET_LINKS)) + ['clipboard' => Json::encode(['version' => 1, 'links' => [$link]]), 'count' => '0', 'operation' => '1'];

        $response = $this->runFieldAction('paste', $params, self::author());

        if ($refusal === null) {
            self::assertSame(200, $response->getStatusCode(), Json::encode($response->data));
        } else {
            self::assertSame(400, $response->getStatusCode());
            self::assertStringContainsString($refusal, Json::encode($response->data, JSON_UNESCAPED_UNICODE));
        }
    }

    // What the editor is told

    public function testTheEditorIsToldEachPresetAsInputsAndNothingMore(): void
    {
        $download = self::presets()->getPresetByUid(self::DOWNLOAD);
        $external = self::presets()->getPresetByUid(self::EXTERNAL);
        self::assertNotNull($download);
        self::assertNotNull($external);

        self::assertSame([
            'name' => 'External',
            'types' => ['url'],
            'values' => ['[attributes][target]' => '_blank', '[attributes][rel]' => 'external noopener'],
            'custom' => [],
            'locked' => ['[attributes][target]' => '_blank'],
        ], LinkForm::presetView($external));

        // A lock on a setting the preset leaves empty keeps it empty.
        $download->locked = ['download', 'urlSuffix'];
        $download->linkAttributes = new LinkAttributes(class: ['file'], download: true, custom: ['data-kind' => 'file']);
        self::assertSame([
            'name' => 'Download',
            'types' => [],
            'values' => ['[attributes][class]' => 'file', '[attributes][download]' => '1'],
            'custom' => [['name' => 'data-kind', 'value' => 'file']],
            'locked' => ['[urlSuffix]' => '', '[attributes][download]' => '1'],
        ], LinkForm::presetView($download));

        // Each named input is one the link form really has.
        $entry = self::savedEntry('preset-inputs');
        $html = $entry->getFieldLayout()?->getFieldByHandle(self::PRESET_LINKS)?->getInputHtml(self::entered([['type' => 'url', 'data' => ['url' => 'https://example.com/']]]), $entry) ?? '';

        foreach (['[urlSuffix]', '[attributes][target]', '[attributes][rel]', '[attributes][class]', '[attributes][download]'] as $suffix) {
            self::assertStringContainsString('[links][link0]' . $suffix . '"', $html, $suffix);
        }

        // The editor's settings carry every preset's view.
        $js = implode("\n", array_merge(...array_values(array_map('array_values', Craft::$app->getView()->js))));
        self::assertStringContainsString('"presets":{"' . self::PRIMARY . '":{"name":"Primary CTA"', $js);
    }

    // Managing presets in the control panel

    public function testThePermissionIsRegisteredAndGivesThePresetsPage(): void
    {
        $all = Craft::$app->getUserPermissions()->getAllPermissions();
        $smartLinks = array_values(array_filter($all, static fn(array $group): bool => isset($group['permissions'][SmartLinks::PERMISSION_MANAGE_PRESETS])));
        self::assertCount(1, $smartLinks);

        $this->signIn(false, ['accessCp', 'accessPlugin-smart-links']);
        self::assertArrayNotHasKey('subnav', SmartLinks::getInstance()->getCpNavItem() ?? []);

        $this->manager();
        self::assertSame(['overview', 'presets'], array_keys(SmartLinks::getInstance()->getCpNavItem()['subnav'] ?? []));
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function actions(): array
    {
        return [
            'the list' => ['index', 'GET', []],
            'the form' => ['edit', 'GET', []],
            'saving' => ['save', 'POST', ['name' => 'Sneaky']],
            'deleting' => ['delete', 'POST', ['id' => self::PRIMARY]],
            'reordering' => ['reorder', 'POST', ['ids' => '[]']],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('actions')]
    public function testEveryActionNeedsThePermission(string $action, string $method, array $params): void
    {
        $before = Craft::$app->getProjectConfig()->get(Presets::CONFIG_KEY);

        // Control panel access alone, and with access to the Smart Links section too.
        foreach ([['accessCp'], ['accessCp', 'accessPlugin-smart-links']] as $permissions) {
            $user = new TestUser();
            $user->grantedPermissions = $permissions;

            try {
                $this->runControllerAction(PresetsController::class, 'presets', $action, $params, $user, method: $method);
                self::fail('A user without the permission was served: ' . implode(', ', $permissions));
            } catch (ForbiddenHttpException) {
                self::assertSame($before, Craft::$app->getProjectConfig()->get(Presets::CONFIG_KEY));
            }
        }
    }

    public function testThePermissionIsWhatActionsNeedTheSectionGatesOnlyPages(): void
    {
        // Craft's “Access Smart Links” gates the section's pages, not action requests (proven over
        // HTTP by the control panel suite); “Manage link presets” is what every action needs.
        $user = new TestUser();
        $user->grantedPermissions = ['accessCp', SmartLinks::PERMISSION_MANAGE_PRESETS];
        $response = $this->runControllerAction(PresetsController::class, 'presets', 'reorder', ['ids' => Json::encode(array_keys(self::presets()->getAllPresets()))], $user);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testThePagesAreServedToAManager(): void
    {
        [$template, $index] = self::page($this->runControllerAction(PresetsController::class, 'presets', 'index', [], $this->manager(), method: 'GET', json: false));
        self::assertSame('smart-links/presets/_index', $template);
        self::assertFalse($index['readOnly']);

        [$template, $edit] = self::page($this->runControllerAction(PresetsController::class, 'presets', 'edit', ['presetUid' => self::EXTERNAL], $this->manager(), method: 'GET', json: false));
        self::assertSame('smart-links/presets/_edit', $template);
        self::assertSame(self::EXTERNAL, $edit['preset']->uid);

        self::assertFalse($edit['readOnly']);
        self::assertSame(LinkPreset::lockableSettingNames(), $edit['lockableSettings']);
        self::assertSame('Email', $edit['typeNames']['email'] ?? null);
        // The pages themselves are rendered by the real control panel, in the control panel suite.

        $this->expectException(NotFoundHttpException::class);
        $this->runControllerAction(PresetsController::class, 'presets', 'edit', ['presetUid' => self::GONE], $this->manager(), method: 'GET', json: false);
    }

    public function testAManagerSavesDeletesAndReordersPresets(): void
    {
        self::withPresets(function(): void {
            $response = $this->runControllerAction(PresetsController::class, 'presets', 'save', [
                'presetUid' => '',
                'name' => 'From the form',
                'enabled' => '1',
                'types' => ['url'],
                'urlSuffix' => '',
                'attributes' => ['target' => '_blank', 'rel' => '', 'class' => 'cta', 'download' => '', 'custom' => ''],
                'locked' => ['class'],
                // Not a preset setting: never read.
                'sortOrder' => '-5',
            ], $this->manager());

            self::assertSame(200, $response->getStatusCode(), Json::encode($response->data));
            $uid = $response->data['preset']['uid'] ?? null;
            self::assertIsString($uid);
            self::assertSame(['attributes' => ['class' => ['cta'], 'target' => '_blank'], 'enabled' => true, 'locked' => ['class'], 'name' => 'From the form', 'sortOrder' => 6, 'types' => ['url']], Craft::$app->getProjectConfig()->get(Presets::CONFIG_KEY . ".$uid"));

            $refused = $this->runControllerAction(PresetsController::class, 'presets', 'save', ['presetUid' => $uid, 'name' => 'From the form', 'types' => ['email'], 'attributes' => ['target' => '_blank']], $this->manager());
            self::assertSame(400, $refused->getStatusCode());
            self::assertArrayHasKey('types', $refused->data['errors'] ?? []);

            $order = array_reverse(array_keys(self::presets()->getAllPresets()));
            $reordered = $this->runControllerAction(PresetsController::class, 'presets', 'reorder', ['ids' => Json::encode($order)], $this->manager());
            self::assertSame(200, $reordered->getStatusCode());
            self::assertSame($order, array_keys(self::presets()->getAllPresets()));

            $deleted = $this->runControllerAction(PresetsController::class, 'presets', 'delete', ['id' => $uid], $this->manager());
            self::assertSame(200, $deleted->getStatusCode());
            self::assertNull(self::presets()->getPresetByUid($uid));
        });
    }

    public function testAnOrderThatIsNotEveryPresetIsRefused(): void
    {
        $before = array_keys(self::presets()->getAllPresets());

        foreach (['not json', Json::encode([self::PRIMARY]), Json::encode(['a' => self::PRIMARY])] as $ids) {
            try {
                $this->runControllerAction(PresetsController::class, 'presets', 'reorder', ['ids' => $ids], $this->manager());
                self::fail("The order $ids was applied.");
            } catch (BadRequestHttpException) {
                self::assertSame($before, array_keys(self::presets()->getAllPresets()));
            }
        }
    }

    public function testChangesNeedCsrfProtection(): void
    {
        $before = Craft::$app->getProjectConfig()->get(Presets::CONFIG_KEY);

        try {
            $this->runControllerAction(PresetsController::class, 'presets', 'save', ['name' => 'Forged'], $this->manager(), withCsrf: false);
            self::fail('A request without a CSRF token was served.');
        } catch (BadRequestHttpException) {
            self::assertSame($before, Craft::$app->getProjectConfig()->get(Presets::CONFIG_KEY));
        }
    }

    public function testWhereAdministrativeChangesAreOffPresetsCanBeViewedNotChanged(): void
    {
        Craft::$app->getConfig()->getGeneral()->allowAdminChanges = false;
        $before = Craft::$app->getProjectConfig()->get(Presets::CONFIG_KEY);

        [, $index] = self::page($this->runControllerAction(PresetsController::class, 'presets', 'index', [], $this->manager(), method: 'GET', json: false));
        self::assertTrue($index['readOnly']);
        [, $edit] = self::page($this->runControllerAction(PresetsController::class, 'presets', 'edit', ['presetUid' => self::EXTERNAL], $this->manager(), method: 'GET', json: false));
        self::assertTrue($edit['readOnly']);

        foreach ([['save', ['name' => 'Changed']], ['delete', ['id' => self::PRIMARY]], ['reorder', ['ids' => '[]']]] as [$action, $params]) {
            try {
                $this->runControllerAction(PresetsController::class, 'presets', $action, $params, $this->manager());
                self::fail("“{$action}” changed presets where administrative changes are off.");
            } catch (ForbiddenHttpException) {
                self::assertSame($before, Craft::$app->getProjectConfig()->get(Presets::CONFIG_KEY));
            }
        }
    }
}
