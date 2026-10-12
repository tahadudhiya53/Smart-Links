<?php

namespace Tahadudhiya\SmartLinks\fields;

use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeDataInterface;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeSet;
use Tahadudhiya\SmartLinks\models\InvalidLinkValue;
use Tahadudhiya\SmartLinks\models\LinkCollection;
use Tahadudhiya\SmartLinks\models\LinkPreset;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ValidationError;

/**
 * Translates between the Smart Link field's form and link authoring input.
 *
 * It only undoes what the form does to fit links into inputs: every offered link type's inputs
 * are on the page, so only the chosen type's data is the link's; a blank custom attribute row is
 * no attribute; and a link that cannot be edited here is posted back exactly as it was.
 * Everything else is passed on unchanged, so the link normalizer judges it and reports what is
 * unexpected.
 *
 * Showing a refused value again never loses part of it: a link the form cannot show in full (one
 * with properties nothing recognises, or values of the wrong kind) is kept whole, as it was,
 * until the author removes it.
 */
final class LinkForm
{
    /** Problems that mean a link has content the form has no input for. */
    private const UNREPRESENTABLE = [Code::UNKNOWN_KEY, Code::WRONG_TYPE];

    /**
     * Turns what the field's form posted into authoring input.
     *
     * @return array{mixed, list<ValidationError>, bool} The authoring input; problems only the
     * form can have; and whether the form posted back a whole value it kept as it was stored
     * (its `stored` input) rather than links. Only that input says so, never the value's shape.
     */
    public static function fromPost(mixed $post, LinkTypeSet $types): array
    {
        // A hidden input posts an empty string, so a value whose last link was removed is posted.
        if ($post === '' || $post === null || !is_array($post)) {
            return [$post, [], false];
        }

        $errors = [];

        foreach (array_keys($post) as $key) {
            if ($key !== 'links' && $key !== 'stored') {
                $errors[] = new ValidationError((string)$key, Code::UNKNOWN_KEY, '“{key}” is not a recognised link property.', ['key' => (string)$key]);
            }
        }

        if (array_key_exists('stored', $post)) {
            // A value that could not be read, and was left as it was: any JSON value at all.
            $readable = false;
            $stored = self::decodeAny($post['stored'], $readable);

            if (!$readable) {
                $errors[] = new ValidationError('', Code::INVALID, 'This value could not be read back.');
            }

            // Links added beside it would otherwise be lost to it, or it to them.
            if (($post['links'] ?? []) !== []) {
                $errors[] = new ValidationError('links', Code::INVALID, 'Clear the value that can’t be read before adding links.');
            }

            return [$stored, $errors, true];
        }

        $links = $post['links'] ?? [];

        if (!is_array($links)) {
            return [$links, $errors, false];
        }

        $input = [];

        foreach (array_values($links) as $index => $link) {
            $input[] = self::linkFromPost($link, "links[$index]", $types, $errors);
        }

        return [$input, $errors, false];
    }

    /**
     * What the form shows for each link of a value.
     *
     * @return array{links: list<array<string, mixed>>, errors: list<string>, stored: string|null}
     * Each link's view, messages about the value as a whole, and a value that could not be read
     * at all, to be posted back as it was.
     */
    public static function view(LinkCollection|InvalidLinkValue $value, LinkTypeSet $types): array
    {
        if ($value instanceof LinkCollection) {
            return [
                'links' => array_map(static fn(LinkValue $link, int $index): array => self::linkView($link, "link$index"), $value->links, array_keys($value->links)),
                'errors' => [],
                'stored' => null,
            ];
        }

        $links = self::inputLinks($value);
        [$byLink, $general] = self::groupErrors($value->errors);

        // A problem with the value as a whole (an unknown format version, a broken envelope)
        // means its links cannot be read link by link, so the value is kept whole, as it was.
        if ($links === null || $general !== []) {
            return ['links' => [], 'errors' => array_merge($general, ...array_values($byLink)), 'stored' => self::encode($value->input)];
        }

        $unrepresentable = [];

        foreach ($value->errors as $error) {
            if (in_array($error->code, self::UNREPRESENTABLE, true) && preg_match('/^links\[(\d+)\]/', $error->path, $match)) {
                $unrepresentable[(int)$match[1]] = true;
            }
        }

        $views = [];

        foreach ($links as $index => $link) {
            $type = is_array($link) ? ($link['type'] ?? null) : null;
            $editable = is_array($link) && !isset($unrepresentable[$index]) && is_string($type) && $types->get($type) !== null;
            $view = $editable ? self::inputView($link, $index) : self::preserved($link, $index);
            $view['errors'] = $byLink[$index] ?? [];
            $views[] = $view;
        }

        return ['links' => $views, 'errors' => $general, 'stored' => null];
    }

