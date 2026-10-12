<?php

namespace Tahadudhiya\SmartLinks\Tests\integration;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SmartLinks\enums\HealthFailure;
use Tahadudhiya\SmartLinks\enums\HealthState;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;
use Tahadudhiya\SmartLinks\errors\TargetHashCollisionException;
use Tahadudhiya\SmartLinks\migrations\Install;
use Tahadudhiya\SmartLinks\models\CanonicalUrl;
use Tahadudhiya\SmartLinks\models\HealthObservation;
use Tahadudhiya\SmartLinks\models\TargetIdentity;
use Tahadudhiya\SmartLinks\records\HealthHistoryRecord;
use Tahadudhiya\SmartLinks\records\HealthRecord;
use Tahadudhiya\SmartLinks\records\IndexRecord;
use Tahadudhiya\SmartLinks\records\SourceRecord;
use Tahadudhiya\SmartLinks\records\UsageRecord;
use Tahadudhiya\SmartLinks\services\Index;
use Tahadudhiya\SmartLinks\services\Presets;
use Tahadudhiya\SmartLinks\SmartLinks;
use Tahadudhiya\SmartLinks\Tests\_support\ProjectConfigSandbox;
use Tahadudhiya\SmartLinks\Tests\_support\StaleIndex;
use yii\db\IntegrityException;
use yii\db\TableSchema;
use yii\db\Transaction;

/**
 * The installed schema, asserted by what it does rather than how the migration is written: which
 * rows it tells apart, which it refuses, what deleting Craft data takes with it, and that
 * uninstalling takes away Smart Links' tables and nothing else.
 *
 * Tests that write rows do so inside a transaction that is rolled back, including the temporary
 * elements they delete, so the host project is left as it was found.
 */
class SchemaTest extends TestCase
{
    /** @var string[] */
    private const TABLES = [
        IndexRecord::TABLE,
        UsageRecord::TABLE,
        SourceRecord::TABLE,
        HealthRecord::TABLE,
        HealthHistoryRecord::TABLE,
    ];

    /**
     * What every column is for. Identity says which row this is, a reference points at another
     * row, a projection is a copy of something content or its identity already holds, evidence
     * is what a check observed.
     */
    private const COLUMNS = [
        IndexRecord::TABLE => [
            'id' => 'key',
            'targetKey' => 'identity',
            'targetHash' => 'identity',
            'linkType' => 'projection',
            'targetElementId' => 'projection',
            'targetSiteId' => 'projection',
            'resolvedUrl' => 'projection',
            'targetLabel' => 'projection',
            'targetStatus' => 'projection',
            'healthUrlHash' => 'projection',
            'dateCreated' => 'bookkeeping',
            'dateUpdated' => 'bookkeeping',
            'uid' => 'bookkeeping',
        ],
        UsageRecord::TABLE => [
            'id' => 'key',
            'elementId' => 'identity',
            'siteId' => 'identity',
            'layoutElementUid' => 'identity',
            'linkUid' => 'identity',
            'indexId' => 'reference',
            'fieldId' => 'reference',
            'sortOrder' => 'ordering',
            'label' => 'projection',
            'dateCreated' => 'bookkeeping',
            'dateUpdated' => 'bookkeeping',
            'uid' => 'bookkeeping',
        ],
        SourceRecord::TABLE => [
            'id' => 'key',
            'elementId' => 'identity',
            'siteId' => 'identity',
            'elementDateUpdated' => 'projection',
            'unreadableValues' => 'projection',
            'dateIndexed' => 'bookkeeping',
            'dateCreated' => 'bookkeeping',
            'dateUpdated' => 'bookkeeping',
            'uid' => 'bookkeeping',
        ],
        HealthRecord::TABLE => [
            'id' => 'key',
            'url' => 'identity',
            'urlHash' => 'identity',
            'state' => 'evidence',
            'statusCode' => 'evidence',
            'failure' => 'evidence',
            'finalUrl' => 'evidence',
            'redirectChain' => 'evidence',
            'dateChecked' => 'evidence',
            'dateCreated' => 'bookkeeping',
            'dateUpdated' => 'bookkeeping',
            'uid' => 'bookkeeping',
        ],
        HealthHistoryRecord::TABLE => [
            'id' => 'key',
            'healthId' => 'reference',
            'state' => 'evidence',
            'statusCode' => 'evidence',
            'failure' => 'evidence',
            'finalUrl' => 'evidence',
            'redirectChain' => 'evidence',
            'dateChecked' => 'evidence',
            'dateCreated' => 'bookkeeping',
            'uid' => 'bookkeeping',
        ],
    ];

