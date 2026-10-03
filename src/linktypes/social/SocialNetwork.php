<?php

namespace Tahadudhiya\SmartLinks\linktypes\social;

/**
 * A network whose accounts are names of a fixed form, with a profile page at a fixed URL.
 *
 * ```php
 * new SocialNetwork('mastodon-social', 'mastodon.social', '[A-Za-z0-9_]{1,30}', 'https://mastodon.social/@{account}');
 * ```
 */
final class SocialNetwork implements SocialNetworkInterface
{
    /**
     * @param string $accountPattern A regular expression body (no delimiters or anchors) that a
     * whole account name matches.
     * @param string $urlTemplate The profile URL, with `{account}` where the account goes.
     * @param bool $atPrefix Whether the network writes accounts with a leading `@` that is not
     * part of the name, so `@name` and `name` are the same account.
     */
    public function __construct(
        private readonly string $handle,
        private readonly string $name,
        private readonly string $accountPattern,
        private readonly string $urlTemplate,
        private readonly bool $atPrefix = true,
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
        $account = trim($account);

        if ($this->atPrefix && str_starts_with($account, '@')) {
            $account = substr($account, 1);
        }

        return preg_match('/^(?:' . $this->accountPattern . ')$/D', $account) ? $account : null;
    }

    public function profileUrl(string $account): string
    {
        return str_replace('{account}', rawurlencode($account), $this->urlTemplate);
    }

    public function accountExample(): string
    {
        return $this->atPrefix ? '@name' : 'name';
    }
}
