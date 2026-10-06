<?php

namespace Tahadudhiya\SmartLinks\models;

use DateTimeImmutable;
use InvalidArgumentException;
use Tahadudhiya\SmartLinks\enums\HealthFailure;
use Tahadudhiya\SmartLinks\enums\HealthState;

/**
 * One health check of one URL: the state it was classified as, and the evidence behind it.
 *
 * Immutable, because it records something that happened. Construction enforces that the evidence
 * is well formed and structurally consistent with the state: exactly one of a response and a
 * failure, a failure's own state, redirects where the state depends on them. It deliberately does
 * not judge whether the state is the right reading of the response. A Redirect ending in a 404 is
 * well-formed evidence here; whether that result is Redirect or Broken is the health checker's
 * classification policy, which is the one place responses are mapped to states.
 */
final class HealthObservation
{
    /**
     * @var list<array{url: string, statusCode: int}> The redirects followed, in order: the health
     * URL requested at each hop and the 3xx status it answered with.
     */
    public readonly array $redirects;

    /**
     * @param string $finalUrl The health URL the check requested last, or was refused. Only health
     * URLs are ever requested ({@see CanonicalUrl::healthUrl()}), so anything else is refused.
     * @param int|null $statusCode The status of the final response, if one came back.
     * @param HealthFailure|null $failure Why no final response came back, if none did.
     * @param array<mixed> $redirects See {@see $redirects}.
     * @throws InvalidArgumentException if the evidence is incomplete or contradicts the state.
     */
    public function __construct(
        public readonly HealthState $state,
        public readonly string $finalUrl,
        public readonly DateTimeImmutable $dateChecked,
        public readonly ?int $statusCode = null,
        public readonly ?HealthFailure $failure = null,
        array $redirects = [],
    ) {
        if (!self::isHealthUrl($finalUrl)) {
            throw new InvalidArgumentException('An observation’s final URL must be a canonical health URL.');
        }

        if (($statusCode === null) === ($failure === null)) {
            throw new InvalidArgumentException('An observation has either a final status code or a failure, never both or neither.');
        }

        if ($statusCode !== null && ($statusCode < 100 || $statusCode > 599)) {
            throw new InvalidArgumentException("{$statusCode} is not an HTTP status code.");
        }

        // Without a response there is nothing to classify: the failure alone decides the state.
        if ($failure !== null && $failure->state() !== $state) {
            throw new InvalidArgumentException("A check that failed with “{$failure->value}” is {$failure->state()->value}, not {$state->value}.");
        }

        $this->redirects = self::redirects($redirects);

        // Healthy and Redirect differ only in whether redirects were followed.
        if ($state === HealthState::HEALTHY && $this->redirects !== []) {
            throw new InvalidArgumentException('A target reached through redirects is a redirect, not healthy.');
        }

        if (($state === HealthState::REDIRECT || $failure === HealthFailure::TOO_MANY_REDIRECTS) && $this->redirects === []) {
            throw new InvalidArgumentException('A redirect result needs the redirects that were followed.');
        }
    }

    /**
     * @param array<mixed> $redirects
     * @return list<array{url: string, statusCode: int}>
     */
    private static function redirects(array $redirects): array
    {
        // The order of the hops is the evidence, so it is taken only as a list, never re-indexed.
        if (!array_is_list($redirects)) {
            throw new InvalidArgumentException('The redirects must be a list, in the order they were followed.');
        }

        $hops = [];

        foreach ($redirects as $hop) {
            if (
                !is_array($hop) ||
                count($hop) !== 2 ||
                !isset($hop['url'], $hop['statusCode']) ||
                !is_string($hop['url']) || !self::isHealthUrl($hop['url']) ||
                !is_int($hop['statusCode']) || $hop['statusCode'] < 300 || $hop['statusCode'] > 399
            ) {
                throw new InvalidArgumentException('Each redirect must be exactly a health URL and the 3xx status it answered with.');
            }

            $hops[] = ['url' => $hop['url'], 'statusCode' => $hop['statusCode']];
        }

        return $hops;
    }

    /**
     * Whether a URL is already exactly a health URL: absolute http(s), canonical, no fragment.
     */
    private static function isHealthUrl(string $url): bool
    {
        try {
            return CanonicalUrl::parse($url)->healthUrl() === $url;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
