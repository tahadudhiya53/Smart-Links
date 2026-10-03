<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use Craft;
use craft\helpers\Cp;
use GraphQL\Type\Definition\Type;
use InvalidArgumentException;
use LogicException;
use Tahadudhiya\SmartLinks\enums\LinkFeature;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\events\RegisterEmbedProvidersEvent;
use Tahadudhiya\SmartLinks\helpers\LinkUrls;
use Tahadudhiya\SmartLinks\links\LinkValidator;
use Tahadudhiya\SmartLinks\linktypes\embed\EmbedProviderInterface;
use Tahadudhiya\SmartLinks\linktypes\embed\SoundCloud;
use Tahadudhiya\SmartLinks\linktypes\embed\Spotify;
use Tahadudhiya\SmartLinks\linktypes\embed\Vimeo;
use Tahadudhiya\SmartLinks\linktypes\embed\YouTube;
use Tahadudhiya\SmartLinks\models\CanonicalUrl;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ResolvedLink;
use Tahadudhiya\SmartLinks\models\TargetIdentity;
use Tahadudhiya\SmartLinks\models\ValidationError;
use yii\base\Event;
use yii\base\InvalidConfigException;

/**
 * A link to embeddable media (a video, a track…) on a known provider.
 *
 * An author pastes the media's URL. It is recognised by its form alone, by the providers that are
 * registered (see {@see EmbedProviderInterface}), and stored as the provider and the media's ID;
 * a URL no provider recognises is refused. Nothing is fetched from the provider, or from the URL,
 * while authoring or rendering. The link leads to the media's page, and the provider builds the
 * URL of its embeddable player from the same data ({@see embedUrl()}).
 */
class EmbedLinkType implements LinkTypeInterface, LinkResolverInterface, GqlLinkTypeInterface
{
    /**
     * @event RegisterEmbedProvidersEvent Collects the available providers, starting with the
     * built-in ones. Triggered on the class, once per link type set.
     */
    public const EVENT_REGISTER_PROVIDERS = 'registerProviders';

    /** @var array<string, EmbedProviderInterface>|null By handle. */
    private ?array $providers = null;

    /**
     * @return list<EmbedProviderInterface>
     */
    public static function builtInProviders(): array
    {
        return [new YouTube(), new Vimeo(), new Spotify(), new SoundCloud()];
    }

    public function handle(): string
    {
        return 'embed';
    }

    public function displayName(): string
    {
        return Craft::t('smart-links', 'Embed');
    }

    public function supportedFeatures(): array
    {
        return [LinkFeature::TARGET, LinkFeature::REL, LinkFeature::TITLE, LinkFeature::CLASS_NAMES, LinkFeature::ID, LinkFeature::ARIA_LABEL, LinkFeature::CUSTOM_ATTRIBUTES];
    }

    /**
     * Every available provider, by handle, in the order a URL is matched against them.
     *
     * @return array<string, EmbedProviderInterface>
     * @throws InvalidConfigException if a registered provider is not one, or two share a handle.
     */
    public function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $event = new RegisterEmbedProvidersEvent(['providers' => self::builtInProviders()]);
        Event::trigger(self::class, self::EVENT_REGISTER_PROVIDERS, $event);
        $providers = [];

        foreach ($event->providers as $provider) {
            /** @phpstan-ignore instanceof.alwaysTrue (Handlers can add anything; this is the check.) */
            if (!$provider instanceof EmbedProviderInterface) {
                throw new InvalidConfigException(sprintf('A registered embed provider must implement %s.', EmbedProviderInterface::class));
            }

            $handle = $provider->handle();

            if (!LinkValidator::isTypeHandle($handle) || isset($providers[$handle])) {
                throw new InvalidConfigException("“{$handle}” is not a valid embed provider handle, or is used twice.");
            }

            $providers[$handle] = $provider;
        }

