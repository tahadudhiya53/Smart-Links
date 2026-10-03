<?php

namespace Tahadudhiya\SmartLinks\linktypes\social;

use Tahadudhiya\SmartLinks\helpers\DomainName;

/**
 * Bluesky, whose accounts are domain names (`name.bsky.social`, or a domain of the account's
 * own), so their case carries no meaning.
 */
final class BlueskyNetwork implements SocialNetworkInterface
{
    public function handle(): string
    {
        return 'bluesky';
    }

    public function name(): string
    {
        return 'Bluesky';
    }

    public function normalizeAccount(string $account): ?string
    {
        $account = trim($account);

        return DomainName::normalize(str_starts_with($account, '@') ? substr($account, 1) : $account);
    }

    public function profileUrl(string $account): string
    {
        return 'https://bsky.app/profile/' . $account;
    }

    public function accountExample(): string
    {
        return '@name.bsky.social';
    }
}
