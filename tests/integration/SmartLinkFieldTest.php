<?php

namespace Tahadudhiya\SmartLinks\Tests\integration;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\Json;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\web\View;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\events\DefineLinkHtmlEvent;
use Tahadudhiya\SmartLinks\fields\LinkForm;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;
use Tahadudhiya\SmartLinks\models\InvalidLinkValue;
use Tahadudhiya\SmartLinks\models\LinkCollection;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ValidationError;
use Tahadudhiya\SmartLinks\services\Links;
use Tahadudhiya\SmartLinks\services\LinkTypes;
use Tahadudhiya\SmartLinks\services\Presets;
use Tahadudhiya\SmartLinks\SmartLinks;
use Tahadudhiya\SmartLinks\Tests\_support\FieldFixture;
use Tahadudhiya\SmartLinks\Tests\_support\linktypes\FakeResolver;
use Tahadudhiya\SmartLinks\Tests\_support\TestUser;
use yii\base\Event;
use yii\base\InvalidConfigException;

/**
 * The Smart Links field in real Craft content: registered through Craft's fields service,
 * configured and saved through it, placed in a real entry type's layout, and saved into real
 * entries in two sites, then reloaded from the database, edited, reordered, drafted, revised,
 * duplicated, copied between sites, queried and rendered. Copying and pasting links runs through
 * the real field controller.
 *
 * Everything is made in one rolled-back transaction (see {@see FieldFixture}). The field is used
 * with test link types, registered through the real registration event in place of the built-in
 * ones, because the field must work for any registered type; the built-in types are tested in
 * {@see LinkTypesTest}.
 */
class SmartLinkFieldTest extends TestCase
{
    use FieldFixture;

