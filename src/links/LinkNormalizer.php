<?php

namespace Tahadudhiya\SmartLinks\links;

use craft\helpers\StringHelper;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeDataInterface;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeInterface;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeSet;
use Tahadudhiya\SmartLinks\models\LinkAttributes;
use Tahadudhiya\SmartLinks\models\LinkCollection;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\NormalizationResult;
use Tahadudhiya\SmartLinks\models\ValidationError;

/**
 * Turns what an author entered into a normalized link value, or into every reason it cannot be.
 *
 * Authoring input is what a form, an API or an import sends: arrays of strings, where an empty
 * string means "not set". Only provably equivalent spellings are normalized: an empty string is
 * absence, a space-separated token list is a list, `rel` keywords are lowercase (HTML compares
 * them case-insensitively), and a form's `"1"`/`"0"` is a boolean. Everything else that is wrong
 * is reported, never repaired, and nothing unrecognised is dropped.
 */
final class LinkNormalizer
{
    private const LINK_KEYS = ['uid', 'type', 'data', 'label', 'urlSuffix', 'attributes', 'presetUid'];
    private const ATTRIBUTE_KEYS = ['target', 'rel', 'title', 'class', 'id', 'ariaLabel', 'download', 'downloadFilename', 'custom'];

    public function __construct(
        private readonly LinkTypeSet $types,
        private readonly LinkValidator $validator,
    ) {
    }

    /**
     * Normalizes a whole field value: a list of links. Nothing (`null`, `''`, `[]`) is an empty
     * value.
     *
     * @return NormalizationResult<LinkCollection>
     */
    public function normalize(mixed $input): NormalizationResult
    {
        if ($input === null || $input === '' || $input === []) {
            return new NormalizationResult(new LinkCollection());
        }

        if (!is_array($input) || !array_is_list($input)) {
            return new NormalizationResult(null, [new ValidationError('', Code::WRONG_TYPE, 'The value must be a list of links.')]);
        }

        $links = [];
        $errors = [];

        foreach ($input as $index => $item) {
            $result = $this->normalizeLink($item, "links[$index]");

            if ($result->value === null && $result->isValid()) {
                // An empty entry in a list is reported, not skipped: dropping it would be silent.
                $errors[] = new ValidationError("links[$index]", Code::MISSING, 'This link is empty.');
            }

            array_push($errors, ...$result->errors);

            if ($result->value instanceof LinkValue) {
                $links[] = $result->value;
            }
        }

        $collection = new LinkCollection($links);

        if ($errors === []) {
            $errors = $this->validator->validateUniqueUids($collection);
        }

        return $errors === [] ? new NormalizationResult($collection) : new NormalizationResult(null, $errors);
    }

