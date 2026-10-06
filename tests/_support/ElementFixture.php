<?php

namespace Tahadudhiya\SmartLinks\Tests\_support;

use Craft;
use craft\base\Element;
use craft\elements\Asset;
use craft\elements\Category;
use craft\fs\Local;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Volume;
use PHPUnit\Framework\Assert;
use Tahadudhiya\SmartLinks\linktypes\ProductLinkType;

/**
 * Elements of every kind a link can point at, beside {@see FieldFixture}'s entries: a category
 * group with pages in the primary site only, a section in the primary site only, a volume with
 * public URLs and one without, and, while Commerce is installed, a product type.
 *
 * Created inside FieldFixture's rolled-back transaction, so set up after it; files go to a
 * temporary directory that is removed afterwards.
 */
trait ElementFixture
{
    protected static string $filesystemRoot;
    protected static CategoryGroup $categoryGroup;
    protected static Volume $publicVolume;
    protected static Volume $privateVolume;
    protected static ?int $productTypeId = null;

    /** A section with entries in the primary site only. */
    protected static Section $primaryOnly;

    protected static function setUpElementFixture(): void
    {
        self::$filesystemRoot = sys_get_temp_dir() . '/smart-links-test-' . bin2hex(random_bytes(6));
        self::$categoryGroup = self::createCategoryGroup();
        self::$primaryOnly = self::createPrimaryOnlySection();
        self::$publicVolume = self::createVolume('smartLinksTestPublic', true);
        self::$privateVolume = self::createVolume('smartLinksTestPrivate', false);
        self::$productTypeId = ProductLinkType::isAvailable() ? self::createProductType() : null;
    }

    protected static function tearDownElementFixture(): void
    {
        if (isset(self::$filesystemRoot) && is_dir(self::$filesystemRoot)) {
            FileHelper::removeDirectory(self::$filesystemRoot);
        }
    }

    protected static function createCategoryGroup(): CategoryGroup
    {
        $group = new CategoryGroup(['name' => 'Smart Links test topics', 'handle' => 'smartLinksTestTopics']);
        // Categories have pages in the primary site only.
        $group->setSiteSettings(array_map(
            static fn(int $siteId): CategoryGroup_SiteSettings => $siteId === self::$primarySiteId
                ? new CategoryGroup_SiteSettings(['siteId' => $siteId, 'hasUrls' => true, 'uriFormat' => 'smart-links-topic/{slug}', 'template' => '_smart-links-test'])
                : new CategoryGroup_SiteSettings(['siteId' => $siteId, 'hasUrls' => false]),
            Craft::$app->getSites()->getAllSiteIds(),
        ));
        $group->setFieldLayout(new FieldLayout(['type' => Category::class]));
        Assert::assertTrue(Craft::$app->getCategories()->saveGroup($group), Json::encode($group->getErrors()));

        return $group;
    }

    protected static function createPrimaryOnlySection(): Section
    {
        $section = new Section([
            'name' => 'Smart Links test primary site only',
            'handle' => 'smartLinksTestPrimaryOnly',
            'type' => Section::TYPE_CHANNEL,
            'siteSettings' => [self::$primarySiteId => new Section_SiteSettings(['siteId' => self::$primarySiteId, 'hasUrls' => true, 'uriFormat' => 'smart-links-primary/{slug}', 'template' => '_smart-links-test'])],
        ]);
        $section->setEntryTypes([self::$entryType]);
        Assert::assertTrue(Craft::$app->getEntries()->saveSection($section), Json::encode($section->getErrors()));

        return $section;
    }

    protected static function savedCategory(string $slug): Category
    {
        $category = new Category(['groupId' => self::$categoryGroup->id, 'title' => $slug, 'slug' => $slug, 'siteId' => self::$primarySiteId]);
        Assert::assertTrue(Craft::$app->getElements()->saveElement($category), Json::encode($category->getErrors()));

        return $category;
    }

    protected static function createVolume(string $handle, bool $hasUrls): Volume
    {
        $path = self::$filesystemRoot . '/' . $handle;
        FileHelper::createDirectory($path);
        $fs = new Local(['name' => $handle, 'handle' => $handle, 'path' => $path, 'hasUrls' => $hasUrls, 'url' => $hasUrls ? 'https://files.example.test/' : null]);
        Assert::assertTrue(Craft::$app->getFs()->saveFilesystem($fs), Json::encode($fs->getErrors()));

        $volume = new Volume(['name' => $handle, 'handle' => $handle]);
        $volume->setFsHandle($handle);
        Assert::assertTrue(Craft::$app->getVolumes()->saveVolume($volume), Json::encode($volume->getErrors()));

        return $volume;
    }

    protected static function savedAsset(Volume $volume, string $filename): Asset
    {
        $temp = tempnam(sys_get_temp_dir(), 'smart-links');
        Assert::assertIsString($temp);
        file_put_contents($temp, '%PDF-1.4 test');

        $asset = new Asset();
        $asset->tempFilePath = $temp;
        $asset->setFilename($filename);
        $asset->newFolderId = Craft::$app->getAssets()->getRootFolderByVolumeId((int)$volume->id)?->id;
        $asset->volumeId = $volume->id;
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);
        Assert::assertTrue(Craft::$app->getElements()->saveElement($asset), Json::encode($asset->getErrors()));

        return $asset;
    }

    protected static function createProductType(): int
    {
        $commerce = Craft::$app->getPlugins()->getPlugin('commerce');
        $siteSettings = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteSettings[$site->id] = self::make('craft\\commerce\\models\\ProductTypeSite', ['siteId' => $site->id, 'hasUrls' => true, 'uriFormat' => 'smart-links-shop/{slug}', 'template' => '_smart-links-test']);
        }

        $productType = self::make('craft\\commerce\\models\\ProductType', ['name' => 'Smart Links test goods', 'handle' => 'smartLinksTestGoods', 'hasDimensions' => false]);
        $productType->setSiteSettings($siteSettings);
        Assert::assertTrue(self::call($commerce, 'getProductTypes')->saveProductType($productType), Json::encode($productType->getErrors()));

        return (int)$productType->id;
    }

    protected static function savedProduct(string $title, bool $enabled): Element
    {
        $product = self::make(ProductLinkType::PRODUCT_CLASS, ['typeId' => self::$productTypeId, 'title' => $title, 'slug' => \craft\helpers\StringHelper::toKebabCase($title), 'enabled' => $enabled, 'siteId' => self::$primarySiteId]);
        $variant = self::make('craft\\commerce\\elements\\Variant', ['sku' => 'SL-' . strtoupper(bin2hex(random_bytes(4))), 'isDefault' => true, 'basePrice' => 10]);
        $product->setVariants([$variant]);
        Assert::assertTrue(Craft::$app->getElements()->saveElement($product), Json::encode($product->getErrors()));
        Assert::assertInstanceOf(Element::class, $product);

        return $product;
    }

    protected static function make(string $class, array $config): mixed
    {
        return new $class($config);
    }

    protected static function call(mixed $object, string $method): mixed
    {
        return $object->$method();
    }
}