    /**
     * One valid link as the form shows it, under the given form key.
     *
     * @return array<string, mixed>
     */
    public static function linkView(LinkValue $link, string $key): array
    {
        $attributes = $link->attributes;

        return [
            'key' => $key,
            'uid' => $link->uid,
            'type' => $link->type,
            'data' => $link->data,
            'label' => $link->label,
            'urlSuffix' => $link->urlSuffix,
            'presetUid' => $link->presetUid,
            'target' => $attributes->target,
            'rel' => implode(' ', $attributes->rel),
            'title' => $attributes->title,
            'class' => implode(' ', $attributes->class),
            'id' => $attributes->id,
            'ariaLabel' => $attributes->ariaLabel,
            'download' => $attributes->download,
            'downloadFilename' => $attributes->downloadFilename,
            'custom' => array_map(static fn(string $name, string $value): array => ['name' => $name, 'value' => $value], array_keys($attributes->custom), array_values($attributes->custom)),
            'stored' => null,
            'errors' => [],
        ];
    }

    /**
     * The inputs a preset's settings are in, by feature, as name suffixes of a link's inputs.
     */
    private const PRESET_INPUTS = [
        'urlSuffix' => '[urlSuffix]',
        'target' => '[attributes][target]',
        'rel' => '[attributes][rel]',
        'class' => '[attributes][class]',
        'download' => '[attributes][download]',
    ];

    /**
     * A preset as the editor applies it to a link's inputs: the value each of its settings puts
     * in its input, by input name suffix, its custom attribute rows, and the value each locked
     * input must keep (empty when the preset locks a setting it does not set). The editor knows
     * nothing about presets beyond this.
     *
     * @return array{name: string, types: list<string>, values: array<string, string>, custom: list<array{name: string, value: string}>, locked: array<string, string>}
     */
    public static function presetView(LinkPreset $preset): array
    {
        $attributes = $preset->linkAttributes;
        $values = [
            'urlSuffix' => $preset->urlSuffix ?? '',
            'target' => $attributes->target ?? '',
            'rel' => implode(' ', $attributes->rel),
            'class' => implode(' ', $attributes->class),
            'download' => $attributes->download ? '1' : '',
        ];

        $inputs = [];
        $locked = [];

        foreach (self::PRESET_INPUTS as $feature => $suffix) {
            if ($values[$feature] !== '') {
                $inputs[$suffix] = $values[$feature];
            }

            if (in_array($feature, $preset->locked, true)) {
                $locked[$suffix] = $values[$feature];
            }
        }

        return [
            'name' => $preset->name,
            'types' => $preset->types,
            'values' => $inputs,
            'custom' => array_map(static fn(string $name, string $value): array => ['name' => $name, 'value' => $value], array_keys($attributes->custom), array_values($attributes->custom)),
            'locked' => $locked,
        ];
    }

    /**
     * Groups errors by the link they are in.
     *
     * @param list<ValidationError> $errors
     * @return array{array<int, list<string>>, list<string>} Messages by link index, and messages
     * about the value as a whole.
     */
    public static function groupErrors(array $errors): array
    {
        $byLink = [];
        $general = [];

        foreach ($errors as $error) {
            if (preg_match('/^links\[(\d+)\]/', $error->path, $match)) {
                $byLink[(int)$match[1]][] = $error->getMessage();
            } else {
                $general[] = $error->getMessage();
            }
        }

        return [$byLink, $general];
    }

    /**
     * @param list<ValidationError> $errors
     */
    private static function linkFromPost(mixed $link, string $path, LinkTypeSet $types, array &$errors): mixed
    {
        if (!is_array($link)) {
            return $link;
        }

        if (array_key_exists('stored', $link)) {
            $stored = count($link) === 1 ? self::decode($link['stored']) : null;

            if ($stored === null) {
                $errors[] = new ValidationError($path, Code::INVALID, 'This link could not be read back.');
            }

            return $stored ?? [];
        }

        // The form posts every offered type's inputs, each under its handle; the link's data is
        // the chosen type's. A key that is no link type's is not the form's, so it is reported.
        if (array_key_exists('data', $link) && is_array($link['data'])) {
            foreach (array_keys($link['data']) as $handle) {
                if ($types->get((string)$handle) === null) {
                    $errors[] = new ValidationError("$path.data.$handle", Code::UNKNOWN_KEY, '“{key}” is not a recognised link property.', ['key' => (string)$handle]);
                }
            }

            $type = $link['type'] ?? null;
            $data = is_string($type) ? ($link['data'][$type] ?? null) : null;

            if ($data === null) {
                unset($link['data']);
            } else {
                $link['data'] = $data;
            }
        }

        $custom = $link['attributes']['custom'] ?? null;

        // The form posts custom attributes as rows, keyed by row; only rows are the form's to
        // reshape. Anything else, like a map of names to values, is passed on as it is.
        if (is_array($custom) && $custom !== [] && is_array($link['attributes']) && array_filter($custom, 'is_array') === $custom) {
            $rows = array_values(array_filter($custom, static fn(mixed $row): bool => !self::isBlankRow($row)));

            if ($rows === []) {
                unset($link['attributes']['custom']);
            } else {
                $link['attributes']['custom'] = $rows;
            }
        }

        return $link;
    }

