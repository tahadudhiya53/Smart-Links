<?php

namespace Tahadudhiya\SmartLinks\links;

use LogicException;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeDataInterface;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeSet;
use Tahadudhiya\SmartLinks\models\LinkAttributes;
use Tahadudhiya\SmartLinks\models\LinkCollection;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ValidationError;

/**
 * Writes a link value to its stored form and reads it back, exactly.
 *
 * The stored form is JSON-safe scalars and arrays, versioned so a later format is recognised
 * rather than guessed: `{"version": 1, "links": [...]}`. Only what is set is written, in a fixed
 * order, so one value has exactly one stored form. An empty value is stored as nothing (`null`).
 * Reading refuses anything else, including non-canonical spellings, because stored content is
 * only ever written here.
 *
 * The order of keys in an object is not part of the stored form: JSON objects are unordered, and
 * databases do not keep it (MySQL's JSON type sorts keys, as PostgreSQL's JSONB does). So reading
 * never depends on it, and anything whose order matters is stored as a list: links, `rel` and
 * `class` tokens, and custom attributes, as `{"name": …, "value": …}` rows.
 *
 * A draft may also hold what its author is still writing, as it was entered, in its own stored
 * form, `{"version": 1, "unfinished": <authoring input>}` (see {@see serializeUnfinished()}). That
 * form is never read as links: {@see deserialize()} refuses it like any other non-links value.
 */
final class LinkSerializer
{
    /** The stored format this class writes, and the only one it reads. */
    public const VERSION = 1;

    /** The key of a draft's unfinished authoring input in its stored form. */
    public const UNFINISHED = 'unfinished';

    private const LINK_KEYS = ['uid', 'type', 'data', 'label', 'urlSuffix', 'attributes', 'presetUid'];
    private const ATTRIBUTE_KEYS = ['target', 'rel', 'title', 'class', 'id', 'ariaLabel', 'download', 'downloadFilename', 'custom'];

    public function __construct(
        private readonly LinkTypeSet $types,
        private readonly LinkValidator $validator,
    ) {
    }

    /**
     * @return array{version: int, links: list<array<string, mixed>>}|null Null for an empty value.
     * @throws LinkValidationException if the value is invalid: nothing invalid is ever stored.
     */
    public function serialize(LinkCollection $value): ?array
    {
        $errors = $this->validator->validateCollection($value);

        if ($errors !== []) {
            throw new LinkValidationException($errors);
        }

        if ($value->isEmpty()) {
            return null;
        }

        return [
            'version' => self::VERSION,
            'links' => array_map(fn(LinkValue $link): array => $this->linkToArray($link), $value->links),
        ];
    }

    /**
     * @throws LinkValidationException if the stored value is malformed, not canonical, in an
     * unknown version, or invalid.
     */
    /**
     * The stored form of authoring input a draft keeps while its author finishes it: the input
     * exactly as entered, never links. Only the field writes it, and only for a draft.
     *
     * @return array{version: int, unfinished: mixed}
     */
    public function serializeUnfinished(mixed $input): array
    {
        return ['version' => self::VERSION, self::UNFINISHED => $input];
    }

    /**
     * Whether a stored value is a draft's unfinished input, exactly as {@see serializeUnfinished()}
     * wrote it.
     */
    public function isUnfinished(mixed $stored): bool
    {
        return is_array($stored)
            && count($stored) === 2
            && ($stored['version'] ?? null) === self::VERSION
            && array_key_exists(self::UNFINISHED, $stored);
    }

    /**
     * The authoring input of a draft's unfinished value.
     *
     * @throws \InvalidArgumentException if it is not one.
     */
    public function unfinishedInput(mixed $stored): mixed
    {
        if (!$this->isUnfinished($stored)) {
            throw new \InvalidArgumentException('This is not an unfinished value.');
        }

        /** @var array<string, mixed> $stored */
        return $stored[self::UNFINISHED];
    }

    public function deserialize(mixed $stored): LinkCollection
    {
        if ($stored === null) {
            return new LinkCollection();
        }

        if (!is_array($stored) || !self::hasExactlyKeys($stored, ['version', 'links'])) {
            throw $this->exception('', Code::INVALID, 'A stored Smart Link value must be exactly a version and its links.');
        }

        if ($stored['version'] !== self::VERSION) {
            throw $this->exception('version', Code::UNSUPPORTED_VERSION, 'Stored Smart Link values in version {version} cannot be read.', ['version' => json_encode($stored['version']) ?: '?']);
        }

        if (!is_array($stored['links']) || !array_is_list($stored['links'])) {
            throw $this->exception('links', Code::WRONG_TYPE, 'The stored links must be a list.');
        }

        if ($stored['links'] === []) {
            throw $this->exception('links', Code::NOT_CANONICAL, 'An empty value is stored as nothing, not as an empty list.');
        }

        $links = [];
        $errors = [];

        foreach ($stored['links'] as $index => $link) {
            $value = $this->linkFromArray($link, "links[$index]", $errors);

            if ($value !== null) {
                $links[] = $value;
            }
        }

        if ($errors !== []) {
            throw new LinkValidationException($errors);
        }

        $collection = new LinkCollection($links);
        $errors = $this->validator->validateCollection($collection);

        if ($errors !== []) {
            throw new LinkValidationException($errors);
        }

        return $collection;
    }