    /**
     * Every index, and whether it is unique. Each one serves a query the architecture names.
     */
    private const INDEXES = [
        IndexRecord::TABLE => [
            ['targetHash', true],
            ['linkType', false],
            ['targetElementId,targetSiteId', false],
            ['healthUrlHash', false],
        ],
        UsageRecord::TABLE => [
            ['elementId,siteId,layoutElementUid,linkUid', true],
            ['indexId', false],
            ['siteId', false],
            ['fieldId', false],
        ],
        SourceRecord::TABLE => [
            ['elementId,siteId', true],
            ['dateIndexed', false],
            ['siteId', false],
        ],
        HealthRecord::TABLE => [
            ['urlHash', true],
            ['state', false],
            ['dateChecked', false],
        ],
        HealthHistoryRecord::TABLE => [
            ['healthId,dateChecked', false],
        ],
    ];

    /** Craft's own data, which nothing Smart Links does may change. */
    private const CRAFT_TABLES = [
        Table::ELEMENTS,
        Table::ELEMENTS_SITES,
        Table::RELATIONS,
        Table::FIELDS,
        Table::SITES,
        Table::PLUGINS,
        Table::PROJECTCONFIG,
    ];

    private ?Transaction $transaction = null;

    protected function tearDown(): void
    {
        if ($this->transaction?->getIsActive()) {
            $this->transaction->rollBack();
        }

        $this->transaction = null;

        parent::tearDown();
    }

    public function testSmartLinksOwnsExactlyTheTablesItsRecordsDeclare(): void
    {
        self::assertEqualsCanonicalizing(array_map([$this, 'rawName'], self::TABLES), $this->installedTables());
    }

    public function testEveryColumnHasAPurpose(): void
    {
        foreach (self::COLUMNS as $table => $roles) {
            $schema = $this->tableSchema($table);
            $unique = array_merge(...array_values(Craft::$app->getDb()->getSchema()->findUniqueIndexes($schema)));

            self::assertEqualsCanonicalizing(array_keys($roles), array_keys($schema->columns), $table);

            foreach ($roles as $column => $role) {
                if (in_array($role, ['identity', 'reference'], true)) {
                    self::assertFalse($schema->columns[$column]->allowNull, "$table.$column identifies or references a row, so it is never empty.");
                }

                // A projection is recomputed from content on every rebuild, so nothing may be
                // identified by it.
                if ($role === 'projection') {
                    self::assertNotContains($column, $unique, "$table.$column is a projection, so it cannot be part of a unique key.");
                }
            }
        }
    }

    public function testTheUsageLabelIsOnlyEverACopy(): void
    {
        // The label belongs to the link in content. The copy here may be empty, is never part of
        // what identifies a usage, and nothing points at it, so a rebuild can always rewrite it.
        $schema = $this->tableSchema(UsageRecord::TABLE);

        self::assertTrue($schema->columns['label']->allowNull);
        self::assertSame('projection', self::COLUMNS[UsageRecord::TABLE]['label']);

        foreach ($this->indexes(UsageRecord::TABLE) as [$columns]) {
            self::assertStringNotContainsString('label', $columns);
        }
    }

    public function testEveryIndexIsOneTheArchitectureNames(): void
    {
        foreach (self::INDEXES as $table => $expected) {
            self::assertEqualsCanonicalizing($expected, $this->indexes($table), $table);
        }
    }

