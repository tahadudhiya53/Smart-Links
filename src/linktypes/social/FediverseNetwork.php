<?php

namespace Tahadudhiya\SmartLinks\linktypes\social;

use Tahadudhiya\SmartLinks\helpers\DomainName;

/**
 * A federated network, such as Mastodon, whose accounts are `name@server`: the server is part of
 * the account, and the profile is on that server.
 */
final class FediverseNetwork implements SocialNetworkInterface
{
    public function __construct(
        private readonly string $handle,
        private readonly string $name,
    ) {
    }

    public function handle(): string
    {
        return $this->handle;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function normalizeAccount(string $account): ?string
    {
        // `@name@server` and `name@server` are the same account; the server is a domain name,
        // so its case carries no meaning. The name's does, on some servers, so it is kept.
        if (!preg_match('/^@?([A-Za-z0-9_](?:[A-Za-z0-9_.-]*[A-Za-z0-9_])?)@([^@]+)$/D', trim($account), $match) || strlen($match[1]) > 64) {
            return null;
        }

        $server = DomainName::normalize($match[2]);

        return $server !== null ? "{$match[1]}@$server" : null;
    }

    public function profileUrl(string $account): string
    {
        [$name, $server] = explode('@', $account, 2);

        return "https://$server/@" . rawurlencode($name);
    }

    public function accountExample(): string
    {
        return '@name@server';
    }
}
