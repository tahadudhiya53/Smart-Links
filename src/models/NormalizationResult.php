<?php

namespace Tahadudhiya\SmartLinks\models;

use InvalidArgumentException;

/**
 * The outcome of normalizing what an author entered: the normalized value, or every reason it
 * could not be normalized. Never both.
 *
 * @template-covariant T of LinkValue|LinkCollection
 */
final class NormalizationResult
{
    /**
     * @param T|null $value Null when there are errors, or when the input was an empty link.
     * @param list<ValidationError> $errors In the order the input was read.
     */
    public function __construct(
        public readonly LinkValue|LinkCollection|null $value,
        public readonly array $errors = [],
    ) {
        if ($value !== null && $errors !== []) {
            throw new InvalidArgumentException('A normalization result has a value or errors, never both.');
        }
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
