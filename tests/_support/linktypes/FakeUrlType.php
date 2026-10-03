<?php

namespace Tahadudhiya\SmartLinks\Tests\_support\linktypes;

use craft\helpers\Cp;
use InvalidArgumentException;
use Tahadudhiya\SmartLinks\enums\LinkFeature;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\linktypes\LinkResolverInterface;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeDataInterface;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeInterface;
use Tahadudhiya\SmartLinks\models\CanonicalUrl;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ResolvedLink;
use Tahadudhiya\SmartLinks\models\TargetIdentity;
use Tahadudhiya\SmartLinks\models\ValidationError;

/**
 * A URL link type for tests: every feature applies, and its data is a canonical URL.
 */
final class FakeUrlType implements LinkTypeInterface
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
        return 'url';
    }

    public function displayName(): string
    {
        return 'URL';
    }

    public function supportedFeatures(): array
    {
        return LinkFeature::cases();
    }

    public function normalizeData(array $input): LinkTypeDataInterface
    {
        FakeTypes::assertOnlyKeys($input, ['url']);

        if (!isset($input['url']) || $input['url'] === '') {
            throw new LinkValidationException([new ValidationError('url', Code::MISSING, 'A URL is required.')]);
        }

        if (!is_string($input['url'])) {
            throw new LinkValidationException([new ValidationError('url', Code::WRONG_TYPE, 'The URL must be text.')]);
        }

        try {
            return new FakeUrlData(CanonicalUrl::parse($input['url']));
        } catch (InvalidArgumentException $exception) {
            throw new LinkValidationException([new ValidationError('url', Code::INVALID, $exception->getMessage())]);
        }
    }

    public function dataFromArray(array $stored): LinkTypeDataInterface
    {
        FakeTypes::assertOnlyKeys($stored, ['url']);
        $data = $this->normalizeData($stored);

        // Stored data was written canonical, so anything else was not written by Smart Links.
        // Key order is not part of the stored form.
        if ($data->toArray() != $stored || array_filter($stored, 'is_string') !== $stored) {
            throw new LinkValidationException([new ValidationError('url', Code::NOT_CANONICAL, 'The stored URL is not in canonical form.')]);
        }

        return $data;
    }

    public function validateData(LinkTypeDataInterface $data): array
    {
        return $data instanceof FakeUrlData ? [] : [new ValidationError('', Code::WRONG_TYPE, 'This is not URL link data.')];
    }

    public function inputHtml(LinkTypeDataInterface|array|null $value, ?int $siteId): string
    {
        return Cp::textFieldHtml([
            'label' => 'URL',
            'id' => 'url',
            'name' => 'url',
            'value' => $value instanceof FakeUrlData ? $value->url->toString() : FakeTypes::inputValue($value, 'url'),
        ]);
    }

    public function targetIdentity(LinkTypeDataInterface $data, int $sourceElementId, int $sourceSiteId): TargetIdentity
    {
        /** @var FakeUrlData $data */
        return TargetIdentity::forUrl('url', $data->url, $data->url->isAbsolute() ? null : $sourceSiteId);
    }

    public function resolver(): LinkResolverInterface
    {
        // An absolute URL leads off this site; a root-relative one stays on it.
        return $this->resolver ??= new FakeResolver(static function(LinkValue $link): ResolvedLink {
            /** @var FakeUrlData $data */
            $data = $link->data;

            return new ResolvedLink(ResolutionStatus::RESOLVED, $data->url->toString() . ($link->urlSuffix ?? ''), external: $data->url->isAbsolute());
        });
    }
}
