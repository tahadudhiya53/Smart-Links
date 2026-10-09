<?php

namespace Tahadudhiya\SmartLinks\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table;
use Tahadudhiya\SmartLinks\enums\HealthState;
use Tahadudhiya\SmartLinks\enums\LinkFeature;
use Tahadudhiya\SmartLinks\models\LinkAttributes;
use Tahadudhiya\SmartLinks\models\LinkPreset;
use Tahadudhiya\SmartLinks\records\HealthHistoryRecord;
use Tahadudhiya\SmartLinks\records\HealthRecord;
use Tahadudhiya\SmartLinks\records\IndexRecord;
use Tahadudhiya\SmartLinks\records\SourceRecord;
use Tahadudhiya\SmartLinks\records\UsageRecord;
use Tahadudhiya\SmartLinks\services\Presets;
use Tahadudhiya\SmartLinks\SmartLinks;

/**
 * Creates the tables Smart Links owns.
 *
 * None of them holds a link. Links live in Smart Link field values, which Craft stores with the
 * element, site, draft and revision they belong to. These tables hold only what is derived from
 * those values, or observed about their targets, so dropping them loses no content.
 *
 * Installing also adds the built-in link presets to project config, as real definitions that can
 * be changed or deleted like any other; uninstalling removes Smart Links' project config.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createIndexTable();
        $this->createUsageTable();
        $this->createSourcesTable();
        $this->createHealthTable();
        $this->createHealthHistoryTable();
        $this->installPresets();

        return true;
    }

    public function safeDown(): bool
    {
        // Tables holding a foreign key go before the table it points at.
        $this->dropTableIfExists(HealthHistoryRecord::TABLE);
        $this->dropTableIfExists(HealthRecord::TABLE);
        $this->dropTableIfExists(SourceRecord::TABLE);
        $this->dropTableIfExists(UsageRecord::TABLE);
        $this->dropTableIfExists(IndexRecord::TABLE);

        Craft::$app->getProjectConfig()->remove(SmartLinks::PROJECT_CONFIG_KEY, 'Uninstall Smart Links');

        return true;
    }

    /**
     * The presets a new installation starts with. They are starting points: each holds only what
     * follows from what it is for. How a call to action or a text link looks is the site's own
     * design, so those hold no classes until an administrator gives them some.
     *
     * @return list<LinkPreset>
     */
    public static function builtInPresets(): array
    {
        $preset = static fn(string $name, array $config = []): LinkPreset => new LinkPreset(['name' => $name] + $config);

        return [
            $preset('Primary CTA'),
            $preset('Secondary CTA'),
            $preset('Text Link'),
            $preset('External Link', [
                'types' => ['url'],
                'linkAttributes' => new LinkAttributes(target: '_blank', rel: ['external', 'noopener']),
            ]),
            $preset('Download Link', [
                'types' => ['asset', 'url'],
                'linkAttributes' => new LinkAttributes(download: true),
                'locked' => [LinkFeature::DOWNLOAD->value],
            ]),
            $preset('Social Link', [
                'types' => ['social'],
                'linkAttributes' => new LinkAttributes(target: '_blank'),
            ]),
        ];
    }

    /**
     * Adds the built-in presets, unless the plugin is being installed from project config, which
     * then brings Smart Links' configuration with it (as it does when the plugin was installed in
     * another environment), or that configuration exists already.
     */
    private function installPresets(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $plugin = SmartLinks::getInstance();

        if ($projectConfig->get("plugins.{$plugin->id}", true) !== null || $projectConfig->get(SmartLinks::PROJECT_CONFIG_KEY, true) !== null || $projectConfig->get(Presets::CONFIG_KEY) !== null) {
            return;
        }

        foreach (self::builtInPresets() as $preset) {
            // Saved as defined: the built-in link types they name are being registered now.
            $plugin->getPresets()->savePreset($preset, false);
        }
    }

    /**
     * One row per distinct link target.
     */
    private function createIndexTable(): void
    {
        $this->createTable(IndexRecord::TABLE, [
            'id' => $this->primaryKey(),
            // The target identity's key. Kept beside its hash so a collision can be detected.
            'targetKey' => $this->text()->notNull(),
            'targetHash' => $this->char(64)->notNull(),
            'linkType' => $this->string(64)->notNull(),
            // Deliberately no foreign keys: the row has to outlive the element or site it points
            // at, or a link to deleted content would vanish instead of being reported.
            'targetElementId' => $this->integer(),
            'targetSiteId' => $this->integer(),
            // Where the target led when last indexed. Empty when it led nowhere.
            'resolvedUrl' => $this->text(),
            // What the target is called (e.g. an entry's title) when last indexed, if it has a name.
            'targetLabel' => $this->text(),
            // How resolving it ended when last indexed (a ResolutionStatus). Empty when resolving
            // failed outright.
            'targetStatus' => $this->string(16),
            // Empty when the target has no http(s) URL to check.
            'healthUrlHash' => $this->char(64),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, IndexRecord::TABLE, ['targetHash'], true);
        // The inventory filtered by link type.
        $this->createIndex(null, IndexRecord::TABLE, ['linkType']);
        // Links to an element: refreshing their URLs when it is saved, and finding them once it
        // is gone.
        $this->createIndex(null, IndexRecord::TABLE, ['targetElementId', 'targetSiteId']);
        // A target's health, and which URLs anything still links to.
        $this->createIndex(null, IndexRecord::TABLE, ['healthUrlHash']);
    }

    /**
     * One row per link in one Smart Link field value.
     */
    private function createUsageTable(): void
    {
        $this->createTable(UsageRecord::TABLE, [
            'id' => $this->primaryKey(),
            'indexId' => $this->integer()->notNull(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'fieldId' => $this->integer()->notNull(),
            // Craft keys content by layout element, and a field can be in a layout more than once.
            'layoutElementUid' => $this->char(36)->notNull(),
            'linkUid' => $this->char(36)->notNull(),
            'sortOrder' => $this->smallInteger()->notNull(),
            // A copy of the link's label, so usage can be listed and searched without loading
            // every element. Rewritten whenever the element is indexed.
            'label' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // The site is part of it because a propagated value carries the same link UID into
        // every site.
        $this->createIndex(null, UsageRecord::TABLE, ['elementId', 'siteId', 'layoutElementUid', 'linkUid'], true);
        // Where is this target used?
        $this->createIndex(null, UsageRecord::TABLE, ['indexId']);
        // Usage in one site, or of one field.
        $this->createIndex(null, UsageRecord::TABLE, ['siteId']);
        $this->createIndex(null, UsageRecord::TABLE, ['fieldId']);

        // Usage describes content that exists, so it goes with that content's element, site or
        // field, and with the target it counts.
        $this->addForeignKey(null, UsageRecord::TABLE, ['indexId'], IndexRecord::TABLE, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, UsageRecord::TABLE, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, UsageRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, UsageRecord::TABLE, ['fieldId'], Table::FIELDS, ['id'], 'CASCADE', null);
    }

    /**
     * One row per element site the index has read: the version of the element whose links the
     * index holds, so content saved since then can be found.
     */
    private function createSourcesTable(): void
    {
        $this->createTable(SourceRecord::TABLE, [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            // The element's `dateUpdated` when it was read. Any later save gives it another one.
            'elementDateUpdated' => $this->dateTime()->notNull(),
            // How many of its Smart Link values could not be read then. Their links are as an
            // earlier reading left them, so the index is not up to date with this source.
            'unreadableValues' => $this->smallInteger()->notNull()->defaultValue(0),
            'dateIndexed' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, SourceRecord::TABLE, ['elementId', 'siteId'], true);
        // Sources a rebuild has not reached since it started.
        $this->createIndex(null, SourceRecord::TABLE, ['dateIndexed']);
        // Sources in one site, which go with it.
        $this->createIndex(null, SourceRecord::TABLE, ['siteId']);

        $this->addForeignKey(null, SourceRecord::TABLE, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, SourceRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE', null);
    }

    /**
     * One row per checked URL: its current health and the evidence behind it.
     */
    private function createHealthTable(): void
    {
        $this->createTable(HealthRecord::TABLE, [
            'id' => $this->primaryKey(),
            // The canonical health URL. Kept beside its hash so a collision can be detected.
            'url' => $this->text()->notNull(),
            'urlHash' => $this->char(64)->notNull(),
            // A URL that has not been checked is Unknown, never Healthy. Until the first check
            // every evidence column is empty.
            'state' => $this->string(16)->notNull()->defaultValue(HealthState::UNKNOWN->value),
            'statusCode' => $this->smallInteger(),
            'failure' => $this->string(32),
            'finalUrl' => $this->text(),
            // JSON list of the redirects followed, each a URL and its status code.
            'redirectChain' => $this->text(),
            'dateChecked' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, HealthRecord::TABLE, ['urlHash'], true);
        // Targets listed by state.
        $this->createIndex(null, HealthRecord::TABLE, ['state']);
        // URLs due for a check, least recently checked first.
        $this->createIndex(null, HealthRecord::TABLE, ['dateChecked']);
    }

    /**
     * One row per earlier observation of a URL. Rows are only inserted, so there is no
     * `dateUpdated`.
     */
    private function createHealthHistoryTable(): void
    {
        $this->createTable(HealthHistoryRecord::TABLE, [
            'id' => $this->primaryKey(),
            'healthId' => $this->integer()->notNull(),
            'state' => $this->string(16)->notNull(),
            'statusCode' => $this->smallInteger(),
            'failure' => $this->string(32),
            'finalUrl' => $this->text()->notNull(),
            'redirectChain' => $this->text(),
            'dateChecked' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // A URL's history in order.
        $this->createIndex(null, HealthHistoryRecord::TABLE, ['healthId', 'dateChecked']);

        // The health row is the URL's identity, and it survives index rebuilds. History goes
        // only when the URL stops being tracked.
        $this->addForeignKey(null, HealthHistoryRecord::TABLE, ['healthId'], HealthRecord::TABLE, ['id'], 'CASCADE', null);
    }
}