    public function testForeignKeysOnlyEverDeleteDerivedRows(): void
    {
        $rules = [];

        foreach (self::TABLES as $table) {
            foreach (Craft::$app->getDb()->getSchema()->getTableForeignKeys($table, true) as $key) {
                $rules[$this->rawName($table) . '.' . implode(',', $key->columnNames)] = $this->unprefixed($key->foreignTableName) . ' ' . strtoupper((string)$key->onDelete);
            }
        }

        ksort($rules);

        // Usage goes with the content it describes. The index deliberately has none: a target
        // has to outlive the element it points at to be reported as missing.
        self::assertSame([
            'smartlinks_health_history.healthId' => 'smartlinks_health CASCADE',
            'smartlinks_sources.elementId' => 'elements CASCADE',
            'smartlinks_sources.siteId' => 'sites CASCADE',
            'smartlinks_usage.elementId' => 'elements CASCADE',
            'smartlinks_usage.fieldId' => 'fields CASCADE',
            'smartlinks_usage.indexId' => 'smartlinks_index CASCADE',
            'smartlinks_usage.siteId' => 'sites CASCADE',
        ], $rules);
    }

    public function testEnumValuesFitTheColumnsTheyAreStoredIn(): void
    {
        foreach ([HealthRecord::TABLE, HealthHistoryRecord::TABLE] as $table) {
            $columns = $this->tableSchema($table)->columns;

            foreach (HealthState::values() as $value) {
                self::assertLessThanOrEqual($columns['state']->size, strlen($value), "$table.state: $value");
            }

            foreach (HealthFailure::values() as $value) {
                self::assertLessThanOrEqual($columns['failure']->size, strlen($value), "$table.failure: $value");
            }
        }

        $targetStatus = $this->tableSchema(IndexRecord::TABLE)->columns['targetStatus'];

        foreach (ResolutionStatus::cases() as $status) {
            self::assertLessThanOrEqual($targetStatus->size, strlen($status->value), "targetStatus: $status->value");
        }
    }

    public function testATargetIsStoredOnceWithItsIdentityAndProjections(): void
    {
        $this->beginFixtures();
        [$siteId] = $this->twoSiteIds();
        $elementId = $this->existingElementId();
        $identity = TargetIdentity::forElement('entry', Entry::class, $elementId, $siteId);

        $id = $this->index()->targetId($identity);
        $record = IndexRecord::findOne($id);

        self::assertNotNull($record);
        self::assertSame($identity->key(), $record->targetKey);
        self::assertSame($identity->hash(), $record->targetHash);
        self::assertSame('entry', $record->linkType);
        self::assertSame($elementId, (int)$record->targetElementId);
        self::assertSame($siteId, (int)$record->targetSiteId);
        // Asking again finds the same row rather than adding one.
        self::assertSame($id, $this->index()->targetId(TargetIdentity::forElement('entry', Entry::class, $elementId, $siteId)));
        self::assertSame(1, (int)IndexRecord::find()->where(['targetHash' => $identity->hash()])->count());
        // The schema refuses a second row for one hash even if something bypasses the service.
        $this->assertRefused(fn() => $this->storeTarget($identity->key(), $identity->hash(), 'entry'));
    }

    public function testTheLongestKeyAnIdentityCanHaveIsStoredWhole(): void
    {
        $this->beginFixtures();
        $identity = TargetIdentity::create('email', ['address' => str_repeat('a', TargetIdentity::MAX_KEY_BYTES - strlen('email?address='))]);

        // Read back whole: nothing cut short, so the next lookup finds the same target again.
        $record = IndexRecord::findOne($this->index()->targetId($identity));
        self::assertNotNull($record);
        self::assertSame(TargetIdentity::MAX_KEY_BYTES, strlen($record->targetKey));
        self::assertSame($identity->key(), $record->targetKey);
        self::assertSame($record->id, $this->index()->targetId($identity));
    }

