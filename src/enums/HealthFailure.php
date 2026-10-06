<?php

namespace Tahadudhiya\SmartLinks\enums;

use Craft;

/**
 * Why a check ended without a final response from the target.
 *
 * Recorded alongside the state so that every Unavailable or Unknown result can say what actually
 * happened, instead of leaving the reader to guess. A failure always implies its state, and no
 * failure implies a state that only an answer from the target can justify.
 */
enum HealthFailure: string
{
    /** The target did not answer within the time allowed. */
    case TIMEOUT = 'timeout';

    /** A connection to the target could not be opened or was dropped. */
    case CONNECTION_FAILED = 'connectionFailed';

    /** The target's host name could not be resolved. */
    case DNS_FAILED = 'dnsFailed';

    /** The request was never sent because the address is one outbound checks may not reach. */
    case REFUSED_BY_POLICY = 'refusedByPolicy';

    /** The target kept redirecting past the number of hops a check follows. */
    case TOO_MANY_REDIRECTS = 'tooManyRedirects';

    public function label(): string
    {
        return match ($this) {
            self::TIMEOUT => Craft::t('smart-links', 'Timed out'),
            self::CONNECTION_FAILED => Craft::t('smart-links', 'Connection failed'),
            self::DNS_FAILED => Craft::t('smart-links', 'Host name not resolved'),
            self::REFUSED_BY_POLICY => Craft::t('smart-links', 'Refused by outbound request policy'),
            self::TOO_MANY_REDIRECTS => Craft::t('smart-links', 'Too many redirects'),
        };
    }

    /**
     * The only state an observation ending in this failure can have.
     *
     * A target that could not be reached was unavailable. A check that never sent its request,
     * or gave up following redirects, learnt nothing about the target at all.
     */
    public function state(): HealthState
    {
        return match ($this) {
            self::TIMEOUT, self::CONNECTION_FAILED, self::DNS_FAILED => HealthState::UNAVAILABLE,
            self::REFUSED_BY_POLICY, self::TOO_MANY_REDIRECTS => HealthState::UNKNOWN,
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
