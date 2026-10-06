<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use GraphQL\Type\Definition\Type;

/**
 * A link type whose data GraphQL can show.
 *
 * Each such type has its own GraphQL object type, `SmartLinkData_<handle>`, a member of the
 * `SmartLinkData` union that a Smart Link's `data` field returns. A link of a type that does not
 * implement this interface still has every other Smart Link field in GraphQL; its `data` is null.
 */
interface GqlLinkTypeInterface extends LinkTypeInterface
{
    /**
     * The fields of this type's GraphQL data object, by name.
     *
     * A field is a GraphQL type, read from the data object's property of the same name, or an
     * array with a `type` and a `resolve` callable that receives the data object.
     *
     * @return array<string, Type|array{type: Type, resolve?: callable(LinkTypeDataInterface): mixed, description?: string}>
     */
    public function gqlDataFields(): array;
}
