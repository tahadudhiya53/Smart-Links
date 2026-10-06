<?php

namespace Tahadudhiya\SmartLinks\linktypes;

/**
 * A social link's data: which network, and which account on it.
 */
final class SocialLinkData implements LinkTypeDataInterface
{
    /**
     * @param string $network The network's handle.
     * @param string $account The account, in the network's canonical spelling.
     */
    public function __construct(
        public readonly string $network,
        public readonly string $account,
    ) {
    }

    public function toArray(): array
    {
        return ['network' => $this->network, 'account' => $this->account];
    }
}