        return $this->providers = $providers;
    }

    public function provider(string $handle): ?EmbedProviderInterface
    {
        return $this->providers()[$handle] ?? null;
    }

    /**
     * Reads either a pasted media URL (`url`), or the stored form (`provider` and `mediaId`).
     */
    public function normalizeData(array $input): LinkTypeDataInterface
    {
        $errors = DataInput::unknownKeys($input, ['url', 'provider', 'mediaId']);
        $url = DataInput::text($input, 'url', $errors);
        $handle = DataInput::text($input, 'provider', $errors);
        $mediaId = DataInput::text($input, 'mediaId', $errors);
        $data = null;

        if ($url !== null && ($handle !== null || $mediaId !== null)) {
            $errors[] = new ValidationError('url', Code::INVALID, 'Give either the media’s URL, or its provider and media ID, not both.');
        } elseif ($url !== null) {
            $data = $this->fromUrl($url, $errors);
        } elseif ($handle !== null || $mediaId !== null) {
            $data = $this->fromParts($handle, $mediaId, $errors);
        } elseif ($errors === []) {
            $errors[] = new ValidationError('url', Code::MISSING, 'Paste the address of the video or track.');
        }

        DataInput::throwIfAny($errors);

        /** @var EmbedLinkData $data */
        return $data;
    }

    public function dataFromArray(array $stored): LinkTypeDataInterface
    {
        // The stored form is the provider and media ID; a URL is only ever authoring input.
        if (!array_key_exists('provider', $stored) || !array_key_exists('mediaId', $stored) || array_key_exists('url', $stored)) {
            throw new LinkValidationException([new ValidationError('', Code::NOT_CANONICAL, 'Stored embed link data is a provider and a media ID.')]);
        }

        $data = $this->normalizeData($stored);

        if (!DataInput::isStoredForm($data->toArray(), $stored)) {
            throw new LinkValidationException([new ValidationError('mediaId', Code::NOT_CANONICAL, 'The stored media ID is not in canonical form.')]);
        }

        return $data;
    }

    public function validateData(LinkTypeDataInterface $data): array
    {
        if (!$data instanceof EmbedLinkData) {
            return [new ValidationError('', Code::WRONG_TYPE, 'This is not {type} link data.', ['type' => $this->handle()])];
        }

        $errors = [];
        $this->fromParts($data->provider, $data->mediaId, $errors);

        return $errors;
    }

    public function inputHtml(LinkTypeDataInterface|array|null $value, ?int $siteId): string
    {
        if ($value instanceof EmbedLinkData) {
            $url = $this->pageUrl($value);
        } else {
            $url = DataInput::inputValue(is_array($value) ? $value : null, 'url');
        }

        $names = array_map(static fn(EmbedProviderInterface $provider): string => $provider->name(), array_values($this->providers()));

        return Cp::textFieldHtml([
            'label' => Craft::t('smart-links', 'Media URL'),
            'instructions' => Craft::t('smart-links', 'The address of a video or track on {providers}. Only the media is kept, not a start time or other settings in the address.', ['providers' => implode(', ', $names)]),
            'id' => 'url',
            'name' => 'url',
            'inputmode' => 'url',
            'autocomplete' => false,
            'value' => $url,
        ]);
    }

    public function targetIdentity(LinkTypeDataInterface $data, int $sourceElementId, int $sourceSiteId): TargetIdentity
    {
        /** @var EmbedLinkData $data */
        return TargetIdentity::create($this->handle(), ['provider' => $data->provider, 'mediaId' => $data->mediaId]);
    }

    /**
     * The media's page, which the link leads to.
     *
     * @throws LogicException if the provider is no longer available, or builds a URL that is not
     * an absolute https URL in canonical form.
     */
    public function pageUrl(EmbedLinkData $data): string
    {
        $provider = $this->providerOf($data);

        return LinkUrls::provided($provider->pageUrl($data->mediaId), "The {$provider->name()} embed provider");
    }

    /**
     * The URL of the provider's embeddable player for a link's media, e.g. for an `<iframe>`.
     *
     * @throws LogicException if the provider is no longer available, or builds a URL that is not
     * an absolute https URL in canonical form.
     */
    public function embedUrl(EmbedLinkData $data): string
    {
        $provider = $this->providerOf($data);

        return LinkUrls::provided($provider->embedUrl($data->mediaId), "The {$provider->name()} embed provider");
    }

    public function gqlDataFields(): array
    {
        return [
            'provider' => Type::nonNull(Type::string()),
            'mediaId' => Type::nonNull(Type::string()),
            'embedUrl' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The URL of the provider’s embeddable player.',
                'resolve' => function(LinkTypeDataInterface $data): string {
                    /** @var EmbedLinkData $data */
                    return $this->embedUrl($data);
                },
            ],
        ];
    }

    public function resolver(): LinkResolverInterface
    {
        return $this;
    }

    public function resolve(LinkValue $link, int $siteId): ResolvedLink
    {
        /** @var EmbedLinkData $data */
        $data = $link->data;

        return new ResolvedLink(ResolutionStatus::RESOLVED, $this->pageUrl($data), true, $this->providerOf($data)->name());
    }

    private function providerOf(EmbedLinkData $data): EmbedProviderInterface
    {
        return $this->provider($data->provider) ?? throw new LogicException("The embed provider “{$data->provider}” is not available.");
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function fromUrl(string $url, array &$errors): ?EmbedLinkData
    {
        try {
            $canonical = CanonicalUrl::parse($url);
        } catch (InvalidArgumentException $exception) {
            $errors[] = new ValidationError('url', Code::INVALID, 'This URL can’t be used: {reason}', ['reason' => $exception->getMessage()]);

            return null;
        }

        foreach ($this->providers() as $handle => $provider) {
            $mediaId = $provider->mediaIdFromUrl($canonical);

            if ($mediaId !== null) {
                return new EmbedLinkData($handle, $mediaId);
            }
        }

        $names = array_map(static fn(EmbedProviderInterface $provider): string => $provider->name(), array_values($this->providers()));
        $errors[] = new ValidationError('url', Code::INVALID, 'This is not the address of a video or track on {providers}.', ['providers' => implode(', ', $names)]);

        return null;
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function fromParts(?string $handle, ?string $mediaId, array &$errors): ?EmbedLinkData
    {
        $provider = $handle !== null ? $this->provider($handle) : null;

        if ($handle === null) {
            $errors[] = new ValidationError('provider', Code::MISSING, 'A provider is required.');
        } elseif ($provider === null) {
            $errors[] = new ValidationError('provider', Code::INVALID, '“{provider}” is not an available embed provider.', ['provider' => $handle]);
        }

        if ($mediaId === null) {
            $errors[] = new ValidationError('mediaId', Code::MISSING, 'A media ID is required.');
        } elseif ($provider !== null && !$provider->isMediaId($mediaId)) {
            $errors[] = new ValidationError('mediaId', Code::INVALID, '“{id}” is not a {provider} media ID.', ['id' => $mediaId, 'provider' => $provider->name()]);
        }

        return $provider !== null && $mediaId !== null && $provider->isMediaId($mediaId) ? new EmbedLinkData((string)$handle, $mediaId) : null;
    }
}
