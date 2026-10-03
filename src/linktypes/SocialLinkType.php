<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use Craft;
use craft\helpers\Cp;
use GraphQL\Type\Definition\Type;
use LogicException;
use Tahadudhiya\SmartLinks\enums\LinkFeature;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\events\RegisterSocialNetworksEvent;
use Tahadudhiya\SmartLinks\helpers\LinkUrls;
use Tahadudhiya\SmartLinks\links\LinkValidator;
use Tahadudhiya\SmartLinks\linktypes\social\BlueskyNetwork;
use Tahadudhiya\SmartLinks\linktypes\social\FediverseNetwork;
use Tahadudhiya\SmartLinks\linktypes\social\SocialNetwork;
use Tahadudhiya\SmartLinks\linktypes\social\SocialNetworkInterface;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ResolvedLink;
use Tahadudhiya\SmartLinks\models\TargetIdentity;
use Tahadudhiya\SmartLinks\models\ValidationError;
use yii\base\Event;
use yii\base\InvalidConfigException;

/**
 * A link to an account on a social network.
 *
 * A link holds the network and the account, never a URL: the network builds the profile URL
 * (see {@see SocialNetworkInterface}). So an author cannot point a "LinkedIn" link anywhere but
 * LinkedIn, and a network changing its URLs changes every link to it at once.
 */
class SocialLinkType implements LinkTypeInterface, LinkResolverInterface, GqlLinkTypeInterface
{
    /**
     * @event RegisterSocialNetworksEvent Collects the available networks, starting with the
     * built-in ones. Triggered on the class, once per link type set.
     *
     * ```php
     * Event::on(SocialLinkType::class, SocialLinkType::EVENT_REGISTER_NETWORKS, function(RegisterSocialNetworksEvent $event) {
     *     $event->networks[] = new SocialNetwork('mastodon-social', 'mastodon.social', '[A-Za-z0-9_]{1,30}', 'https://mastodon.social/@{account}');
     * });
     * ```
     */
    public const EVENT_REGISTER_NETWORKS = 'registerNetworks';

    /** @var array<string, SocialNetworkInterface>|null By handle. */
    private ?array $networks = null;

    /**
     * The built-in networks. Each account rule is the set of characters and the length the
     * network allows in a profile's name.
     *
     * @return list<SocialNetworkInterface>
     */
    public static function builtInNetworks(): array
    {
        return [
            new SocialNetwork('facebook', 'Facebook', '[A-Za-z0-9.]{5,50}', 'https://www.facebook.com/{account}', false),
            new SocialNetwork('instagram', 'Instagram', '[A-Za-z0-9._]{1,30}', 'https://www.instagram.com/{account}/'),
            new SocialNetwork('x', 'X', '[A-Za-z0-9_]{1,15}', 'https://x.com/{account}'),
            new SocialNetwork('linkedin', 'LinkedIn', '[A-Za-z0-9-]{3,100}', 'https://www.linkedin.com/in/{account}/', false),
            new SocialNetwork('linkedin-company', 'LinkedIn (company page)', '[A-Za-z0-9-]{2,100}', 'https://www.linkedin.com/company/{account}/', false),
            new SocialNetwork('youtube', 'YouTube', '[A-Za-z0-9._-]{3,30}', 'https://www.youtube.com/@{account}'),
            new SocialNetwork('tiktok', 'TikTok', '[A-Za-z0-9._]{2,24}', 'https://www.tiktok.com/@{account}'),
            new SocialNetwork('github', 'GitHub', '[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}', 'https://github.com/{account}', false),
            new SocialNetwork('pinterest', 'Pinterest', '[A-Za-z0-9_]{3,30}', 'https://www.pinterest.com/{account}/', false),
            new SocialNetwork('threads', 'Threads', '[A-Za-z0-9._]{1,30}', 'https://www.threads.com/@{account}'),
            new BlueskyNetwork(),
            new FediverseNetwork('mastodon', 'Mastodon'),
        ];
    }

    public function handle(): string
    {
        return 'social';
    }

    public function displayName(): string
    {
        return Craft::t('smart-links', 'Social');
    }

    public function supportedFeatures(): array
    {
        return [LinkFeature::TARGET, LinkFeature::REL, LinkFeature::TITLE, LinkFeature::CLASS_NAMES, LinkFeature::ID, LinkFeature::ARIA_LABEL, LinkFeature::CUSTOM_ATTRIBUTES];
    }

    /**
     * Every available network, by handle, in the order authors are offered them.
     *
     * @return array<string, SocialNetworkInterface>
     * @throws InvalidConfigException if a registered network is not one, or two share a handle.
     */
    public function networks(): array
    {
        if ($this->networks !== null) {
            return $this->networks;
        }

        $event = new RegisterSocialNetworksEvent(['networks' => self::builtInNetworks()]);
        Event::trigger(self::class, self::EVENT_REGISTER_NETWORKS, $event);
        $networks = [];

        foreach ($event->networks as $network) {
            /** @phpstan-ignore instanceof.alwaysTrue (Handlers can add anything; this is the check.) */
            if (!$network instanceof SocialNetworkInterface) {
                throw new InvalidConfigException(sprintf('A registered social network must implement %s.', SocialNetworkInterface::class));
            }

            $handle = $network->handle();

            if (!LinkValidator::isTypeHandle($handle) || isset($networks[$handle])) {
                throw new InvalidConfigException("“{$handle}” is not a valid social network handle, or is used twice.");
            }

            $networks[$handle] = $network;
        }

        return $this->networks = $networks;
    }

