<?php

namespace Tahadudhiya\SmartLinks\models;

/**
 * One page of the link inventory.
 */
final class InventoryPage
{
    /**
     * @param list<InventoryItem> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $pageSize,
    ) {
    }

    public function pageCount(): int
    {
        return max(1, (int)ceil($this->total / $this->pageSize));
    }

    /** The position of the first item shown, from 1; 0 when there are none. */
    public function first(): int
    {
        return $this->items === [] ? 0 : ($this->page - 1) * $this->pageSize + 1;
    }

    public function last(): int
    {
        return $this->items === [] ? 0 : $this->first() + count($this->items) - 1;
    }
}
