<?php

namespace Tahadudhiya\SmartLinks\services;

use Craft;
use LogicException;
use Tahadudhiya\SmartLinks\errors\TargetHashCollisionException;
use Tahadudhiya\SmartLinks\models\TargetIdentity;
use Tahadudhiya\SmartLinks\records\IndexRecord;
use yii\base\Component;
use yii\db\IntegrityException;

/**
 * The link index: the one place `smartlinks_index` rows are found and created.
 *
 * Rows are looked up by hash, but a hash only ever stands in for its key, so every row is
 * confirmed by key before it is treated as the target asked for.
 */
class Index extends Component
{
    /**
     * Returns the ID of the index row for a target, creating the row if there is none.
     *
     * @throws TargetHashCollisionException if a different target is stored under the same hash.
     * @throws IntegrityException if the insert is refused and no row explains it (see
     * {@see insertTarget()}).
     */
    public function targetId(TargetIdentity $identity): int
    {
        $record = $this->findTarget($identity->hash()) ?? $this->insertTarget($identity);

        if ($record->targetKey !== $identity->key()) {
            throw new TargetHashCollisionException($identity->hash(), $record->targetKey, $identity->key());
        }

        return $record->id;
    }

    protected function findTarget(string $targetHash): ?IndexRecord
    {
        return IndexRecord::findOne(['targetHash' => $targetHash]);
    }

    /**
     * Inserts the row, or returns the row another process inserted under the same hash after
     * {@see findTarget()} found none. The caller still compares its key.
     *
     * Inside a caller's REPEATABLE READ transaction, the other process's row can be invisible to
     * the re-read. The unique violation is then rethrown rather than guessed around, and the
     * caller can retry in a fresh transaction.
     */
    private function insertTarget(TargetIdentity $identity): IndexRecord
    {
        $record = new IndexRecord();
        $record->targetKey = $identity->key();
        $record->targetHash = $identity->hash();
        $record->linkType = $identity->type;
        $record->targetElementId = isset($identity->components['elementId']) ? (int)$identity->components['elementId'] : null;
        $record->targetSiteId = isset($identity->components['siteId']) ? (int)$identity->components['siteId'] : null;

        try {
            // Its own transaction, or a savepoint in the caller's, so a refused insert is rolled
            // back without leaving the caller's transaction unusable.
            Craft::$app->getDb()->transaction(static function() use ($record): void {
                if (!$record->insert()) {
                    throw new LogicException('The index row could not be inserted: ' . implode(' ', $record->getFirstErrors()));
                }
            });
        } catch (IntegrityException $exception) {
            // The row has no foreign keys and every required column is set, so only the unique
            // hash can refuse it. If no row holds that hash, the refusal is something else.
            return $this->findTarget($identity->hash()) ?? throw $exception;
        }

        return $record;
    }
}
