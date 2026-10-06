<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\models\ValidationError;

/**
 * Reads a link type's data the way the link core reads the rest of a link: an empty string is
 * not set, nothing unrecognised is dropped, and every problem is reported at once.
 *
 * Shared by the built-in link types, and usable by registered ones.
 */
final class DataInput
{
    /**
     * Unknown keys are reported, not dropped, as the link core reports its own.
     *
     * @param array<mixed> $input
     * @param list<string> $known
     * @return list<ValidationError>
     */
    public static function unknownKeys(array $input, array $known): array
    {
        $errors = [];

        foreach (array_keys($input) as $key) {
            if (!in_array($key, $known, true)) {
                $errors[] = new ValidationError((string)$key, Code::UNKNOWN_KEY, '“{key}” is not a recognised link property.', ['key' => (string)$key]);
            }
        }

        return $errors;
    }

    /**
     * Text under a key, or null when it is absent or empty.
     *
     * @param array<mixed> $input
     * @param list<ValidationError> $errors
     */
    public static function text(array $input, string $key, array &$errors): ?string
    {
        $value = $input[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            $errors[] = new ValidationError($key, Code::WRONG_TYPE, '“{key}” must be text.', ['key' => $key]);

            return null;
        }

        return $value;
    }

    /**
     * A positive ID: an integer, or the digits a form posts it as. Anything else is null.
     */
    public static function positiveId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        // Only the one decimal spelling: `012`, `+12` and `1e3` are not what a form posts.
        return is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/', $value) && (string)(int)$value === $value ? (int)$value : null;
    }

    /**
     * Text an author typed: non-empty UTF-8 without control characters, as the link core's rule
     * for labels and titles.
     */
    public static function isText(string $value): bool
    {
        return $value !== '' && mb_check_encoding($value, 'UTF-8') && !preg_match('/\p{Cc}/u', $value);
    }

    /**
     * Text that may run over several lines, such as a message body: line breaks are allowed,
     * every other control character is not.
     */
    public static function isMultilineText(string $value): bool
    {
        return $value !== '' && mb_check_encoding($value, 'UTF-8') && !preg_match('/(?![\r\n])\p{Cc}/u', $value);
    }

    /**
     * Whether stored data is exactly the canonical form, apart from the order of its keys, which
     * databases do not keep. Values are compared strictly, so `"12"` is not `12`.
     *
     * @param array<string, string|int|bool> $canonical
     * @param array<mixed> $stored
     */
    public static function isStoredForm(array $canonical, array $stored): bool
    {
        ksort($canonical, SORT_STRING);
        ksort($stored, SORT_STRING);

        return $canonical === $stored;
    }

    /**
     * What an author entered under a key, to show it again in an input. Anything but text or a
     * number is not what an input posts, so it shows as nothing.
     *
     * @param array<mixed>|null $input
     */
    public static function inputValue(?array $input, string $key): string
    {
        $value = $input[$key] ?? null;

        return is_string($value) || is_int($value) ? (string)$value : '';
    }

    /**
     * @param list<ValidationError> $errors
     * @throws LinkValidationException if there are any.
     */
    public static function throwIfAny(array $errors): void
    {
        if ($errors !== []) {
            throw new LinkValidationException($errors);
        }
    }
}
