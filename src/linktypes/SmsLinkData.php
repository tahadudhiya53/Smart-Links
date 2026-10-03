<?php

namespace Tahadudhiya\SmartLinks\linktypes;

/**
 * An SMS link's data: the number, as for a phone link, and the message it starts with.
 */
final class SmsLinkData implements LinkTypeDataInterface
{
    /**
     * @param string|null $body May run over several lines.
     */
    public function __construct(
        public readonly string $number,
        public readonly ?string $body = null,
    ) {
    }

    public function toArray(): array
    {
        return $this->body === null ? ['number' => $this->number] : ['number' => $this->number, 'body' => $this->body];
    }
}
