<?php

namespace Tahadudhiya\SmartLinks\Tests\integration;

use Craft;
use craft\enums\CmsEdition;
use craft\web\twig\variables\Cp;
use craft\web\View;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SmartLinks\SmartLinks;
use Tahadudhiya\SmartLinks\Tests\_support\TestUser;
use yii\base\Component;

/**
 * Runs against the surrounding Craft project with Smart Links installed in it, which is the only
 * place the plugin's bootstrap and Craft's handling of its control panel section can be shown to
 * actually hold together.
 */
class InstallationTest extends TestCase
{
    /** @var string The permission Craft registers for, and demands of, the plugin's CP section. */
    private const ACCESS_PLUGIN = 'accessPlugin-smart-links';

    /** @var string The ID Craft gives the section's nav item. Its URL comes back absolute. */
    private const NAV_ID = 'nav-smart-links';

    private ?Component $consoleRequest = null;

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity(null);

        if ($this->consoleRequest !== null) {
            Craft::$app->set('request', $this->consoleRequest);
            $this->consoleRequest = null;
        }

        parent::tearDown();
    }

    private function plugin(): SmartLinks
    {
        $plugin = Craft::$app->getPlugins()->getPlugin('smart-links');

        self::assertInstanceOf(SmartLinks::class, $plugin, 'Smart Links must be installed and enabled in the host project.');

        return $plugin;
    }

    /**
     * @param string[] $permissions
     */
    private function signIn(bool $admin, array $permissions = []): void
    {
        $user = new TestUser();
        $user->admin = $admin;
        $user->grantedPermissions = $permissions;

        Craft::$app->getUser()->setIdentity($user);
    }

    /**
     * Builds the control panel nav the way Craft does while serving a control panel page, which
     * needs a web request to work out the selected item.
     *
     * @return string[]
     */
    private function navIds(): array
    {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['REQUEST_URI'] = '/admin/dashboard';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->consoleRequest ??= Craft::$app->getRequest();
        Craft::$app->set('request', new \craft\web\Request());

        return array_column((new Cp())->nav(), 'id');
    }

    /**
     * @param array<string, array{nested?: array<string, mixed>}> $permissions
     * @return string[]
     */
    private static function permissionNames(array $permissions): array
    {
        $names = [];

        foreach ($permissions as $name => $definition) {
            $names[] = $name;
            array_push($names, ...self::permissionNames($definition['nested'] ?? []));
        }

        return $names;
    }

    public function testCraftBootsThePluginFromItsPackage(): void
    {
        $plugin = $this->plugin();

        self::assertSame('smart-links', $plugin->id);
        self::assertSame('Smart Links', $plugin->name);
        self::assertTrue(Craft::$app->getPlugins()->isPluginEnabled('smart-links'));
    }

    public function testCraftRegistersTheSectionAccessPermission(): void
    {
        $this->plugin();

        if (Craft::$app->edition === CmsEdition::Solo) {
            self::markTestSkipped('Craft Solo has no user permissions: only admins reach the control panel.');
        }

        // Team lists it at the top level, Pro and Enterprise nest it under control panel access.
        $names = self::permissionNames(array_merge(...array_column(Craft::$app->getUserPermissions()->getAllPermissions(), 'permissions')));

        self::assertContains(self::ACCESS_PLUGIN, $names);
    }

    public function testTheNavItemIsShownToAUserHoldingTheAccessPermission(): void
    {
        $this->plugin();
        $this->signIn(admin: false, permissions: ['accessCp', self::ACCESS_PLUGIN]);

        self::assertContains(self::NAV_ID, $this->navIds());
    }

    public function testTheNavItemIsHiddenFromAUserWithoutTheAccessPermission(): void
    {
        // Reaching the control panel is not the same as being allowed into Smart Links.
        $this->plugin();
        $this->signIn(admin: false, permissions: ['accessCp']);

        self::assertNotContains(self::NAV_ID, $this->navIds());
    }

    public function testTheControlPanelTemplateCompiles(): void
    {
        $this->plugin();

        $view = Craft::$app->getView();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        // Loading compiles the template and everything it extends, so a broken tag or an unknown
        // filter fails here rather than in front of a user.
        self::assertSame('smart-links/index', $view->getTwig()->load('smart-links/index')->getTemplateName());
    }
}