    /**
     * @return array<string, mixed>
     */
    private function linkToArray(LinkValue $link): array
    {
        return array_filter([
            'uid' => $link->uid,
            'type' => $link->type,
            'data' => $link->data->toArray(),
            'label' => $link->label,
            'urlSuffix' => $link->urlSuffix,
            'attributes' => $this->attributesToArray($link->attributes),
            'presetUid' => $link->presetUid,
        ], static fn(mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesToArray(LinkAttributes $attributes): array
    {
        return array_filter([
            'target' => $attributes->target,
            'rel' => $attributes->rel,
            'title' => $attributes->title,
            'class' => $attributes->class,
            'id' => $attributes->id,
            'ariaLabel' => $attributes->ariaLabel,
            'download' => $attributes->download,
            'downloadFilename' => $attributes->downloadFilename,
            'custom' => array_map(static fn(string $name, string $value): array => ['name' => $name, 'value' => $value], array_keys($attributes->custom), array_values($attributes->custom)),
        ], static fn(mixed $value): bool => $value !== null && $value !== [] && $value !== false);
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function linkFromArray(mixed $link, string $path, array &$errors): ?LinkValue
    {
        if (!is_array($link) || $link === [] || array_is_list($link)) {
            $errors[] = new ValidationError($path, Code::WRONG_TYPE, 'A stored link must be an object.');

            return null;
        }

        $count = count($errors);
        $this->unknownKeys($link, self::LINK_KEYS, $path, $errors);

        $uid = $this->requiredString($link, 'uid', $path, $errors);
        $typeHandle = $this->requiredString($link, 'type', $path, $errors);
        $label = $this->optionalString($link, 'label', $path, $errors);
        $urlSuffix = $this->optionalString($link, 'urlSuffix', $path, $errors);
        $presetUid = $this->optionalString($link, 'presetUid', $path, $errors);
        $attributes = $this->attributesFromArray($link, ValidationError::join($path, 'attributes'), $errors);
        $data = $this->dataFromArray($link, $typeHandle, $path, $errors);

        if (count($errors) > $count || $uid === null || $typeHandle === null || $data === null) {
            return null;
        }

        return new LinkValue($uid, $typeHandle, $data, $label, $urlSuffix, $attributes, $presetUid);
    }

    /**
     * @param array<mixed> $link
     * @param list<ValidationError> $errors
     */
    private function dataFromArray(array $link, ?string $typeHandle, string $linkPath, array &$errors): ?LinkTypeDataInterface
    {
        if ($typeHandle === null) {
            return null;
        }

        $typeErrors = $this->validator->validateTypeHandle($typeHandle, ValidationError::join($linkPath, 'type'));

        if ($typeErrors !== []) {
            array_push($errors, ...$typeErrors);

            return null;
        }

        $type = $this->types->get($typeHandle) ?? throw new LogicException("The validated link type “{$typeHandle}” is not available.");
        $path = ValidationError::join($linkPath, 'data');
        $data = $link['data'] ?? [];

        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            $errors[] = new ValidationError($path, Code::WRONG_TYPE, 'Stored link data must be an object.');

            return null;
        }

        if (array_key_exists('data', $link) && $data === []) {
            $errors[] = new ValidationError($path, Code::NOT_CANONICAL, 'Empty data is stored by leaving it out.');

            return null;
        }

        try {
            return $type->dataFromArray($data);
        } catch (LinkValidationException $exception) {
            array_push($errors, ...$exception->within($path)->errors);

            return null;
        }
    }

    /**
     * @param array<mixed> $link
     * @param list<ValidationError> $errors
     */
    private function attributesFromArray(array $link, string $path, array &$errors): LinkAttributes
    {
        if (!array_key_exists('attributes', $link)) {
            return new LinkAttributes();
        }

        $input = $link['attributes'];

        if ($input === []) {
            $errors[] = new ValidationError($path, Code::NOT_CANONICAL, 'Empty attributes are stored by leaving them out.');

            return new LinkAttributes();
        }

        if (!is_array($input) || array_is_list($input)) {
            $errors[] = new ValidationError($path, Code::WRONG_TYPE, 'Stored link attributes must be an object.');

            return new LinkAttributes();
        }

        $this->unknownKeys($input, self::ATTRIBUTE_KEYS, $path, $errors);

        $download = false;

        if (array_key_exists('download', $input)) {
            if ($input['download'] === true) {
                $download = true;
            } else {
                $errors[] = new ValidationError(ValidationError::join($path, 'download'), Code::NOT_CANONICAL, 'Download is stored only as true; off is stored by leaving it out.');
            }
        }

        return new LinkAttributes(
            target: $this->optionalString($input, 'target', $path, $errors),
            rel: $this->stringList($input, 'rel', $path, $errors),
            title: $this->optionalString($input, 'title', $path, $errors),
            class: $this->stringList($input, 'class', $path, $errors),
            id: $this->optionalString($input, 'id', $path, $errors),
            ariaLabel: $this->optionalString($input, 'ariaLabel', $path, $errors),
            download: $download,
            downloadFilename: $this->optionalString($input, 'downloadFilename', $path, $errors),
            custom: $this->customRows($input, $path, $errors),
        );
    }

    /**
     * @param array<mixed> $input
     * @param list<ValidationError> $errors
     */
    private function requiredString(array $input, string $key, string $path, array &$errors): ?string
    {
        if (!array_key_exists($key, $input)) {
            $errors[] = new ValidationError(ValidationError::join($path, $key), Code::MISSING, 'A stored link needs its “{key}”.', ['key' => $key]);

            return null;
        }

        return $this->optionalString($input, $key, $path, $errors);
    }

    /**
     * @param array<mixed> $input
     * @param list<ValidationError> $errors
     */
    private function optionalString(array $input, string $key, string $path, array &$errors): ?string
    {
        if (!array_key_exists($key, $input)) {
            return null;
        }

        if (!is_string($input[$key])) {
            $errors[] = new ValidationError(ValidationError::join($path, $key), Code::WRONG_TYPE, '“{key}” must be text.', ['key' => $key]);

            return null;
        }

        return $input[$key];
    }

    /**
     * @param array<mixed> $input
     * @param list<ValidationError> $errors
     * @return list<string>
     */
    private function stringList(array $input, string $key, string $path, array &$errors): array
    {
        if (!array_key_exists($key, $input)) {
            return [];
        }

        $value = $input[$key];

        if ($value === []) {
            $errors[] = new ValidationError(ValidationError::join($path, $key), Code::NOT_CANONICAL, 'An empty “{key}” is stored by leaving it out.', ['key' => $key]);

            return [];
        }

        if (!is_array($value) || !array_is_list($value) || array_filter($value, 'is_string') !== $value) {
            $errors[] = new ValidationError(ValidationError::join($path, $key), Code::WRONG_TYPE, '“{key}” must be stored as a list of text.', ['key' => $key]);

            return [];
        }

        return $value;
    }

    /**
     * Custom attributes, stored as `{"name": …, "value": …}` rows so their order survives storage.
     *
     * @param array<mixed> $input
     * @param list<ValidationError> $errors
     * @return array<string, string>
     */
    private function customRows(array $input, string $path, array &$errors): array
    {
        if (!array_key_exists('custom', $input)) {
            return [];
        }

        $rows = $input['custom'];
        $path = ValidationError::join($path, 'custom');

        if ($rows === []) {
            $errors[] = new ValidationError($path, Code::NOT_CANONICAL, 'An empty “{key}” is stored by leaving it out.', ['key' => 'custom']);

            return [];
        }

        if (!is_array($rows) || !array_is_list($rows)) {
            $errors[] = new ValidationError($path, Code::WRONG_TYPE, 'Custom attributes must be stored as a list of names and values.');

            return [];
        }

        $custom = [];

        foreach ($rows as $index => $row) {
            $rowPath = "{$path}[$index]";

            if (!is_array($row) || !self::hasExactlyKeys($row, ['name', 'value']) || !is_string($row['name']) || !is_string($row['value'])) {
                $errors[] = new ValidationError($rowPath, Code::WRONG_TYPE, 'Custom attributes must be stored as a list of names and values.');
            } elseif (array_key_exists($row['name'], $custom)) {
                $errors[] = new ValidationError("$rowPath.name", Code::DUPLICATE, '“{value}” is listed more than once.', ['value' => $row['name']]);
            } else {
                $custom[$row['name']] = $row['value'];
            }
        }

        return $custom;
    }

    /**
     * Whether an object has exactly these keys, in any order.
     *
     * @param array<mixed> $input
     * @param list<string> $keys
     */
    private static function hasExactlyKeys(array $input, array $keys): bool
    {
        $actual = array_map('strval', array_keys($input));
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    /**
     * @param array<mixed> $input
     * @param list<string> $known
     * @param list<ValidationError> $errors
     */
    private function unknownKeys(array $input, array $known, string $path, array &$errors): void
    {
        foreach (array_keys($input) as $key) {
            if (!in_array($key, $known, true)) {
                $errors[] = new ValidationError(ValidationError::join($path, (string)$key), Code::UNKNOWN_KEY, '“{key}” is not a recognised stored property.', ['key' => (string)$key]);
            }
        }
    }

    /**
     * @param array<string, string|int> $params
     */
    private function exception(string $path, Code $code, string $message, array $params = []): LinkValidationException
    {
        return new LinkValidationException([new ValidationError($path, $code, $message, $params)]);
    }
}
