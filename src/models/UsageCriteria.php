<?php

namespace Tahadudhiya\SmartLinks\models;

use Craft;
use InvalidArgumentException;
use LogicException;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;

/**
 * Which link occurrences to show: those in one site or one field, a page at a time. Read strictly
 * from a page's query string, as the inventory's criteria are: a value no filter can have is
 * refused, never ignored.
 */
final class UsageCriteria
{
    /** Occurrences per page. */
    public const PAGE_SIZE = 50;

    /**
     * @param int|null $siteId Only occurrences in this site's content.
     * @param int|null $fieldId Only occurrences in this Smart Links field.
     * @throws InvalidArgumentException for a page below 1.
     */
    public function __construct(
        public readonly ?int $siteId = null,
        public readonly ?int $fieldId = null,
        public readonly int $page = 1,
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('The page must be a positive number.');
        }
    }

    /**
     * Reads a usage page's query parameters: `sourceSite` and `source` by handle, and `page`.
     * Absent or empty means “all”. Other parameters are not the page's to read: Craft adds its own
     * `site` to every control panel URL, the site the control panel is showing.
     *
     * @param array<mixed> $params
     * @throws InvalidArgumentException if a parameter is malformed or names nothing that exists.
     */
    public static function fromParams(array $params): self
    {
        foreach (['sourceSite', 'source', 'page'] as $name) {
            if (isset($params[$name]) && !is_string($params[$name])) {
                throw new InvalidArgumentException("The “{$name}” parameter must be text.");
            }
        }

        $text = static fn(string $name): ?string => isset($params[$name]) && $params[$name] !== '' ? $params[$name] : null;
        $siteId = null;

        if (($handle = $text('sourceSite')) !== null) {
            $siteId = Craft::$app->getSites()->getSiteByHandle($handle, true)->id ?? throw new InvalidArgumentException("There is no site “{$handle}”.");
        }

        $fieldId = null;

        if (($handle = $text('source')) !== null) {
            $field = Craft::$app->getFields()->getFieldByHandle($handle);

            if (!$field instanceof SmartLinkField) {
                throw new InvalidArgumentException("There is no Smart Links field “{$handle}”.");
            }

            $fieldId = (int)$field->id;
        }

        $page = $text('page') ?? '1';

        if (!preg_match('/^[1-9][0-9]{0,8}$/', $page)) {
            throw new InvalidArgumentException('The page must be a positive number.');
        }

        return new self($siteId !== null ? (int)$siteId : null, $fieldId, (int)$page);
    }

    /**
     * These criteria, changed, as query parameters, with defaults left out.
     *
     * @param array<string, string|int|null> $changes
     * @return array<string, string|int>
     */
    public function params(array $changes = []): array
    {
        $params = [
            'sourceSite' => $this->siteId !== null ? (Craft::$app->getSites()->getSiteById($this->siteId, true)->handle ?? throw new LogicException("Site {$this->siteId} is gone.")) : null,
            'source' => $this->fieldId !== null ? (Craft::$app->getFields()->getFieldById($this->fieldId)->handle ?? throw new LogicException("Field {$this->fieldId} is gone.")) : null,
            'page' => $this->page > 1 ? $this->page : null,
        ];

        return array_filter(array_merge($params, $changes), static fn($value): bool => $value !== null && $value !== '');
    }

    public function isFiltered(): bool
    {
        return $this->siteId !== null || $this->fieldId !== null;
    }
}
