<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use Craft;
use craft\helpers\Cp;
use GraphQL\Type\Definition\Type;
use InvalidArgumentException;
use Tahadudhiya\SmartLinks\enums\LinkFeature;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\helpers\LinkUrls;
use Tahadudhiya\SmartLinks\models\CanonicalUrl;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ResolvedLink;
use Tahadudhiya\SmartLinks\models\TargetIdentity;
use Tahadudhiya\SmartLinks\models\ValidationError;

/**
 * A link to a URL: an absolute http(s) URL, a path on the site (`/about`), or an anchor in the
 * page the link is on (`#team`).
 *
 * URLs are held in their canonical form ({@see CanonicalUrl}), which refuses what could make a
 * stored URL differ from the one a browser visits: credentials, control characters, backslashes,
 * ambiguous numeric hosts, and malformed hosts or encodings. Every other scheme is refused,
 * including `javascript:` and `data:`; email addresses, phone numbers and SMS have their own
 * link types.
 */
class UrlLinkType implements LinkTypeInterface, LinkResolverInterface, CraftLinkConverterInterface, GqlLinkTypeInterface
{
    /**
     * The longest URL accepted, in bytes. It bounds what is stored with every link, and the
     * target identity and resolved URL the index derives from it.
     */
    public const MAX_LENGTH = 4096;

    /** Schemes with their own link type, named so authors are pointed at it. */
    private const OTHER_TYPES = [
        'mailto' => 'Use an Email link for email addresses.',
        'tel' => 'Use a Phone link for phone numbers.',
        'sms' => 'Use an SMS link for text messages.',
    ];

    public function handle(): string
    {
        return 'url';
    }

    public function displayName(): string
    {
        return Craft::t('smart-links', 'URL');
    }

    public function supportedFeatures(): array
    {
        return LinkFeature::cases();
    }

    public function normalizeData(array $input): LinkTypeDataInterface
    {
        $errors = DataInput::unknownKeys($input, ['url']);
        $url = DataInput::text($input, 'url', $errors);

        if ($url === null) {
            if ($errors === []) {
                $errors[] = new ValidationError('url', Code::MISSING, 'Enter a URL.');
            }

            throw new LinkValidationException($errors);
        }

        DataInput::throwIfAny($errors);

        return self::parse($url);
    }

    public function dataFromArray(array $stored): LinkTypeDataInterface
    {
        DataInput::throwIfAny(DataInput::unknownKeys($stored, ['url']));
        $url = $stored['url'] ?? null;

        if (!is_string($url)) {
            throw new LinkValidationException([new ValidationError('url', Code::NOT_CANONICAL, 'A stored URL is text.')]);
        }

        $data = self::parse($url);

        if (!DataInput::isStoredForm($data->toArray(), $stored)) {
            throw new LinkValidationException([new ValidationError('url', Code::NOT_CANONICAL, 'The stored URL is not in canonical form.')]);
        }

        return $data;
    }

    public function validateData(LinkTypeDataInterface $data): array
    {
        if (!$data instanceof UrlLinkData) {
            return [new ValidationError('', Code::WRONG_TYPE, 'This is not {type} link data.', ['type' => $this->handle()])];
        }

        try {
            $canonical = self::parse($data->url);
        } catch (LinkValidationException $exception) {
            return $exception->errors;
        }

        // Data built in code must be what normalizing its URL gives.
        if ($canonical->url !== $data->url || ($canonical->canonical?->toString() !== $data->canonical?->toString())) {
            return [new ValidationError('url', Code::NOT_CANONICAL, 'The URL is not in canonical form.')];
        }

        return [];
    }

    public function inputHtml(LinkTypeDataInterface|array|null $value, ?int $siteId): string
    {
        return Cp::textFieldHtml([
            'label' => Craft::t('smart-links', 'URL'),
            'instructions' => Craft::t('smart-links', 'A full URL (https://…), a path on this site (/about), or an anchor in this page (#team).'),
            'id' => 'url',
            'name' => 'url',
            'inputmode' => 'url',
            'autocomplete' => false,
            'value' => $value instanceof UrlLinkData ? $value->url : DataInput::inputValue(is_array($value) ? $value : null, 'url'),
        ]);
    }

    public function targetIdentity(LinkTypeDataInterface $data, int $sourceElementId, int $sourceSiteId): TargetIdentity
    {
        /** @var UrlLinkData $data */
        if ($data->canonical === null) {
            // An anchor points into the page it is on, so that page is part of its target.
            return TargetIdentity::create($this->handle(), ['url' => $data->url, 'elementId' => $sourceElementId, 'siteId' => $sourceSiteId]);
        }

        return TargetIdentity::forUrl($this->handle(), $data->canonical, $data->canonical->isAbsolute() ? null : $sourceSiteId);
    }

    public function gqlDataFields(): array
    {
        return ['url' => ['type' => Type::nonNull(Type::string()), 'description' => 'The URL, path or anchor, in canonical form, without the link’s URL suffix.']];
    }

    public function resolver(): LinkResolverInterface
    {
        return $this;
    }

    public function resolve(LinkValue $link, int $siteId): ResolvedLink
    {
        /** @var UrlLinkData $data */
        $data = $link->data;
        $external = $data->canonical !== null && LinkUrls::isExternal($data->canonical, $siteId);

        return new ResolvedLink(ResolutionStatus::RESOLVED, LinkUrls::applySuffix($data->url, $link->urlSuffix), $external);
    }

    public function craftLinkTypes(): array
    {
        return ['url'];
    }

    public function dataFromCraftLink(string $value): array
    {
        return ['url' => $value];
    }

    /**
     * @throws LinkValidationException if it is not a URL this type accepts.
     */
    private static function parse(string $url): UrlLinkData
    {
        if (strlen($url) > self::MAX_LENGTH) {
            throw new LinkValidationException([new ValidationError('url', Code::INVALID, 'A URL can be at most {max, number} characters long.', ['max' => self::MAX_LENGTH])]);
        }

        $trimmed = trim($url, "\x00..\x20");

        try {
            if (str_starts_with($trimmed, '#')) {
                return new UrlLinkData(CanonicalUrl::fragmentReference($trimmed), null);
            }

            if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $trimmed, $match) && !in_array(strtolower($match[1]), ['http', 'https'], true)) {
                $message = self::OTHER_TYPES[strtolower($match[1])] ?? 'Only http and https URLs can be linked to.';

                throw new LinkValidationException([new ValidationError('url', Code::INVALID, $message)]);
            }

            $canonical = CanonicalUrl::parse($trimmed);
        } catch (InvalidArgumentException $exception) {
            throw new LinkValidationException([new ValidationError('url', Code::INVALID, 'This URL can’t be used: {reason}', ['reason' => $exception->getMessage()])]);
        }

        return new UrlLinkData($canonical->toString(), $canonical);
    }
}
