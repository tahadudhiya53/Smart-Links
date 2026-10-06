<?php

namespace Tahadudhiya\SmartLinks\models;

use Craft;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode;

/**
 * One problem with a link value: where it is, what kind it is, and what to tell the author.
 */
final class ValidationError
{
    /**
     * @param string $path Where the problem is, e.g. `links[1].attributes.rel`. Empty for the
     * value as a whole.
     * @param string $message The source message, translated by {@see getMessage()}.
     * @param array<string, string|int> $params The message's placeholders.
     */
    public function __construct(
        public readonly string $path,
        public readonly ValidationErrorCode $code,
        public readonly string $message,
        public readonly array $params = [],
    ) {
    }

    public function getMessage(): string
    {
        return Craft::t('smart-links', $this->message, $this->params);
    }

    /**
     * The same error, reported from inside `$prefix`.
     */
    public function within(string $prefix): self
    {
        return new self(self::join($prefix, $this->path), $this->code, $this->message, $this->params);
    }

    /**
     * Joins two paths: `links[0]` and `uid` give `links[0].uid`; `rel` and `[1]` give `rel[1]`.
     */
    public static function join(string $prefix, string $path): string
    {
        return match (true) {
            $prefix === '' => $path,
            $path === '' => $prefix,
            str_starts_with($path, '[') => $prefix . $path,
            default => "$prefix.$path",
        };
    }
}
