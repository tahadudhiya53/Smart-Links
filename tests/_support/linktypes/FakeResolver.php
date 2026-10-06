<?php

namespace Tahadudhiya\SmartLinks\Tests\_support\linktypes;

use Closure;
use Tahadudhiya\SmartLinks\linktypes\LinkResolverInterface;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ResolvedLink;

/**
 * A resolver that answers as it is told, and remembers what it was asked.
 */
final class FakeResolver implements LinkResolverInterface
{
    /** @var list<array{LinkValue, int}> Every link and site it was asked to resolve, in order. */
    public array $calls = [];

    /**
     * @param Closure(LinkValue, int): ResolvedLink $answer
     */
    public function __construct(
        private readonly Closure $answer,
    ) {
    }

    public function resolve(LinkValue $link, int $siteId): ResolvedLink
    {
        $this->calls[] = [$link, $siteId];

        return ($this->answer)($link, $siteId);
    }
}
