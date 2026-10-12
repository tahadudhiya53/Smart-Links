<?php

namespace Tahadudhiya\SmartLinks\jobs;

use Craft;
use Tahadudhiya\SmartLinks\errors\IndexLockedException;

/**
 * Lets an index job run again when another process held the index's lock for longer than it
 * waited: nothing was written then, so running it again later is all it needs. Craft's queue
 * runs it again once its time to run has passed. Any other failure fails the job, as usual.
 *
 * @see \yii\queue\RetryableJobInterface
 */
trait RetriesWhenIndexIsLocked
{
    /** How many times a job is run in all, at most, while the lock stays held. */
    public static int $lockedAttempts = 5;

    public function getTtr(): int
    {
        return (int)Craft::$app->getQueue()->ttr;
    }

    public function canRetry($attempt, $error): bool
    {
        return $error instanceof IndexLockedException && $attempt < self::$lockedAttempts;
    }
}
