<?php

namespace Tahadudhiya\SmartLinks\models;

use InvalidArgumentException;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;

/**
 * Where a link leads now, as its resolver worked it out: the resolved stage between the stored
 * link and what is rendered.
 *
 * The URL is trusted resolver output, not parsed here: a resolver builds it from data it owns,
 * and it can be anything an href can be (an absolute or root-relative URL, `mailto:`, `tel:`…).
 * Only what no href can be is refused: whitespace (every Unicode White_Space character, e.g.
 * space, tab, line breaks, U+00A0, U+2028, U+3000) and control characters (C0, DEL and C1).
 * Other non-ASCII characters are accepted. Escaping it is the job of whatever writes markup.
 */
final class ResolvedLink
{
    /**
     * @param string|null $url The href, including any URL suffix. Present exactly when the link
     * resolved.
     * @param bool $external Whether the destination is outside the site it was resolved in. Only a
     * resolved link has a destination, so only a resolved link can be external.
     * @param string|null $defaultLabel What to show when the author gave no label, e.g. an
     * entry's title. It may be known even when the link did not resolve. Text, so spaces are
     * allowed, but control characters (C0, DEL and C1, which include tab and line breaks) are not.
     * @throws InvalidArgumentException for a state no resolution can be in.
     */
    public function __construct(
        public readonly ResolutionStatus $status,
        public readonly ?string $url = null,
        public readonly bool $external = false,
        public readonly ?string $defaultLabel = null,
    ) {
        $resolved = $status === ResolutionStatus::RESOLVED;

        if ($resolved !== ($url !== null)) {
            throw new InvalidArgumentException('A resolved link has a URL, and only a resolved link does.');
        }

        // Whitespace and control characters cannot be part of an href, so a URL containing them
        // is a resolver bug, not a destination. `\p{Z}` and `\p{Cc}` name the Unicode classes
        // outright, so the rule does not depend on how `\s` is compiled.
        if ($url !== null && ($url === '' || !mb_check_encoding($url, 'UTF-8') || preg_match('/[\s\p{Z}\p{Cc}]/u', $url))) {
            throw new InvalidArgumentException('A resolved URL must be non-empty UTF-8 without whitespace or control characters.');
        }

        if ($external && !$resolved) {
            throw new InvalidArgumentException('A link that did not resolve has no destination to be external.');
        }

        if ($defaultLabel !== null && ($defaultLabel === '' || !mb_check_encoding($defaultLabel, 'UTF-8') || preg_match('/\p{Cc}/u', $defaultLabel))) {
            throw new InvalidArgumentException('A default label is either non-empty text without control characters, or absent.');
        }
    }
}