    private const UID_A = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';
    private const UID_B = '2b3c4d5e-6f7a-4b8c-9d0e-1f2a3b4c5d6e';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::setUpFixture();
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownFixture();
        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity(null);
        Event::off(Links::class, Links::EVENT_DEFINE_LINK_HTML);
        $this->restoreConsoleRequest();
        parent::tearDown();
    }

    // Helpers

    /**
     * @param array<string, mixed> $values Field values by handle.
     */
    private static function newEntry(string $slug, array $values = [], ?int $siteId = null): Entry
    {
        $entry = new Entry();
        $entry->sectionId = (int)self::$section->id;
        $entry->typeId = (int)self::$entryType->id;
        $entry->siteId = $siteId ?? self::$primarySiteId;
        $entry->title = $slug;
        $entry->slug = $slug;

        foreach ($values as $handle => $value) {
            // Links are set as code sets them: entered through the link core.
            $entry->setFieldValue($handle, is_array($value) ? self::entered($value) : $value);
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function saveNewEntry(string $slug, array $values = []): Entry
    {
        $entry = self::newEntry($slug, $values);
        self::assertTrue(Craft::$app->getElements()->saveElement($entry), Json::encode($entry->getErrors()));

        return $entry;
    }

    /**
     * The entry read back from the database, never the object that was saved.
     */
    private static function reload(Entry $entry, ?int $siteId = null): Entry
    {
        $reloaded = Entry::find()->id($entry->id)->siteId($siteId ?? $entry->siteId)->status(null)->drafts(null)->revisions(null)->one();
        self::assertInstanceOf(Entry::class, $reloaded);
        self::assertNotSame($entry, $reloaded);

        return $reloaded;
    }

    private static function links(Entry $entry, string $handle = self::LINKS): LinkCollection
    {
        $value = $entry->getFieldValue($handle);
        self::assertInstanceOf(LinkCollection::class, $value, $value instanceof InvalidLinkValue ? (new LinkValidationException($value->errors))->getMessage() : '');

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function uids(LinkCollection $links): array
    {
        return array_map(static fn(LinkValue $link): string => $link->uid, $links->links);
    }

    /**
     * @return list<string|null>
     */
    private static function labels(LinkCollection $links): array
    {
        return array_map(static fn(LinkValue $link): ?string => $link->label, $links->links);
    }

    /**
     * What Craft stored for a field instance, straight from `elements_sites.content`.
     */
    private static function storedContent(Entry $entry, string $handle, ?int $siteId = null): mixed
    {
        $content = (new Query())->select(['content'])->from(Table::ELEMENTS_SITES)->where(['elementId' => $entry->id, 'siteId' => $siteId ?? $entry->siteId])->scalar();
        $decoded = is_string($content) ? Json::decode($content) : [];

        return $decoded[self::$instances[$handle]->layoutElement->uid] ?? null;
    }

    /**
     * Writes a field instance's content directly, as another process or a broken import could.
     */
    private static function writeStoredContent(Entry $entry, string $handle, mixed $value): void
    {
        $content = Json::decode((string)(new Query())->select(['content'])->from(Table::ELEMENTS_SITES)->where(['elementId' => $entry->id, 'siteId' => $entry->siteId])->scalar());
        $content[self::$instances[$handle]->layoutElement->uid] = $value;
        // Yii encodes a JSON column's value itself.
        Craft::$app->getDb()->createCommand()->update(Table::ELEMENTS_SITES, ['content' => $content], ['elementId' => $entry->id, 'siteId' => $entry->siteId])->execute();
    }

    /**
     * @param array<mixed> $array
     * @return array<mixed>
     */
    private static function sortKeys(array $array): array
    {
        $array = array_map(static fn(mixed $item): mixed => is_array($item) ? self::sortKeys($item) : $item, $array);

        if (!array_is_list($array)) {
            ksort($array);
        }

        return $array;
    }

    /**
     * The attributes of the one anchor the HTML is, by name, as a browser parses them. Craft's
     * Html helper writes attributes in its own order, which has no meaning in HTML.
     *
     * @return array<string, string>
     */
    private static function anchor(string $html, string $text): array
    {
        $dom = self::parse($html);
        $anchors = $dom->getElementsByTagName('a');
        self::assertCount(1, $anchors);
        $anchor = $anchors->item(0);
        self::assertInstanceOf(\DOMElement::class, $anchor);
        self::assertSame($text, $anchor->textContent);
        $attributes = [];

        foreach ($anchor->attributes ?? [] as $attribute) {
            $attributes[$attribute->name] = $attribute->value;
        }

        ksort($attributes);

        return $attributes;
    }

    private static function parse(string $html): \DOMDocument
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');

        return $dom;
    }

    /**
     * As a browser parses the HTML: no element it should not have, and no event handler on any.
     */
    private static function assertNoInjectedMarkup(string $html): void
    {
        $dom = self::parse($html);

        foreach (['script', 'img', 'iframe', 'b', 'i'] as $tag) {
            self::assertSame(0, $dom->getElementsByTagName($tag)->length, "A <$tag> element was injected.");
        }

        foreach ($dom->getElementsByTagName('*') as $element) {
            foreach ($element->attributes ?? [] as $attribute) {
                self::assertStringStartsNotWith('on', $attribute->name, 'An event handler attribute was injected: ' . $attribute->name);
            }
        }
    }

    private static function field(string $handle = self::LINKS): SmartLinkField
    {
        return self::$instances[$handle];
    }

    /**
     * The field as an entry's own layout has it, which validation uses.
     */
    private static function layoutField(Entry $entry, string $handle): SmartLinkField
    {
        $field = $entry->getFieldLayout()?->getFieldByHandle($handle);
        self::assertInstanceOf(SmartLinkField::class, $field);

        return $field;
    }

    /**
     * Three links as an author enters them: one of each type.
     *
     * @return list<array<string, mixed>>
     */
    private static function threeLinks(): array
    {
        return [
            ['type' => 'url', 'data' => ['url' => 'HTTPS://Example.com/About'], 'label' => 'About', 'attributes' => ['target' => '_blank', 'rel' => 'NoFollow', 'class' => 'btn btn-primary', 'custom' => ['data-track' => 'cta']]],
            ['type' => 'entry', 'data' => ['elementId' => '12', 'siteId' => '1'], 'urlSuffix' => '#team'],
            ['type' => 'email', 'data' => ['address' => 'hello@example.com'], 'label' => 'Email us'],
        ];
    }

    /**
     * A URL link with every property set, as an author enters it.
     *
     * @return array<string, mixed>
     */
    private static function fullLink(): array
    {
        return [
            'type' => 'url',
            'data' => ['url' => 'https://example.com/über/a.pdf'],
            'label' => 'Ünïcödé — 日本語 ✓',
            'urlSuffix' => '?ref=nav#top',
            'presetUid' => self::PRIMARY,
            'attributes' => [
                'target' => '_blank',
                'rel' => 'nofollow sponsored',
                'title' => 'Download the “report”',
                'class' => 'btn btn--primary md:px-4',
                'id' => 'report-link',
                'ariaLabel' => 'Download the annual report',
                'download' => '1',
                'downloadFilename' => 'report.pdf',
                'custom' => ['data-track' => 'cta', 'aria-describedby' => 'note', 'data-empty' => ''],
            ],
        ];
    }

    /**
     * What the field's form posts for links, the way the link editor names its inputs.
     *
     * @param list<array<string, mixed>> $links Each link's type, data, and other inputs.
     * @return array<string, mixed>
     */
    private static function post(array $links): array
    {
        $posted = [];

        foreach ($links as $index => $link) {
            $type = $link['type'];
            // Every offered type's inputs are posted; only the chosen type's are the link's.
            $data = ['url' => ['url' => ''], 'entry' => ['elementId' => '', 'siteId' => ''], 'email' => ['address' => '']];
            $data[$type] = $link['data'];
            $posted["link$index"] = ['uid' => $link['uid'] ?? '', 'type' => $type, 'data' => $data, 'label' => $link['label'] ?? '', 'presetUid' => $link['presetUid'] ?? '']
                + ['urlSuffix' => $link['urlSuffix'] ?? '']
                + ['attributes' => $link['attributes'] ?? ['target' => '', 'rel' => '', 'download' => '']];
        }

        return ['links' => $posted];
    }

    private static ?Entry $target = null;

    /**
     * The entry whose editors links are copied from and pasted into.
     */
    private static function target(): Entry
    {
        return self::$target ??= self::savedEntry('paste-target');
    }

    /**
     * @return array{context: string, destination: string}
     */
    private static function editorOf(string $handle): array
    {
        return self::editorParams(self::editorFor(self::target(), $handle));
    }

    /**
     * @param list<array<string, mixed>> $links Links in the stored form, without UIDs.
     */
    private static function clipboard(array $links): string
    {
        return Json::encode(['version' => 1, 'links' => $links]);
    }

    private function editor(): TestUser
    {
        return self::author();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function paste(string $handle, string $clipboard, array $params = []): array
    {
        $response = $this->runFieldAction('paste', $params + self::editorOf($handle) + ['clipboard' => $clipboard, 'count' => '0', 'operation' => '5'], $this->editor());
        $data = $response->data;
        self::assertIsArray($data);

        return ['status' => $response->getStatusCode()] + $data;
    }

    // Registration

    public function testCraftsFieldsServiceRegistersTheFieldType(): void
    {
        // Craft lists only its own field types itself; every other one comes from its event.
        self::assertContains(SmartLinkField::class, Craft::$app->getFields()->getAllFieldTypes());
        self::assertSame('Smart Links', SmartLinkField::displayName());
    }

    public function testAFieldIsCreatedSavedAndLoadedAgainThroughCraft(): void
    {
        $fields = Craft::$app->getFields();
        // Types and presets in an order of the author's own, not the order they are registered in.
        $field = $fields->createField(['type' => SmartLinkField::class, 'name' => 'Created', 'handle' => 'smartLinksTestCreated', 'types' => ['email', 'url'], 'presets' => [self::SECONDARY, self::PRIMARY], 'minLinks' => 1, 'maxLinks' => 2]);
        self::assertInstanceOf(SmartLinkField::class, $field);
        self::assertTrue($fields->saveField($field), Json::encode($field->getErrors()));

        // A fresh fields service reads the field from the database, not from this object.
        $original = $fields;
        Craft::$app->set('fields', Craft::$app->getComponents()['fields']);

        try {
            $loaded = Craft::$app->getFields()->getFieldById((int)$field->id);
            self::assertInstanceOf(SmartLinkField::class, $loaded);
            self::assertNotSame($field, $loaded);
            self::assertSame(['email', 'url'], $loaded->types);
            self::assertSame([self::SECONDARY, self::PRIMARY], $loaded->presets);
            self::assertSame(1, $loaded->minLinks);
            self::assertSame(2, $loaded->maxLinks);
            self::assertTrue($loaded->multiple);
        } finally {
            Craft::$app->set('fields', $original);
        }
    }

    public function testTheTypeRegistryIsBuiltOnceARequest(): void
    {
        $service = SmartLinks::getInstance()->getLinkTypes();

        self::assertSame($service->getTypeSet(), $service->getTypeSet());
    }

    public function testARegisteredClassThatIsNotALinkTypeIsRefused(): void
    {
        $handler = static function(RegisterComponentTypesEvent $event): void {
            $event->types[] = \stdClass::class;
        };

        Event::on(LinkTypes::class, LinkTypes::EVENT_REGISTER_LINK_TYPES, $handler);

        try {
            (new LinkTypes())->getTypeSet();
            self::fail('A class that is not a link type was registered.');
        } catch (InvalidConfigException $exception) {
            self::assertStringContainsString('LinkTypeInterface', $exception->getMessage());
        } finally {
            Event::off(LinkTypes::class, LinkTypes::EVENT_REGISTER_LINK_TYPES, $handler);
        }
    }

    // Presets

    public function testPresetsAreReadFromProjectConfig(): void
    {
        $presets = SmartLinks::getInstance()->getPresets()->getAllPresets();

        self::assertSame([self::PRIMARY, self::SECONDARY], array_keys($presets));
        self::assertSame('Primary CTA', $presets[self::PRIMARY]->name);
        self::assertNull(SmartLinks::getInstance()->getPresets()->getPresetByUid(self::GONE));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedPresetDefinitions(): array
    {
        return [
            'an unknown preset property' => [['name' => 'X', 'defaults' => ['target' => '_blank']]],
            'no name' => [['label' => 'X']],
            'an empty name' => [['name' => '  ']],
            'a definition that is not one' => ['X'],
        ];
    }

    #[DataProvider('malformedPresetDefinitions')]
    public function testAMalformedPresetDefinitionIsRefusedNotPartlyRead(mixed $definition): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $uid = '9d6b2e4f-5c7a-4b1d-8f0e-4a5b6c7d8e9f';
        $projectConfig->set(Presets::CONFIG_KEY . ".$uid", $definition);

        try {
            SmartLinks::getInstance()->getPresets()->getAllPresets();
            self::fail('A malformed preset definition was read.');
        } catch (InvalidConfigException $exception) {
            self::assertStringContainsString($uid, $exception->getMessage());
        } finally {
            $projectConfig->remove(Presets::CONFIG_KEY . ".$uid");
        }
    }

    /**
     * @return array<string, array{string|null, list<array{string, Code}>}>
     */
    public static function presetCases(): array
    {
        return [
            'no preset' => [null, []],
            'a preset that exists and is allowed' => [self::PRIMARY, []],
            'a preset that exists but is not allowed' => [self::SECONDARY, [['links[0].presetUid', Code::NOT_SUPPORTED]]],
            'a preset that no longer exists' => [self::GONE, [['links[0].presetUid', Code::INVALID]]],
        ];
    }

    /**
     * @param list<array{string, Code}> $expected
     */
    #[DataProvider('presetCases')]
    public function testEachLinksPresetIsCheckedAgainstTheField(?string $presetUid, array $expected): void
    {
        $entry = self::newEntry('preset-' . ($presetUid ?? 'none'), [self::LINKS => [['type' => 'url', 'data' => ['url' => 'https://example.com/'], 'presetUid' => $presetUid]]]);
        $value = self::links($entry);

        self::assertSame($presetUid, $value->links[0]->presetUid);
        self::assertSame($expected, array_map(static fn(ValidationError $error): array => [$error->path, $error->code], self::field()->linkProblems($value)));
        self::assertSame($expected === [], Craft::$app->getElements()->saveElement($entry));

        if ($expected !== []) {
            // Reported, never removed: the link still has the preset it was made with.
            self::assertCount(1, $entry->getErrors(self::LINKS));
            self::assertSame($presetUid, self::links($entry)->links[0]->presetUid);
        }
    }

    public function testThePresetIsKeptThroughSaveReloadAndEdit(): void
    {
        $entry = self::saveNewEntry('preset-kept', [self::LINKS => [['type' => 'url', 'data' => ['url' => 'https://example.com/'], 'presetUid' => self::PRIMARY]]]);
        $reloaded = self::reload($entry);
        self::assertSame(self::PRIMARY, self::links($reloaded)->links[0]->presetUid);

        $posted = self::field()->normalizeValueFromRequest(self::post([['uid' => self::uids(self::links($reloaded))[0], 'type' => 'url', 'data' => ['url' => 'https://example.com/'], 'label' => 'Edited', 'presetUid' => self::PRIMARY]]), $reloaded);
        $reloaded->setFieldValue(self::LINKS, $posted);
        self::assertTrue(Craft::$app->getElements()->saveElement($reloaded), Json::encode($reloaded->getErrors()));

        self::assertSame(self::PRIMARY, self::links(self::reload($entry))->links[0]->presetUid);
        self::assertSame(self::PRIMARY, self::storedContent($entry, self::LINKS)['links'][0]['presetUid']);
    }

    public function testTheEditorOffersAllowedPresetsAndShowsOthersForWhatTheyAre(): void
    {
        $entry = self::newEntry('preset-editor', [self::LINKS => [
            ['type' => 'url', 'data' => ['url' => 'https://a.example/'], 'presetUid' => self::PRIMARY],
            ['type' => 'url', 'data' => ['url' => 'https://b.example/'], 'presetUid' => self::SECONDARY],
            ['type' => 'url', 'data' => ['url' => 'https://c.example/'], 'presetUid' => self::GONE],
        ]]);

        $html = self::field()->getInputHtml(self::links($entry), $entry);

        self::assertMatchesRegularExpression('/<option value="' . self::PRIMARY . '" selected>Primary CTA<\/option>/', $html);
        self::assertStringContainsString('Secondary CTA (not allowed in this field)', $html);
        self::assertStringContainsString('A preset that no longer exists (' . self::GONE . ')', $html);
        self::assertStringContainsString('The “Secondary CTA” preset is not allowed in this field.', $html);
        self::assertStringContainsString('The preset this link was made with no longer exists.', $html);
    }

    // Settings

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidSettings(): array
    {
        return [
            'no link types' => [['types' => []], 'types'],
            'an unregistered link type' => [['types' => ['url', 'phone']], 'types'],
            'a link type allowed twice' => [['types' => ['url', 'url']], 'types'],
            'link types that are not a list of handles' => [['types' => 'url'], 'types'],
            'a preset that does not exist' => [['types' => ['url'], 'presets' => [self::GONE]], 'presets'],
            'a preset allowed twice' => [['types' => ['url'], 'presets' => [self::PRIMARY, self::PRIMARY]], 'presets'],
            'presets that are not a list' => [['types' => ['url'], 'presets' => self::PRIMARY], 'presets'],
            'a negative minimum' => [['types' => ['url'], 'minLinks' => -1], 'minLinks'],
            'a negative minimum, as text' => [['types' => ['url'], 'minLinks' => '-1'], 'minLinks'],
            'a minimum that is not a number' => [['types' => ['url'], 'minLinks' => 'two'], 'minLinks'],
            'a minimum that is not a whole number' => [['types' => ['url'], 'minLinks' => '1.5'], 'minLinks'],
            'a minimum of the wrong kind' => [['types' => ['url'], 'minLinks' => [1]], 'minLinks'],
            'multiple that is not on or off' => [['types' => ['url'], 'multiple' => 'banana'], 'multiple'],
            'a maximum of none' => [['types' => ['url'], 'maxLinks' => 0], 'maxLinks'],
            'a negative maximum' => [['types' => ['url'], 'maxLinks' => -2], 'maxLinks'],
            'a maximum that is not a number' => [['types' => ['url'], 'maxLinks' => 'many'], 'maxLinks'],
            'a minimum above the maximum' => [['types' => ['url'], 'minLinks' => 3, 'maxLinks' => 2], 'maxLinks'],
            'a minimum on a single-link field' => [['types' => ['url'], 'multiple' => false, 'minLinks' => 1], 'maxLinks'],
            'a maximum on a single-link field' => [['types' => ['url'], 'multiple' => false, 'maxLinks' => 2], 'maxLinks'],
            'a default link that is invalid' => [['types' => ['url'], 'defaultLinksInput' => ['links' => [['type' => 'url', 'data' => ['url' => ['url' => 'javascript:alert(1)']]]]]], 'defaultLinks'],
            'a default link of a type the field does not allow' => [['types' => ['url'], 'defaultLinksInput' => ['links' => [['type' => 'email', 'data' => ['email' => ['address' => 'a@b.c']]]]]], 'defaultLinks'],
            'a default link with a preset that no longer exists' => [['types' => ['url'], 'presets' => [self::PRIMARY], 'defaultLinksInput' => ['links' => [['type' => 'url', 'data' => ['url' => ['url' => 'https://a.example/']], 'presetUid' => self::GONE]]]], 'defaultLinks'],
            'a default link with a preset the field does not allow' => [['types' => ['url'], 'defaultLinksInput' => ['links' => [['type' => 'url', 'data' => ['url' => ['url' => 'https://a.example/']], 'presetUid' => self::SECONDARY]]]], 'defaultLinks'],
            'more default links than the field holds' => [['types' => ['url'], 'multiple' => false, 'defaultLinksInput' => ['links' => [
                ['type' => 'url', 'data' => ['url' => ['url' => 'https://a.example/']]],
                ['type' => 'url', 'data' => ['url' => ['url' => 'https://b.example/']]],
            ]]], 'defaultLinks'],
            // Only the settings form's own key is editor input; anything else is the stored form.
            'stored default links shaped like authoring input' => [['types' => ['url'], 'defaultLinks' => [['type' => 'url', 'data' => ['url' => 'https://a.example/']]]], 'defaultLinks'],
            'stored default links shaped like the editor’s post' => [['types' => ['url'], 'defaultLinks' => ['links' => ['a' => ['type' => 'url', 'data' => ['url' => ['url' => 'https://a.example/']]]]]], 'defaultLinks'],
            'stored default links that are an empty list' => [['types' => ['url'], 'defaultLinks' => []], 'defaultLinks'],
            'stored default links that are text' => [['types' => ['url'], 'defaultLinks' => 'https://a.example/'], 'defaultLinks'],
            'stored default links that are true' => [['types' => ['url'], 'defaultLinks' => true], 'defaultLinks'],
            'stored default links of an unregistered type' => [['types' => ['url'], 'defaultLinks' => ['version' => 1, 'links' => [['uid' => self::UID_A, 'type' => 'phone', 'data' => ['number' => '+44']]]]], 'defaultLinks'],
            'stored default links with a preset that no longer exists' => [['types' => ['url'], 'defaultLinks' => ['version' => 1, 'links' => [['uid' => self::UID_A, 'type' => 'url', 'data' => ['url' => 'https://a.example/'], 'presetUid' => self::GONE]]]], 'defaultLinks'],
            'posted default links that are not the editor’s input' => [['types' => ['url'], 'defaultLinksInput' => 'https://a.example/'], 'defaultLinks'],
            'posted default links with an unknown property' => [['types' => ['url'], 'defaultLinksInput' => ['links' => ['a' => ['type' => 'url', 'data' => ['url' => ['url' => 'https://a.example/']], 'href' => 'x']]]], 'defaultLinks'],
            'stored default links that cannot be read' => [['types' => ['url'], 'defaultLinks' => ['version' => 2, 'links' => []]], 'defaultLinks'],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('invalidSettings')]
    public function testCraftRefusesToSaveInvalidSettings(array $settings, string $attribute): void
    {
        $handle = 'smartLinksTestInvalid' . substr(md5(serialize($settings)), 0, 8);
        // Created as Craft creates fields, so Craft's own handling of settings applies.
        $field = Craft::$app->getFields()->createField(['type' => SmartLinkField::class, 'name' => 'Invalid', 'handle' => $handle] + $settings);

        self::assertFalse(Craft::$app->getFields()->saveField($field), 'The settings were saved.');
        self::assertNotEmpty($field->getErrors($attribute), Json::encode($field->getErrors()));
        self::assertNull(Craft::$app->getFields()->getFieldByHandle($handle));
    }

    public function testDefaultLinksAreReadByTheSettingTheyArriveInNotByTheirShape(): void
    {
        // The settings form's input, given as the input and as stored configuration.
        $structure = ['links' => ['a' => ['uid' => '', 'type' => 'url', 'data' => ['url' => ['url' => 'https://a.example/']], 'label' => 'A']]];

        $posted = new SmartLinkField(['name' => 'Posted', 'handle' => 'smartLinksTestPostedShape', 'types' => ['url'], 'defaultLinksInput' => $structure]);
        self::assertTrue($posted->validate(), Json::encode($posted->getErrors()));
        self::assertSame(1, $posted->getSettings()['defaultLinks']['version']);
        self::assertArrayNotHasKey('defaultLinksInput', $posted->getSettings());

        $stored = new SmartLinkField(['name' => 'Stored', 'handle' => 'smartLinksTestStoredShape', 'types' => ['url'], 'defaultLinks' => $structure]);
        self::assertFalse($stored->validate());
        self::assertNotEmpty($stored->getErrors('defaultLinks'));
        // Refused as it is, never rewritten into something else.
        self::assertSame($structure, $stored->defaultLinks);
    }

    public function testAnUnknownSettingIsRefused(): void
    {
        $this->expectException(\yii\base\UnknownPropertyException::class);

        Craft::$app->getFields()->createField(['type' => SmartLinkField::class, 'name' => 'Unknown', 'handle' => 'smartLinksTestUnknown', 'types' => ['url'], 'allowedTypes' => ['url']]);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function validSettings(): array
    {
        return [
            'no limits' => [['types' => ['url'], 'minLinks' => null, 'maxLinks' => null]],
            'a minimum of zero' => [['types' => ['url'], 'minLinks' => 0]],
            'a minimum of one' => [['types' => ['url'], 'minLinks' => 1]],
            'a minimum equal to the maximum' => [['types' => ['url'], 'minLinks' => 2, 'maxLinks' => 2]],
            'a maximum of one' => [['types' => ['url'], 'maxLinks' => 1]],
            'limits as the settings form posts them' => [['types' => ['url'], 'minLinks' => '1', 'maxLinks' => '5']],
            'a single-link field' => [['types' => ['url'], 'multiple' => false]],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('validSettings')]
    public function testCraftSavesValidSettings(array $settings): void
    {
        $field = Craft::$app->getFields()->createField(['type' => SmartLinkField::class, 'name' => 'Valid', 'handle' => 'smartLinksTestValid' . substr(md5(serialize($settings)), 0, 8)] + $settings);

        self::assertTrue(Craft::$app->getFields()->saveField($field), Json::encode($field->getErrors()));
        self::assertTrue(Craft::$app->getFields()->deleteField($field));
    }

    public function testSettingsFromTheSettingsFormAreStoredCanonically(): void
    {
        // Numbers are posted as text, and default links as the link editor's inputs.
        $field = new SmartLinkField([
            'name' => 'Posted',
            'handle' => 'smartLinksTestPosted',
            'types' => ['email', 'url'],
            'multiple' => '1',
            'minLinks' => '',
            'maxLinks' => '4',
            'defaultLinksInput' => ['links' => ['new1' => ['uid' => '', 'type' => 'url', 'data' => ['url' => ['url' => 'HTTPS://Example.com']], 'label' => '']]],
        ]);

        self::assertTrue($field->validate(), Json::encode($field->getErrors()));
        self::assertNull($field->minLinks);
        self::assertSame(4, $field->maxLinks);

        $stored = $field->getSettings()['defaultLinks'];
        self::assertIsArray($stored);
        self::assertSame(1, $stored['version']);
        self::assertSame(['url' => 'https://example.com/'], $stored['links'][0]['data']);
    }

    public function testAFieldRecreatedFromItsConfigHasTheSameSettings(): void
    {
        // Duplicating a field, or deploying it through project config, rebuilds it from its config.
        $original = self::createField('smartLinksTestFullSettings', [
            'types' => ['url', 'email'],
            'presets' => [self::PRIMARY],
            'minLinks' => 1,
            'maxLinks' => 3,
            'defaultLinksInput' => ['links' => [['type' => 'url', 'data' => ['url' => ['url' => 'https://example.com/']], 'presetUid' => self::PRIMARY, 'attributes' => ['custom' => [['name' => 'data-b', 'value' => '2'], ['name' => 'data-a', 'value' => '1']]]]]],
        ]);
        $config = Craft::$app->getFields()->createFieldConfig($original);

        // Craft packs settings for project config, and unpacks them again when applying it.
        $settings = ProjectConfigHelper::unpackAssociativeArrays($config['settings']);
        $copy = Craft::$app->getFields()->createField(['type' => SmartLinkField::class, 'handle' => 'smartLinksTestCopy', 'name' => 'Copy', 'settings' => $settings]);

        self::assertInstanceOf(SmartLinkField::class, $copy);
        self::assertTrue(Craft::$app->getFields()->saveField($copy), Json::encode($copy->getErrors()));
        self::assertSame($original->getSettings(), $copy->getSettings());
        self::assertSame(['url', 'email'], $copy->types);
        self::assertSame([self::PRIMARY], $copy->presets);
        self::assertSame([1, 3, true], [$copy->minLinks, $copy->maxLinks, $copy->multiple]);
        self::assertSame(['data-b', 'data-a'], array_column($copy->defaultLinks['links'][0]['attributes']['custom'] ?? [], 'name'));

        // Settings hold handles, UIDs and link data, never database IDs of this install.
        $json = Json::encode($config['settings']);
        self::assertStringNotContainsString('"id"', $json);
        self::assertStringNotContainsString('fieldId', $json);
    }

    public function testAnAllowedTypeOrPresetThatIsGoneStaysVisibleInTheSettings(): void
    {
        $field = new SmartLinkField(['name' => 'Stale', 'handle' => 'smartLinksTestStale', 'types' => ['url', 'phone'], 'presets' => [self::GONE]]);

        $html = (string)$field->getSettingsHtml();

        self::assertStringContainsString('phone (not available)', $html);
        self::assertStringContainsString('A preset that no longer exists (' . self::GONE . ')', $html);
        self::assertFalse($field->validate());
        self::assertSame(['“phone” is not an available link type.'], $field->getErrors('types'));
        self::assertSame(['“' . self::GONE . '” is not an existing preset.'], $field->getErrors('presets'));
    }

    public function testTheSettingsFormRenders(): void
    {
        $html = (string)self::field(self::DEFAULTS)->getSettingsHtml();

        self::assertStringContainsString('name="types[]"', $html);
        self::assertStringContainsString('name="presets[]"', $html);
        self::assertStringContainsString('name="multiple"', $html);
        self::assertStringContainsString('name="defaultLinksInput[links][link0][type]"', $html);
        self::assertStringContainsString('value="Start here"', $html);
    }

    // Saving and reading

    public function testEveryPartOfALinkIsStoredAndReadBackFromTheDatabase(): void
    {
        $entry = self::saveNewEntry('full-link', [self::LINKS => [self::fullLink()]]);
        $reloaded = self::reload($entry);
        $links = self::links($reloaded);

        self::assertCount(1, $links);
        $link = $links->links[0];
        self::assertSame(self::uids(self::links($entry)), [$link->uid]);
        self::assertSame('url', $link->type);
        self::assertSame(['url' => 'https://example.com/%C3%BCber/a.pdf'], $link->data->toArray());
        self::assertSame('Ünïcödé — 日本語 ✓', $link->label);
        self::assertSame('?ref=nav#top', $link->urlSuffix);
        self::assertSame(self::PRIMARY, $link->presetUid);
        self::assertSame('_blank', $link->attributes->target);
        self::assertSame(['nofollow', 'sponsored'], $link->attributes->rel);
        self::assertSame('Download the “report”', $link->attributes->title);
        self::assertSame(['btn', 'btn--primary', 'md:px-4'], $link->attributes->class);
        self::assertSame('report-link', $link->attributes->id);
        self::assertSame('Download the annual report', $link->attributes->ariaLabel);
        self::assertTrue($link->attributes->download);
        self::assertSame('report.pdf', $link->attributes->downloadFilename);
        self::assertSame(['data-track' => 'cta', 'aria-describedby' => 'note', 'data-empty' => ''], $link->attributes->custom);

        // What Craft stored is exactly the canonical form; the database orders an object's keys
        // its own way, so that alone is not compared.
        $stored = self::storedContent($entry, self::LINKS);
        self::assertIsArray($stored);
        self::assertSame(self::sortKeys((array)SmartLinks::getInstance()->getLinks()->getSerializer()->serialize($links)), self::sortKeys($stored));
        self::assertSame([['name' => 'data-track', 'value' => 'cta'], ['name' => 'aria-describedby', 'value' => 'note'], ['name' => 'data-empty', 'value' => '']], self::sortKeys($stored['links'][0]['attributes']['custom']));
    }

    public function testAuthorInputSurvivesTheWholeRoundTripToRenderedHtml(): void
    {
        $entry = self::newEntry('round-trip');
        $input = self::fullLink();
        $input['attributes']['custom'] = [['name' => 'data-track', 'value' => 'cta'], ['name' => 'aria-describedby', 'value' => 'note'], ['name' => '', 'value' => '']];

        // The editor's post, through the field's request normalization and the link core.
        $value = self::field()->normalizeValueFromRequest(self::post([$input, ['type' => 'email', 'data' => ['address' => 'b@example.com'], 'label' => 'Second']]), $entry);
        self::assertInstanceOf(LinkCollection::class, $value);
        $entry->setFieldValue(self::LINKS, $value);
        self::assertTrue(Craft::$app->getElements()->saveElement($entry), Json::encode($entry->getErrors()));

        $reloaded = self::links(self::reload($entry));
        self::assertEquals($value, $reloaded);
        self::assertSame(['Ünïcödé — 日本語 ✓', 'Second'], self::labels($reloaded));
        // Empty optional inputs are not set, rather than set to nothing.
        self::assertNull($reloaded->links[1]->urlSuffix);
        self::assertTrue($reloaded->links[1]->attributes->isEmpty());

        self::assertSame([
            'aria-describedby' => 'note',
            'aria-label' => 'Download the annual report',
            'class' => 'btn btn--primary md:px-4',
            'data-track' => 'cta',
            'download' => 'report.pdf',
            'href' => 'https://example.com/%C3%BCber/a.pdf?ref=nav#top',
            'id' => 'report-link',
            'rel' => 'nofollow sponsored noopener',
            'target' => '_blank',
            'title' => 'Download the “report”',
        ], self::anchor((string)SmartLinks::getInstance()->getLinks()->html($reloaded->links[0], (int)$entry->siteId), 'Ünïcödé — 日本語 ✓'));
    }

    public function testNoLinksAreStoredAsNothing(): void
    {
        $entry = self::saveNewEntry('no-links', [self::LINKS => []]);

        self::assertNull(self::storedContent($entry, self::LINKS));
        self::assertTrue(self::links(self::reload($entry))->isEmpty());
    }

    public function testEditingKeepsEachLinksUidAndReorderingMovesIt(): void
    {
        $entry = self::saveNewEntry('reorder', [self::LINKS => [
            ['type' => 'url', 'data' => ['url' => 'https://a.example/'], 'label' => 'A'],
            ['type' => 'url', 'data' => ['url' => 'https://b.example/'], 'label' => 'B'],
            ['type' => 'email', 'data' => ['address' => 'c@example.com'], 'label' => 'C'],
        ]]);
        [$a, $b, $c] = self::uids(self::links($entry));
        $reloaded = self::reload($entry);

        // The editor posts links in the order they appear, each with its UID.
        $posted = self::field()->normalizeValueFromRequest(self::post([
            ['uid' => $c, 'type' => 'email', 'data' => ['address' => 'c@example.com'], 'label' => 'C'],
            ['uid' => $a, 'type' => 'url', 'data' => ['url' => 'https://a.example/'], 'label' => 'A'],
            ['uid' => $b, 'type' => 'url', 'data' => ['url' => 'https://b.example/'], 'label' => 'B'],
        ]), $reloaded);
        $reloaded->setFieldValue(self::LINKS, $posted);
        self::assertTrue(Craft::$app->getElements()->saveElement($reloaded), Json::encode($reloaded->getErrors()));

        $after = self::links(self::reload($entry));
        self::assertSame(['C', 'A', 'B'], self::labels($after));
        self::assertSame([$c, $a, $b], self::uids($after));
        self::assertSame([$c, $a, $b], array_column(self::storedContent($entry, self::LINKS)['links'], 'uid'));
        self::assertSame(['c@example.com'], [$after->links[0]->data->toArray()['address']]);
    }

    public function testANewLinkInTheEditorGetsANewUid(): void
    {
        $entry = self::saveNewEntry('new-link', [self::LINKS => [self::threeLinks()[0]]]);
        [$existing] = self::uids(self::links($entry));

        $value = self::field()->normalizeValueFromRequest(self::post([
            ['uid' => $existing, 'type' => 'url', 'data' => ['url' => 'https://example.com/About']],
            ['type' => 'url', 'data' => ['url' => 'https://example.com/new']],
        ]), $entry);

        self::assertInstanceOf(LinkCollection::class, $value);
        self::assertSame($existing, $value->links[0]->uid);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value->links[1]->uid);
        self::assertNotSame($existing, $value->links[1]->uid);
    }

    public function testRemovingTheLastLinkEmptiesTheValue(): void
    {
        $entry = self::saveNewEntry('remove-all', [self::LINKS => self::threeLinks()]);

        // Only the editor's hidden input is posted once every link is removed.
        $entry->setFieldValue(self::LINKS, self::field()->normalizeValueFromRequest('', $entry));
        self::assertTrue(Craft::$app->getElements()->saveElement($entry));

        self::assertNull(self::storedContent($entry, self::LINKS));
    }

    public function testTheFormsInputsForOtherTypesAndBlankRowsAreNotTheLinks(): void
    {
        [$input, $errors] = LinkForm::fromPost(['links' => ['a' => [
            'type' => 'email',
            'data' => ['url' => ['url' => 'https://ignored.example/'], 'email' => ['address' => 'a@b.c']],
            'attributes' => ['custom' => [['name' => '', 'value' => ''], ['name' => 'data-x', 'value' => '1']]],
        ]]], SmartLinks::getInstance()->getLinkTypes()->getTypeSet());

        self::assertSame([], $errors);
        self::assertSame([['type' => 'email', 'data' => ['address' => 'a@b.c'], 'attributes' => ['custom' => [['name' => 'data-x', 'value' => '1']]]]], $input);

        // Only the form's rows are reshaped: a map of names to values is passed on as it is.
        [$mapped] = LinkForm::fromPost(['links' => ['a' => ['type' => 'email', 'attributes' => ['custom' => ['data-x' => '1', 'data-y' => '']]]]], SmartLinks::getInstance()->getLinkTypes()->getTypeSet());
        self::assertSame(['data-x' => '1', 'data-y' => ''], $mapped[0]['attributes']['custom']);
    }

    public function testTheSameFieldPlacedTwiceKeepsTwoValues(): void
    {
        $entry = self::saveNewEntry('two-instances', [self::LINKS => [self::threeLinks()[0]], self::LINKS_AGAIN => [self::threeLinks()[2]]]);
        $reloaded = self::reload($entry);

        self::assertSame(['About'], self::labels(self::links($reloaded, self::LINKS)));
        self::assertSame(['Email us'], self::labels(self::links($reloaded, self::LINKS_AGAIN)));
    }

    public function testLinksAreSearchable(): void
    {
        $entry = self::newEntry('search', [self::LINKS => self::threeLinks()]);
        $keywords = self::field()->getSearchKeywords(self::links($entry), $entry);

        self::assertStringContainsString('About', $keywords);
        self::assertStringContainsString('Email us', $keywords);
    }

    // Element queries

    public function testElementQueriesAskWhetherAnEntryHasLinks(): void
    {
        $with = self::saveNewEntry('query-with', [self::LINKS => [self::threeLinks()[0]]]);
        $without = self::saveNewEntry('query-without', [self::LINKS => []]);
        $broken = self::saveNewEntry('query-broken', [self::LINKS => [self::threeLinks()[0]]]);
        self::writeStoredContent($broken, self::LINKS, ['version' => 9, 'links' => []]);
        $brokenList = self::saveNewEntry('query-broken-list', [self::LINKS => [self::threeLinks()[0]]]);
        self::writeStoredContent($brokenList, self::LINKS, [['bad' => 'value']]);

        $query = static function(string $condition) use ($with, $without, $broken, $brokenList): array {
            $query = Entry::find()->section(self::SECTION)->siteId(self::$primarySiteId)->id([$with->id, $without->id, $broken->id, $brokenList->id]);
            // The field's query parameter, as `entries.smartLinksTestLinks(':notempty:')` sets it.
            Craft::configure($query, [self::LINKS => $condition]);
            $ids = array_map('intval', $query->ids());
            sort($ids);

            return $ids;
        };

        $expected = [(int)$with->id, (int)$broken->id, (int)$brokenList->id];
        sort($expected);
        self::assertSame($expected, $query(':notempty:'));
        // Content that cannot be read is content, not an empty value.
        self::assertSame([(int)$without->id], $query(':empty:'));
    }

    // Defaults

    public function testAFieldWithoutDefaultsStartsEmpty(): void
    {
        self::assertTrue(self::links(self::newEntry('no-defaults'), self::SINGLE)->isEmpty());
    }

    public function testANewEntryStartsWithItsOwnCopiesOfTheDefaultLinks(): void
    {
        $settings = self::field(self::DEFAULTS)->defaultLinks;
        $first = self::saveNewEntry('defaults-one');
        $second = self::saveNewEntry('defaults-two');

        $firstLinks = self::links(self::reload($first), self::DEFAULTS);
        $secondLinks = self::links(self::reload($second), self::DEFAULTS);

        // Every default, in order, with what it was made with.
        self::assertSame(['Start here', null], self::labels($firstLinks));
        self::assertSame(['url', 'email'], array_map(static fn(LinkValue $link): string => $link->type, $secondLinks->links));
        self::assertSame(self::PRIMARY, $firstLinks->links[0]->presetUid);
        // Each element's links are its own occurrences, not the settings' or each other's.
        self::assertSame([], array_intersect(self::uids($firstLinks), self::uids($secondLinks)));
        self::assertSame([], array_intersect(self::uids($firstLinks), array_column($settings['links'] ?? [], 'uid')));

        // Editing an element's links leaves the field's defaults alone.
        $first->setFieldValue(self::DEFAULTS, new LinkCollection());
        self::assertTrue(Craft::$app->getElements()->saveElement($first));
        self::assertSame($settings, self::field(self::DEFAULTS)->defaultLinks);
    }

    public function testAnExistingEntryWithNoLinksDoesNotGetTheDefaults(): void
    {
        $entry = self::saveNewEntry('defaults-cleared', [self::DEFAULTS => []]);

        self::assertTrue(self::links(self::reload($entry), self::DEFAULTS)->isEmpty());
    }

    public function testDefaultLinksThatCanNoLongerBeReadAreShownNotSkipped(): void
    {
        $field = new SmartLinkField(['name' => 'Gone', 'handle' => self::DEFAULTS, 'types' => ['url'], 'defaultLinks' => [
            'version' => 1,
            'links' => [['uid' => self::UID_A, 'type' => 'phone', 'data' => ['number' => '+44']]],
        ]]);

        // The settings are invalid as they are…
        self::assertFalse($field->validate());
        self::assertNotEmpty($field->getErrors('defaultLinks'));

        // …and new content shows the problem rather than quietly starting without defaults.
        $value = $field->normalizeValue(null, self::newEntry('unreadable-defaults'));
        self::assertInstanceOf(InvalidLinkValue::class, $value);
        self::assertFalse($value->stored);
        self::assertSame('This field’s default links can’t be read. Remove them here, and correct the field’s settings.', $value->errors[0]->getMessage());
        self::assertSame(Code::UNKNOWN_LINK_TYPE, $value->errors[1]->code);

        // It cannot be stored as it is.
        $this->expectException(LinkValidationException::class);
        $field->serializeValue($value, null);
    }

    // Validation

    public function testALinkOfATypeTheFieldDoesNotAllowIsRefused(): void
    {
        $entry = self::newEntry('wrong-type', [self::SINGLE => [['type' => 'email', 'data' => ['address' => 'a@b.c']]]]);

        self::assertFalse(Craft::$app->getElements()->saveElement($entry));
        self::assertSame(['Link 1: Email links are not allowed in this field.'], $entry->getErrors(self::SINGLE));
        // Reported, never converted: the link is still an email link.
        self::assertSame('email', self::links($entry, self::SINGLE)->links[0]->type);
    }

    public function testASingleLinkFieldHoldsNoneOrOneLink(): void
    {
        self::assertTrue(self::newEntry('single-none', [self::SINGLE => []])->validate());
        self::assertTrue(self::newEntry('single-one', [self::SINGLE => [['type' => 'url', 'data' => ['url' => 'https://a.example/']]]])->validate());

        $two = self::newEntry('single-two', [self::SINGLE => [
            ['type' => 'url', 'data' => ['url' => 'https://a.example/']],
            ['type' => 'url', 'data' => ['url' => 'https://b.example/']],
        ]]);
        // Disabled content is refused too: the limit is what the field holds, not a publishing rule.
        $two->enabled = false;

        self::assertFalse(Craft::$app->getElements()->saveElement($two));
        self::assertSame(['This field holds at most 1 link.'], $two->getErrors(self::SINGLE));
    }

    public function testAMultipleLinkFieldHoldsUpToItsMaximum(): void
    {
        self::assertTrue(Craft::$app->getElements()->saveElement(self::newEntry('three-links', [self::LINKS => self::threeLinks()])));

        $links = self::threeLinks();
        $links[] = ['type' => 'url', 'data' => ['url' => 'https://d.example/']];
        $entry = self::newEntry('too-many', [self::LINKS => $links]);

        self::assertFalse(Craft::$app->getElements()->saveElement($entry));
        self::assertSame(['This field holds at most 3 links.'], $entry->getErrors(self::LINKS));
    }

    public function testTheMinimumAppliesToLiveContentWithLinks(): void
    {
        $entry = self::newEntry('too-few', [self::URL_ONLY => [['type' => 'url', 'data' => ['url' => 'https://a.example/']]]]);
        $field = self::layoutField($entry, self::URL_ONLY);
        $field->minLinks = 2;

        try {
            $entry->setScenario(Element::SCENARIO_LIVE);
            self::assertFalse($entry->validate());
            self::assertSame(['smartLinksTestUrlOnly needs at least 2 links.'], $entry->getErrors(self::URL_ONLY));

            // Having no links is the “required” setting's business, not the minimum's.
            $empty = self::newEntry('none-at-all', [self::URL_ONLY => []]);
            $empty->setScenario(Element::SCENARIO_LIVE);
            self::assertTrue($empty->validate(), Json::encode($empty->getErrors()));

            // A draft, saved with only its essentials checked, may be incomplete.
            $entry->setScenario(Element::SCENARIO_ESSENTIALS);
            self::assertTrue($entry->validate(), Json::encode($entry->getErrors()));
        } finally {
            $field->minLinks = null;
        }
    }

    public function testInvalidInputIsShownBackAndNeverSaved(): void
    {
        $entry = self::newEntry('invalid', [self::LINKS => [
            ['type' => 'url', 'data' => ['url' => 'javascript:alert(1)'], 'label' => 'Bad'],
            ['type' => 'email', 'data' => ['address' => 'not an address']],
        ]]);

        $value = $entry->getFieldValue(self::LINKS);
        self::assertInstanceOf(InvalidLinkValue::class, $value);
        self::assertFalse($value->stored);
        self::assertSame(['links[0].data.url', 'links[1].data.address'], array_map(static fn(ValidationError $error): string => $error->path, $value->errors));

        self::assertFalse(Craft::$app->getElements()->saveElement($entry));
        self::assertCount(2, $entry->getErrors(self::LINKS));
        self::assertStringStartsWith('Link 1: ', $entry->getErrors(self::LINKS)[0]);
        self::assertStringStartsWith('Link 2: ', $entry->getErrors(self::LINKS)[1]);
    }

    public function testADraftKeepsWhatItsAuthorHasNotFinishedAndNothingElseDoes(): void
    {
        $entry = self::savedEntry('unfinished-draft', [self::LINKS => [['type' => 'url', 'data' => ['url' => 'https://example.com/live'], 'label' => 'Live']]]);
        $creator = \craft\elements\User::find()->admin()->one();
        self::assertNotNull($creator);
        $draft = Craft::$app->getDrafts()->createDraft($entry, (int)$creator->id, provisional: true);
        $unfinished = ['links' => ['new1' => ['type' => 'url', 'data' => ['url' => ['url' => '']], 'label' => 'Half done']]];

        // Craft's autosave: the editor's post, saved with only the essentials checked.
        $draft->setFieldValueFromRequest(self::LINKS, $unfinished);
        $draft->setScenario(Element::SCENARIO_ESSENTIALS);
        self::assertTrue(Craft::$app->getElements()->saveElement($draft), Json::encode($draft->getErrors()));

        // Kept exactly as entered, in the draft's unfinished form, never as links.
        $layoutElement = self::$instances[self::LINKS]->layoutElement?->uid;
        $content = Json::decode((string)(new Query())->select(['content'])->from(Table::ELEMENTS_SITES)->where(['elementId' => $draft->id, 'siteId' => self::$primarySiteId])->scalar());
        // (MySQL's JSON column sorts object keys, so they are compared without their order.)
        self::assertEquals(['version' => 1, 'unfinished' => [['type' => 'url', 'data' => ['url' => ''], 'label' => 'Half done']]], $content[$layoutElement] ?? null);

        // Read back as the author's input, with its errors, to be finished in the editor.
        $reloaded = Entry::find()->id($draft->id)->drafts()->provisionalDrafts()->status(null)->one();
        self::assertNotNull($reloaded);
        $value = $reloaded->getFieldValue(self::LINKS);
        self::assertInstanceOf(InvalidLinkValue::class, $value);
        self::assertFalse($value->stored);
        self::assertSame(['links[0].data.url'], array_map(static fn(ValidationError $error): string => $error->path, $value->errors));
        $html = self::$instances[self::LINKS]->getInputHtml($value, $reloaded);
        self::assertStringContainsString('value="Half done"', $html);
        self::assertStringContainsString('A URL is required.', $html);

        // Not publishable: a full save refuses it, and so does applying the draft.
        $reloaded->setScenario(Element::SCENARIO_LIVE);
        self::assertFalse($reloaded->validate());
        self::assertNotEmpty($reloaded->getErrors(self::LINKS));

        try {
            Craft::$app->getDrafts()->applyDraft($reloaded);
            self::fail('A draft with an unfinished link was applied.');
        } catch (\craft\errors\InvalidElementException) {
        }

        $live = Entry::find()->id($entry->id)->one()?->getFieldValue(self::LINKS);
        self::assertInstanceOf(LinkCollection::class, $live);
        self::assertSame('Live', $live->links[0]->label);

        // Finished, the draft holds links again, and applies.
        $finished = Entry::find()->id($draft->id)->drafts()->provisionalDrafts()->status(null)->one();
        self::assertNotNull($finished);
        $finished->setFieldValueFromRequest(self::LINKS, ['links' => ['new1' => ['type' => 'url', 'data' => ['url' => ['url' => 'https://example.com/done']], 'label' => 'Done']]]);
        $finished->setScenario(Element::SCENARIO_ESSENTIALS);
        self::assertTrue(Craft::$app->getElements()->saveElement($finished));
        Craft::$app->getDrafts()->applyDraft($finished);
        $live = Entry::find()->id($entry->id)->one()?->getFieldValue(self::LINKS);
        self::assertInstanceOf(LinkCollection::class, $live);
        self::assertSame('Done', $live->links[0]->label);
    }

    public function testAnUnfinishedValueOutsideADraftIsNotReadAsInput(): void
    {
        // Smart Links writes this form only for drafts; on live content it is not the author's
        // input, so it is kept as content that can't be read, and refused by a full save.
        $entry = self::savedEntry('unfinished-outside-draft');
        $entry->setFieldValue(self::LINKS, ['version' => 1, 'unfinished' => [['type' => 'url', 'data' => ['url' => 'https://example.com/']]]]);
        $value = $entry->getFieldValue(self::LINKS);

        self::assertInstanceOf(InvalidLinkValue::class, $value);
        self::assertTrue($value->stored);
        self::assertFalse($entry->validate());

        // Invalid input on live content is never stored, even by an essentials-only save.
        $entry->setFieldValueFromRequest(self::LINKS, ['links' => ['new1' => ['type' => 'url', 'data' => ['url' => ['url' => '']]]]]);
        $entry->setScenario(Element::SCENARIO_ESSENTIALS);
        self::assertFalse($entry->validate());
        self::assertNotEmpty($entry->getErrors(self::LINKS));
    }

    public function testInvalidInputCannotBeWrittenBySkippingValidation(): void
    {
        $entry = self::newEntry('invalid-unvalidated', [self::LINKS => [['type' => 'url', 'data' => ['url' => 'ftp://example.com/']]]]);

        $this->expectException(LinkValidationException::class);

        Craft::$app->getElements()->saveElement($entry, runValidation: false);
    }

    /**
     * @return array<string, array{mixed, list<array{string, Code}>}>
     */
    public static function malformedPosts(): array
    {
        $url = ['url' => ['url' => 'https://a.example/']];

        return [
            'not a form at all' => ['<script>', [['', Code::WRONG_TYPE]]],
            'links that are not a list' => [['links' => 'x'], [['', Code::WRONG_TYPE]]],
            'an unexpected top-level key' => [['links' => [], 'html' => '<b>'], [['html', Code::UNKNOWN_KEY]]],
            'an unexpected link property' => [['links' => ['a' => ['type' => 'url', 'data' => $url, 'href' => 'javascript:alert(1)']]], [['links[0].href', Code::UNKNOWN_KEY]]],
            'an unexpected attribute' => [['links' => ['a' => ['type' => 'url', 'data' => $url, 'attributes' => ['onclick' => 'alert(1)']]]], [['links[0].attributes.onclick', Code::UNKNOWN_KEY]]],
            'an event handler as a custom attribute' => [['links' => ['a' => ['type' => 'url', 'data' => $url, 'attributes' => ['custom' => [['name' => 'onmouseover', 'value' => 'alert(1)']]]]]], [['links[0].attributes.custom.onmouseover', Code::INVALID]]],
            'a custom attribute given twice' => [['links' => ['a' => ['type' => 'url', 'data' => $url, 'attributes' => ['custom' => [['name' => 'data-a', 'value' => '1'], ['name' => 'data-a', 'value' => '2']]]]]], [['links[0].attributes.custom[1].name', Code::DUPLICATE]]],
            'a custom attribute row with an unknown part' => [['links' => ['a' => ['type' => 'url', 'data' => $url, 'attributes' => ['custom' => [['name' => 'data-a', 'value' => '1', 'html' => '<b>']]]]]], [['links[0].attributes.custom[0].html', Code::UNKNOWN_KEY]]],
            'unexpected data for the type' => [['links' => ['a' => ['type' => 'url', 'data' => ['url' => ['url' => 'https://a.example/', 'extra' => '1']]]]], [['links[0].data.extra', Code::UNKNOWN_KEY]]],
            'data for a type nothing registered' => [['links' => ['a' => ['type' => 'url', 'data' => $url + ['phone' => ['number' => '1']]]]], [['links[0].data.phone', Code::UNKNOWN_KEY]]],
            'a type nothing registered' => [['links' => ['a' => ['type' => 'phone', 'data' => ['phone' => ['number' => '1']]]]], [['links[0].data.phone', Code::UNKNOWN_KEY], ['links[0].type', Code::UNKNOWN_LINK_TYPE]]],
            'a preset reference that is not one' => [['links' => ['a' => ['type' => 'url', 'data' => $url, 'presetUid' => 'primary']]], [['links[0].presetUid', Code::INVALID]]],
            'a preset given as an object' => [['links' => ['a' => ['type' => 'url', 'data' => $url, 'presetUid' => ['uid' => self::PRIMARY]]]], [['links[0].presetUid', Code::WRONG_TYPE]]],
            'an unknown preset property' => [['links' => ['a' => ['type' => 'url', 'data' => $url, 'preset' => self::PRIMARY]]], [['links[0].preset', Code::UNKNOWN_KEY]]],
            'a kept link that is not JSON' => [['links' => ['a' => ['stored' => '{not json']]], [['links[0]', Code::INVALID], ['links[0]', Code::MISSING]]],
            'a kept value that is not JSON' => [['stored' => '{not json'], [['', Code::INVALID]]],
            'links added beside a kept value' => [['stored' => '{"version":1,"links":[]}', 'links' => ['a' => ['type' => 'url']]], [['links', Code::INVALID], ['links', Code::NOT_CANONICAL]]],
        ];
    }

    /**
     * @param list<array{string, Code}> $expected
     */
    #[DataProvider('malformedPosts')]
    public function testMalformedPostsAreReportedNotRepaired(mixed $post, array $expected): void
    {
        $value = self::field()->normalizeValueFromRequest($post, null);

        self::assertInstanceOf(InvalidLinkValue::class, $value);
        self::assertSame($expected, array_map(static fn(ValidationError $error): array => [$error->path, $error->code], $value->errors));
    }

    public function testRefusedInputTheEditorCannotShowInFullIsKeptWhole(): void
    {
        $post = ['links' => ['a' => ['type' => 'url', 'data' => ['url' => ['url' => 'https://a.example/']], 'label' => 'Kept', 'href' => 'javascript:alert(1)']]];
        $value = self::field()->normalizeValueFromRequest($post, null);
        self::assertInstanceOf(InvalidLinkValue::class, $value);

        // There is no input for “href”, so the link is shown as it was and posted back as it was:
        // submitting the same form again fails again, rather than quietly dropping it.
        $html = self::field()->getInputHtml($value, self::newEntry('kept-whole'));
        self::assertStringContainsString('This link has content this editor can’t show', $html);
        self::assertStringContainsString('&quot;href&quot;:&quot;javascript:alert(1)&quot;', $html);
        self::assertStringNotContainsString('name="smartLinksTestLinks[links][link0][label]"', $html);

        preg_match('/name="smartLinksTestLinks\[links\]\[link0\]\[stored\]" value="([^"]*)"/', $html, $match);
        self::assertNotEmpty($match);
        $resubmitted = self::field()->normalizeValueFromRequest(['links' => ['link0' => ['stored' => html_entity_decode($match[1], ENT_QUOTES)]]], null);
        self::assertInstanceOf(InvalidLinkValue::class, $resubmitted);
        self::assertSame([['links[0].href', Code::UNKNOWN_KEY]], array_map(static fn(ValidationError $error): array => [$error->path, $error->code], $resubmitted->errors));
    }

    // Malformed stored content

    /**
     * @return array<string, array{mixed, Code}>
     */
    public static function malformedStoredContent(): array
    {
        $link = ['uid' => self::UID_A, 'type' => 'url', 'data' => ['url' => 'https://example.com/']];
        $value = static fn(array ...$links): array => ['version' => 1, 'links' => $links];

        return [
            'a list of malformed links' => [[['bad' => 'value']], Code::INVALID],
            // Shaped exactly like authoring input, but stored content is never authoring input.
            'a list shaped like valid authoring input' => [[['type' => 'url', 'data' => ['url' => 'https://example.com/']]], Code::INVALID],
            'an empty list' => [[], Code::INVALID],
            'text that is not JSON' => ['{not json', Code::INVALID],
            'empty text' => ['', Code::INVALID],
            'a number' => [42, Code::INVALID],
            'a fraction' => [1.5, Code::INVALID],
            'true' => [true, Code::INVALID],
            'false' => [false, Code::INVALID],
            'an unknown version that is otherwise valid' => [['version' => 2, 'links' => [$link]], Code::UNSUPPORTED_VERSION],
            'missing links' => [['version' => 1], Code::INVALID],
            'links that are not a list' => [['version' => 1, 'links' => 'all of them'], Code::WRONG_TYPE],
            'a malformed UID' => [$value(['uid' => 'not-a-uid'] + $link), Code::INVALID],
            'an unknown link type' => [$value(['type' => 'phone', 'data' => ['number' => '+44']] + $link), Code::UNKNOWN_LINK_TYPE],
            'malformed type data' => [$value(['data' => ['url' => 'HTTPS://example.com']] + $link), Code::NOT_CANONICAL],
            'an unknown attribute' => [$value($link + ['attributes' => ['onclick' => 'alert(1)']]), Code::UNKNOWN_KEY],
            'an invalid custom attribute' => [$value($link + ['attributes' => ['custom' => [['name' => 'onclick', 'value' => 'x']]]]), Code::INVALID],
            'custom attributes stored as a map' => [$value($link + ['attributes' => ['custom' => ['data-a' => 'b']]]), Code::WRONG_TYPE],
            'an invalid preset reference' => [$value($link + ['presetUid' => 'primary']), Code::INVALID],
            'a duplicate UID' => [$value($link, ['data' => ['url' => 'https://example.com/b']] + $link), Code::DUPLICATE],
        ];
    }

    #[DataProvider('malformedStoredContent')]
    public function testMalformedStoredContentIsPreservedAndSurfaced(mixed $stored, Code $code): void
    {
        $entry = self::saveNewEntry('malformed', [self::LINKS => [self::threeLinks()[0]]]);
        self::writeStoredContent($entry, self::LINKS, $stored);

        $reloaded = self::reload($entry);
        $value = $reloaded->getFieldValue(self::LINKS);
        self::assertInstanceOf(InvalidLinkValue::class, $value);
        self::assertTrue($value->stored);
        self::assertContains($code, array_map(static fn(ValidationError $error): Code => $error->code, $value->errors));

        // A full save asks the author to deal with it, and leaves the content as it was.
        self::assertFalse(Craft::$app->getElements()->saveElement($reloaded));
        self::assertNotEmpty($reloaded->getErrors(self::LINKS));
        self::assertSame(is_array($stored) ? self::sortKeys($stored) : $stored, self::normalizeStored(self::storedContent($entry, self::LINKS)));

        // Anything that copies the content copies it exactly.
        $duplicate = Craft::$app->getElements()->duplicateElement($reloaded);
        self::assertSame(is_array($stored) ? self::sortKeys($stored) : $stored, self::normalizeStored(self::storedContent($duplicate, self::LINKS)));

        // The editor shows it with its errors, so removing or correcting it is the author's choice.
        $html = self::field()->getInputHtml($value, $reloaded);
        self::assertStringContainsString('<ul class="errors"', $html);

        if (!is_array($stored) || !is_array($stored['links'] ?? null) || !array_is_list($stored['links']) || $code === Code::UNSUPPORTED_VERSION) {
            // Not readable link by link, so kept whole and posted back as it was.
            self::assertMatchesRegularExpression('/name="smartLinksTestLinks\[stored\]" value="/', $html);
        }
    }

    public function testStoredContentIsNeverReadAsAuthoringInput(): void
    {
        // The same list, from storage and from the editor: only the request path reads it as links.
        $list = [['type' => 'url', 'data' => ['url' => 'https://example.com/']]];

        self::assertInstanceOf(InvalidLinkValue::class, self::field()->normalizeValue($list, null));
        self::assertTrue(self::field()->normalizeValue($list, null)->stored);
        self::assertInstanceOf(LinkCollection::class, self::field()->normalizeValueFromRequest(self::post($list), null));
    }

    public function testValidStoredContentIsReadExactly(): void
    {
        $entry = self::saveNewEntry('valid-stored', [self::LINKS => [self::threeLinks()[0]]]);
        $stored = ['version' => 1, 'links' => [
            ['uid' => self::UID_A, 'type' => 'url', 'data' => ['url' => 'https://example.com/a'], 'label' => 'A', 'presetUid' => self::PRIMARY, 'attributes' => ['rel' => ['nofollow'], 'custom' => [['name' => 'data-b', 'value' => '2'], ['name' => 'data-a', 'value' => '1']]]],
            ['uid' => self::UID_B, 'type' => 'email', 'data' => ['address' => 'b@example.com']],
        ]];
        self::writeStoredContent($entry, self::LINKS, $stored);

        $links = self::links(self::reload($entry));
        self::assertSame([self::UID_A, self::UID_B], self::uids($links));
        self::assertSame(['data-b' => '2', 'data-a' => '1'], $links->links[0]->attributes->custom);
        // Read back and written again, it is the same stored form.
        self::assertSame(self::sortKeys($stored), self::sortKeys((array)SmartLinks::getInstance()->getLinks()->getSerializer()->serialize($links)));
    }

    public function testRequestInputGoesThroughCraftsRequestLifecycle(): void
    {
        $entry = self::saveNewEntry('request-lifecycle');
        $this->useWebRequest('POST', '/admin/entries');
        Craft::$app->getRequest()->setBodyParams(['fields' => [self::LINKS => self::post([
            ['type' => 'url', 'data' => ['url' => 'https://example.com/posted'], 'label' => 'Posted'],
        ])]]);

        // What Craft's element editor does with the posted fields.
        $entry->setFieldValuesFromRequest('fields');
        self::assertTrue(Craft::$app->getElements()->saveElement($entry), Json::encode($entry->getErrors()));
        self::assertSame(['Posted'], self::labels(self::links(self::reload($entry))));

        // Malformed posted input is refused, and the stored value is left as it was.
        Craft::$app->getRequest()->setBodyParams(['fields' => [self::LINKS => ['links' => ['a' => ['type' => 'url', 'data' => ['url' => ['url' => 'javascript:alert(1)']]]]]]]);
        $entry->setFieldValuesFromRequest('fields');
        self::assertInstanceOf(InvalidLinkValue::class, $entry->getFieldValue(self::LINKS));
        self::assertFalse($entry->getFieldValue(self::LINKS)->stored);
        self::assertFalse(Craft::$app->getElements()->saveElement($entry));
        self::assertSame(['Posted'], self::labels(self::links(self::reload($entry))));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function keptValues(): array
    {
        return [
            'an object in an unknown version' => [['version' => 9, 'links' => [['anything' => 'at all']]]],
            'a list' => [[['bad' => 'value']]],
            'text' => ['{not json'],
            'a number' => [42],
            'false' => [false],
        ];
    }

    #[DataProvider('keptValues')]
    public function testUnreadableStoredContentStaysAsItIsUntilItIsExplicitlyCleared(mixed $stored): void
    {
        $entry = self::saveNewEntry('kept', [self::LINKS => [self::threeLinks()[0]]]);
        self::writeStoredContent($entry, self::LINKS, $stored);
        $reloaded = self::reload($entry);

        // The editor keeps it whole and posts it back; posted back unchanged, it is the stored
        // content still, so a draft's autosave keeps it exactly.
        $html = self::field()->getInputHtml($reloaded->getFieldValue(self::LINKS), $reloaded);
        preg_match('/name="smartLinksTestLinks\[stored\]" value="([^"]*)"/', $html, $match);
        self::assertNotEmpty($match);
        $postedBack = ['stored' => html_entity_decode($match[1], ENT_QUOTES)];
        $kept = self::field()->normalizeValueFromRequest($postedBack, $reloaded);
        self::assertInstanceOf(InvalidLinkValue::class, $kept);
        self::assertTrue($kept->stored);
        $reloaded->setFieldValue(self::LINKS, $kept);
        $reloaded->setScenario(Element::SCENARIO_ESSENTIALS);
        self::assertTrue(Craft::$app->getElements()->saveElement($reloaded, runValidation: true), Json::encode($reloaded->getErrors()));
        self::assertSame(self::normalizeStored($stored), self::normalizeStored(self::storedContent($entry, self::LINKS)));

        // A full save is refused while it is there; nothing replaces it with an empty value.
        $reloaded->setScenario(Element::SCENARIO_LIVE);
        self::assertFalse(Craft::$app->getElements()->saveElement($reloaded));
        self::assertSame(self::normalizeStored($stored), self::normalizeStored(self::storedContent($entry, self::LINKS)));

        // Anything else posted as “kept” is input, and is refused.
        $altered = self::field()->normalizeValueFromRequest(['stored' => Json::encode(['version' => 9, 'links' => [['anything' => 'else']]])], $reloaded);
        self::assertInstanceOf(InvalidLinkValue::class, $altered);
        self::assertFalse($altered->stored);

        // Clearing it is the author's explicit choice: the editor then posts no value.
        $cleared = self::field()->normalizeValueFromRequest('', $reloaded);
        self::assertInstanceOf(LinkCollection::class, $cleared);
        $reloaded->setFieldValue(self::LINKS, $cleared);
        self::assertTrue(Craft::$app->getElements()->saveElement($reloaded), Json::encode($reloaded->getErrors()));
        self::assertNull(self::storedContent($entry, self::LINKS));
    }

    private static function normalizeStored(mixed $stored): mixed
    {
        return is_array($stored) ? self::sortKeys($stored) : $stored;
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function valuesTheEditorCannotWriteBack(): array
    {
        return [
            'a whole value' => [['version' => 9, 'links' => [NAN]]],
            'one link of a value' => [['version' => 1, 'links' => [['uid' => self::UID_A, 'type' => 'url', 'data' => ['url' => INF]]]]],
        ];
    }

    /**
     * A kept value that cannot be written as JSON cannot be posted back as it is. Any stand-in
     * would read back as something else (`null` reads as no links, and would save as nothing),
     * so the editor refuses to render it rather than lose it.
     */
    #[DataProvider('valuesTheEditorCannotWriteBack')]
    public function testAKeptValueTheEditorCannotWriteBackIsRefusedNotReplaced(mixed $value): void
    {
        $kept = self::field()->normalizeValue($value, null);
        self::assertInstanceOf(InvalidLinkValue::class, $kept);
        self::assertTrue($kept->stored);

        $this->expectException(\JsonException::class);
        self::field()->getInputHtml($kept, null);
    }

    public function testStoredContentThatBreaksOnlyTheFieldsRulesIsReportedNotChanged(): void
    {
        // Readable, but of a type the single-link field does not allow, and too many.
        $entry = self::saveNewEntry('field-rules', [self::SINGLE => [['type' => 'url', 'data' => ['url' => 'https://a.example/']]]]);
        $stored = ['version' => 1, 'links' => [
            ['uid' => self::UID_A, 'type' => 'email', 'data' => ['address' => 'a@b.c']],
            ['uid' => self::UID_B, 'type' => 'url', 'data' => ['url' => 'https://b.example/']],
        ]];
        self::writeStoredContent($entry, self::SINGLE, $stored);

        $reloaded = self::reload($entry);
        self::assertSame([self::UID_A, self::UID_B], self::uids(self::links($reloaded, self::SINGLE)));
        self::assertFalse(Craft::$app->getElements()->saveElement($reloaded));
        self::assertSame(['Link 1: Email links are not allowed in this field.', 'This field holds at most 1 link.'], $reloaded->getErrors(self::SINGLE));
        self::assertSame(self::sortKeys($stored), self::sortKeys((array)self::storedContent($entry, self::SINGLE)));
    }

    // Drafts, revisions, duplicates and sites

    public function testADraftHasItsOwnCopyUntilItIsApplied(): void
    {
        $entry = self::saveNewEntry('drafted', [self::LINKS => self::threeLinks()]);
        [$a, $b, $c] = self::uids(self::links($entry));

        $draft = Craft::$app->getDrafts()->createDraft($entry);
        self::assertInstanceOf(Entry::class, $draft);
        $draftLinks = self::links(self::reload($draft));
        // The draft starts as a copy: the same occurrences.
        self::assertSame([$a, $b, $c], self::uids($draftLinks));

        // A reorder, a removal and an addition in the draft…
        $draft->setFieldValue(self::LINKS, self::field()->normalizeValueFromRequest(self::post([
            ['uid' => $c, 'type' => 'email', 'data' => ['address' => 'hello@example.com'], 'label' => 'Email us'],
            ['uid' => $a, 'type' => 'url', 'data' => ['url' => 'https://example.com/About'], 'label' => 'About'],
            ['type' => 'url', 'data' => ['url' => 'https://example.com/new'], 'label' => 'New'],
        ]), $draft));
        self::assertTrue(Craft::$app->getElements()->saveElement($draft), Json::encode($draft->getErrors()));

        // …leave the live entry alone, and each reloads with its own value.
        self::assertSame([$a, $b, $c], self::uids(self::links(self::reload($entry))));
        $reloadedDraft = self::links(self::reload($draft));
        self::assertSame(['Email us', 'About', 'New'], self::labels($reloadedDraft));
        self::assertSame([$c, $a], array_slice(self::uids($reloadedDraft), 0, 2));

        // Applying it makes its links the live ones, the same occurrences.
        Craft::$app->getDrafts()->applyDraft($draft);
        self::assertSame(self::uids($reloadedDraft), self::uids(self::links(self::reload($entry))));
    }

    public function testARevisionKeepsTheLinksItWasTakenWith(): void
    {
        $entry = self::saveNewEntry('revised', [self::LINKS => self::threeLinks()]);
        $revisionId = Craft::$app->getRevisions()->createRevision($entry, force: true);
        $uids = self::uids(self::links($entry));

        $entry->setFieldValue(self::LINKS, self::entered([self::threeLinks()[2]]));
        self::assertTrue(Craft::$app->getElements()->saveElement($entry));

        $revision = Entry::find()->id($revisionId)->revisions()->status(null)->siteId($entry->siteId)->one();
        self::assertInstanceOf(Entry::class, $revision);
        self::assertSame($uids, self::uids(self::links($revision)));
        self::assertSame(['About', null, 'Email us'], self::labels(self::links($revision)));
        // Taking a revision did not change the current content.
        self::assertSame(['Email us'], self::labels(self::links(self::reload($entry))));
    }

    public function testADuplicatedEntryHasTheSameLinksStoredSeparately(): void
    {
        $entry = self::saveNewEntry('original', [self::LINKS => [self::fullLink(), self::threeLinks()[1], self::threeLinks()[2]]]);
        $duplicate = Craft::$app->getElements()->duplicateElement($entry);

        self::assertNotSame($entry->id, $duplicate->id);
        // The value is copied as it is: order, data, attributes, presets, and the occurrences'
        // UIDs, which are unique within a value, not across elements.
        self::assertEquals(self::links($entry), self::links(self::reload($duplicate)));
        self::assertSame(self::sortKeys((array)self::storedContent($entry, self::LINKS)), self::sortKeys((array)self::storedContent($duplicate, self::LINKS)));

        $duplicate->setFieldValue(self::LINKS, new LinkCollection());
        self::assertTrue(Craft::$app->getElements()->saveElement($duplicate));
        self::assertCount(3, self::links(self::reload($entry)));
    }

    public function testEachSiteKeepsItsOwnTranslatedValue(): void
    {
        $entry = self::saveNewEntry('translated', [self::LINKS => self::threeLinks()]);
        $second = self::reload($entry, self::$secondSiteId);

        // A new entry's value reaches every site, with the same occurrences.
        self::assertSame(self::uids(self::links($entry)), self::uids(self::links($second)));

        $second->setFieldValue(self::LINKS, self::entered([['type' => 'url', 'data' => ['url' => 'https://example.com/de'], 'label' => 'Über uns']]));
        self::assertTrue(Craft::$app->getElements()->saveElement($second), Json::encode($second->getErrors()));

        // Stored separately per site, and neither overwrites the other.
        self::assertSame('Über uns', self::storedContent($entry, self::LINKS, self::$secondSiteId)['links'][0]['label']);
        self::assertSame('About', self::storedContent($entry, self::LINKS, self::$primarySiteId)['links'][0]['label']);
        self::assertSame(['Über uns'], self::labels(self::links(self::reload($entry, self::$secondSiteId))));
        self::assertSame(['About', null, 'Email us'], self::labels(self::links(self::reload($entry, self::$primarySiteId))));
    }

    public function testAnUntranslatedValueIsTheSameInEverySite(): void
    {
        $entry = self::saveNewEntry('shared', [self::SINGLE => [['type' => 'url', 'data' => ['url' => 'https://example.com/first']]]]);
        $second = self::reload($entry, self::$secondSiteId);

        $second->setFieldValue(self::SINGLE, self::entered([['type' => 'url', 'data' => ['url' => 'https://example.com/changed'], 'label' => 'Changed']]));
        self::assertTrue(Craft::$app->getElements()->saveElement($second), Json::encode($second->getErrors()));

        self::assertSame(['Changed'], self::labels(self::links(self::reload($entry, self::$primarySiteId), self::SINGLE)));
    }

    public function testAValueIsCopiedToAnotherSiteWithItsOccurrences(): void
    {
        $entry = self::saveNewEntry('copied', [self::LINKS => self::threeLinks()]);
        $second = self::reload($entry, self::$secondSiteId);
        $second->setFieldValue(self::LINKS, new LinkCollection());
        self::assertTrue(Craft::$app->getElements()->saveElement($second));

        // What Craft's “copy from another site” does.
        self::field()->copyCrossSiteValue($entry, $second);
        self::assertTrue(Craft::$app->getElements()->saveElement($second));

        self::assertSame(self::uids(self::links($entry)), self::uids(self::links(self::reload($entry, self::$secondSiteId))));
    }

    public function testLinksAreResolvedInTheSiteTheyAreRenderedIn(): void
    {
        $entry = self::saveNewEntry('per-site', [self::LINKS => [self::threeLinks()[0]]]);
        $second = self::reload($entry, self::$secondSiteId);
        $resolver = SmartLinks::getInstance()->getLinkTypes()->getType('url')?->resolver();
        self::assertInstanceOf(FakeResolver::class, $resolver);
        $resolver->calls = [];

        self::field()->getPreviewHtml(self::links($second), $second);
        self::field()->getPreviewHtml(self::links(self::reload($entry)), $entry);

        self::assertSame([self::$secondSiteId, self::$primarySiteId], array_map(static fn(array $call): int => $call[1], $resolver->calls));
    }

    // Rendering

    public function testATemplateRendersAnEntrysLinks(): void
    {
        $entry = self::reload(self::saveNewEntry('rendered', [self::LINKS => self::threeLinks()]));

        $html = Craft::$app->getView()->renderString(
            '{% for link in entry.' . self::LINKS . ' %}{{ links.html(link, entry.siteId) }}|{% endfor %}',
            ['entry' => $entry, 'links' => SmartLinks::getInstance()->getLinks()],
            View::TEMPLATE_MODE_SITE,
        );

        self::assertSame(
            // Craft's Html helper writes attributes in its own fixed order, which has no meaning in HTML.
            '<a class="btn btn-primary" href="https://example.com/About" rel="nofollow noopener" target="_blank" data-track="cta">About</a>|'
            . '<a href="/entries/12#team">Entry 12</a>|'
            . '<a href="mailto:hello@example.com">Email us</a>|',
            $html,
        );
    }

    public function testATemplateNeverRendersStoredContentThatCannotBeReadAsALink(): void
    {
        $entry = self::saveNewEntry('unreadable-on-the-front-end', [self::LINKS => [self::threeLinks()[0]]]);
        $stored = ['version' => 9, 'links' => [['anything' => 'else']]];
        self::writeStoredContent($entry, self::LINKS, $stored);

        // Warnings are collected as Craft's logger dispatches them.
        $target = new class() extends \yii\log\Target {
            /** @var list<string> */
            public array $collected = [];

            public function export(): void
            {
                foreach ($this->messages as $message) {
                    $this->collected[] = (string)$message[0];
                }
            }
        };
        $target->setLevels(['warning']);
        $target->exportInterval = 1;
        $dispatcher = Craft::getLogger()->dispatcher;
        $dispatcher->targets['smartLinksTest'] = $target;

        try {
            $reloaded = self::reload($entry);
            $html = Craft::$app->getView()->renderString(
                '{% for link in entry.' . self::LINKS . ' %}{{ links.html(link, entry.siteId) }}{% endfor %}',
                ['entry' => $reloaded, 'links' => SmartLinks::getInstance()->getLinks()],
                View::TEMPLATE_MODE_SITE,
            );
            Craft::getLogger()->flush();
        } finally {
            unset($dispatcher->targets['smartLinksTest']);
        }

        // Nothing pretends a link is there, and why is on record, naming the field.
        self::assertSame('', $html);
        self::assertInstanceOf(InvalidLinkValue::class, $reloaded->getFieldValue(self::LINKS));
        self::assertNotEmpty(array_filter($target->collected, static fn(string $message): bool => str_contains($message, 'A stored value of the “' . self::LINKS . '” field cannot be read')), Json::encode($target->collected));

        // And reading it changed nothing: the content is still exactly what was stored.
        self::assertSame(self::normalizeStored($stored), self::normalizeStored(self::storedContent($entry, self::LINKS)));
    }

    public function testALinkThatLeadsNowhereIsNotRenderedAsAnAnchor(): void
    {
        $links = SmartLinks::getInstance()->getLinks();
        $entry = self::newEntry('nowhere', [self::LINKS => [
            ['type' => 'entry', 'data' => ['elementId' => '404', 'siteId' => '1'], 'label' => 'Gone'],
            ['type' => 'entry', 'data' => ['elementId' => '403', 'siteId' => '1']],
        ]]);
        [$missing, $disabled] = self::links($entry)->links;

        self::assertNull($links->html($missing, self::$primarySiteId));
        self::assertNull($links->html($disabled, self::$primarySiteId));
        // Previews say why, and give it no other destination.
        $preview = self::field()->getPreviewHtml(self::links($entry), $entry);
        self::assertSame('<span class="smartlinks-status warning" data-status="missing">Gone (leads nowhere: its target doesn’t exist)</span>, <span class="smartlinks-status warning" data-status="disabled">Entry (leads nowhere: its target is disabled)</span>', $preview);
        self::assertSame(0, self::parse($preview)->getElementsByTagName('a')->length);
    }

    public function testAResolverFailureSurfacesInsteadOfLookingRendered(): void
    {
        $entry = self::newEntry('resolver-fails', [self::LINKS => [['type' => 'entry', 'data' => ['elementId' => '500', 'siteId' => '1'], 'label' => 'Fails']]]);
        $links = self::links($entry);
        $renderers = [
            'template rendering' => fn() => SmartLinks::getInstance()->getLinks()->html($links->links[0], self::$primarySiteId),
            'the element index preview' => fn() => self::field()->getPreviewHtml($links, $entry),
            'the read-only view' => fn() => self::field()->getStaticHtml($links, $entry),
        ];

        foreach ($renderers as $where => $render) {
            try {
                $render();
                self::fail("A resolver failure was turned into output in $where.");
            } catch (\Tahadudhiya\SmartLinks\errors\LinkResolutionException $exception) {
                self::assertSame('The entry resolver failed.', $exception->getPrevious()?->getMessage());
            }
        }
    }

    public function testAnInvalidLinkBuiltInCodeIsNeverRendered(): void
    {
        $invalid = new LinkValue(self::UID_A, 'email', new \Tahadudhiya\SmartLinks\Tests\_support\linktypes\FakeEmailData('a@b.c'), attributes: new \Tahadudhiya\SmartLinks\models\LinkAttributes(target: '_blank'));

        foreach ([
            fn() => SmartLinks::getInstance()->getLinks()->html($invalid, self::$primarySiteId),
            fn() => self::field()->getPreviewHtml(new LinkCollection([$invalid]), self::newEntry('invalid-built')),
        ] as $render) {
            try {
                $render();
                self::fail('An invalid link was rendered.');
            } catch (\Tahadudhiya\SmartLinks\errors\LinkResolutionException $exception) {
                self::assertInstanceOf(LinkValidationException::class, $exception->getPrevious());
            }
        }
    }

    public function testRenderedLinksEscapeWhatAuthorsTyped(): void
    {
        $entry = self::saveNewEntry('xss', [self::LINKS => [[
            'type' => 'url',
            'data' => ['url' => 'https://example.com/"><script>alert(1)</script>'],
            'label' => '<script>alert("label")</script>',
            'attributes' => [
                'title' => '" onmouseover="alert(1)',
                'ariaLabel' => '"><img src=x onerror=alert(2)>',
                'class' => '"><b',
                'id' => '"onfocus="x',
                'custom' => ['data-x' => '"><img src=x onerror=alert(1)>', 'aria-describedby' => "' onclick='z"],
            ],
        ]]]);
        $value = $entry->getFieldValue(self::LINKS);

        self::assertInstanceOf(LinkCollection::class, $value, 'The link should be valid apart from what HTML escapes.');
        $link = self::links(self::reload($entry))->links[0];

        $html = (string)SmartLinks::getInstance()->getLinks()->html($link, (int)$entry->siteId);

        self::assertNoInjectedMarkup($html);
        // Every value is still there, as text, inside its own attribute.
        $attributes = self::anchor($html, '<script>alert("label")</script>');
        self::assertSame('" onmouseover="alert(1)', $attributes['title']);
        self::assertSame('"><img src=x onerror=alert(2)>', $attributes['aria-label']);
        self::assertSame('"onfocus="x', $attributes['id']);
        self::assertSame('"><img src=x onerror=alert(1)>', $attributes['data-x']);
        self::assertSame("' onclick='z", $attributes['aria-describedby']);

        foreach ([self::field()->getInputHtml(self::links($entry), $entry), self::field()->getPreviewHtml(self::links($entry), $entry), self::field()->getStaticHtml(self::links($entry), $entry)] as $cpHtml) {
            self::assertNoInjectedMarkup($cpHtml);
        }
    }

    public function testMaliciousTextIsEscapedEverywhereTheEditorShowsIt(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $uid = 'ad1e2f3a-4b5c-4d6e-8f7a-9b0c1d2e3f4a';
        $projectConfig->set(Presets::CONFIG_KEY . ".$uid", ['name' => '"><img src=x onerror=alert("preset")>']);
        $field = self::createField('smartLinksTestEvil', ['types' => ['url'], 'presets' => [$uid]]);

        try {
            $entry = self::newEntry('evil');
            $valid = self::entered([[
                'type' => 'url',
                'data' => ['url' => 'https://example.com/'],
                'presetUid' => $uid,
                'attributes' => ['target' => '"onclick="x', 'download' => true, 'downloadFilename' => '"><img src=x onerror=alert(1)>.pdf'],
            ]]);
            self::assertInstanceOf(LinkCollection::class, $valid);

            // A refused name, echoed in its error message, and malformed stored content.
            $refused = $field->normalizeValueFromRequest(['links' => ['a' => ['type' => 'url', 'data' => ['url' => ['url' => 'https://example.com/']], 'attributes' => ['custom' => [['name' => '"><script>alert(1)</script>', 'value' => 'x']]]]]], $entry);
            $stored = InvalidLinkValue::fromStorage(['version' => 1, 'links' => [['uid' => self::UID_A, 'type' => 'phone', 'label' => '</pre><script>alert(1)</script>']]], [new ValidationError('links[0].type', Code::UNKNOWN_LINK_TYPE, '“{type}” is not an available link type.', ['type' => '<img src=x onerror=alert(2)>'])]);

            foreach ([$field->getInputHtml($valid, $entry), $field->getInputHtml($refused, $entry), $field->getInputHtml($stored, $entry), (string)$field->getSettingsHtml(), (string)SmartLinks::getInstance()->getLinks()->html($valid->links[0], self::$primarySiteId)] as $html) {
                self::assertNoInjectedMarkup($html);
            }

            $anchor = self::anchor((string)SmartLinks::getInstance()->getLinks()->html($valid->links[0], self::$primarySiteId), 'https://example.com/');
            self::assertSame('"onclick="x', $anchor['target']);
            self::assertSame('"><img src=x onerror=alert(1)>.pdf', $anchor['download']);
        } finally {
            Craft::$app->getFields()->deleteField($field);
            $projectConfig->remove(Presets::CONFIG_KEY . ".$uid");
        }
    }

    public function testMarkupThatCannotBeAnAttributeIsRefusedNotStripped(): void
    {
        $value = self::entered([[
            'type' => 'url',
            'data' => ['url' => 'https://example.com/'],
            'attributes' => ['rel' => '"><script>', 'custom' => [['name' => '"><script>', 'value' => 'x']]],
        ]]);

        self::assertInstanceOf(InvalidLinkValue::class, $value);
        self::assertSame([
            ['links[0].attributes.rel[0]', Code::INVALID],
            ['links[0].attributes.custom."><script>', Code::INVALID],
        ], array_map(static fn(ValidationError $error): array => [$error->path, $error->code], $value->errors));
    }

    public function testTheMarkupCanBeReplacedAndTheReplacementOwnsItsEscaping(): void
    {
        Event::on(Links::class, Links::EVENT_DEFINE_LINK_HTML, static function(DefineLinkHtmlEvent $event): void {
            $event->html = '<span data-href="' . htmlspecialchars($event->rendered->href) . '">' . htmlspecialchars($event->rendered->text) . '</span>';
        });

        $entry = self::newEntry('custom-markup', [self::LINKS => [['type' => 'email', 'data' => ['address' => 'hello@example.com'], 'label' => '<b>Hi</b>']]]);

        // The event receives raw values, as documented, and writes whatever it escapes itself.
        self::assertSame('<span data-href="mailto:hello@example.com">&lt;b&gt;Hi&lt;/b&gt;</span>', (string)SmartLinks::getInstance()->getLinks()->html(self::links($entry)->links[0], (int)$entry->siteId));
    }

    // Preview and static display

    public function testThePreviewShowsEachKindOfValueSafely(): void
    {
        $field = self::field();
        $entry = self::newEntry('preview');

        self::assertSame('', $field->getPreviewHtml(new LinkCollection(), $entry));
        self::assertSame('<a href="mailto:hello@example.com">Email us</a>', $field->getPreviewHtml(self::links(self::newEntry('p1', [self::LINKS => [self::threeLinks()[2]]])), $entry));
        self::assertSame('<a href="mailto:hello@example.com">Email us</a>, <a href="/entries/12#team">Entry 12</a>', $field->getPreviewHtml(self::links(self::newEntry('p2', [self::LINKS => [self::threeLinks()[2], self::threeLinks()[1]]])), $entry));
        self::assertSame('<a href="mailto:a@example.com">日本語 &lt;i&gt;</a>', $field->getPreviewHtml(self::links(self::newEntry('p3', [self::LINKS => [['type' => 'email', 'data' => ['address' => 'a@example.com'], 'label' => '日本語 <i>']]])), $entry));
        self::assertSame('<span class="error">This value is invalid. Edit the element to see why.</span>', $field->getPreviewHtml(InvalidLinkValue::fromStorage(['version' => 9], []), $entry));
    }

    public function testTheStaticViewShowsEachKindOfValueSafely(): void
    {
        $field = self::field();
        $entry = self::newEntry('static');

        self::assertStringContainsString('No links.', $field->getStaticHtml(new LinkCollection(), $entry));
        $html = $field->getStaticHtml(self::links(self::newEntry('s1', [self::LINKS => [
            ['type' => 'email', 'data' => ['address' => 'a@example.com'], 'label' => 'Ünï <script>'],
            ['type' => 'entry', 'data' => ['elementId' => '404', 'siteId' => '1']],
        ]])), $entry);
        self::assertStringContainsString('<span class="light">Email:</span> <a href="mailto:a@example.com">Ünï &lt;script&gt;</a>', $html);
        self::assertStringContainsString('(leads nowhere: its target doesn’t exist)', $html);
        self::assertStringContainsString('This value is invalid.', $field->getStaticHtml(InvalidLinkValue::fromStorage('x', []), $entry));
    }

    public function testShowingAnInvalidValueNeverChangesWhatIsStored(): void
    {
        $entry = self::saveNewEntry('shown-only', [self::LINKS => [self::threeLinks()[0]]]);
        $stored = ['version' => 1, 'links' => [['uid' => self::UID_A, 'type' => 'phone', 'data' => ['number' => '+44']]]];
        self::writeStoredContent($entry, self::LINKS, $stored);
        $reloaded = self::reload($entry);

        self::field()->getPreviewHtml($reloaded->getFieldValue(self::LINKS), $reloaded);
        self::field()->getStaticHtml($reloaded->getFieldValue(self::LINKS), $reloaded);
        self::field()->getInputHtml($reloaded->getFieldValue(self::LINKS), $reloaded);

        self::assertSame(self::sortKeys($stored), self::sortKeys((array)self::storedContent($entry, self::LINKS)));
    }

    // Control panel editor

    public function testTheEditorShowsEveryLinkWithItsInputs(): void
    {
        $this->useWebRequest();
        $this->signIn(admin: true);
        $entry = self::saveNewEntry('editor', [self::LINKS => self::threeLinks()]);
        $html = self::field()->getInputHtml(self::links($entry), $entry);

        self::assertStringContainsString('<input type="hidden" name="smartLinksTestLinks" value="">', $html);

        foreach (self::uids(self::links($entry)) as $index => $uid) {
            self::assertStringContainsString("name=\"smartLinksTestLinks[links][link$index][uid]\" value=\"$uid\"", $html);
        }

        // Each type's own inputs, namespaced into the link's data.
        self::assertStringContainsString('name="smartLinksTestLinks[links][link0][data][url][url]" value="https://example.com/About"', $html);
        self::assertStringContainsString('name="smartLinksTestLinks[links][link1][data][entry][elementId]" value="12"', $html);
        self::assertStringContainsString('name="smartLinksTestLinks[links][link0][attributes][custom][0][name]" value="data-track"', $html);
        self::assertStringContainsString('<input type="checkbox" id="smartLinksTestLinks-link0-download" class="checkbox" name="smartLinksTestLinks[links][link0][attributes][download]" value="1"', $html);
        // Email links have no target, so that input is disabled and posts nothing.
        self::assertMatchesRegularExpression('/<select id="smartLinksTestLinks-link2-target" name="smartLinksTestLinks\[links\]\[link2\]\[attributes\]\[target\]" disabled/', $html);
        // A template for new links, with a placeholder key.
        self::assertStringContainsString('name="smartLinksTestLinks[links][__SMARTLINK__][type]"', $html);
        // Markup is never shown as text: every input is really there.
        self::assertStringNotContainsString('&lt;div', $html);
        self::assertStringNotContainsString('&lt;input', $html);
    }

    public function testTheEditorsControlsAreAccessible(): void
    {
        $this->useWebRequest();
        $this->signIn(admin: true);
        $entry = self::newEntry('a11y', [self::LINKS => [self::threeLinks()[0], ['type' => 'url', 'data' => ['url' => 'nope']]]]);
        $value = self::field()->normalizeValueFromRequest(self::post([self::threeLinks()[0] + ['data' => ['url' => 'https://a.example/']], ['type' => 'url', 'data' => ['url' => 'nope']]]), $entry);
        self::assertInstanceOf(InvalidLinkValue::class, $value);
        $html = self::field()->getInputHtml($value, $entry);
        $dom = self::parse($html);
        $xpath = new \DOMXPath($dom);
        $ids = [];

        foreach ($xpath->query('//*[@id]') ?: [] as $node) {
            /** @var \DOMElement $node */
            $ids[$node->getAttribute('id')] = true;
        }

        // Buttons are real buttons that do not submit the form.
        foreach ($xpath->query('//button') ?: [] as $button) {
            /** @var \DOMElement $button */
            self::assertSame('button', $button->getAttribute('type'), $dom->saveHTML($button) ?: '');
        }

        // Collapse toggles say whether they are open, and control a link's body that exists.
        $toggles = $xpath->query('//button[@data-smartlinks-toggle]') ?: [];
        self::assertGreaterThan(0, count($toggles));

        foreach ($toggles as $toggle) {
            /** @var \DOMElement $toggle */
            self::assertSame('true', $toggle->getAttribute('aria-expanded'));
            self::assertArrayHasKey($toggle->getAttribute('aria-controls'), $ids);
        }

        // Every actions menu trigger is labelled and controls its menu, which has the keyboard
        // alternative to dragging: Move up and Move down.
        foreach ($xpath->query('//button[@data-disclosure-trigger]') ?: [] as $trigger) {
            /** @var \DOMElement $trigger */
            self::assertNotSame('', $trigger->getAttribute('aria-label'));
            $menuId = $trigger->getAttribute('aria-controls');
            self::assertArrayHasKey($menuId, $ids);
            self::assertCount(1, $xpath->query("//*[@id='$menuId']//button[@data-smartlinks-action='move-up']") ?: []);
            self::assertCount(1, $xpath->query("//*[@id='$menuId']//button[@data-smartlinks-action='move-down']") ?: []);
        }

        // Every visible input, select and textarea has a label.
        foreach ($xpath->query('//input[not(@type="hidden")] | //select | //textarea') ?: [] as $control) {
            /** @var \DOMElement $control */
            $id = $control->getAttribute('id');
            $labelled = $control->getAttribute('aria-label') !== ''
                || ($id !== '' && count($xpath->query("//label[@for='$id']") ?: []) > 0);
            self::assertTrue($labelled, 'Unlabelled control: ' . ($dom->saveHTML($control) ?: ''));
        }

        // Every label points at a control that exists.
        foreach ($xpath->query('//label[@for]') ?: [] as $label) {
            /** @var \DOMElement $label */
            self::assertArrayHasKey($label->getAttribute('for'), $ids, 'A label for nothing: ' . ($dom->saveHTML($label) ?: ''));
        }

        // A link with errors is described by them.
        $invalid = ($xpath->query("//*[@data-key='link1']") ?: null)?->item(0);
        self::assertInstanceOf(\DOMElement::class, $invalid);
        self::assertArrayHasKey($invalid->getAttribute('aria-describedby'), $ids);

        // Inputs for unsupported features are hidden and disabled, so neither keyboard nor form reaches them.
        foreach ($xpath->query("//*[contains(@class, 'smartlinks-link__feature') and contains(@class, 'hidden')]//input | //*[contains(@class, 'smartlinks-link__feature') and contains(@class, 'hidden')]//select") ?: [] as $hidden) {
            /** @var \DOMElement $hidden */
            self::assertTrue($hidden->hasAttribute('disabled'));
        }
    }

    public function testTheEditorShowsRefusedInputWithItsErrors(): void
    {
        $this->useWebRequest();
        $value = self::field()->normalizeValueFromRequest(self::post([['type' => 'url', 'data' => ['url' => 'nope'], 'label' => 'Typed by the author']]), null);
        self::assertInstanceOf(InvalidLinkValue::class, $value);

        $html = self::field()->getInputHtml($value, self::newEntry('refused'));

        self::assertStringContainsString('value="Typed by the author"', $html);
        self::assertStringContainsString('value="nope"', $html);
        self::assertStringContainsString('smartlinks-link has-errors', $html);
    }

    public function testCraftDecidesWhoMayEditAndShowsOthersTheLinksWithoutInputs(): void
    {
        $this->useWebRequest();
        $entry = self::saveNewEntry('read-only', [self::LINKS => self::threeLinks()]);

        // Editing follows Craft's own entry permissions; the field adds no way around them.
        $author = new TestUser();
        $site = 'editSite:' . Craft::$app->getSites()->getSiteById(self::$primarySiteId)?->uid;
        $author->grantedPermissions = [$site, 'accessCp', 'viewEntries:' . self::$section->uid, 'saveEntries:' . self::$section->uid, 'viewPeerEntries:' . self::$section->uid, 'savePeerEntries:' . self::$section->uid];
        $viewer = new TestUser();
        $viewer->grantedPermissions = [$site, 'accessCp', 'viewEntries:' . self::$section->uid, 'viewPeerEntries:' . self::$section->uid];
        self::assertTrue(Craft::$app->getElements()->canSave($entry, $author));
        self::assertFalse(Craft::$app->getElements()->canSave($entry, $viewer));

        // Craft renders a field statically for a user who may not edit the element.
        Craft::$app->getUser()->setIdentity($viewer);
        $html = (string)self::field()->layoutElement->formHtml($entry, true);

        self::assertStringNotContainsString('<input', $html);
        self::assertStringNotContainsString('<select', $html);
        self::assertStringContainsString('<a href="mailto:hello@example.com">Email us</a>', $html);
    }

    public function testOnlyAdminsMayChangeFieldSettings(): void
    {
        // Craft's own fields controller guards field settings; Smart Links adds no other way in.
        $this->useWebRequest('POST', '/admin/actions/fields/save-field', ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);
        $this->signIn(admin: false, permissions: ['accessCp']);
        $controller = new \craft\controllers\FieldsController('fields', Craft::$app);
        $controller->enableCsrfValidation = false;

        $this->expectException(\yii\web\ForbiddenHttpException::class);
        $controller->runAction('save-field');
    }

    // Copying and pasting

    public function testALinkIsCopiedAsItsData(): void
    {
        $response = $this->runFieldAction('copy', self::editorOf(self::LINKS) + ['links' => [
            'link0' => self::post([self::fullLink()])['links']['link0'],
        ]], $this->editor());

        self::assertSame(200, $response->getStatusCode(), Json::encode($response->data));
        $clipboard = Json::decode($response->data['clipboard']);
        self::assertSame(1, $clipboard['version']);
        self::assertArrayNotHasKey('uid', $clipboard['links'][0]);
        self::assertSame('url', $clipboard['links'][0]['type']);
        self::assertSame(self::PRIMARY, $clipboard['links'][0]['presetUid']);
        self::assertSame(['data-track', 'aria-describedby', 'data-empty'], array_column($clipboard['links'][0]['attributes']['custom'], 'name'));
    }

    public function testALinkThatIsNotValidYetIsNotCopied(): void
    {
        $response = $this->runFieldAction('copy', self::editorOf(self::LINKS) + ['links' => [
            'link0' => self::post([['type' => 'url', 'data' => ['url' => 'nope']]])['links']['link0'],
        ]], $this->editor());

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayNotHasKey('clipboard', $response->data);
    }

    public function testCopiedLinksPasteIntoAFieldThatAllowsThemExactlyAsCopied(): void
    {
        $copy = $this->runFieldAction('copy', self::editorOf(self::LINKS) + ['links' => [
            'link0' => self::post([['type' => 'url', 'data' => ['url' => 'https://a.example/'], 'label' => 'First', 'attributes' => ['custom' => [['name' => 'data-x', 'value' => '1']]]]])['links']['link0'],
            'link1' => self::post([['type' => 'url', 'data' => ['url' => 'https://b.example/'], 'label' => 'Second']])['links']['link0'],
        ]], $this->editor());
        $this->restoreConsoleRequest();

        $result = $this->paste(self::URL_ONLY, $copy->data['clipboard']);

        self::assertSame(200, $result['status']);
        self::assertSame(['new5-1', 'new5-2'], array_column($result['links'], 'key'));
        [$first, $second] = array_column($result['links'], 'html');
        self::assertStringContainsString('name="fields[smartLinksTestUrlOnly][links][new5-1][data][url][url]" value="https://a.example/"', $first);
        self::assertStringContainsString('name="fields[smartLinksTestUrlOnly][links][new5-1][label]" value="First"', $first);
        self::assertStringContainsString('name="fields[smartLinksTestUrlOnly][links][new5-1][attributes][custom][0][name]" value="data-x"', $first);
        // A paste is a new occurrence: no UID until it is saved.
        self::assertStringContainsString('name="fields[smartLinksTestUrlOnly][links][new5-1][uid]" value=""', $first);
        self::assertStringContainsString('value="Second"', $second);
    }

    /**
     * @return array<string, array{string, list<array{string, string}>}>
     */
    public static function refusedPastes(): array
    {
        $url = ['type' => 'url', 'data' => ['url' => 'https://a.example/']];
        $entry = ['type' => 'entry', 'data' => ['elementId' => 12, 'siteId' => 1]];

        return [
            // A clipboard mixing links the destination takes with ones it does not is refused
            // whole, every reason reported: FieldEndpointSecurityTest::testAMixedClipboardIsRefusedWhole.
            'a type the destination does not allow' => [self::clipboard([$entry]), [['links[0].type', 'notSupported']]],
            'a type no longer registered' => [self::clipboard([['type' => 'phone', 'data' => ['number' => '+44']]]), [['links[0].type', 'unknownLinkType']]],
            'a preset the destination does not allow' => [self::clipboard([$url + ['presetUid' => self::PRIMARY]]), [['links[0].presetUid', 'notSupported']]],
            'a preset that no longer exists' => [self::clipboard([$url + ['presetUid' => self::GONE]]), [['links[0].presetUid', 'invalid']]],
            'an unknown attribute' => [self::clipboard([$url + ['attributes' => ['onclick' => 'alert(1)']]]), [['links[0].attributes.onclick', 'unknownKey']]],
            'unknown type-specific data' => [self::clipboard([['type' => 'url', 'data' => ['url' => 'https://a.example/', 'extra' => 'x']]]), [['links[0].data.extra', 'unknownKey']]],
            'an unknown link property' => [self::clipboard([$url + ['href' => 'javascript:alert(1)']]), [['links[0].href', 'unknownKey']]],
            'an event handler as a custom attribute' => [self::clipboard([$url + ['attributes' => ['custom' => [['name' => 'onmouseover', 'value' => 'alert(1)']]]]]), [['links[0].attributes.custom.onmouseover', 'invalid']]],
            'a copied UID' => [self::clipboard([$url + ['uid' => self::UID_A]]), [['links[0].uid', 'unknownKey']]],
            'text that is not JSON' => ['{not json', [['', 'invalid']]],
            'JSON that is not copied links' => ['["a"]', [['', 'invalid']]],
            'an unknown envelope key' => [Json::encode(['version' => 1, 'links' => [$url], 'html' => '<b>']), [['', 'invalid']]],
            'another clipboard version' => [Json::encode(['version' => 2, 'links' => [$url]]), [['version', 'unsupportedVersion']]],
            'no links' => [self::clipboard([]), [['links', 'invalid']]],
            'too much text' => [str_repeat('x', 70000), [['', 'invalid']]],
            'an empty clipboard' => ['', [['', 'invalid']]],
        ];
    }

    /**
     * @param list<array{string, string}> $expected
     */
    #[DataProvider('refusedPastes')]
    public function testAPasteTheDestinationCannotTakeIsRefusedWhole(string $clipboard, array $expected): void
    {
        $result = $this->paste(self::URL_ONLY, $clipboard);

        self::assertSame(400, $result['status']);
        self::assertArrayNotHasKey('links', $result);
        self::assertSame($expected, array_map(static fn(array $code): array => [$code['path'], $code['code']], $result['codes']));
        self::assertNotEmpty($result['errors']);
    }

    public function testACopiedEntryLinkIsNeverTurnedIntoAUrlLink(): void
    {
        $copy = $this->runFieldAction('copy', self::editorOf(self::LINKS) + ['links' => [
            'link0' => self::post([['type' => 'entry', 'data' => ['elementId' => '12', 'siteId' => '1'], 'label' => 'Team']])['links']['link0'],
        ]], $this->editor());
        $this->restoreConsoleRequest();

        $refused = $this->paste(self::URL_ONLY, $copy->data['clipboard']);
        self::assertSame(400, $refused['status']);
        self::assertSame(['Link 1: Entry links are not allowed in this field.'], $refused['errors']);
        $this->restoreConsoleRequest();

        // The same clipboard still pastes where entry links are allowed, unchanged.
        $accepted = $this->paste(self::LINKS, $copy->data['clipboard']);
        self::assertSame(200, $accepted['status']);
        self::assertStringContainsString('name="fields[smartLinksTestLinks][links][new5-1][data][entry][elementId]" value="12"', $accepted['links'][0]['html']);
        self::assertMatchesRegularExpression('/<option value="entry"[^>]*selected/', $accepted['links'][0]['html']);
    }

    public function testTheSameLinkTwiceInAClipboardPastesAsTwoOccurrences(): void
    {
        $link = ['type' => 'url', 'data' => ['url' => 'https://a.example/'], 'label' => 'Twice'];
        $result = $this->paste(self::URL_ONLY, self::clipboard([$link, $link]));

        self::assertSame(200, $result['status']);
        self::assertSame(['new5-1', 'new5-2'], array_column($result['links'], 'key'));

        foreach ($result['links'] as $pasted) {
            self::assertMatchesRegularExpression('/\[uid\]" value=""/', $pasted['html']);
        }
    }

    public function testAPasteBeyondTheFieldsMaximumIsRefused(): void
    {
        $result = $this->paste(self::SINGLE, self::clipboard([['type' => 'url', 'data' => ['url' => 'https://a.example/']]]), ['count' => '1']);

        self::assertSame(400, $result['status']);
        self::assertSame(['This field holds at most 1 link.'], $result['errors']);
    }

    public function testPastedTextIsRenderedAsTextNeverAsMarkup(): void
    {
        $result = $this->paste(self::URL_ONLY, self::clipboard([[
            'type' => 'url',
            'data' => ['url' => 'https://a.example/'],
            'label' => '<script>alert(1)</script>',
            'attributes' => ['title' => '"><img src=x onerror=alert(2)>', 'custom' => [['name' => 'data-x', 'value' => '" onfocus="alert(3)']]],
        ]]));

        self::assertSame(200, $result['status']);
        $html = $result['links'][0]['html'];
        self::assertNoInjectedMarkup($html);
        self::assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
    }
}
