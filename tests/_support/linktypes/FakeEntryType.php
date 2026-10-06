<?php

namespace Tahadudhiya\SmartLinks\Tests\_support\linktypes;

use craft\helpers\Cp;
use Tahadudhiya\SmartLinks\enums\LinkFeature;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\linktypes\LinkResolverInterface;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeDataInterface;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeInterface;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ResolvedLink;
use Tahadudhiya\SmartLinks\models\TargetIdentity;
use Tahadudhiya\SmartLinks\models\ValidationError;

/**
 * An entry link type for tests: everything but download applies, and its data is two IDs.
 */
final class FakeEntryType implements LinkTypeInterface
{
    /**
     * @param LinkResolverInterface|null $resolver Replaces the default, to test resolution.
     */
    public function __construct(
        private ?LinkResolverInterface $resolver = null,
    ) {
    }

    public function handle(): string
    {
        return 'entry';
    }

    public function displayName(): string
    {
        return 'Entry';
    }

    public function supportedFeatures(): array
    {
        return array_values(array_filter(LinkFeature::cases(), static fn(LinkFeature $feature): bool => $feature !== LinkFeature::DOWNLOAD));
    }

    public function normalizeData(array $input): LinkTypeDataInterface
    {
        FakeTypes::assertOnlyKeys($input, ['elementId', 'siteId']);
        $errors = [];

        // A form posts IDs as digit strings, which mean the same number.
        $ids = [];

        foreach (['elementId', 'siteId'] as $key) {
            $value = $input[$key] ?? null;

            if ($value === null || $value === '') {
                $errors[] = new ValidationError($key, Code::MISSING, 'An ID is required.');
            } elseif (is_int($value) && $value > 0) {
                $ids[$key] = $value;
            } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value)) {
                $ids[$key] = (int)$value;
            } else {
                $errors[] = new ValidationError($key, Code::INVALID, 'An ID must be a positive whole number.');
            }
        }

        if ($errors !== []) {
            throw new LinkValidationException($errors);
        }

        return new FakeEntryData($ids['elementId'], $ids['siteId']);
    }

    public function dataFromArray(array $stored): LinkTypeDataInterface
    {
        FakeTypes::assertOnlyKeys($stored, ['elementId', 'siteId']);

        foreach (['elementId', 'siteId'] as $key) {
            if (!is_int($stored[$key] ?? null)) {
                throw new LinkValidationException([new ValidationError($key, Code::NOT_CANONICAL, 'A stored ID is an integer.')]);
            }
        }

        return $this->normalizeData($stored);
    }

    public function validateData(LinkTypeDataInterface $data): array
    {
        if (!$data instanceof FakeEntryData) {
            return [new ValidationError('', Code::WRONG_TYPE, 'This is not entry link data.')];
        }

        return $data->elementId > 0 && $data->siteId > 0 ? [] : [new ValidationError('', Code::INVALID, 'IDs must be positive.')];
    }

    public function inputHtml(LinkTypeDataInterface|array|null $value, ?int $siteId): string
    {
        $html = '';

        foreach (['elementId' => 'Entry ID', 'siteId' => 'Site ID'] as $key => $label) {
            $html .= Cp::textFieldHtml([
                'label' => $label,
                'id' => $key,
                'name' => $key,
                'value' => $value instanceof FakeEntryData ? (string)$value->{$key} : FakeTypes::inputValue($value, $key),
            ]);
        }

        return $html;
    }

    public function targetIdentity(LinkTypeDataInterface $data, int $sourceElementId, int $sourceSiteId): TargetIdentity
    {
        /** @var FakeEntryData $data */
        return TargetIdentity::create('entry', $data->toArray());
    }

    public function resolver(): LinkResolverInterface
    {
        return $this->resolver ??= new FakeResolver(static function(LinkValue $link): ResolvedLink {
            /** @var FakeEntryData $data */
            $data = $link->data;

            // Entries 404 and 403 stand for a deleted and a disabled entry; 500 for a resolver that fails.
            return match ($data->elementId) {
                500 => throw new \RuntimeException('The entry resolver failed.'),
                404 => new ResolvedLink(ResolutionStatus::MISSING),
                403 => new ResolvedLink(ResolutionStatus::DISABLED, defaultLabel: 'Disabled entry'),
                default => new ResolvedLink(ResolutionStatus::RESOLVED, "/entries/{$data->elementId}" . ($link->urlSuffix ?? ''), defaultLabel: "Entry {$data->elementId}"),
            };
        });
    }
}
