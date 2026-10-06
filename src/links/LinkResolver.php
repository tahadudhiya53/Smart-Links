<?php

namespace Tahadudhiya\SmartLinks\links;

use Tahadudhiya\SmartLinks\errors\LinkResolutionException;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\linktypes\LinkResolverInterface;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeSet;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ResolvedLink;
use Throwable;

/**
 * Resolves any link by handing it to its own type's resolver.
 *
 * The type is found by handle and asked for its resolver, so no code chooses a resolver by
 * looking at a type handle. A link value can be built without validation, so every link is
 * validated in full here first: a type's resolver only ever receives a valid link. A link that
 * cannot be resolved at all fails loudly; it is never reported as resolved, or as missing.
 */
final class LinkResolver implements LinkResolverInterface
{
    /** Built from the same types, so validation and resolution agree on which types exist. */
    private readonly LinkValidator $validator;

    public function __construct(
        private readonly LinkTypeSet $types,
    ) {
        $this->validator = new LinkValidator($types);
    }

    /**
     * @throws LinkResolutionException if the link's type is unavailable, the link is invalid
     * (its validation errors are the exception's previous exception), or its resolver fails.
     */
    public function resolve(LinkValue $link, int $siteId): ResolvedLink
    {
        $type = $this->types->get($link->type);

        if ($type === null) {
            throw new LinkResolutionException($link->uid, $link->type, 'its link type is not available.');
        }

        // The full link rules, which include the type's own check of its data.
        $errors = $this->validator->validateLink($link);

        if ($errors !== []) {
            $invalid = new LinkValidationException($errors);

            throw new LinkResolutionException($link->uid, $link->type, "the link is invalid: {$invalid->getMessage()}", $invalid);
        }

        try {
            return $type->resolver()->resolve($link, $siteId);
        } catch (Throwable $exception) {
            throw new LinkResolutionException($link->uid, $link->type, $exception->getMessage(), $exception);
        }
    }
}
