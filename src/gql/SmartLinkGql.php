<?php

namespace Tahadudhiya\SmartLinks\gql;

use Craft;
use craft\base\ElementInterface;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element as ElementInterfaceType;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;
use Tahadudhiya\SmartLinks\linktypes\ElementLinkTypeInterface;
use Tahadudhiya\SmartLinks\linktypes\GqlLinkTypeInterface;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeDataInterface;
use Tahadudhiya\SmartLinks\models\InvalidLinkValue;
use Tahadudhiya\SmartLinks\models\LinkCollection;
use Tahadudhiya\SmartLinks\SmartLinks;

/**
 * The Smart Links field in GraphQL.
 *
 * A field reads as a list of `SmartLink` objects (one, or null, for a single-link field):
 * the link as authored, and what it renders as in the site of the element it is read from. A
 * link's own data is a member of the `SmartLinkData` union, one object type per link type. A
 * link to an element also has `element`, read through that element type's own GraphQL resolver,
 * so it is there only when the schema may read that element and it is live.
 *
 * Mutations take a list of `SmartLinkInput` objects, the field's whole value, as authoring input:
 * it is checked by exactly the rules the control panel's editor is, and refused if invalid.
 */
final class SmartLinkGql
{
    public const LINK = 'SmartLink';
    public const ATTRIBUTE = 'SmartLinkAttribute';
    public const DATA = 'SmartLinkData';
    public const INPUT = 'SmartLinkInput';
    public const ATTRIBUTE_INPUT = 'SmartLinkAttributeInput';

    /**
     * The field's query type, read from the element it belongs to.
     *
     * @return array<string, mixed>
     */
    public static function contentType(SmartLinkField $field): array
    {
        $link = self::linkType();

        return [
            'name' => $field->handle,
            'type' => $field->multiple ? Type::listOf(Type::nonNull($link)) : $link,
            'description' => $field->instructions,
            'resolve' => static function(ElementInterface $source, array $arguments, mixed $context, ResolveInfo $info) use ($field): GqlLink|array|null {
                $links = self::links($source, $info->fieldName);

                return $field->multiple ? $links : ($links[0] ?? null);
            },
        ];
    }

    /**
     * The field's mutation argument: the whole value, as a list of links.
     *
     * @return array<string, mixed>
     */
    public static function mutationArgument(SmartLinkField $field): array
    {
        return [
            'name' => $field->handle,
            'type' => Type::listOf(Type::nonNull(self::inputType())),
            'description' => $field->instructions,
            'normalizeValue' => [self::class, 'toFormPost'],
        ];
    }

    /**
     * Turns a mutation's links into what the field's editor posts, so a mutation is read by the
     * field exactly as the control panel is. Null clears the field. Data is the link type's
     * authoring input as a JSON object; anything else is passed on, and refused by the link rules.
     *
     * @return array<string, mixed>|null
     */
    public static function toFormPost(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $links = [];

        foreach (is_array($value) ? $value : [$value] as $input) {
            $input = (array)$input;
            $type = $input['type'] ?? null;
            $link = array_filter([
                'uid' => $input['uid'] ?? null,
                'type' => $type,
                'label' => $input['label'] ?? null,
                'urlSuffix' => $input['urlSuffix'] ?? null,
                'presetUid' => $input['presetUid'] ?? null,
            ], static fn(mixed $part): bool => $part !== null);

            if (array_key_exists('data', $input) && $input['data'] !== null && is_string($type)) {
                $data = $input['data'];

                try {
                    $decoded = is_string($data) ? json_decode($data, true, 32, JSON_THROW_ON_ERROR) : $data;
                } catch (\JsonException) {
                    // Passed on as it is, so the link rules report it.
                    $decoded = $data;
                }

                $link['data'] = [$type => $decoded];
            }

            $attributes = array_filter([
                'target' => $input['target'] ?? null,
                'rel' => $input['rel'] ?? null,
                'title' => $input['title'] ?? null,
                'class' => $input['class'] ?? null,
                'id' => $input['id'] ?? null,
                'ariaLabel' => $input['ariaLabel'] ?? null,
                'download' => $input['download'] ?? null,
                'downloadFilename' => $input['downloadFilename'] ?? null,
                'custom' => isset($input['customAttributes']) ? array_map(static fn(mixed $row): array => (array)$row, (array)$input['customAttributes']) : null,
            ], static fn(mixed $part): bool => $part !== null);

            if ($attributes !== []) {
                $link['attributes'] = $attributes;
            }

            $links[] = $link;
        }

        return ['links' => $links];
    }

