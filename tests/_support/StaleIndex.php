<?php

namespace Tahadudhiya\SmartLinks\Tests\_support;

use Tahadudhiya\SmartLinks\records\IndexRecord;
use Tahadudhiya\SmartLinks\services\Index;

/**
 * An index whose first lookups miss, as they do for a process that looked just before another
 * process inserted the same target. It makes the race after the lookup repeatable in one test.
 */
class StaleIndex extends Index
{
    /** @var int How many lookups still miss. */
    public int $staleLookups = 1;

    protected function findTarget(string $targetHash): ?IndexRecord
    {
        if ($this->staleLookups > 0) {
            $this->staleLookups--;

            return null;
        }

        return parent::findTarget($targetHash);
    }
}
