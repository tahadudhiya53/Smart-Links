<?php

namespace Tahadudhiya\SmartLinks\Tests\unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\SmartLinks\SmartLinks;

/**
 * Covers what Craft reads from the plugin itself: autoloading, the metadata Craft installs it
 * by, and the files it looks for by convention. That Craft actually boots the class as a plugin
 * is proven in the integration suite, against a real Craft application.
 */
class PluginTest extends TestCase
{
    /** @var string The Composer version development stays on until the user releases. */
    private const VERSION = '5.0.0';

    /** @var string The schema version, which only moves when a released schema changes. */
    private const SCHEMA_VERSION = '1.0.0';

    private const ROOT = __DIR__ . '/../..';

    /**
     * @return array<string, mixed>
     */
    private static function composer(): array
    {
        return json_decode((string)file_get_contents(self::ROOT . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testPluginClassAutoloads(): void
    {
        self::assertTrue(class_exists(SmartLinks::class));
    }

    public function testComposerMetadataMatchesPluginClass(): void
    {
        $composer = self::composer();

        self::assertSame('craft-plugin', $composer['type']);
        self::assertSame('tahadudhiya53/craft-smart-links', $composer['name']);
        self::assertSame(SmartLinks::class, $composer['extra']['class']);
        self::assertSame('smart-links', $composer['extra']['handle']);
        self::assertSame('Smart Links', $composer['extra']['name']);
    }

    public function testComposerAutoloadsBothTheSourceAndTestNamespaces(): void
    {
        $composer = self::composer();

        self::assertSame('src/', $composer['autoload']['psr-4']['Tahadudhiya\\SmartLinks\\']);
        self::assertSame('tests/', $composer['autoload-dev']['psr-4']['Tahadudhiya\\SmartLinks\\Tests\\']);
    }

    public function testCraftAndPhpRequirementsAreDeclared(): void
    {
        $composer = self::composer();

        self::assertSame('^5.9.0', $composer['require']['craftcms/cms']);
        self::assertSame('>=8.2.0', $composer['require']['php']);
    }

    public function testTheVersionIsTheOneDevelopmentStaysOn(): void
    {
        // Development work is not a release, so neither version moves until the user releases.
        // This is what fails if a change bumps one out of habit.
        $defaults = (new \ReflectionClass(SmartLinks::class))->getDefaultProperties();

        self::assertSame(self::VERSION, self::composer()['version']);
        self::assertSame(self::SCHEMA_VERSION, $defaults['schemaVersion']);
    }

    public function testThereIsNeverMoreThanTheInstallMigration(): void
    {
        // Unreleased schema changes are made by editing the install migration and reinstalling,
        // so any other migration file means that workflow has been departed from.
        $migrations = glob(self::ROOT . '/src/migrations/*.php') ?: [];

        self::assertSame([], array_values(array_diff(array_map('basename', $migrations), ['Install.php'])));
    }

    public function testThePluginHasAControlPanelSectionAndNoSettingsYet(): void
    {
        $defaults = (new \ReflectionClass(SmartLinks::class))->getDefaultProperties();

        self::assertTrue($defaults['hasCpSection']);
        self::assertFalse($defaults['hasCpSettings']);
        self::assertFileExists(self::ROOT . '/src/templates/index.twig');
    }

    public function testBothControlPanelIconsExist(): void
    {
        // Craft reads them from different places: the plugin's icon from the package, the nav
        // icon from a file it looks for by name.
        self::assertFileExists(self::ROOT . '/src/icon.svg');
        self::assertFileExists(self::ROOT . '/src/icon-mask.svg');
    }

    public function testTheNavIconIsDrawnWithFillsAlone(): void
    {
        // Craft recolours the nav icon with `fill: currentColor; stroke-width: 0`, so anything
        // drawn with a stroke is erased rather than recoloured, and the icon disappears.
        $mask = (string)file_get_contents(self::ROOT . '/src/icon-mask.svg');

        self::assertStringNotContainsString('stroke', $mask);
    }

    public function testEveryTranslatedTemplateStringHasASourceMessage(): void
    {
        $messages = require self::ROOT . '/src/translations/en/smart-links.php';
        $missing = [];

        foreach (glob(self::ROOT . '/src/templates/*.twig') ?: [] as $template) {
            preg_match_all('/"([^"]+)"\s*\|\s*t\(\s*\'smart-links\'/', (string)file_get_contents($template), $matches);

            foreach ($matches[1] as $message) {
                if (!array_key_exists($message, $messages)) {
                    $missing[] = $message;
                }
            }
        }

        self::assertSame([], $missing);
    }
}