    public static function linkType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::LINK, fn(): ObjectType => new ObjectType([
            'name' => self::LINK,
            'description' => 'A link in a Smart Links field.',
            'fields' => fn(): array => Craft::$app->getGql()->prepareFieldDefinitions(self::linkFields(), self::LINK),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private static function linkFields(): array
    {
        $string = static fn(callable $resolve, ?string $description = null): array => ['type' => Type::string(), 'resolve' => $resolve, 'description' => $description];
        $attributes = static fn(GqlLink $link): \Tahadudhiya\SmartLinks\models\LinkAttributes => $link->link->attributes;

        $fields = [
            'uid' => ['type' => Type::nonNull(Type::string()), 'resolve' => static fn(GqlLink $link): string => $link->link->uid, 'description' => 'The link’s identity within the field value.'],
            'type' => ['type' => Type::nonNull(Type::string()), 'resolve' => static fn(GqlLink $link): string => $link->link->type, 'description' => 'The link type’s handle.'],
            'label' => $string(static fn(GqlLink $link): ?string => $link->link->label, 'The label the author gave.'),
            'urlSuffix' => $string(static fn(GqlLink $link): ?string => $link->link->urlSuffix),
            'presetUid' => $string(static fn(GqlLink $link): ?string => $link->link->presetUid),
            'target' => $string(static fn(GqlLink $link): ?string => $attributes($link)->target),
            'rel' => $string(static fn(GqlLink $link): ?string => $attributes($link)->rel !== [] ? implode(' ', $attributes($link)->rel) : null, 'As authored; `url`’s rendered link also has `noopener` for a new window.'),
            'title' => $string(static fn(GqlLink $link): ?string => $attributes($link)->title),
            'class' => $string(static fn(GqlLink $link): ?string => $attributes($link)->class !== [] ? implode(' ', $attributes($link)->class) : null),
            'id' => $string(static fn(GqlLink $link): ?string => $attributes($link)->id, 'The HTML `id` attribute.'),
            'ariaLabel' => $string(static fn(GqlLink $link): ?string => $attributes($link)->ariaLabel),
            'download' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => static fn(GqlLink $link): bool => $attributes($link)->download],
            'downloadFilename' => $string(static fn(GqlLink $link): ?string => $attributes($link)->downloadFilename),
            'customAttributes' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(self::attributeType()))),
                'resolve' => static fn(GqlLink $link): array => array_map(
                    static fn(string $name, string $value): array => ['name' => $name, 'value' => $value],
                    array_keys($attributes($link)->custom),
                    array_values($attributes($link)->custom),
                ),
            ],
            'status' => [
                'type' => Type::nonNull(Type::string()),
                'resolve' => static fn(GqlLink $link): string => $link->resolved()->status->value,
                'description' => 'Where the link leads: `resolved`, or for a target that leads nowhere, `missing`, `disabled` or `noUrl`.',
            ],
            'url' => $string(static fn(GqlLink $link): ?string => $link->rendered()?->href, 'The href, in the site the element is read in. Null when the link leads nowhere.'),
            'text' => $string(static fn(GqlLink $link): ?string => $link->rendered()?->text, 'The text the link renders with. Null when the link leads nowhere.'),
            'external' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => static fn(GqlLink $link): bool => $link->resolved()->external],
            'html' => $string(
                static fn(GqlLink $link): ?string => ($html = SmartLinks::getInstance()->getLinks()->htmlFor($link->link, $link->resolved())) !== null ? (string)$html : null,
                'The `<a>` tag, every value encoded. Null when the link leads nowhere.',
            ),
        ];

        $data = self::dataType();

        if ($data !== null) {
            $fields['data'] = [
                'type' => $data,
                'resolve' => static fn(GqlLink $link): ?GqlLink => $link->type() instanceof GqlLinkTypeInterface ? $link : null,
                'description' => 'The link type’s own data.',
            ];
        }

        if (self::hasElementTypes()) {
            $fields['element'] = [
                'type' => ElementInterfaceType::getType(),
                'resolve' => static fn(GqlLink $link): ?ElementInterface => $link->element(),
                'description' => 'The linked element, when the schema may read it and it is live in the site the link leads to.',
            ];
        }

        return $fields;
    }

    /**
     * @return list<GqlLink>
     * @throws UserError if the stored value cannot be read: it is reported, not shown as no links.
     */
    private static function links(ElementInterface $source, string $handle): array
    {
        $value = $source->getFieldValue($handle);

        if ($value instanceof InvalidLinkValue) {
            throw new UserError($value->stored
                ? sprintf('The value of the “%s” field can’t be read.', $handle)
                : sprintf('The value of the “%s” field is not finished yet.', $handle));
        }

        if (!$value instanceof LinkCollection) {
            return [];
        }

        return array_map(static fn($link): GqlLink => new GqlLink($link, (int)$source->siteId), $value->links);
    }

    private static function attributeType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::ATTRIBUTE, fn(): ObjectType => new ObjectType([
            'name' => self::ATTRIBUTE,
            'description' => 'A custom `data-*` or `aria-*` attribute of a Smart Link.',
            'fields' => [
                'name' => Type::nonNull(Type::string()),
                'value' => Type::nonNull(Type::string()),
            ],
        ]));
    }

    /**
     * The union of every registered link type's data, or null when no type has GraphQL data.
     */
    private static function dataType(): ?UnionType
    {
        $types = [];

        foreach (SmartLinks::getInstance()->getLinkTypes()->getTypeSet()->handles() as $handle) {
            $type = SmartLinks::getInstance()->getLinkTypes()->getType($handle);

            if ($type instanceof GqlLinkTypeInterface) {
                $types[$handle] = self::dataObjectType($type);
            }
        }

        if ($types === []) {
            return null;
        }

        return GqlEntityRegistry::getOrCreate(self::DATA, fn(): UnionType => new UnionType([
            'name' => self::DATA,
            'description' => 'A Smart Link’s own data, by link type.',
            'types' => array_values($types),
            'resolveType' => static fn(GqlLink $link): ObjectType => $types[$link->link->type],
        ]));
    }

    private static function dataObjectType(GqlLinkTypeInterface $type): ObjectType
    {
        $name = self::DATA . '_' . str_replace('-', '_', $type->handle());

        return GqlEntityRegistry::getOrCreate($name, fn(): ObjectType => new ObjectType([
            'name' => $name,
            'description' => sprintf('The data of a %s link.', $type->displayName()),
            'fields' => function() use ($type): array {
                $fields = [];

                foreach ($type->gqlDataFields() as $fieldName => $definition) {
                    $definition = $definition instanceof Type ? ['type' => $definition] : $definition;
                    $read = $definition['resolve'] ?? static fn(LinkTypeDataInterface $data): mixed => $data->$fieldName;
                    $definition['resolve'] = static fn(GqlLink $link): mixed => $read($link->link->data);
                    $fields[$fieldName] = $definition;
                }

                return $fields;
            },
        ]));
    }

    private static function hasElementTypes(): bool
    {
        $types = SmartLinks::getInstance()->getLinkTypes()->getTypeSet();

        foreach ($types->handles() as $handle) {
            $type = $types->get($handle);

            if ($type instanceof ElementLinkTypeInterface && $type->gqlElementResolver() !== null) {
                return true;
            }
        }

        return false;
    }

    private static function inputType(): InputObjectType
    {
        $attribute = GqlEntityRegistry::getOrCreate(self::ATTRIBUTE_INPUT, fn(): InputObjectType => new InputObjectType([
            'name' => self::ATTRIBUTE_INPUT,
            'fields' => [
                'name' => Type::nonNull(Type::string()),
                'value' => Type::nonNull(Type::string()),
            ],
        ]));

        return GqlEntityRegistry::getOrCreate(self::INPUT, fn(): InputObjectType => new InputObjectType([
            'name' => self::INPUT,
            'description' => 'A link in a Smart Links field, as authoring input.',
            // Craft applies an input type's normalizer to the whole argument, here the field's list
            // of links, when it reads a mutation.
            'normalizeValue' => [self::class, 'toFormPost'],
            'fields' => [
                'uid' => ['type' => Type::string(), 'description' => 'An existing link’s identity, to keep it; leave it out for a new link.'],
                'type' => Type::nonNull(Type::string()),
                'data' => ['type' => Type::string(), 'description' => 'The link type’s data as a JSON object, e.g. `{"url": "https://example.com/"}` or `{"elementId": 12}`.'],
                'label' => Type::string(),
                'urlSuffix' => Type::string(),
                'presetUid' => Type::string(),
                'target' => Type::string(),
                'rel' => Type::string(),
                'title' => Type::string(),
                'class' => Type::string(),
                'id' => Type::string(),
                'ariaLabel' => Type::string(),
                'download' => Type::boolean(),
                'downloadFilename' => Type::string(),
                'customAttributes' => Type::listOf(Type::nonNull($attribute)),
            ],
        ]));
    }
}
