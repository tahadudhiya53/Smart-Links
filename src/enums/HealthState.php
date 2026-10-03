<?php

namespace Tahadudhiya\SmartLinks\enums;

use Craft;

/**
 * What a health check concluded about a link target.
 *
 * Which state a response earns is decided by the health checker's classification policy. These
 * are the meanings that policy assigns. A state never claims more than the check showed: a
 * target that could not be reached, or that refused to answer, has not been shown to be broken,
 * and a redirect is a working answer rather than a failure.
 */
enum HealthState: string
{
    /** The target's direct answer was judged a success. */
    case HEALTHY = 'healthy';

    /** The target was reached through redirects, and the answer they led to was judged working. */
    case REDIRECT = 'redirect';

    /** The target's answer was judged to say that what was requested does not exist. */
    case BROKEN = 'broken';

    /** The target could not be reached, or was judged to have failed on its side. */
    case UNAVAILABLE = 'unavailable';

    /** The target answered, but was judged to be refusing the checker what it shows a visitor. */
    case BLOCKED = 'blocked';

    /** The target has not been checked, or the check was inconclusive. */
    case UNKNOWN = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::HEALTHY => Craft::t('smart-links', 'Healthy'),
            self::REDIRECT => Craft::t('smart-links', 'Redirect'),
            self::BROKEN => Craft::t('smart-links', 'Broken'),
            self::UNAVAILABLE => Craft::t('smart-links', 'Unavailable'),
            self::BLOCKED => Craft::t('smart-links', 'Blocked'),
            self::UNKNOWN => Craft::t('smart-links', 'Unknown'),
        };
    }

    /**
     * Whether the state settles if the target works for a visitor: Healthy and Redirect say it
     * does, Broken says it does not.
     *
     * Unavailable, Blocked and Unknown leave that open, even when a response came back: the
     * target could not be reached, refused the checker, or taught it nothing. None of them may be
     * reported as broken. Whether a response arrived is a different question, answered by the
     * observation's evidence.
     */
    public function isConclusive(): bool
    {
        return match ($this) {
            self::HEALTHY, self::REDIRECT, self::BROKEN => true,
            self::UNAVAILABLE, self::BLOCKED, self::UNKNOWN => false,
        };
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
