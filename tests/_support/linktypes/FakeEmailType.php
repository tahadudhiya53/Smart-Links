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
 * An email link type for tests: no target, rel, download or URL suffix, which do not apply to
 * opening a mail client.
 */
final class FakeEmailType implements LinkTypeInterface
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
        return 'email';
    }

    public function displayName(): string
    {
        return 'Email';
    }

    public function supportedFeatures(): array
    {
        return [LinkFeature::TITLE, LinkFeature::CLASS_NAMES, LinkFeature::ID, LinkFeature::ARIA_LABEL, LinkFeature::CUSTOM_ATTRIBUTES];
    }

    public function normalizeData(array $input): LinkTypeDataInterface
    {
        FakeTypes::assertOnlyKeys($input, ['address']);
        $address = $input['address'] ?? null;

        if (!is_string($address) || !preg_match('/^[^@\s]+@[^@\s]+$/', $address)) {
            throw new LinkValidationException([new ValidationError('address', Code::INVALID, 'An email address is required.')]);
        }

        return new FakeEmailData($address);
    }

    public function dataFromArray(array $stored): LinkTypeDataInterface
    {
        return $this->normalizeData($stored);
    }

    public function validateData(LinkTypeDataInterface $data): array
    {
        return $data instanceof FakeEmailData ? [] : [new ValidationError('', Code::WRONG_TYPE, 'This is not email link data.')];
    }

    public function inputHtml(LinkTypeDataInterface|array|null $value, ?int $siteId): string
    {
        return Cp::textFieldHtml([
            'label' => 'Email address',
            'id' => 'address',
            'name' => 'address',
            'value' => $value instanceof FakeEmailData ? $value->address : FakeTypes::inputValue($value, 'address'),
        ]);
    }

    public function targetIdentity(LinkTypeDataInterface $data, int $sourceElementId, int $sourceSiteId): TargetIdentity
    {
        /** @var FakeEmailData $data */
        return TargetIdentity::create('email', $data->toArray());
    }

    public function resolver(): LinkResolverInterface
    {
        return $this->resolver ??= new FakeResolver(static function(LinkValue $link): ResolvedLink {
            /** @var FakeEmailData $data */
            $data = $link->data;

            return new ResolvedLink(ResolutionStatus::RESOLVED, "mailto:{$data->address}");
        });
    }
}
