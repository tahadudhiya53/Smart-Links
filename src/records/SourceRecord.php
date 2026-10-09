<?php

namespace Tahadudhiya\SmartLinks\records;

use craft\db\ActiveRecord;

/**
 * A `smartlinks_sources` row: one element site whose Smart Link field content the index has read.
 *
 * Derived data. It records which version of the element the index's usage rows for that site
 * were built from, so content saved since, or never read, can be found without reading it.
 * Records are the database's shape and nothing else.
 *
 * @property int $id
 * @property int $elementId
 * @property int $siteId
 * @property string $elementDateUpdated
 * @property int $unreadableValues
 * @property string $dateIndexed
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class SourceRecord extends ActiveRecord
{
    public const TABLE = '{{%smartlinks_sources}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
