<?php

namespace Tahadudhiya\SmartLinks\models;

use DateTime;
use Tahadudhiya\SmartLinks\enums\HealthState;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;

/**
 * One link target in the inventory, with what is known about where it is used.
 */
final class InventoryItem
{
    /**
     * @param string|null $targetStatus A {@see ResolutionStatus} value; null when the target could not be resolved.
     * @param HealthState|null $health Null when the target has no URL a health check could request.
     * @param string|null $checkUrl The target's http(s) URL, safe to link to; null for any other kind of URL.
     * @param array{label: string, url: string|null}|null $source The first place it is used.
     * @param list<string> $siteNames The sites it is used in.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $linkType,
        public readonly string $typeName,
        public readonly string $targetKey,
        public readonly ?string $targetLabel,
        public readonly ?string $resolvedUrl,
        public readonly ?string $checkUrl,
        public readonly ?string $targetStatus,
        public readonly ?HealthState $health,
        public readonly ?DateTime $dateChecked,
        public readonly int $usageCount,
        public readonly int $sourceCount,
        public readonly ?string $label,
        public readonly int $labelCount,
        public readonly ?array $source,
        public readonly array $siteNames,
    ) {
    }

    public function targetState(): ?ResolutionStatus
    {
        return $this->targetStatus !== null ? ResolutionStatus::tryFrom($this->targetStatus) : null;
    }
}