    public function testATargetWhoseHashIsTakenByAnotherTargetIsRefusedNotMerged(): void
    {
        $this->beginFixtures();
        $stored = TargetIdentity::create('url', ['url' => 'https://example.com/a']);
        $requested = TargetIdentity::create('url', ['url' => 'https://example.com/b']);

        // A real SHA-256 collision cannot be produced, so the stored row is given the requested
        // target's hash with its own key: exactly what a collision would leave in the table.
        $rowId = $this->storeTarget($stored->key(), $requested->hash(), 'url');

        try {
            $this->index()->targetId($requested);
            self::fail('Two different targets were treated as one.');
        } catch (TargetHashCollisionException $exception) {
            self::assertSame($requested->hash(), $exception->targetHash);
            self::assertSame($stored->key(), $exception->storedKey);
            self::assertSame($requested->key(), $exception->requestedKey);
        }

        // Neither overwritten nor duplicated.
        self::assertSame($stored->key(), IndexRecord::findOne($rowId)?->targetKey);
        self::assertSame(1, (int)IndexRecord::find()->where(['targetHash' => $requested->hash()])->count());
    }

    public function testATargetInsertedByAnotherProcessMeanwhileIsFoundNotDuplicated(): void
    {
        $this->beginFixtures();
        $identity = TargetIdentity::forUrl('url', CanonicalUrl::parse('https://example.com/raced'), null);
        // The other process's row, committed after this process looked and found nothing.
        $theirs = $this->storeTarget($identity->key(), $identity->hash(), 'url');

        self::assertSame($theirs, (new StaleIndex())->targetId($identity));
        self::assertSame(1, (int)IndexRecord::find()->where(['targetHash' => $identity->hash()])->count());
        $this->assertTransactionStillUsable();
    }

    public function testATargetRacedByADifferentTargetUnderItsHashIsRefused(): void
    {
        $this->beginFixtures();
        $theirs = TargetIdentity::create('url', ['url' => 'https://example.com/theirs']);
        $ours = TargetIdentity::create('url', ['url' => 'https://example.com/ours']);
        $rowId = $this->storeTarget($theirs->key(), $ours->hash(), 'url');

        try {
            (new StaleIndex())->targetId($ours);
            self::fail('A raced row under the same hash was accepted as this target.');
        } catch (TargetHashCollisionException $exception) {
            self::assertSame($theirs->key(), $exception->storedKey);
        }

        self::assertSame($theirs->key(), IndexRecord::findOne($rowId)?->targetKey);
        $this->assertTransactionStillUsable();
    }

    public function testARefusedInsertNoRowExplainsIsRethrownNotGuessedAround(): void
    {
        // Both lookups miss, as when the other process's row is not visible to this transaction.
        $this->beginFixtures();
        $identity = TargetIdentity::forUrl('url', CanonicalUrl::parse('https://example.com/unseen'), null);
        $this->storeTarget($identity->key(), $identity->hash(), 'url');
        $index = new StaleIndex();
        $index->staleLookups = 2;

        try {
            $index->targetId($identity);
            self::fail('A refused insert was treated as a success.');
        } catch (IntegrityException) {
            self::addToAssertionCount(1);
        }

        $this->assertTransactionStillUsable();
    }

    public function testSiteAndTypeKeepElementTargetsApart(): void
    {
        $this->beginFixtures();
        [$site1, $site2] = $this->twoSiteIds();
        $elementId = $this->existingElementId();

        $ids = [
            $this->index()->targetId(TargetIdentity::forElement('entry', Entry::class, $elementId, $site1)),
            $this->index()->targetId(TargetIdentity::forElement('entry', Entry::class, $elementId, $site2)),
            $this->index()->targetId(TargetIdentity::forElement('asset', Asset::class, $elementId, $site1)),
            $this->index()->targetId(TargetIdentity::forElement('category', Category::class, $elementId, $site1)),
        ];

        self::assertCount(4, array_unique($ids));
    }

    public function testTargetsThatResolveToOneUrlShareOneHealthRow(): void
    {
        $this->beginFixtures();
        [$site1, $site2] = $this->twoSiteIds();
        $url = CanonicalUrl::parse('https://example.com/shared');
        $health = $this->health($url);

        // An entry's versions in two sites and an absolute URL target, all resolving to one page.
        $targets = [
            $this->index()->targetId(TargetIdentity::forElement('entry', Entry::class, $this->existingElementId(), $site1)),
            $this->index()->targetId(TargetIdentity::forElement('entry', Entry::class, $this->existingElementId(), $site2)),
            $this->index()->targetId(TargetIdentity::forUrl('url', $url, null)),
        ];
        // What indexing will record once it resolves them.
        IndexRecord::updateAll(['resolvedUrl' => $url->toString(), 'healthUrlHash' => $url->healthUrlHash()], ['id' => $targets]);

        $joined = (new Query())
            ->select(['h.id'])
            ->from(['i' => IndexRecord::TABLE])
            ->innerJoin(['h' => HealthRecord::TABLE], '[[h.urlHash]] = [[i.healthUrlHash]]')
            ->where(['i.id' => $targets])
            ->column();

        self::assertCount(3, array_unique($targets));
        self::assertSame([$health->id], array_values(array_unique(array_map('intval', $joined))));
    }

