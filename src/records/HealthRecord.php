<?php

namespace Tahadudhiya\SmartLinks\records;

use craft\db\ActiveRecord;

/**
 * A `smartlinks_health` row: the latest evidenced health of one URL.
 *
 * Keyed by URL rather than by index row, so a URL is checked once however many targets resolve
 * to it, and its evidence survives an index rebuild. Records are the database's shape and
 * nothing else.
 *
 * @property int $id
 * @property string $url
 * @property string $urlHash
 * @property string $state
 * @property int|null $statusCode
 * @property string|null $failure
 * @property string|null $finalUrl
 * @property string|null $redirectChain
 * @property string|null $dateChecked
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class HealthRecord extends ActiveRecord
{
    public const TABLE = '{{%smartlinks_health}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
