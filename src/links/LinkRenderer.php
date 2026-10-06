<?php

namespace Tahadudhiya\SmartLinks\links;

use Tahadudhiya\SmartLinks\enums\ResolutionStatus;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\RenderedLink;
use Tahadudhiya\SmartLinks\models\ResolvedLink;

/**
 * Composes what a link renders as from the link and where it resolved to.
 *
 * It produces the final text and attributes, not markup: writing them into HTML, and escaping
 * them, happens where markup is written. Attribute values are taken as authored, apart from one
 * safety rule: a link opening in a new window always has `rel="noopener"`, so the opened page
 * cannot reach back into this one.
 *
 * Only a valid link is rendered, so what reaches markup is only ever what the link rules allow,
 * however the link was built. Custom attributes cannot replace a core one: the validator limits
 * them to `data-*` and `aria-*` (not `aria-label`), and core attributes are composed first.
 */
final class LinkRenderer
{
    public function __construct(
        private readonly LinkValidator $validator,
    ) {
    }

    /**
     * @return RenderedLink|null Null when the link did not resolve: a link that leads nowhere is
     * not rendered as one.
     * @throws LinkValidationException if the link is invalid.
     */
    public function render(LinkValue $link, ResolvedLink $resolved): ?RenderedLink
    {
        $errors = $this->validator->validateLink($link);

        if ($errors !== []) {
            throw new LinkValidationException($errors);
        }

        if ($resolved->status !== ResolutionStatus::RESOLVED || $resolved->url === null) {
            return null;
        }

        $attributes = $link->attributes;
        $rel = $attributes->rel;

        if ($attributes->target === '_blank' && !in_array('noopener', $rel, true)) {
            $rel[] = 'noopener';
        }

        $rendered = array_filter([
            'target' => $attributes->target,
            'rel' => $rel !== [] ? implode(' ', $rel) : null,
            'title' => $attributes->title,
            'class' => $attributes->class !== [] ? implode(' ', $attributes->class) : null,
            'id' => $attributes->id,
            'aria-label' => $attributes->ariaLabel,
            'download' => $attributes->download ? ($attributes->downloadFilename ?? true) : null,
        ], static fn(string|bool|null $value): bool => $value !== null);

        return new RenderedLink(
            href: $resolved->url,
            text: $link->label ?? $resolved->defaultLabel ?? $resolved->url,
            attributes: $rendered + $attributes->custom,
            external: $resolved->external,
        );
    }
}
