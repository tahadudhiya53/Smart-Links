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

    /**
     * Every message source code translates, or reports as a validation error, is in the
     * translation file, so none reaches an author untranslated.
     */
    public function testEveryMessageInSourceCodeHasASourceMessage(): void
    {
        $messages = require self::ROOT . '/src/translations/en/smart-links.php';
        $missing = [];
        $sources = new \RegexIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/src')), '/\.php$/');
        $literal = "'((?:[^'\\\\]|\\\\.)*)'";
        $patterns = [
            "/Craft::t\\(\\s*'smart-links',\\s*$literal/",
            // Queue job descriptions and progress labels, translated when the queue shows them.
            "/Translation::prep\\(\\s*'smart-links',\\s*$literal/",
            // new ValidationError($path, $code, 'message'…), with any path and code expression.
            "/new ValidationError\\((?:[^,()]|\\([^()]*\\))*,(?:[^,()]|\\([^()]*\\))*,\\s*$literal/",
        ];
        $found = 0;

        foreach ($sources as $source) {
            $code = (string)file_get_contents((string)$source);

            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $code, $matches);

                foreach ($matches[1] as $message) {
                    $found++;
                    $message = stripslashes($message);

                    if (!array_key_exists($message, $messages)) {
                        $missing[] = $message;
                    }
                }
            }
        }

        self::assertGreaterThan(100, $found, 'The scan found too few messages to be reading the source.');
        self::assertSame([], array_values(array_unique($missing)));
    }

    public function testEveryTranslatedTemplateStringHasASourceMessage(): void
    {
        $messages = require self::ROOT . '/src/translations/en/smart-links.php';
        $missing = [];

        $templates = new \RegexIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/src/templates')), '/\.twig$/');

        foreach ($templates as $template) {
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