    public function testAUsageIsOneLinkInOneSiteOfOneFieldValue(): void
    {
        $this->beginFixtures();
        [$site1, $site2] = $this->twoSiteIds();
        $elementId = $this->existingElementId();
        $target = $this->index()->targetId(TargetIdentity::forUrl('url', CanonicalUrl::parse('https://example.com/'), null));
        $layoutElement = StringHelper::UUID();
        $linkUid = StringHelper::UUID();

        // A propagated value carries the same link into every site.
        $this->usage($target, $elementId, $site1, $layoutElement, $linkUid);
        $this->usage($target, $elementId, $site2, $layoutElement, $linkUid);
        // The same link UID under another layout element is a different occurrence.
        $this->usage($target, $elementId, $site1, StringHelper::UUID(), $linkUid);
        // Two links to one target in one value are two occurrences of it.
        $this->usage($target, $elementId, $site1, $layoutElement, StringHelper::UUID(), 2);

        self::assertSame(4, (int)UsageRecord::find()->where(['indexId' => $target])->count());
        // The same link in the same site of the same field value is one occurrence.
        $this->assertRefused(fn() => $this->usage($target, $elementId, $site1, $layoutElement, $linkUid));
    }

    public function testRemovingTheSourceElementRemovesItsUsageButNotTheTarget(): void
    {
        $this->beginFixtures();
        [$siteId] = $this->twoSiteIds();
        $source = $this->temporaryElementId();
        $target = $this->index()->targetId(TargetIdentity::forUrl('url', CanonicalUrl::parse('https://example.com/'), null));
        $this->usage($target, $source, $siteId, StringHelper::UUID(), StringHelper::UUID());

        Db::delete(Table::ELEMENTS, ['id' => $source]);

        self::assertFalse(UsageRecord::find()->where(['elementId' => $source])->exists());
        self::assertNotNull(IndexRecord::findOne($target));
    }

    public function testATargetWhoseElementIsDeletedStaysReportable(): void
    {
        $this->beginFixtures();
        [$siteId] = $this->twoSiteIds();
        $element = $this->temporaryElementId();
        $source = $this->existingElementId();
        $target = $this->index()->targetId(TargetIdentity::forElement('entry', Entry::class, $element, $siteId));
        $usage = $this->usage($target, $source, $siteId, StringHelper::UUID(), StringHelper::UUID());
        $url = CanonicalUrl::parse('https://example.com/gone');
        $health = $this->health($url);

        Db::delete(Table::ELEMENTS, ['id' => $element]);

        $orphaned = (new Query())
            ->select(['i.id'])
            ->from(['i' => IndexRecord::TABLE])
            ->leftJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[i.targetElementId]]')
            ->where(['not', ['i.targetElementId' => null]])
            ->andWhere(['e.id' => null])
            ->column();

