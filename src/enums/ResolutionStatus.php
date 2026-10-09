<?php

namespace Tahadudhiya\SmartLinks\enums;

use Craft;

/**
 * Whether a link could be turned into a destination, and if not, why.
 */
enum ResolutionStatus: string
{
    /** The link leads somewhere. */
    case RESOLVED = 'resolved';

    /**
     * What the link points at does not exist where it links: it was deleted, or it is not in
     * the site the link points into.
     */
    case MISSING = 'missing';

    /** What the link points at exists but is not live, e.g. a disabled entry. */
    case DISABLED = 'disabled';

    /** What the link points at exists and is live, but has no URL. */
    case NO_URL = 'noUrl';

    /**
     * What the status says about a link's target.
     */
    public function label(): string
    {
        return match ($this) {
            self::RESOLVED => Craft::t('smart-links', 'Leads somewhere'),
            self::MISSING => Craft::t('smart-links', 'Doesn’t exist'),
            self::DISABLED => Craft::t('smart-links', 'Disabled'),
            self::NO_URL => Craft::t('smart-links', 'No URL'),
        };
    }
}
