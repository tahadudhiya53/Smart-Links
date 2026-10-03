<?php

namespace Tahadudhiya\SmartLinks\linktypes\social;

/**
 * A social network that Social links can point at an account on.
 *
 * A network is code, registered with {@see \Tahadudhiya\SmartLinks\linktypes\SocialLinkType::EVENT_REGISTER_NETWORKS}.
 * A link stores the network's handle and the account; the profile URL is always built from
 * them here, never stored, so a network changing its URLs is one change in code.
 */
interface SocialNetworkInterface
{
    /**
     * Stored with every link to the network, so it never changes. Lowercase words joined by
     * hyphens, e.g. `linkedin-company`.
     */
    public function handle(): string;

    /**
     * The name authors see, e.g. `Instagram`.
     */
    public function name(): string;

    /**
     * The account in its one canonical spelling, or null when it is not an account on this
     * network. Only provably equivalent spellings may be normalized (a leading `@` that is not
     * part of the name; the case of a domain name).
     */
    public function normalizeAccount(string $account): ?string;

    /**
     * The profile page of a canonical account: an absolute https URL in canonical form.
     */
    public function profileUrl(string $account): string;

    /**
     * An example of an account, shown as the input's placeholder, e.g. `@name`.
     */
    public function accountExample(): string;
}
