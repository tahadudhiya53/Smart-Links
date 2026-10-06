<?php

namespace Tahadudhiya\SmartLinks\records;

use craft\db\ActiveRecord;

/**
 * A `smartlinks_usage` row: one place a link target is used in Smart Link field content.
 *
 * Derived data, rebuilt from field content. Records are the database's shape and nothing else.
 *
 * @property int $id
 * @property int $indexId
 * @property int $elementId
 * @property int $siteId
 * @property int $fieldId
 * @property string $layoutElementUid
 * @property string $linkUid
 * @property int $sortOrder
 * @property string|null $label
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class UsageRecord extends ActiveRecord
{
    public const TABLE = '{{%smartlinks_usage}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