        // The target and the content linking to it both remain, so the link can be reported as
        // pointing at deleted content. Health is keyed by URL, not by element, so it is untouched.
        self::assertContains($target, array_map('intval', $orphaned));
        self::assertNotNull(UsageRecord::findOne($usage));
        self::assertNotNull(HealthRecord::findOne($health->id));
    }

    public function testHealthHistoryIsAppendOnlyThroughEveryRecordPath(): void
    {
        $this->beginFixtures();
        $url = CanonicalUrl::parse('https://example.com/');
        $health = $this->health($url);
        // Nothing has been checked yet, so nothing is known.
        self::assertSame(HealthState::UNKNOWN->value, (new Query())->select('state')->from(HealthRecord::TABLE)->where(['id' => $health->id])->scalar());

        $observation = new HealthObservation(HealthState::HEALTHY, (string)$url->healthUrl(), new DateTimeImmutable('2026-01-01 00:00:00'), 200);
        $history = new HealthHistoryRecord();
        $history->healthId = $health->id;
        $history->state = $observation->state->value;
        $history->statusCode = $observation->statusCode;
        $history->finalUrl = $observation->finalUrl;
        $history->dateChecked = Db::prepareDateForDb($observation->dateChecked);
        self::assertTrue($history->insert());
        $recorded = $this->historyRow($history->id);

        // The current state moves on; the observation it moved on from does not.
        $health->state = HealthState::BROKEN->value;
        $health->statusCode = 404;
        self::assertTrue($health->save());
        self::assertSame($recorded, $this->historyRow($history->id));

        // Each path is tried on the row as loaded, unchanged, so a path is refused for what it is,
        // not because an earlier attempt left something to write.
        $attempts = [
            'save with a change' => function(HealthHistoryRecord $row) {
                $row->statusCode = 500;
                $row->save();
            },
            'save without a change' => fn(HealthHistoryRecord $row) => $row->save(),
            'update with a change' => function(HealthHistoryRecord $row) {
                $row->state = HealthState::BROKEN->value;
                $row->update();
            },
            'update without a change' => fn(HealthHistoryRecord $row) => $row->update(),
            'updateAttributes' => fn(HealthHistoryRecord $row) => $row->updateAttributes(['state' => HealthState::BROKEN->value]),
            'updateAttributes without a change' => fn(HealthHistoryRecord $row) => $row->updateAttributes([]),
            'updateCounters' => fn(HealthHistoryRecord $row) => $row->updateCounters(['statusCode' => 1]),
            'updateAll' => fn(HealthHistoryRecord $row) => HealthHistoryRecord::updateAll(['state' => HealthState::BROKEN->value], ['id' => $row->id]),
            'updateAllCounters' => fn(HealthHistoryRecord $row) => HealthHistoryRecord::updateAllCounters(['statusCode' => 1], ['id' => $row->id]),
            'delete' => fn(HealthHistoryRecord $row) => $row->delete(),
            'deleteAll' => fn(HealthHistoryRecord $row) => HealthHistoryRecord::deleteAll(['id' => $row->id]),
        ];

        foreach ($attempts as $path => $attempt) {
            $row = HealthHistoryRecord::findOne($history->id);
            self::assertInstanceOf(HealthHistoryRecord::class, $row);

            try {
                $attempt($row);
                $refusal = null;
            } catch (\Throwable $refusal) {
            }

            self::assertInstanceOf(LogicException::class, $refusal, "History was written through $path().");
            self::assertSame($recorded, $this->historyRow($history->id), "History changed through $path().");
        }

        // History goes only with its URL's health row, by the database's cascade.
        self::assertSame(1, $health->delete());
        self::assertFalse(HealthHistoryRecord::find()->where(['id' => $history->id])->exists());
    }

    public function testNoSmartLinksCodeWritesHistoryExceptThroughItsRecord(): void
    {
        // The database does not forbid writes to history, so the guarantee rests on the record
        // being the only way Smart Links reaches the table.
        $allowed = ['records/HealthHistoryRecord.php', 'migrations/Install.php'];
        $src = realpath(__DIR__ . '/../../src');
        $offenders = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator((string)$src)) as $file) {
            $path = substr($file->getPathname(), strlen((string)$src) + 1);

            if ($file->getExtension() === 'php' && !in_array($path, $allowed, true)) {
                $source = (string)file_get_contents($file->getPathname());

                // Any way of naming the table, as raw SQL would need to.
                if (preg_match('/HealthHistoryRecord::(?:TABLE|tableName|getDb|getTableSchema)\b|smartlinks_health_history/', $source)) {
                    $offenders[] = $path;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    public function testUninstallingRemovesOnlySmartLinksTablesAndReinstallingRestoresThem(): void
    {
        foreach (self::TABLES as $table) {
            if ((new Query())->from($table)->exists()) {
                self::markTestSkipped("$table holds rows, and this test drops the table to prove uninstalling works.");
            }
        }

        $schema = $this->schemaSnapshot();
        $otherTables = $this->otherTables();
        $craft = $this->craftDigests();
        $projectConfig = Craft::$app->getProjectConfig();

        // Smart Links' configuration is taken away too, whatever the host project has of it.
        ProjectConfigSandbox::open();
        $projectConfig->set(Presets::CONFIG_KEY . '.' . StringHelper::UUID(), ['name' => 'Removed on uninstall', 'sortOrder' => 99]);

        try {
            $this->quietly(fn() => (new Install())->safeDown());

            try {
                self::assertSame([], $this->installedTables());
                self::assertSame($otherTables, $this->otherTables());
                self::assertNull($projectConfig->get(SmartLinks::PROJECT_CONFIG_KEY));
                // Project config changes are saved when a request ends: none of Craft's tables changed.
                self::assertSame($craft, $this->craftDigests());
            } finally {
                $this->quietly(fn() => (new Install())->safeUp());
            }

            // The host's project config says the plugin is installed, as it does when the plugin
            // is installed from another environment's: its configuration arrives with that, so
            // installing adds no presets of its own.
            self::assertNotNull($projectConfig->get('plugins.smart-links', true));
            self::assertNull($projectConfig->get(Presets::CONFIG_KEY));
        } finally {
            ProjectConfigSandbox::close();
        }

        // Down and up are exact opposites: reinstalling gives back the schema that was removed.
        self::assertSame($schema, $this->schemaSnapshot());
        self::assertSame($craft, $this->craftDigests());
    }

    public function testTheInstalledSchemaVersionIsThePlugins(): void
    {
        $plugin = Craft::$app->getPlugins()->getPlugin('smart-links');
        self::assertNotNull($plugin);

        self::assertSame($plugin->schemaVersion, Craft::$app->getPlugins()->getStoredPluginInfo('smart-links')['schemaVersion'] ?? null);
    }

    private function beginFixtures(): void
    {
        $this->transaction = Craft::$app->getDb()->beginTransaction();
    }

    /**
     * Runs an insert that the schema must refuse, inside a savepoint so the refusal does not
     * abort the surrounding transaction on databases that would.
     */
    private function assertRefused(callable $insert): void
    {
        $savepoint = Craft::$app->getDb()->beginTransaction();

        try {
            $insert();
            self::fail('The schema accepted a row it should have refused.');
        } catch (IntegrityException) {
            self::addToAssertionCount(1);
        } finally {
            $savepoint->rollBack();
        }
    }

    /**
     * A refused insert must roll back only its own savepoint: the caller's transaction goes on.
     */
    private function assertTransactionStillUsable(): void
    {
        self::assertTrue($this->transaction?->getIsActive());

        $identity = TargetIdentity::forUrl('url', CanonicalUrl::parse('https://example.com/after-' . StringHelper::randomString(8)), null);
        self::assertNotNull(IndexRecord::findOne($this->index()->targetId($identity)));
    }

    private function index(): Index
    {
        $plugin = Craft::$app->getPlugins()->getPlugin('smart-links');
        self::assertInstanceOf(SmartLinks::class, $plugin);

        return $plugin->getIndex();
    }

    /**
     * Writes an index row directly, as nothing but a fixture should.
     */
    private function storeTarget(string $key, string $hash, string $type): int
    {
        $record = new IndexRecord();
        $record->targetKey = $key;
        $record->targetHash = $hash;
        $record->linkType = $type;
        $record->insert();

        return $record->id;
    }

    private function health(CanonicalUrl $url): HealthRecord
    {
        $record = new HealthRecord();
        $record->url = (string)$url->healthUrl();
        $record->urlHash = (string)$url->healthUrlHash();
        self::assertTrue($record->insert());

        return $record;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function historyRow(int $id): array|false
    {
        return (new Query())->from(HealthHistoryRecord::TABLE)->where(['id' => $id])->one();
    }

    private function usage(int $indexId, int $elementId, int $siteId, string $layoutElementUid, string $linkUid, int $sortOrder = 1): int
    {
        $record = new UsageRecord();
        $record->indexId = $indexId;
        $record->elementId = $elementId;
        $record->siteId = $siteId;
        $record->fieldId = (int)(new Query())->select('id')->from(Table::FIELDS)->scalar();
        $record->layoutElementUid = $layoutElementUid;
        $record->linkUid = $linkUid;
        $record->sortOrder = $sortOrder;
        $record->insert();

        return $record->id;
    }

    private function existingElementId(): int
    {
        $id = (new Query())->select('id')->from(Table::ELEMENTS)->scalar();
        self::assertNotFalse($id, 'The host project needs at least one element.');

        return (int)$id;
    }

    /**
     * An element row created only for a test to delete. It exists only inside the fixture
     * transaction.
     */
    private function temporaryElementId(): int
    {
        Db::insert(Table::ELEMENTS, ['type' => Entry::class, 'enabled' => true, 'archived' => false]);

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * @return int[]
     */
    private function twoSiteIds(): array
    {
        $ids = array_map('intval', Craft::$app->getSites()->getAllSiteIds());

        if (count($ids) < 2) {
            self::markTestSkipped('Needs a multisite host project.');
        }

        return array_slice($ids, 0, 2);
    }

    private function tableSchema(string $table): TableSchema
    {
        $schema = Craft::$app->getDb()->getTableSchema($table, true);
        self::assertNotNull($schema, "$table is missing. Reinstall Smart Links.");

        return $schema;
    }

    /**
     * @return array<int, array{string, bool}>
     */
    private function indexes(string $table): array
    {
        $indexes = [];

        foreach (Craft::$app->getDb()->getSchema()->getTableIndexes($table, true) as $index) {
            if (!$index->isPrimary) {
                $indexes[] = [implode(',', $index->columnNames), (bool)$index->isUnique];
            }
        }

        return $indexes;
    }

    /**
     * @return string[]
     */
    private function installedTables(): array
    {
        $prefix = Craft::$app->getDb()->tablePrefix . 'smartlinks_';

        return array_values(array_filter(
            Craft::$app->getDb()->getSchema()->getTableNames('', true),
            static fn(string $table): bool => str_starts_with($table, $prefix),
        ));
    }

    /**
     * @return string[]
     */
    private function otherTables(): array
    {
        $tables = array_values(array_diff(Craft::$app->getDb()->getSchema()->getTableNames('', true), $this->installedTables()));
        sort($tables);

        return $tables;
    }

    /**
     * Everything about the installed tables except the generated names of their indexes and keys.
     *
     * @return array<string, mixed>
     */
    private function schemaSnapshot(): array
    {
        $snapshot = [];

        foreach (self::TABLES as $table) {
            $schema = $this->tableSchema($table);
            $columns = [];

            foreach ($schema->columns as $name => $column) {
                $columns[$name] = [$column->dbType, $column->allowNull, $column->defaultValue];
            }

            $keys = array_map(
                fn($key) => [$key->columnNames, $this->unprefixed($key->foreignTableName), $key->onDelete],
                Craft::$app->getDb()->getSchema()->getTableForeignKeys($table, true),
            );
            $indexes = $this->indexes($table);
            sort($keys);
            sort($indexes);

            $snapshot[$table] = [$columns, $indexes, $keys];
        }

        return $snapshot;
    }

    /**
     * @return array<string, string>
     */
    private function craftDigests(): array
    {
        $digests = [];

        foreach (self::CRAFT_TABLES as $table) {
            $schema = $this->tableSchema($table);
            $hash = hash_init('sha256');

            foreach ((new Query())->from($table)->orderBy(implode(', ', $schema->primaryKey))->each(500) as $row) {
                hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR));
            }

            $digests[$table] = hash_final($hash);
        }

        return $digests;
    }

    private function quietly(callable $migration): void
    {
        ob_start();

        try {
            self::assertNotFalse($migration());
        } finally {
            ob_end_clean();
        }
    }

    private function rawName(string $table): string
    {
        return Craft::$app->getDb()->getSchema()->getRawTableName($table);
    }

    private function unprefixed(string $table): string
    {
        $prefix = (string)Craft::$app->getDb()->tablePrefix;

        return $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
    }
}
