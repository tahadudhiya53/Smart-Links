<?php

namespace Tahadudhiya\SmartLinks\enums;

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
}
