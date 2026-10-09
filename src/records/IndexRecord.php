<?php

namespace Tahadudhiya\SmartLinks\records;

use craft\db\ActiveRecord;

/**
 * A `smartlinks_index` row: one distinct link target found in Smart Link field content.
 *
 * Derived data. Every row can be rebuilt from field content, and none is ever read back as the
 * link itself. Records are the database's shape and nothing else.
 *
 * @property int $id
 * @property string $targetKey
 * @property string $targetHash
 * @property string $linkType
 * @property int|null $targetElementId
 * @property int|null $targetSiteId
 * @property string|null $resolvedUrl
 * @property string|null $targetLabel
 * @property string|null $targetStatus
 * @property string|null $healthUrlHash
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class IndexRecord extends ActiveRecord
{
    public const TABLE = '{{%smartlinks_index}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