    public function network(string $handle): ?SocialNetworkInterface
    {
        return $this->networks()[$handle] ?? null;
    }

    public function normalizeData(array $input): LinkTypeDataInterface
    {
        $errors = DataInput::unknownKeys($input, ['network', 'account']);
        $handle = DataInput::text($input, 'network', $errors);
        $account = DataInput::text($input, 'account', $errors);
        $network = null;

        if ($handle === null) {
            if (in_array($input['network'] ?? null, [null, ''], true)) {
                $errors[] = new ValidationError('network', Code::MISSING, 'Choose a network.');
            }
        } elseif (($network = $this->network($handle)) === null) {
            $errors[] = new ValidationError('network', Code::INVALID, '“{network}” is not an available social network.', ['network' => $handle]);
        }

        if ($account === null) {
            if (in_array($input['account'] ?? null, [null, ''], true)) {
                $errors[] = new ValidationError('account', Code::MISSING, 'Enter the account name.');
            }
        } elseif ($network !== null) {
            $normalized = $network->normalizeAccount($account);

            if ($normalized === null) {
                $errors[] = new ValidationError('account', Code::INVALID, '“{account}” is not a {network} account name, like {example}.', ['account' => $account, 'network' => $network->name(), 'example' => $network->accountExample()]);
            }

            $account = $normalized;
        }

        DataInput::throwIfAny($errors);

        /** @var string $handle */
        /** @var string $account */
        return new SocialLinkData($handle, $account);
    }

    public function dataFromArray(array $stored): LinkTypeDataInterface
    {
        $data = $this->normalizeData($stored);

        if (!DataInput::isStoredForm($data->toArray(), $stored)) {
            throw new LinkValidationException([new ValidationError('account', Code::NOT_CANONICAL, 'The stored account is not in canonical form.')]);
        }

        return $data;
    }

    public function validateData(LinkTypeDataInterface $data): array
    {
        if (!$data instanceof SocialLinkData) {
            return [new ValidationError('', Code::WRONG_TYPE, 'This is not {type} link data.', ['type' => $this->handle()])];
        }

        $network = $this->network($data->network);

        if ($network === null) {
            return [new ValidationError('network', Code::INVALID, '“{network}” is not an available social network.', ['network' => $data->network])];
        }

        return $network->normalizeAccount($data->account) === $data->account ? [] : [new ValidationError('account', Code::NOT_CANONICAL, 'The account is not in canonical form.')];
    }

    public function inputHtml(LinkTypeDataInterface|array|null $value, ?int $siteId): string
    {
        $input = $value instanceof LinkTypeDataInterface ? $value->toArray() : $value;
        $options = [['label' => Craft::t('smart-links', 'Choose a network'), 'value' => '']];

        foreach ($this->networks() as $handle => $network) {
            $options[] = ['label' => $network->name(), 'value' => $handle];
        }

        $current = DataInput::inputValue($input, 'network');

        // A network that is no longer available stays selected, named for what it is.
        if ($current !== '' && $this->network($current) === null) {
            $options[] = ['label' => Craft::t('smart-links', '{network} (not available)', ['network' => $current]), 'value' => $current];
        }

        return Cp::selectFieldHtml([
            'label' => Craft::t('smart-links', 'Network'),
            'id' => 'network',
            'name' => 'network',
            'options' => $options,
            'value' => $current,
        ]) . Cp::textFieldHtml([
            'label' => Craft::t('smart-links', 'Account'),
            'instructions' => Craft::t('smart-links', 'The account’s name, not its URL: e.g. @name, or @name@server on Mastodon.'),
            'id' => 'account',
            'name' => 'account',
            'autocomplete' => false,
            'value' => DataInput::inputValue($input, 'account'),
        ]);
    }

    public function targetIdentity(LinkTypeDataInterface $data, int $sourceElementId, int $sourceSiteId): TargetIdentity
    {
        /** @var SocialLinkData $data */
        return TargetIdentity::create($this->handle(), ['network' => $data->network, 'account' => $data->account]);
    }

    /**
     * The profile URL a link with this data leads to.
     *
     * @throws LogicException if the network is no longer available, or builds a URL that is not
     * an absolute https URL in canonical form: a fault in its definition that must not reach a page.
     */
    public function profileUrl(SocialLinkData $data): string
    {
        $network = $this->networkOf($data);

        return LinkUrls::provided($network->profileUrl($data->account), "The {$network->name()} social network");
    }

    public function gqlDataFields(): array
    {
        return [
            'network' => Type::nonNull(Type::string()),
            'account' => Type::nonNull(Type::string()),
        ];
    }

    public function resolver(): LinkResolverInterface
    {
        return $this;
    }

    public function resolve(LinkValue $link, int $siteId): ResolvedLink
    {
        /** @var SocialLinkData $data */
        $data = $link->data;

        return new ResolvedLink(ResolutionStatus::RESOLVED, $this->profileUrl($data), true, $this->networkOf($data)->name());
    }

    private function networkOf(SocialLinkData $data): SocialNetworkInterface
    {
        return $this->network($data->network) ?? throw new LogicException("The social network “{$data->network}” is not available.");
    }
}
