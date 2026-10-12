<?php

namespace Tahadudhiya\SmartLinks\models;

use Craft;

/**
 * What one indexing run did, and every problem it met without stopping.
 *
 * A problem is something the run could not index and left as it was, e.g. a value that can't be
 * read. It is reported here and logged, never passed over.
 */
final class IndexingResult
{
    /** The most problems kept for reporting; every one is logged. */
    public const MAX_PROBLEMS = 50;

    /** Element sites read. */
    public int $sources = 0;

    /** Links indexed. */
    public int $links = 0;

    /** Values that can't be read, whose usage was kept as last indexed. */
    public int $unreadableValues = 0;

    public int $removedSources = 0;

    public int $removedUsages = 0;

    public int $removedTargets = 0;

    /** @var list<string> */
    public array $problems = [];

    /** How many problems there were, including any not kept. */
    public int $problemCount = 0;

    public function addProblem(string $problem): void
    {
        Craft::warning($problem, 'smart-links');
        $this->problemCount++;

        if (count($this->problems) < self::MAX_PROBLEMS) {
            $this->problems[] = $problem;
        }
    }
}
