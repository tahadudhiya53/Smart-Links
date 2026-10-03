<?php

namespace Tahadudhiya\SmartLinks\linktypes\embed;

use Tahadudhiya\SmartLinks\models\CanonicalUrl;

/**
 * Reads a canonical URL's parts for a provider that matches its media URLs by host and path.
 */
abstract class BaseEmbedProvider implements EmbedProviderInterface
{
    /**
     * The hosts the provider's media URLs are on, lowercase.
     *
     * @return list<string>
     */
    abstract protected function hosts(): array;

    /**
     * The media ID a path (and query) on one of the provider's hosts names, or null.
     *
     * @param array<string, list<string>> $query The URL's query parameters, decoded, each with
     * every value it was given, in order.
     */
    abstract protected function mediaIdFromPath(string $host, string $path, array $query): ?string;

    /**
     * A query parameter's value when it is given exactly once; null when it is absent, and when
     * it is given more than once, since which one the provider would play is then a guess.
     *
     * @param array<string, list<string>> $query
     */
    protected static function single(array $query, string $name): ?string
    {
        return isset($query[$name]) && count($query[$name]) === 1 ? $query[$name][0] : null;
    }

    public function mediaIdFromUrl(CanonicalUrl $url): ?string
    {
        if (!$url->isAbsolute()) {
            return null;
        }

        $parts = parse_url($url->toString());

        if ($parts === false || !in_array($parts['host'] ?? '', $this->hosts(), true) || isset($parts['port'])) {
            return null;
        }

        $query = [];

        // Read by hand, not with parse_str(), which keeps only the last of a repeated name and
        // reads `v[]` as an array.
        foreach (($parts['query'] ?? '') === '' ? [] : explode('&', $parts['query']) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $query[rawurldecode($name)][] = rawurldecode($value);
        }

        $id = $this->mediaIdFromPath($parts['host'], $parts['path'] ?? '/', $query);

        return $id !== null && $this->isMediaId($id) ? $id : null;
    }
}
