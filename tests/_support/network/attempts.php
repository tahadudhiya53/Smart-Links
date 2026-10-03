<?php

// The network guard's control: run by NetworkIsolationTest under the guard, it makes every kind
// of request the guard must catch, each one caught and ignored as careless code would, including
// an embed provider and a social network that fetch remote data through Smart Links' own
// normalizer. It prints, for each, how many attempts the guard recorded, as JSON.

use craft\helpers\Json;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use Tahadudhiya\SmartLinks\events\RegisterEmbedProvidersEvent;
use Tahadudhiya\SmartLinks\events\RegisterSocialNetworksEvent;
use Tahadudhiya\SmartLinks\linktypes\embed\BaseEmbedProvider;
use Tahadudhiya\SmartLinks\linktypes\EmbedLinkType;
use Tahadudhiya\SmartLinks\linktypes\social\SocialNetworkInterface;
use Tahadudhiya\SmartLinks\linktypes\SocialLinkType;
use Tahadudhiya\SmartLinks\SmartLinks;
use yii\base\Event;

require __DIR__ . '/../../integration-bootstrap.php';

/** Fetches remote data while recognising a URL: what an oEmbed lookup during authoring would do. */
final class FetchingProvider extends BaseEmbedProvider
{
    public function handle(): string
    {
        return 'fetching';
    }

    public function name(): string
    {
        return 'Fetching';
    }

    public function isMediaId(string $mediaId): bool
    {
        return $mediaId !== '';
    }

    public function pageUrl(string $mediaId): string
    {
        return "https://fetching.example/v/$mediaId";
    }

    public function embedUrl(string $mediaId): string
    {
        return "https://fetching.example/embed/$mediaId";
    }

    protected function hosts(): array
    {
        return ['fetching.example'];
    }

    protected function mediaIdFromPath(string $host, string $path, array $query): string
    {
        try {
            Craft::createGuzzleClient()->get('https://fetching.example/oembed?url=' . rawurlencode("https://$host$path"));
        } catch (Throwable) {
            // Careless code: the failure is ignored, so only the guard's record shows the attempt.
        }

        return '1';
    }
}

/** Looks an account up remotely while normalizing it. */
final class FetchingNetwork implements SocialNetworkInterface
{
    public function handle(): string
    {
        return 'fetching';
    }

    public function name(): string
    {
        return 'Fetching';
    }

    public function normalizeAccount(string $account): string
    {
        try {
            file_get_contents("https://fetching.example/users/$account");
        } catch (Throwable) {
        }

        return $account;
    }

    public function profileUrl(string $account): string
    {
        return "https://fetching.example/$account";
    }

    public function accountExample(): string
    {
        return 'name';
    }
}

Event::on(EmbedLinkType::class, EmbedLinkType::EVENT_REGISTER_PROVIDERS, static function(RegisterEmbedProvidersEvent $event): void {
    $event->providers[] = new FetchingProvider();
});
Event::on(SocialLinkType::class, SocialLinkType::EVENT_REGISTER_NETWORKS, static function(RegisterSocialNetworksEvent $event): void {
    $event->networks[] = new FetchingNetwork();
});

$normalize = static fn(array $link) => SmartLinks::getInstance()->getLinks()->getNormalizer()->normalize([$link]);

$attempts = [
    'file_get_contents' => static fn() => file_get_contents('https://example.com/'),
    'fopen' => static fn() => fopen('http://example.com/', 'r'),
    'get_headers' => static fn() => get_headers('https://example.com/'),
    'guzzle (Craft’s client)' => static fn() => Craft::createGuzzleClient()->get('https://example.com/'),
    'guzzle (stream handler)' => static fn() => (new Client(['handler' => HandlerStack::create(new StreamHandler())]))->get('https://example.com/'),
    'curl' => static fn() => curl_exec(curl_init('https://example.com/')),
    'fsockopen' => static fn() => fsockopen('example.com', 80),
    'stream_socket_client' => static fn() => stream_socket_client('tcp://example.com:80'),
    'gethostbyname' => static fn() => gethostbyname('example.com'),
    'dns_get_record' => static fn() => dns_get_record('example.com'),
    'embed provider fetching' => static fn() => $normalize(['type' => 'embed', 'data' => ['url' => 'https://fetching.example/v/1']]),
    'social network fetching' => static fn() => $normalize(['type' => 'social', 'data' => ['network' => 'fetching', 'account' => 'jane']]),
];

$log = (string)getenv('SMARTLINKS_NETWORK_LOG');
$recorded = [];

foreach ($attempts as $name => $attempt) {
    $before = is_file($log) ? count(file($log)) : 0;

    try {
        @$attempt();
    } catch (Throwable) {
        // Every attempt fails; what matters is whether the guard saw it.
    }

    $recorded[$name] = (is_file($log) ? count(file($log)) : 0) - $before;
}

echo Json::encode($recorded);
