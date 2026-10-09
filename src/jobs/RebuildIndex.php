<?php

namespace Tahadudhiya\SmartLinks\jobs;

use craft\helpers\Queue;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use Tahadudhiya\SmartLinks\models\IndexingResult;
use Tahadudhiya\SmartLinks\services\Index;
use Tahadudhiya\SmartLinks\SmartLinks;
use yii\queue\RetryableJobInterface;

/**
 * Reads Smart Link field content into the link index again, one batch of elements per job:
 * every source (a full rebuild), or every element of one field layout whose Smart Link fields
 * changed.
 *
 * Each job reads the next batch after the last element ID it was given and, only once that batch
 * is written, queues the next one, as Craft's own batched jobs do. So no job outlasts the queue's
 * time limit, a failed batch stops the rebuild there and is retried on its own, and the rebuild
 * is finished only by the job that finds nothing left. Elements are walked by ID rather than by
 * offset, so elements added or deleted meanwhile never make it skip one. A full rebuild walks
 * every source, then what is left behind it (see {@see Index::rebuildRemainder()}), and only then
 * removes what no source accounts for. The index stays readable throughout.
 */
class RebuildIndex extends BaseJob implements RetryableJobInterface
{
    use RetriesWhenIndexIsLocked;

    /** When a full rebuild began, as stored in the database. */
    public string $startedAt = '';

    /** The layout whose elements to read again, instead of a full rebuild. */
    public ?int $fieldLayoutId = null;

    /** Whether a full rebuild has walked every source, and reads what is left behind it. */
    public bool $finishing = false;

    /** The last element ID an earlier batch read. */
    public int $afterElementId = 0;

    /** How many elements earlier batches read, for progress. */
    public int $done = 0;

    /** How many elements the rebuild expects to read, counted once by its first job, for progress. */
    public ?int $total = null;

    /** Elements read per job. */
    public int $batchSize = Index::BATCH_SIZE;

    public function execute($queue): void
    {
        $index = SmartLinks::getInstance()->getIndex();
        $result = new IndexingResult();

        [$last, $count] = match (true) {
            $this->fieldLayoutId !== null => $index->reindexLayoutBatch($this->fieldLayoutId, $this->afterElementId, $this->batchSize, $result),
            $this->finishing => $index->rebuildRemainder($this->startedAt, $this->afterElementId, $this->batchSize, $result),
            default => $index->rebuildBatch($this->afterElementId, $this->batchSize, $result),
        };

        $done = $this->done + $count;
        $label = $result->problemCount > 0
            ? Translation::prep('smart-links', '{count, number} {count, plural, =1{problem} other{problems}} logged', ['count' => $result->problemCount])
            : null;

        if ($last !== null) {
            $total = $this->total ?? $index->sourceElementCount();
            $this->setProgress($queue, $done / max($done + 1, $total), $label);
            Queue::push(new self(['afterElementId' => $last, 'done' => $done, 'total' => $total] + $this->config()));

            return;
        }

        if ($this->fieldLayoutId === null && !$this->finishing) {
            $this->setProgress($queue, 1, $label);
            Queue::push(new self(['finishing' => true, 'afterElementId' => 0, 'done' => $done, 'total' => $this->total] + $this->config()));

            return;
        }

        if ($this->fieldLayoutId !== null) {
            $index->pruneUnusedTargets();
        } else {
            $index->finishRebuild($result);
        }

        $this->setProgress($queue, 1, $label);
    }

    protected function defaultDescription(): ?string
    {
        return $this->fieldLayoutId !== null
            ? Translation::prep('smart-links', 'Updating the link index for a changed field layout')
            : Translation::prep('smart-links', 'Rebuilding the link index');
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return ['startedAt' => $this->startedAt, 'fieldLayoutId' => $this->fieldLayoutId, 'finishing' => $this->finishing, 'batchSize' => $this->batchSize];
    }
}
