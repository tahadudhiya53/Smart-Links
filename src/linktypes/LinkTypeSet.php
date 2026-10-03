<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use InvalidArgumentException;
use Tahadudhiya\SmartLinks\links\LinkValidator;

/**
 * The link types available to normalize, validate and read links with, by handle.
 */
final class LinkTypeSet
{
    /** @var array<string, LinkTypeInterface> */
    private readonly array $types;

    /**
     * @param list<LinkTypeInterface> $types
     * @throws InvalidArgumentException if a handle is malformed or used twice.
     */
    public function __construct(array $types)
    {
        $byHandle = [];

        foreach ($types as $type) {
            $handle = $type->handle();

            if (!LinkValidator::isTypeHandle($handle)) {
                throw new InvalidArgumentException("“{$handle}” is not a valid link type handle.");
            }

            // Two types under one handle would make stored links ambiguous.
            if (isset($byHandle[$handle])) {
                throw new InvalidArgumentException("Two link types have the handle “{$handle}”.");
            }

            $byHandle[$handle] = $type;
        }

        $this->types = $byHandle;
    }

    public function get(string $handle): ?LinkTypeInterface
    {
        return $this->types[$handle] ?? null;
    }

    /**
     * @return list<string>
     */
    public function handles(): array
    {
        return array_keys($this->types);
    }
}
