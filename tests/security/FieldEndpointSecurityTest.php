<?php

namespace Tahadudhiya\SmartLinks\Tests\security;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\Json;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SmartLinks\fields\LinkEditor;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;
use Tahadudhiya\SmartLinks\Tests\_support\FieldFixture;
use Tahadudhiya\SmartLinks\Tests\_support\TestUser;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;

/**
 * The field controller is the one endpoint Smart Links has. It stores nothing, but it is still a
 * boundary. Only control panel users reach it, only by POST with a valid CSRF token, asking for
 * JSON. Each request names its editor with a context this install signed, for one field in one
 * field layout element of one element in one site, and with the editor the page says it is; the
 * two must agree, the user must be one Craft lets save that element, and the rules are the
 * field's own, read as they are now. So a context cannot be replayed against another field,
 * another instance of the field, another element or another site, nor widen what a field allows.
 *
 * A guest is refused by Craft's own login requirement, which needs a real web app, so that is
 * tested over HTTP against the host project's web server.
 */
class FieldEndpointSecurityTest extends TestCase
{
    use FieldFixture;

    private static Entry $entry;
    private static Entry $other;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::setUpFixture();
        self::$entry = self::savedEntry('endpoint-entry');
        self::$other = self::savedEntry('endpoint-other');
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownFixture();
        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity(null);
        $this->restoreConsoleRequest();
        parent::tearDown();
    }

    /**
     * @param list<array<string, mixed>>|null $links
     * @return array<string, string>
     */
    private static function clipboard(?array $links = null): array
    {
        return [
            'clipboard' => Json::encode(['version' => 1, 'links' => $links ?? [['type' => 'url', 'data' => ['url' => 'https://a.example/']]]]),
            'count' => '0',
            'operation' => '1',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function pasteInto(Entry $entry, string $handle): array
    {
        return self::editorParams(self::editorFor($entry, $handle)) + self::clipboard();
    }

    /**
     * Pastes, expecting a refusal of the given kind, and that nothing was rendered or stored.
     *
     * @param class-string<\Throwable> $exception
     * @param array<string, mixed> $params
     */
    private function assertRefused(string $exception, array $params, ?TestUser $user = null): void
    {
        $before = $this->contentChecksum();

        try {
            $this->runFieldAction('paste', $params, $user ?? self::author());
            self::fail('The paste was served.');
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($exception, $thrown, $thrown->getMessage());
        }

        self::assertSame($before, $this->contentChecksum());
    }

    public function testTheEditorsOwnContextIsServed(): void
    {
        // The baseline each refusal below differs from by one thing.
        $response = $this->runFieldAction('paste', self::pasteInto(self::$entry, self::LINKS), self::author());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('name="fields[smartLinksTestLinks][links][new1-1][data][url][url]"', $response->data['links'][0]['html']);
    }

    public function testAUserWithoutControlPanelAccessIsRefused(): void
    {
        $user = self::author();
        $user->grantedPermissions = array_values(array_diff($user->grantedPermissions, ['accessCp']));

        $this->assertRefused(ForbiddenHttpException::class, self::pasteInto(self::$entry, self::LINKS), $user);
    }

    public function testAUserCraftDoesNotLetSaveTheElementIsRefused(): void
    {
        $viewer = new TestUser();
        $viewer->grantedPermissions = ['accessCp', 'viewEntries:' . self::$section->uid];

        $this->assertRefused(ForbiddenHttpException::class, self::pasteInto(self::$entry, self::LINKS), $viewer);
    }

    public function testAGetRequestIsRefused(): void
    {
        $this->expectException(MethodNotAllowedHttpException::class);
        $this->runFieldAction('paste', self::pasteInto(self::$entry, self::LINKS), self::author(), withCsrf: false, method: 'GET');
    }

    public function testARequestWithoutAValidCsrfTokenIsRefused(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Unable to verify your data submission.');
        $this->runFieldAction('paste', self::pasteInto(self::$entry, self::LINKS), self::author(), withCsrf: false);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedOperations(): array
    {
        return [
            'none' => [null],
            'zero' => ['0'],
            'a negative number' => ['-1'],
            'a key rather than a number' => ['new5'],
            'markup' => ['1"><script>'],
            'too long' => ['1234567890'],
            'a list' => [['1']],
        ];
    }

    /**
     * Pasted links are rendered under keys made from the number the editor reserved for the
     * paste, so a paste without a well-formed one is refused before anything is rendered.
     */
    #[DataProvider('malformedOperations')]
    public function testAPasteWithoutAReservedOperationNumberIsRefused(mixed $operation): void
    {
        $params = self::pasteInto(self::$entry, self::LINKS);
        unset($params['operation']);

        if ($operation !== null) {
            $params['operation'] = $operation;
        }

        $this->assertRefused(BadRequestHttpException::class, $params);
    }

    public function testASiteRequestIsRefused(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Request must be a control panel request');
        $this->runFieldAction('paste', self::pasteInto(self::$entry, self::LINKS), self::author(), path: '/actions/smart-links/field/');
    }

    public function testAContextIsNotServedForAnotherField(): void
    {
        $params = self::editorParams(self::editorFor(self::$entry, self::LINKS));
        $params['destination'] = self::editorParams(self::editorFor(self::$entry, self::URL_ONLY))['destination'];

        $this->assertRefused(BadRequestHttpException::class, $params + self::clipboard());
    }

    public function testAContextIsNotServedForTheSameFieldsOtherInstance(): void
    {
        // The links field is in the layout twice; each placement is its own editor.
        $params = self::editorParams(self::editorFor(self::$entry, self::LINKS));
        $params['destination'] = self::editorParams(self::editorFor(self::$entry, self::LINKS_AGAIN))['destination'];

        $this->assertRefused(BadRequestHttpException::class, $params + self::clipboard());
    }

    public function testAContextIsNotServedForAnotherElement(): void
    {
        $params = self::editorParams(self::editorFor(self::$entry, self::LINKS));
        $params['destination'] = self::editorParams(self::editorFor(self::$other, self::LINKS))['destination'];

        $this->assertRefused(BadRequestHttpException::class, $params + self::clipboard());
    }

    public function testAContextIsNotServedForAnotherSite(): void
    {
        $inSecondSite = Entry::find()->id(self::$entry->id)->siteId(self::$secondSiteId)->one();
        self::assertInstanceOf(Entry::class, $inSecondSite);

        $params = self::editorParams(self::editorFor($inSecondSite, self::LINKS));
        $params['destination'] = self::editorParams(self::editorFor(self::$entry, self::LINKS))['destination'];
        $this->assertRefused(BadRequestHttpException::class, $params + self::clipboard());

        // And a site's own editor is served only to a user who may edit that site.
        $this->restoreConsoleRequest();
        $this->assertRefused(ForbiddenHttpException::class, self::pasteInto($inSecondSite, self::LINKS), self::author([self::$primarySiteId]));
        $this->restoreConsoleRequest();
        self::assertSame(200, $this->runFieldAction('paste', self::pasteInto($inSecondSite, self::LINKS), self::author())->getStatusCode());
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function contextChanges(): array
    {
        return [
            'scope' => ['scope', 'settings'],
            'field' => ['field', '00000000-0000-4000-8000-000000000000'],
            'layout element' => ['layoutElement', '00000000-0000-4000-8000-000000000000'],
            'element' => ['element', 1],
            'site' => ['site', 999],
            'input name' => ['name', 'smartLinksTestUrlOnly'],
            'editor ID' => ['id', 'smartLinksTestUrlOnly'],
            'namespace' => ['namespace', 'other'],
            'namespaced editor ID' => ['editor', 'fields-smartLinksTestUrlOnly'],
        ];
    }

    #[DataProvider('contextChanges')]
    public function testAnyChangeToAContextIsRefused(string $key, mixed $value): void
    {
        $params = self::editorParams(self::editorFor(self::$entry, self::LINKS));

        // A context is the signature followed by the JSON it signs.
        $json = (string)preg_replace('/^[0-9a-f]{64}/', '', $params['context']);
        $signature = substr($params['context'], 0, 64);
        $binding = Json::decode($json);
        self::assertArrayHasKey($key, $binding);
        $binding[$key] = $value;
        $params['context'] = $signature . Json::encode($binding);
        // The page may say anything about itself; it still has to match a signed context.
        $params['destination'] = Json::encode(['editor' => $binding['editor'], 'field' => $binding['field'], 'element' => $binding['element'], 'site' => $binding['site']]);

        $this->assertRefused(BadRequestHttpException::class, $params + self::clipboard());
    }

    public function testAnUnsignedContextIsRefused(): void
    {
        $params = self::editorParams(self::editorFor(self::$entry, self::LINKS));
        $params['context'] = (string)preg_replace('/^[0-9a-f]{64}/', '', $params['context']);

        $this->assertRefused(BadRequestHttpException::class, $params + self::clipboard());
    }

    public function testAContextIsServedWithTheFieldsRulesAsTheyAreNow(): void
    {
        // The page was rendered when entry links were allowed…
        $params = self::editorParams(self::editorFor(self::$entry, self::LINKS));
        $fields = Craft::$app->getFields();
        $field = $fields->getFieldByHandle(self::LINKS);
        self::assertInstanceOf(SmartLinkField::class, $field);
        $field->types = ['url'];
        self::assertTrue($fields->saveField($field), Json::encode($field->getErrors()));

        try {
            // …but the field no longer allows them, so its old context cannot paste one.
            $response = $this->runFieldAction('paste', $params + self::clipboard([['type' => 'entry', 'data' => ['elementId' => 12, 'siteId' => 1]]]), self::author());
            self::assertSame(400, $response->getStatusCode());
            self::assertSame(['Link 1: Entry links are not allowed in this field.'], $response->data['errors']);
        } finally {
            $field->types = ['url', 'entry', 'email'];
            self::assertTrue($fields->saveField($field), Json::encode($field->getErrors()));
        }
    }

    public function testAMixedClipboardIsRefusedWhole(): void
    {
        $response = $this->runFieldAction('paste', self::editorParams(self::editorFor(self::$entry, self::URL_ONLY)) + self::clipboard([
            ['type' => 'url', 'data' => ['url' => 'https://a.example/']],
            ['type' => 'entry', 'data' => ['elementId' => 12, 'siteId' => 1]],
            ['type' => 'email', 'data' => ['address' => 'a@b.c']],
            ['type' => 'url', 'data' => ['url' => 'nope']],
        ]), self::author());

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayNotHasKey('links', $response->data);
        // Every reason, link by link, so the author sees exactly why nothing was pasted.
        self::assertSame([
            ['links[1].type', 'notSupported'],
            ['links[2].type', 'notSupported'],
            ['links[3].data.url', 'invalid'],
        ], array_map(static fn(array $code): array => [$code['path'], $code['code']], $response->data['codes']));
    }

    /**
     * A field's default-links editor, as Craft's field settings render it. Every Smart Links
     * field's settings are rendered in the same namespace, so two fields' editors have the same
     * editor ID; only the field tells them apart.
     */
    private static function settingsEditor(SmartLinkField $field): LinkEditor
    {
        $view = Craft::$app->getView();
        $namespace = $view->getNamespace();
        $view->setNamespace('types[' . SmartLinkField::class . ']');

        try {
            return LinkEditor::forSettings($field);
        } finally {
            $view->setNamespace($namespace);
        }
    }

    private static function admin(): TestUser
    {
        $admin = new TestUser();
        $admin->admin = true;

        return $admin;
    }

    private static function savedField(string $handle): SmartLinkField
    {
        $field = Craft::$app->getFields()->getFieldByHandle($handle);
        self::assertInstanceOf(SmartLinkField::class, $field);

        return $field;
    }

    public function testAFieldsSettingsEditorIsServedToAnAdmin(): void
    {
        $response = $this->runFieldAction('paste', self::editorParams(self::settingsEditor(self::savedField(self::LINKS))) + self::clipboard(), self::admin());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('name="types[' . SmartLinkField::class . '][defaultLinksInput][links][new1-1][type]"', $response->data['links'][0]['html']);
    }

    public function testTwoFieldsSettingsEditorsHaveTheSameIdButCannotStandInForEachOther(): void
    {
        $a = self::settingsEditor(self::savedField(self::LINKS));
        $b = self::settingsEditor(self::savedField(self::URL_ONLY));
        self::assertSame($a->destination()['editor'] ?? null, $b->destination()['editor'] ?? null);

        $aIntoB = self::editorParams($a);
        $aIntoB['destination'] = self::editorParams($b)['destination'];
        $this->assertRefused(BadRequestHttpException::class, $aIntoB + self::clipboard(), self::admin());

        $this->restoreConsoleRequest();
        $bIntoA = self::editorParams($b);
        $bIntoA['destination'] = self::editorParams($a)['destination'];
        $this->assertRefused(BadRequestHttpException::class, $bIntoA + self::clipboard(), self::admin());
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function settingsContextChanges(): array
    {
        return [
            'field UID' => ['field', '00000000-0000-4000-8000-000000000000'],
            'editor ID' => ['id', 'otherLinks'],
            'namespace' => ['namespace', 'types[other]'],
            'namespaced editor ID' => ['editor', 'types-other-defaultLinks'],
            'scope' => ['scope', 'element'],
        ];
    }

    #[DataProvider('settingsContextChanges')]
    public function testAnyChangeToASettingsContextIsRefused(string $key, mixed $value): void
    {
        $params = self::editorParams(self::settingsEditor(self::savedField(self::LINKS)));
        $signature = substr($params['context'], 0, 64);
        $binding = Json::decode(substr($params['context'], 64));
        $binding[$key] = $value;
        $params['context'] = $signature . Json::encode($binding);
        $params['destination'] = Json::encode(['editor' => $binding['editor'], 'field' => $binding['field'], 'element' => $binding['element'], 'site' => $binding['site']]);

        $this->assertRefused(BadRequestHttpException::class, $params + self::clipboard(), self::admin());
    }

    public function testAnUnsignedSettingsContextIsRefused(): void
    {
        $params = self::editorParams(self::settingsEditor(self::savedField(self::LINKS)));
        $params['context'] = substr($params['context'], 64);

        $this->assertRefused(BadRequestHttpException::class, $params + self::clipboard(), self::admin());
    }

    public function testASettingsContextOutlivesNeitherItsFieldNorAFieldThatReplacesIt(): void
    {
        $fields = Craft::$app->getFields();
        $field = self::createField('smartLinksTestShortLived', ['types' => ['url']]);
        $params = self::editorParams(self::settingsEditor($field));
        self::assertTrue($fields->deleteField($field));

        $this->assertRefused(BadRequestHttpException::class, $params + self::clipboard(), self::admin());

        // A new field under the same handle is another field, with another UID.
        $replacement = self::createField('smartLinksTestShortLived', ['types' => ['url']]);
        self::assertNotSame($field->uid, $replacement->uid);
        $this->restoreConsoleRequest();
        $this->assertRefused(BadRequestHttpException::class, $params + self::clipboard(), self::admin());
        self::assertTrue($fields->deleteField($replacement));
    }

    public function testASettingsContextIsServedOnlyToAnAdminWhereAdminChangesAreAllowed(): void
    {
        $params = self::editorParams(self::settingsEditor(self::savedField(self::LINKS))) + self::clipboard();

        $this->assertRefused(ForbiddenHttpException::class, $params, self::author());

        $general = Craft::$app->getConfig()->getGeneral();
        $allowed = $general->allowAdminChanges;
        $general->allowAdminChanges = false;

        try {
            $this->restoreConsoleRequest();
            $this->assertRefused(ForbiddenHttpException::class, $params, self::admin());
        } finally {
            $general->allowAdminChanges = $allowed;
        }
    }

    public function testASettingsEditorUsesTheFieldsMaximumAsItIsNow(): void
    {
        // Rendered while the field held one link; it now holds any number, and the old context
        // gets the field's current maximum, not the one it was rendered with.
        $fields = Craft::$app->getFields();
        $field = self::createField('smartLinksTestMax', ['types' => ['url'], 'multiple' => false]);
        $params = self::editorParams(self::settingsEditor($field));

        $refused = $this->runFieldAction('paste', $params + self::clipboard([['type' => 'url', 'data' => ['url' => 'https://a.example/']], ['type' => 'url', 'data' => ['url' => 'https://b.example/']]]), self::admin());
        self::assertSame(400, $refused->getStatusCode());

        $field->multiple = true;
        self::assertTrue($fields->saveField($field), Json::encode($field->getErrors()));
        $this->restoreConsoleRequest();
        $accepted = $this->runFieldAction('paste', $params + self::clipboard([['type' => 'url', 'data' => ['url' => 'https://a.example/']], ['type' => 'url', 'data' => ['url' => 'https://b.example/']]]), self::admin());
        self::assertSame(200, $accepted->getStatusCode());
        self::assertTrue($fields->deleteField($field));
    }

    public function testAFieldNotSavedYetHasNoSettingsContext(): void
    {
        $editor = self::settingsEditor(new SmartLinkField(['handle' => 'unsaved', 'types' => ['url']]));

        self::assertNull($editor->context());
        self::assertNull($editor->destination());
    }

    public function testAnEditorForAnElementNotSavedYetIsBoundToItsField(): void
    {
        $new = new Entry(['sectionId' => self::$section->id, 'typeId' => self::$entryType->id, 'siteId' => self::$primarySiteId]);
        $editor = self::editorFor($new, self::URL_ONLY);

        self::assertNull($editor->destination()['element'] ?? null);
        self::assertSame(200, $this->runFieldAction('paste', self::editorParams($editor) + self::clipboard(), self::author())->getStatusCode());
    }

    public function testAFieldNotSavedYetHasNoContext(): void
    {
        // Nothing can name such an editor, so it offers no copying or pasting at all.
        $editor = LinkEditor::forField(new SmartLinkField(['handle' => 'unsaved', 'types' => ['url']]), null);

        self::assertNull($editor->context());
        self::assertNull($editor->destination());
    }

    public function testCopyNeedsTheEditorsContextToo(): void
    {
        $params = self::editorParams(self::editorFor(self::$entry, self::LINKS));
        $params['destination'] = self::editorParams(self::editorFor(self::$other, self::LINKS))['destination'];

        $this->expectException(BadRequestHttpException::class);
        $this->runFieldAction('copy', $params + ['links' => ['a' => ['type' => 'url', 'data' => ['url' => ['url' => 'https://a.example/']]]]], self::author());
    }

    public function testCopyingAndPastingStoreNothing(): void
    {
        $before = $this->contentChecksum();

        $copy = $this->runFieldAction('copy', self::editorParams(self::editorFor(self::$entry, self::LINKS)) + ['links' => ['a' => ['type' => 'url', 'data' => ['url' => ['url' => 'https://a.example/']]]]], self::author());
        self::assertSame(200, $copy->getStatusCode());
        $this->restoreConsoleRequest();
        self::assertSame(200, $this->runFieldAction('paste', self::editorParams(self::editorFor(self::$entry, self::LINKS)) + self::clipboard(), self::author())->getStatusCode());

        self::assertSame($before, $this->contentChecksum());
    }

    public function testAGuestIsRefusedByCraftsLoginRequirement(): void
    {
        // A real request to the host project's web server: its own session, cookies and CSRF.
        $client = new Client(['base_uri' => 'http://localhost', 'cookies' => true, 'http_errors' => false, 'timeout' => 10, 'allow_redirects' => false]);

        $login = $client->get('/admin/login');
        self::assertSame(200, $login->getStatusCode(), 'The host project’s web server must serve the control panel at http://localhost/admin.');
        self::assertMatchesRegularExpression('/csrfTokenName":"([^"]+)".*?csrfTokenValue":"([^"]+)"/s', (string)$login->getBody());
        preg_match('/csrfTokenName":"([^"]+)".*?csrfTokenValue":"([^"]+)"/s', (string)$login->getBody(), $csrf);

        $response = $client->post('/admin/actions/smart-links/field/paste', [
            'headers' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
            'form_params' => [$csrf[1] => stripslashes($csrf[2])] + self::pasteInto(self::$entry, self::LINKS),
        ]);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringNotContainsString('smartlinks-link', (string)$response->getBody());
    }

    private function contentChecksum(): string
    {
        return md5(Json::encode([
            (new Query())->from(Table::ELEMENTS)->count(),
            (new Query())->select(['content'])->from(Table::ELEMENTS_SITES)->orderBy(['id' => SORT_ASC])->column(),
            (new Query())->from(Table::RELATIONS)->count(),
            (new Query())->from(Table::QUEUE)->count(),
        ]));
    }
}
