<?php

namespace Tahadudhiya\SmartLinks\errors;

use Throwable;
use yii\base\Exception;

/**
 * A link could not be resolved at all: its type is not available, the link is invalid, or its
 * resolver failed.
 *
 * Distinct from a link that resolved to nowhere (missing, disabled, no URL), which is a normal
 * result. This is never turned into one, so a failure cannot look like a working or a missing
 * link.
 */
class LinkResolutionException extends Exception
{
    public function __construct(
        public readonly string $linkUid,
        public readonly string $linkType,
        string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct("The {$linkType} link {$linkUid} could not be resolved: {$reason}", 0, $previous);
    }

    public function getName(): string
    {
        return 'Link resolution failed';
    }
}
