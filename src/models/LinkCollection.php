<?php

namespace Tahadudhiya\SmartLinks\models;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;

/**
 * A Smart Link value: the ordered links one field holds for one element in one site.
 *
 * This is the source of truth the index and usage are derived from. An empty collection is the
 * field having no links. Rules across links, such as distinct UIDs, live in
 * {@see \Tahadudhiya\SmartLinks\links\LinkValidator}.
 *
 * @implements IteratorAggregate<int, LinkValue>
 */
final class LinkCollection implements Countable, IteratorAggregate
{
    /** @var list<LinkValue> In order. */
    public readonly array $links;

    /**
     * @param array<int, LinkValue> $links In order.
     * @throws InvalidArgumentException if the links are not a list: their order is part of the
     * value.
     */
    public function __construct(array $links = [])
    {
        if (!array_is_list($links)) {
            throw new InvalidArgumentException('Links must be a list, in order.');
        }

        $this->links = $links;
    }

    public function isEmpty(): bool
    {
        return $this->links === [];
    }

    public function count(): int
    {
        return count($this->links);
    }

    /**
     * @return ArrayIterator<int, LinkValue>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->links);
    }
}
