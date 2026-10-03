<?php

namespace Tahadudhiya\SmartLinks\errors;

use Tahadudhiya\SmartLinks\models\ValidationError;
use yii\base\Exception;

/**
 * A link value that cannot be accepted, with every reason why.
 *
 * Thrown where there is no author to show errors to: reading stored content, and serializing a
 * value built in code. Authoring input gets its errors returned instead.
 */
class LinkValidationException extends Exception
{
    /**
     * @param list<ValidationError> $errors At least one.
     */
    public function __construct(
        public readonly array $errors,
    ) {
        $first = $errors[0] ?? null;
        $summary = $first !== null ? ($first->path !== '' ? "{$first->path}: " : '') . $first->getMessage() : 'Invalid link value.';
        $more = count($errors) - 1;

        parent::__construct($more > 0 ? "$summary (and $more more)" : $summary);
    }

    public function getName(): string
    {
        return 'Invalid link value';
    }

    /**
     * The same errors, reported from inside `$prefix`.
     */
    public function within(string $prefix): self
    {
        return new self(array_map(static fn(ValidationError $error): ValidationError => $error->within($prefix), $this->errors));
    }
}
