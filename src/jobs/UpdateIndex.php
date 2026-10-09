<?php

namespace Tahadudhiya\SmartLinks\jobs;

use craft\helpers\Queue;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use Tahadudhiya\SmartLinks\services\Index;
use Tahadudhiya\SmartLinks\SmartLinks;
use yii\queue\RetryableJobInterface;

/**
 * Brings the link index up to date with elements that were saved, deleted, restored or moved.
 *
 * Queued, because reading an element's links resolves each new target, which can mean element
 * queries for every link. Running it again changes nothing. A job given more elements than one
 * batch reads one batch and then queues the rest, so no job outgrows the queue's time limit.
 */
class UpdateIndex extends BaseJob implements RetryableJobInterface
{
    use RetriesWhenIndexIsLocked;

    /** @var list<int> */
    public array $elementIds = [];

    public function execute($queue): void
    {
        $ids = array_values(array_unique(array_map('intval', $this->elementIds)));
        $result = SmartLinks::getInstance()->getIndex()->updateElements(array_slice($ids, 0, Index::BATCH_SIZE));
        $rest = array_slice($ids, Index::BATCH_SIZE);

        // Only once this batch is written, so a failed batch, run again, never queues the rest twice.
        if ($rest !== []) {
            Queue::push(new self(['elementIds' => $rest]));
        }

        $this->setProgress($queue, 1, $result->problemCount > 0
            ? Translation::prep('smart-links', '{count, number} {count, plural, =1{problem} other{problems}} logged', ['count' => $result->problemCount])
            : null);
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('smart-links', 'Updating the link index');
    }
}
