<?php

namespace Tahadudhiya\SmartLinks\linktypes;

/**
 * An email link's data: the address, and the subject and body the message starts with.
 */
final class EmailLinkData implements LinkTypeDataInterface
{
    /**
     * @param string $address The local part as authored, the domain lowercase and in ASCII.
     * @param string|null $body May run over several lines.
     */
    public function __construct(
        public readonly string $address,
        public readonly ?string $subject = null,
        public readonly ?string $body = null,
    ) {
    }

    public function toArray(): array
    {
        return array_filter([
            'address' => $this->address,
            'subject' => $this->subject,
            'body' => $this->body,
        ], static fn(?string $value): bool => $value !== null);
    }
}
