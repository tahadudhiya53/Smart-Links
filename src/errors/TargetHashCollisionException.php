<?php

namespace Tahadudhiya\SmartLinks\errors;

use yii\base\Exception;

/**
 * Two different target identities hash to the same `targetHash`.
 *
 * Thrown rather than resolved, because merging them would count one target's usage and health
 * against another's.
 */
class TargetHashCollisionException extends Exception
{
    public function __construct(
        public readonly string $targetHash,
        public readonly string $storedKey,
        public readonly string $requestedKey,
    ) {
        parent::__construct("The target “{$requestedKey}” has the same hash ({$targetHash}) as the stored target “{$storedKey}”.");
    }

    public function getName(): string
    {
        return 'Target hash collision';
    }
}
