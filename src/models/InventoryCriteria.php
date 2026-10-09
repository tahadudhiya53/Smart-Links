<?php

namespace Tahadudhiya\SmartLinks\models;

use Craft;
use InvalidArgumentException;
use LogicException;
use Tahadudhiya\SmartLinks\enums\HealthState;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;
use Tahadudhiya\SmartLinks\links\LinkValidator;

/**
 * Which part of the link inventory to show, in which order: what the inventory page's query
 * string asks for, read strictly. A value no filter or sort can have is refused, never ignored or
 * replaced, so a page never shows something other than what its URL says.
 */
final class InventoryCriteria
{
    /** Targets per page. */
    public const PAGE_SIZE = 50;

    /** The longest search text read. */
    public const MAX_SEARCH = 255;

    /** Filters by health state; also targets without a URL a health check could request. */
    public const HEALTH_NONE = 'none';

    /** Filters by how resolving ended; also targets that could not be resolved at all. */
    public const TARGET_FAILED = 'failed';

    /** Sort keys, with the direction each starts in. */
    public const SORTS = [
        'link' => 'asc',
        'type' => 'asc',
        'usage' => 'desc',
        'health' => 'asc',
        'checked' => 'desc',
        'target' => 'asc',
    ];

    private function __construct(
        public readonly ?string $search,
        public readonly ?int $siteId,
        public readonly ?string $type,
        public readonly ?int $fieldId,
        public readonly ?string $health,
        public readonly ?string $target,
        public readonly string $sort,
        public readonly string $dir,
        public readonly int $page,
    ) {
    }

    /**
     * Reads the inventory page's query parameters. Absent or empty means “all”. Other parameters
     * are not the page's to read: Craft adds its own `site` to every control panel URL, the site
     * the control panel is showing, so the site filter is `sourceSite`.
     *
     * @param array<mixed> $params
     * @throws InvalidArgumentException if a parameter is malformed or names nothing that exists.
     */
    public static function fromParams(array $params): self
    {
        foreach ($params as $name => $value) {
            if (!in_array($name, ['search', 'sourceSite', 'type', 'source', 'health', 'target', 'sort', 'dir', 'page'], true)) {
                continue;
            }

            if (!is_string($value)) {
                throw new InvalidArgumentException("The “{$name}” parameter must be text.");
            }
        }

        $text = static fn(string $name): ?string => isset($params[$name]) && $params[$name] !== '' ? $params[$name] : null;

        $search = $text('search') !== null ? trim($text('search')) : null;

        if ($search !== null && (mb_strlen($search) > self::MAX_SEARCH || !mb_check_encoding($search, 'UTF-8'))) {
            throw new InvalidArgumentException(sprintf('Search text can be at most %d characters.', self::MAX_SEARCH));
        }

        $siteId = null;

        if (($handle = $text('sourceSite')) !== null) {
            $siteId = Craft::$app->getSites()->getSiteByHandle($handle, true)->id ?? throw new InvalidArgumentException("There is no site “{$handle}”.");
        }

        $type = $text('type');

        if ($type !== null && !LinkValidator::isTypeHandle($type)) {
            throw new InvalidArgumentException("“{$type}” is not a link type handle.");
        }

        $fieldId = null;

        if (($handle = $text('source')) !== null) {
            $field = Craft::$app->getFields()->getFieldByHandle($handle);

            if (!$field instanceof SmartLinkField) {
                throw new InvalidArgumentException("There is no Smart Links field “{$handle}”.");
            }

            $fieldId = (int)$field->id;
        }

        $health = $text('health');

        if ($health !== null && !in_array($health, [...HealthState::values(), self::HEALTH_NONE], true)) {
            throw new InvalidArgumentException("“{$health}” is not a health state.");
        }

        $target = $text('target');

        if ($target !== null && !in_array($target, [...array_column(ResolutionStatus::cases(), 'value'), self::TARGET_FAILED], true)) {
            throw new InvalidArgumentException("“{$target}” is not a target state.");
        }

        $sort = $text('sort') ?? 'usage';

        if (!isset(self::SORTS[$sort])) {
            throw new InvalidArgumentException("The inventory can’t be sorted by “{$sort}”.");
        }

        $dir = $text('dir') ?? self::SORTS[$sort];

        if (!in_array($dir, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('The sort direction must be “asc” or “desc”.');
        }

        $page = $text('page') ?? '1';

        if (!preg_match('/^[1-9][0-9]{0,8}$/', $page)) {
            throw new InvalidArgumentException('The page must be a positive number.');
        }

        return new self($search !== '' ? $search : null, $siteId !== null ? (int)$siteId : null, $type, $fieldId, $health, $target, $sort, $dir, (int)$page);
    }

    /**
     * These criteria, changed: what a filter form, sort link or page link asks for, as query
     * parameters, with defaults left out.
     *
     * @param array<string, string|int|null> $changes
     * @return array<string, string|int>
     */
    public function params(array $changes = []): array
    {
        $params = [
            'search' => $this->search,
            'sourceSite' => $this->siteId !== null ? (Craft::$app->getSites()->getSiteById($this->siteId, true)->handle ?? throw new LogicException("Site {$this->siteId} is gone.")) : null,
            'type' => $this->type,
            'source' => $this->fieldId !== null ? (Craft::$app->getFields()->getFieldById($this->fieldId)->handle ?? throw new LogicException("Field {$this->fieldId} is gone.")) : null,
            'health' => $this->health,
            'target' => $this->target,
            'sort' => $this->sort !== 'usage' ? $this->sort : null,
            'dir' => $this->dir !== self::SORTS[$this->sort] ? $this->dir : null,
            'page' => $this->page > 1 ? $this->page : null,
        ];

        return array_filter(array_merge($params, $changes), static fn($value): bool => $value !== null && $value !== '');
    }

    public function isFiltered(): bool
    {
        return $this->search !== null || $this->siteId !== null || $this->type !== null || $this->fieldId !== null || $this->health !== null || $this->target !== null;
    }
}