    /**
     * Normalizes one link. Nothing (`null`, `''`, `[]`) is no link, which is not an error.
     *
     * @return NormalizationResult<LinkValue>
     */
    public function normalizeLink(mixed $input, string $path = ''): NormalizationResult
    {
        if ($input === null || $input === '' || $input === []) {
            return new NormalizationResult(null);
        }

        if (!is_array($input) || array_is_list($input)) {
            return new NormalizationResult(null, [new ValidationError($path, Code::WRONG_TYPE, 'A link must be an object.')]);
        }

        $errors = $this->unknownKeys($input, self::LINK_KEYS, $path, '“{key}” is not a recognised link property.');

        $typeHandle = $this->text($input, 'type', $path, $errors);

        if ($typeHandle === null && !$this->hasWrongType($errors, ValidationError::join($path, 'type'))) {
            $errors[] = new ValidationError(ValidationError::join($path, 'type'), Code::MISSING, 'A link type is required.');
        }

        $uid = $this->text($input, 'uid', $path, $errors) ?? StringHelper::UUID();
        $label = $this->text($input, 'label', $path, $errors);
        $urlSuffix = $this->text($input, 'urlSuffix', $path, $errors);
        $presetUid = $this->text($input, 'presetUid', $path, $errors);
        $attributes = $this->attributes($input['attributes'] ?? null, ValidationError::join($path, 'attributes'), $errors);

        $type = $typeHandle !== null && LinkValidator::isTypeHandle($typeHandle) ? $this->types->get($typeHandle) : null;
        $data = $type !== null ? $this->data($type, $input['data'] ?? null, ValidationError::join($path, 'data'), $errors) : null;

        array_push($errors, ...$this->validator->validateParts($uid, $typeHandle, $data, $label, $urlSuffix, $attributes, $presetUid, $path));

        // Data is only built once a type is known, so without data there is no type either.
        if ($errors !== [] || $data === null) {
            return new NormalizationResult(null, $errors);
        }

        return new NormalizationResult(new LinkValue($uid, $typeHandle, $data, $label, $urlSuffix, $attributes, $presetUid));
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function data(LinkTypeInterface $type, mixed $input, string $path, array &$errors): ?LinkTypeDataInterface
    {
        $input ??= [];

        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            $errors[] = new ValidationError($path, Code::WRONG_TYPE, 'Link data must be an object.');

            return null;
        }

        try {
            return $type->normalizeData($input);
        } catch (LinkValidationException $exception) {
            array_push($errors, ...$exception->within($path)->errors);

            return null;
        }
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function attributes(mixed $input, string $path, array &$errors): LinkAttributes
    {
        if ($input === null || $input === []) {
            return new LinkAttributes();
        }

        if (!is_array($input) || array_is_list($input)) {
            $errors[] = new ValidationError($path, Code::WRONG_TYPE, 'Link attributes must be an object.');

            return new LinkAttributes();
        }

        array_push($errors, ...$this->unknownKeys($input, self::ATTRIBUTE_KEYS, $path, '“{key}” is not a recognised link attribute.'));

        return new LinkAttributes(
            target: $this->text($input, 'target', $path, $errors),
            rel: array_map('strtolower', $this->tokens($input, 'rel', $path, $errors)),
            title: $this->text($input, 'title', $path, $errors),
            class: $this->tokens($input, 'class', $path, $errors),
            id: $this->text($input, 'id', $path, $errors),
            ariaLabel: $this->text($input, 'ariaLabel', $path, $errors),
            download: $this->flag($input, 'download', $path, $errors),
            downloadFilename: $this->text($input, 'downloadFilename', $path, $errors),
            custom: $this->custom($input['custom'] ?? null, ValidationError::join($path, 'custom'), $errors),
        );
    }

    /**
     * An optional text value: absent or `''` is not set.
     *
     * @param array<mixed> $input
     * @param list<ValidationError> $errors
     */
    private function text(array $input, string $key, string $path, array &$errors): ?string
    {
        $value = $input[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            $errors[] = new ValidationError(ValidationError::join($path, $key), Code::WRONG_TYPE, '“{key}” must be text.', ['key' => $key]);

            return null;
        }

        return $value;
    }

    /**
     * A token list, given as a list or as one string separated by ASCII whitespace, as HTML
     * separates them.
     *
     * @param array<mixed> $input
     * @param list<ValidationError> $errors
     * @return list<string>
     */
    private function tokens(array $input, string $key, string $path, array &$errors): array
    {
        $value = $input[$key] ?? null;

        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            return preg_split('/[\t\n\f\r ]+/', trim($value, "\t\n\f\r "), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (is_array($value) && array_is_list($value) && array_filter($value, 'is_string') === $value) {
            return $value;
        }

        $errors[] = new ValidationError(ValidationError::join($path, $key), Code::WRONG_TYPE, '“{key}” must be text or a list of words.', ['key' => $key]);

        return [];
    }

    /**
     * @param array<mixed> $input
     * @param list<ValidationError> $errors
     */
    private function flag(array $input, string $key, string $path, array &$errors): bool
    {
        $value = $input[$key] ?? null;

        // What a checkbox or lightswitch posts, besides a real boolean.
        if ($value === null || $value === false || $value === '' || $value === '0' || $value === 0) {
            return false;
        }

        if ($value === true || $value === '1' || $value === 1) {
            return true;
        }

        $errors[] = new ValidationError(ValidationError::join($path, $key), Code::WRONG_TYPE, '“{key}” must be on or off.', ['key' => $key]);

        return false;
    }

    /**
     * Custom attributes, as a map of names to values or as a list of `name`/`value` rows, the way
     * a form posts them. Rows keep their order, so they are the same map; a name given twice is
     * reported rather than letting one row silently replace the other.
     *
     * @param list<ValidationError> $errors
     * @return array<string, string>
     */
    private function custom(mixed $input, string $path, array &$errors): array
    {
        if ($input === null || $input === []) {
            return [];
        }

        if (is_array($input) && array_is_list($input)) {
            return $this->customRows($input, $path, $errors);
        }

        if (!is_array($input)) {
            $errors[] = new ValidationError($path, Code::WRONG_TYPE, 'Custom attributes must map names to text.');

            return [];
        }

        $custom = [];

        foreach ($input as $name => $value) {
            if (!is_string($value)) {
                $errors[] = new ValidationError(ValidationError::join($path, (string)$name), Code::WRONG_TYPE, 'Custom attributes must map names to text.');
            } else {
                $custom[(string)$name] = $value;
            }
        }

        return $custom;
    }

    /**
     * @param list<mixed> $rows
     * @param list<ValidationError> $errors
     * @return array<string, string>
     */
    private function customRows(array $rows, string $path, array &$errors): array
    {
        $custom = [];

        foreach ($rows as $index => $row) {
            $rowPath = "{$path}[$index]";

            if (!is_array($row) || array_is_list($row)) {
                $errors[] = new ValidationError($rowPath, Code::WRONG_TYPE, 'A custom attribute row must have a name and a value.');

                continue;
            }

            array_push($errors, ...$this->unknownKeys($row, ['name', 'value'], $rowPath, '“{key}” is not a recognised custom attribute property.'));

            $name = $row['name'] ?? null;
            $value = $row['value'] ?? '';

            if ($name === null || $name === '') {
                $errors[] = new ValidationError("$rowPath.name", Code::MISSING, 'A custom attribute needs a name.');
            } elseif (!is_string($name)) {
                $errors[] = new ValidationError("$rowPath.name", Code::WRONG_TYPE, '“{key}” must be text.', ['key' => 'name']);
            } elseif (!is_string($value)) {
                $errors[] = new ValidationError("$rowPath.value", Code::WRONG_TYPE, '“{key}” must be text.', ['key' => 'value']);
            } elseif (array_key_exists($name, $custom)) {
                $errors[] = new ValidationError("$rowPath.name", Code::DUPLICATE, '“{value}” is listed more than once.', ['value' => $name]);
            } else {
                $custom[$name] = $value;
            }
        }

        return $custom;
    }

    /**
     * @param array<mixed> $input
     * @param list<string> $known
     * @return list<ValidationError>
     */
    private function unknownKeys(array $input, array $known, string $path, string $message): array
    {
        $errors = [];

        foreach (array_keys($input) as $key) {
            if (!in_array($key, $known, true)) {
                $errors[] = new ValidationError(ValidationError::join($path, (string)$key), Code::UNKNOWN_KEY, $message, ['key' => (string)$key]);
            }
        }

        return $errors;
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function hasWrongType(array $errors, string $path): bool
    {
        foreach ($errors as $error) {
            if ($error->path === $path && $error->code === Code::WRONG_TYPE) {
                return true;
            }
        }

        return false;
    }
}
