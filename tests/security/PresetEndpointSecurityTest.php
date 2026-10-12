<?php

namespace Tahadudhiya\SmartLinks\Tests\security;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Json;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SmartLinks\controllers\PresetsController;
use Tahadudhiya\SmartLinks\services\Presets;
use Tahadudhiya\SmartLinks\SmartLinks;
use Tahadudhiya\SmartLinks\Tests\_support\FieldFixture;
use Tahadudhiya\SmartLinks\Tests\_support\TestUser;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

/**
 * The presets controller changes project config, so it is a privileged boundary: only control
 * panel users with the “Manage link presets” permission reach it, changes need POST, CSRF and an
 * environment that allows administrative changes, and what a request names must be an existing
 * preset, never taken as something else. Permission, CSRF and admin-changes refusals through
 * Craft's own controller handling are in the presets integration test; here, a guest over real
 * HTTP, and forged requests.
 */
class PresetEndpointSecurityTest extends TestCase
{
    use FieldFixture;

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
        $this->restoreConsoleRequest();
        parent::tearDown();
    }

    private static function manager(): TestUser
    {
        $user = new TestUser();
        $user->grantedPermissions = ['accessCp', 'accessPlugin-smart-links', SmartLinks::PERMISSION_MANAGE_PRESETS];

        return $user;
    }

    /**
     * The presets as the host project has stored them, read on a connection of its own: this
     * test's connection is inside a transaction that would not see what a web server commits.
     *
     * @return list<array<string, mixed>>
     */
    private static function storedPresets(): array
    {
        $db = clone Craft::$app->getDb();
        $db->close();

        try {
            return (new Query())->select(['path', 'value'])->from(Table::PROJECTCONFIG)->where(['like', 'path', Presets::CONFIG_KEY . '.%', false])->orderBy(['path' => SORT_ASC])->all($db);
        } finally {
            $db->close();
        }
    }

    /**
     * @return array<string, array{string, string, array<string, string>}>
     */
    public static function guestRequests(): array
    {
        return [
            'the list' => ['GET', '/admin/smart-links/presets', []],
            'the form' => ['GET', '/admin/smart-links/presets/new', []],
            'saving' => ['POST', '/admin/actions/smart-links/presets/save', ['presetUid' => '', 'name' => 'Forged', 'enabled' => '1']],
            'deleting' => ['POST', '/admin/actions/smart-links/presets/delete', ['id' => '6a3e9b1c-2f4d-4e8a-9c7b-1d2e3f4a5b6c']],
            'reordering' => ['POST', '/admin/actions/smart-links/presets/reorder', ['ids' => '[]']],
        ];
    }

    /**
     * @param array<string, string> $params
     */
    #[DataProvider('guestRequests')]
    public function testAGuestIsRefusedOverHttp(string $method, string $path, array $params): void
    {
        // A real request to the host project's web server: its own session, cookies and CSRF.
        $client = new Client(['base_uri' => 'http://localhost', 'cookies' => true, 'http_errors' => false, 'timeout' => 10, 'allow_redirects' => false]);
        $login = $client->get('/admin/login');
        self::assertSame(200, $login->getStatusCode(), 'The host project’s web server must serve the control panel at http://localhost/admin.');
        preg_match('/csrfTokenName":"([^"]+)".*?csrfTokenValue":"([^"]+)"/s', (string)$login->getBody(), $csrf);
        self::assertCount(3, $csrf);
        $before = self::storedPresets();

        $response = $method === 'GET'
            ? $client->get($path)
            : $client->post($path, ['headers' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'], 'form_params' => [$csrf[1] => stripslashes($csrf[2])] + $params]);

        // A page sends a guest to the login form; an action is refused.
        if ($method === 'GET') {
            self::assertSame(302, $response->getStatusCode());
            self::assertStringContainsString('/admin/login', $response->getHeaderLine('Location'));
        } else {
            self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        }

        self::assertStringNotContainsString('smartlinks-presets-table', (string)$response->getBody());
        self::assertSame($before, self::storedPresets());
    }

    public function testWhereAdministrativeChangesAreOffPresetsCanOnlyBeViewedOverHttp(): void
    {
        // A second web server for the host project, Craft's own `web/index.php` under PHP's
        // built-in server, whose Craft reads `allowAdminChanges = false` from a secrets file
        // (Craft's secrets come before .env and the environment); no host file is changed. It
        // shares the database, so an admin signs in with a one-time token, which must exist
        // outside this test's rolled-back transaction: it is made, and removed, on a connection
        // of its own.
        $admin = \craft\elements\User::find()->admin()->status(null)->one();
        self::assertNotNull($admin);
        $directory = sys_get_temp_dir() . '/smart-links-admin-changes-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents("$directory/secrets.php", "<?php return ['CRAFT_ALLOW_ADMIN_CHANGES' => false];");
        $port = 18000 + random_int(0, 999);
        $root = Craft::getAlias('@root');
        $server = proc_open(['php', '-S', "127.0.0.1:$port", '-t', "$root/web", "$root/web/index.php"], [['pipe', 'r'], ['file', "$directory/server.log", 'a'], ['file', "$directory/server.log", 'a']], $pipes, $root, ['CRAFT_SECRETS_PATH' => "$directory/secrets.php"] + getenv());
        self::assertIsResource($server);
        $db = clone Craft::$app->getDb();
        $db->close();
        $token = Craft::$app->getSecurity()->generateRandomString(32);
        $db->createCommand()->insert(Table::TOKENS, ['token' => $token, 'route' => Json::encode(['users/impersonate-with-token', ['userId' => $admin->id, 'prevUserId' => $admin->id]]), 'usageLimit' => 1, 'usageCount' => 0, 'expiryDate' => \craft\helpers\Db::prepareDateForDb(new \DateTime('+10 minutes'))])->execute();

        try {
            $client = new Client(['base_uri' => "http://127.0.0.1:$port", 'cookies' => true, 'http_errors' => false, 'timeout' => 20, 'allow_redirects' => false]);

            for ($tries = 0; $tries < 50 && !@fsockopen('127.0.0.1', $port); $tries++) {
                usleep(100000);
            }

            $client->get("/admin?token=$token");
            $index = $client->get('/admin/smart-links/presets');
            self::assertSame(200, $index->getStatusCode(), (string)file_get_contents("$directory/server.log"));
            $page = (string)$index->getBody();
            self::assertStringContainsString('Changes to these settings aren’t permitted in this environment.', $page);
            self::assertStringNotContainsString('smart-links/presets/delete', $page);
            self::assertStringNotContainsString('smart-links/presets/new', $page);

            // The host project's own presets, as the second server reads them; this test's
            // project config is its own until it ends.
            $hostPresets = array_map(static fn(string $path): string => explode('.', $path)[2], (new Query())->select(['path'])->from(Table::PROJECTCONFIG)->where(['like', 'path', Presets::CONFIG_KEY . '.%.name', false])->orderBy(['path' => SORT_ASC])->column($db));
            self::assertNotEmpty($hostPresets, 'The host project needs a preset.');
            $uid = $hostPresets[0];
            $edit = (string)$client->get("/admin/smart-links/presets/$uid")->getBody();
            self::assertStringContainsString('Changes to these settings aren’t permitted in this environment.', $edit);
            self::assertStringNotContainsString('smart-links/presets/save', $edit);

            preg_match('/csrfTokenName":"([^"]+)".*?csrfTokenValue":"([^"]+)"/s', $page, $csrf);
            self::assertCount(3, $csrf);
            $before = self::storedPresets();
            $post = static fn(string $action, array $params) => $client->post("/admin/actions/smart-links/presets/$action", [
                'headers' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
                'form_params' => [$csrf[1] => stripslashes($csrf[2])] + $params,
            ]);

            foreach ([
                'create' => ['save', ['presetUid' => '', 'name' => 'Not allowed here', 'enabled' => '1']],
                'edit' => ['save', ['presetUid' => $uid, 'name' => 'Renamed here', 'enabled' => '1']],
                'disable' => ['save', ['presetUid' => $uid, 'name' => 'Renamed here', 'enabled' => '']],
                'delete' => ['delete', ['id' => $uid]],
                'reorder' => ['reorder', ['ids' => Json::encode(array_reverse($hostPresets))]],
            ] as $what => [$action, $params]) {
                $response = $post($action, $params);
                self::assertSame(403, $response->getStatusCode(), "$what: " . $response->getBody());
            }

            self::assertSame($before, self::storedPresets());
        } finally {
            $db->createCommand()->delete(Table::TOKENS, ['token' => $token])->execute();
            $db->close();
            proc_terminate($server);
            proc_close($server);
            array_map('unlink', glob("$directory/*") ?: []);
            rmdir($directory);
        }
    }

    /**
     * @return array<string, array{array<string, mixed>, class-string<\Throwable>}>
     */
    public static function forgedSaves(): array
    {
        return [
            'a preset UID that is not text' => [['presetUid' => [self::PRIMARY], 'name' => 'Forged', 'enabled' => '1'], BadRequestHttpException::class],
            'a UID no preset has' => [['presetUid' => self::GONE, 'name' => 'Forged', 'enabled' => '1'], NotFoundHttpException::class],
            'no preset UID at all' => [['name' => 'Forged', 'enabled' => '1'], BadRequestHttpException::class],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('forgedSaves')]
    public function testASaveNamingNoExistingPresetIsRefusedNotTakenAsANewOne(array $params, string $exception): void
    {
        $before = Craft::$app->getProjectConfig()->get(Presets::CONFIG_KEY);

        try {
            $this->runControllerAction(PresetsController::class, 'presets', 'save', $params, self::manager());
            self::fail('A forged save was served.');
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($exception, $thrown);
        }

        self::assertSame($before, Craft::$app->getProjectConfig()->get(Presets::CONFIG_KEY));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function forgedSettings(): array
    {
        return [
            'an attribute a preset has no setting for' => [['attributes' => ['title' => 'Read more']], 'linkAttributes'],
            'an attribute no link has' => [['attributes' => ['onclick' => 'alert(1)']], 'linkAttributes'],
            'a custom attribute that runs script' => [['attributes' => ['custom' => [['name' => 'onmouseover', 'value' => 'alert(1)']]]], 'custom'],
            'a target with markup' => [['attributes' => ['target' => '"><script>']], 'target'],
            'attributes that are not an object' => [['attributes' => 'target=_blank'], 'linkAttributes'],
            'a name that is not text' => [['name' => ['Forged']], 'name'],
            'a lock on custom attributes' => [['locked' => ['custom']], 'locked'],
            'a lock on a feature that does not exist' => [['locked' => ['href']], 'locked'],
            'a link type that is not registered' => [['types' => ['javascript']], 'types'],
            'enabled left out' => [['enabled' => null], 'enabled'],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('forgedSettings')]
    public function testForgedSettingsAreRefusedAndNothingIsWritten(array $params, string $setting): void
    {
        $before = Craft::$app->getProjectConfig()->get(Presets::CONFIG_KEY);
        $params += ['presetUid' => '', 'name' => 'Forged', 'enabled' => '1', 'types' => '', 'urlSuffix' => '', 'attributes' => '', 'locked' => ''];

        $response = $this->runControllerAction(PresetsController::class, 'presets', 'save', array_filter($params, static fn(mixed $value): bool => $value !== null), self::manager());

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey($setting, $response->data['errors'] ?? [], json_encode($response->data['errors'] ?? null) ?: '');
        self::assertSame($before, Craft::$app->getProjectConfig()->get(Presets::CONFIG_KEY));
    }
}