    private static function isBlankRow(mixed $row): bool
    {
        return is_array($row) && ($row['name'] ?? '') === '' && ($row['value'] ?? '') === '' && array_diff(array_keys($row), ['name', 'value']) === [];
    }

    /**
     * A refused link as the author entered it, to be edited again. Only called for a link with no
     * content the form lacks an input for, so showing it again loses nothing.
     *
     * @param array<mixed> $link
     * @return array<string, mixed>
     */
    private static function inputView(array $link, int $index): array
    {
        $attributes = is_array($link['attributes'] ?? null) ? $link['attributes'] : [];
        $data = $link['data'] ?? null;

        return [
            'key' => "link$index",
            'uid' => self::text($link['uid'] ?? null),
            'type' => $link['type'],
            'data' => is_array($data) || $data instanceof LinkTypeDataInterface ? $data : null,
            'label' => self::text($link['label'] ?? null),
            'urlSuffix' => self::text($link['urlSuffix'] ?? null),
            'presetUid' => self::text($link['presetUid'] ?? null),
            'target' => self::text($attributes['target'] ?? null),
            'rel' => self::tokens($attributes['rel'] ?? null),
            'title' => self::text($attributes['title'] ?? null),
            'class' => self::tokens($attributes['class'] ?? null),
            'id' => self::text($attributes['id'] ?? null),
            'ariaLabel' => self::text($attributes['ariaLabel'] ?? null),
            'download' => in_array($attributes['download'] ?? null, [true, 1, '1'], true),
            'downloadFilename' => self::text($attributes['downloadFilename'] ?? null),
            'custom' => self::customRows($attributes['custom'] ?? null),
            'stored' => null,
            'errors' => [],
        ];
    }

    /**
     * A link kept exactly as it was, because it cannot be edited here.
     *
     * @return array<string, mixed>
     */
    private static function preserved(mixed $link, int $index): array
    {
        $type = is_array($link) && is_string($link['type'] ?? null) ? $link['type'] : null;

        return [
            'key' => "link$index",
            'type' => $type,
            'label' => is_array($link) ? self::text($link['label'] ?? null) : null,
            'stored' => self::encode($link),
            'json' => json_encode($link, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            'errors' => [],
        ];
    }

    /**
     * The links of an invalid value, or null when it is not a list of links at all.
     *
     * @return list<mixed>|null
     */
    private static function inputLinks(InvalidLinkValue $value): ?array
    {
        $input = $value->input;

        // Stored content is `{"version": …, "links": […]}`, so its links are read link by link only
        // when it is that object; any other stored value is kept whole. Authoring input is the
        // list of links itself.
        if ($value->stored) {
            $input = is_array($input) && !array_is_list($input) && is_array($input['links'] ?? null) ? $input['links'] : null;
        }

        return is_array($input) && array_is_list($input) ? $input : null;
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private static function customRows(mixed $custom): array
    {
        if (!is_array($custom)) {
            return [];
        }

        $rows = [];

        foreach ($custom as $name => $value) {
            $rows[] = is_array($value)
                ? ['name' => self::text($value['name'] ?? null) ?? '', 'value' => self::text($value['value'] ?? null) ?? '']
                : ['name' => (string)$name, 'value' => self::text($value) ?? ''];
        }

        return $rows;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function tokens(mixed $value): string
    {
        if (is_array($value)) {
            return implode(' ', array_filter($value, 'is_string'));
        }

        return is_string($value) ? $value : '';
    }

    /**
     * A kept value as the editor posts it back.
     *
     * @throws \JsonException if the value cannot be written as JSON at all (e.g. it holds NAN):
     * it could not be posted back as it is, and any stand-in, such as `null`, would read back
     * as another value, or as no links.
     */
    private static function encode(mixed $value): string
    {
        // Invalid UTF-8 cannot be posted back unchanged; it is refused again when it returns.
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /**
     * Any JSON value, as a kept value can be. `$readable` says whether it was JSON at all.
     */
    private static function decodeAny(mixed $json, bool &$readable): mixed
    {
        $readable = false;

        if (!is_string($json)) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $readable = true;

        return $decoded;
    }

    /**
     * @return array<mixed>|null
     */
    public static function decode(mixed $json): ?array
    {
        if (!is_string($json)) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
