<?php

namespace Tahadudhiya\SmartLinks\Tests\_support\linktypes;

use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeSet;
use Tahadudhiya\SmartLinks\models\ValidationError;

/**
 * The link types the link core is tested with, standing in for real ones.
 */
final class FakeTypes
{
    public static function set(): LinkTypeSet
    {
        return new LinkTypeSet([new FakeUrlType(), new FakeEntryType(), new FakeEmailType()]);
    }

    /**
     * What an author entered for one data key, to show it again. Anything but text is not what
     * the input posts, so it is shown as nothing.
     *
     * @param array<mixed>|null $input
     */
    public static function inputValue(?array $input, string $key): string
    {
        $value = $input[$key] ?? null;

        return is_string($value) || is_int($value) ? (string)$value : '';
    }

    /**
     * Link types report unknown data keys rather than dropping them, like the core does.
     *
     * @param array<mixed> $input
     * @param list<string> $known
     */
    public static function assertOnlyKeys(array $input, array $known): void
    {
        $errors = [];

        foreach (array_keys($input) as $key) {
            if (!in_array($key, $known, true)) {
                $errors[] = new ValidationError((string)$key, Code::UNKNOWN_KEY, 'Unknown data key.');
            }
        }

        if ($errors !== []) {
            throw new LinkValidationException($errors);
        }
    }
}
