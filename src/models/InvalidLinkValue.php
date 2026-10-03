<?php

namespace Tahadudhiya\SmartLinks\models;

/**
 * A Smart Link field value that is not a valid link value, kept as it was so nothing is lost.
 *
 * It is either what an author entered that could not be normalized, which is shown again with
 * its errors and can never be stored; or stored content that can no longer be read (e.g. its
 * link type is no longer registered), which is kept exactly as stored until an author fixes it.
 */
final class InvalidLinkValue
{
    /**
     * @param mixed $input The authoring input, or the stored value, exactly as received.
     * @param list<ValidationError> $errors At least one, with paths into the value, e.g.
     * `links[1].data.url`.
     * @param bool $stored Whether this is stored content rather than new input.
     */
    private function __construct(
        public readonly mixed $input,
        public readonly array $errors,
        public readonly bool $stored,
    ) {
    }

    /**
     * @param list<ValidationError> $errors
     */
    public static function fromInput(mixed $input, array $errors): self
    {
        return new self($input, $errors, false);
    }

    /**
     * @param list<ValidationError> $errors
     */
    public static function fromStorage(mixed $stored, array $errors): self
    {
        return new self($stored, $errors, true);
    }
}
