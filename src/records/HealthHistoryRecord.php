<?php

namespace Tahadudhiya\SmartLinks\records;

use craft\db\ActiveRecord;
use LogicException;

/**
 * A `smartlinks_health_history` row: an earlier health observation of a URL.
 *
 * Append-only through ActiveRecord. Every update and delete path, instance or static, ends in
 * one of the methods refused below, so a row can only be inserted. It is removed only by the
 * database's cascade when its health row is deleted. The database does not forbid other writes,
 * so no Smart Links code writes to this table any other way. Records are the database's shape and
 * nothing else.
 *
 * @property int $id
 * @property int $healthId
 * @property string $state
 * @property int|null $statusCode
 * @property string|null $failure
 * @property string $finalUrl
 * @property string|null $redirectChain
 * @property string $dateChecked
 * @property string $dateCreated
 * @property string $uid
 */
class HealthHistoryRecord extends ActiveRecord
{
    public const TABLE = '{{%smartlinks_health_history}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }

    /**
     * Refuses updating a recorded row before anything else is looked at, so an update is refused
     * whether or not it would change anything.
     *
     * @throws LogicException for any save of an existing row.
     */
    public function beforeSave($insert): bool
    {
        if (!$insert) {
            throw new LogicException('Health history is append-only: a recorded observation cannot be changed.');
        }

        return parent::beforeSave($insert);
    }

    /**
     * @throws LogicException always: history goes only with the health row it belongs to.
     */
    public function beforeDelete(): bool
    {
        throw new LogicException('Health history is removed only together with the health row it belongs to.');
    }

    /**
     * Yii writes these straight through {@see updateAll()}, but returns early when nothing has
     * changed; refused here so the answer never depends on the values given.
     *
     * @throws LogicException always: a recorded observation cannot be changed.
     */
    public function updateAttributes($attributes): int
    {
        throw new LogicException('Health history is append-only: a recorded observation cannot be changed.');
    }

    /**
     * @throws LogicException always: a recorded observation cannot be changed.
     */
    public static function updateAll($attributes, $condition = '', $params = []): int
    {
        throw new LogicException('Health history is append-only: a recorded observation cannot be changed.');
    }

    /**
     * @throws LogicException always: a recorded observation cannot be changed.
     */
    public static function updateAllCounters($counters, $condition = '', $params = []): int
    {
        throw new LogicException('Health history is append-only: a recorded observation cannot be changed.');
    }

    /**
     * @throws LogicException always: history goes only with the health row it belongs to.
     */
    public static function deleteAll($condition = null, $params = []): int
    {
        throw new LogicException('Health history is removed only together with the health row it belongs to.');
    }
}
