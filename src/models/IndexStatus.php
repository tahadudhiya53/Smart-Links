<?php

namespace Tahadudhiya\SmartLinks\models;

/**
 * What the link index holds, and how far it is known to be behind content.
 *
 * Each count is a kind of difference the index can see without reading content. A rebuild clears
 * all of them; an element's next update clears its own.
 */
final class IndexStatus
{
    public function __construct(
        /** Distinct link targets. */
        public readonly int $targets,
        /** Link occurrences. */
        public readonly int $usages,
        /** Element sites read. */
        public readonly int $sources,
        /** Element sites holding a Smart Link field that were saved since they were read, or never read. */
        public readonly int $unindexedSources,
        /** Element sites with a Smart Link value that could not be read: its links are as an earlier reading left them. */
        public readonly int $unreadableSources,
        /** Element sites read earlier that are no longer sources: trashed, gone from a site, or without a Smart Link field now. */
        public readonly int $orphanedSources,
        /** Link occurrences of a source no longer read, or of a field no longer in a layout. */
        public readonly int $orphanedUsages,
        /** Targets no link uses any more. */
        public readonly int $unusedTargets,
        /** Targets recorded as leading somewhere whose element has since been deleted, trashed or removed from the site, or whose site is gone. */
        public readonly int $outdatedTargets,
    ) {
    }

    public function isStale(): bool
    {
        return $this->staleCount() > 0;
    }

    public function staleCount(): int
    {
        return $this->unindexedSources + $this->unreadableSources + $this->orphanedSources + $this->orphanedUsages + $this->unusedTargets + $this->outdatedTargets;
    }
}
