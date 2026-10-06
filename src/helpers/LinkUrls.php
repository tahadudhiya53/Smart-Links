<?php

namespace Tahadudhiya\SmartLinks\helpers;

use Craft;
use InvalidArgumentException;
use LogicException;
use Tahadudhiya\SmartLinks\models\CanonicalUrl;

/**
 * What resolvers do to the hrefs they build: apply a link's URL suffix, and tell whether a URL
 * leads off the site it is resolved in.
 */
final class LinkUrls
{
    /**
     * Applies a URL suffix to an href. A query suffix joins the href's query (before any
     * fragment, which is where a query goes); a fragment suffix is the href's fragment, in place
     * of any it had, since a URL has only one.
     *
     * @param string|null $suffix A validated URL suffix: `?…` or `#…`.
     */
    public static function applySuffix(string $href, ?string $suffix): string
    {
        if ($suffix === null) {
            return $href;
        }

        $hash = strpos($href, '#');
        $base = $hash === false ? $href : substr($href, 0, $hash);
        $fragment = $hash === false ? '' : substr($href, $hash);

        if (str_starts_with($suffix, '#')) {
            return $base . $suffix;
        }

        return $base . (str_contains($base, '?') ? '&' . substr($suffix, 1) : $suffix) . $fragment;
    }

    /**
     * A URL a social network or embed provider built: it must be an absolute https URL already in
     * canonical form, so what reaches an href or an `<iframe>` is exactly one URL, on the web.
     *
     * @param string $source Who built it, for the message.
     * @throws LogicException if it is not: a fault in that definition, which must not reach a page.
     */
    public static function provided(string $url, string $source): string
    {
        try {
            $canonical = CanonicalUrl::parse($url);
        } catch (InvalidArgumentException $exception) {
            throw new LogicException("$source built “{$url}”, which is not a valid URL.", 0, $exception);
        }

        if (!str_starts_with($url, 'https://') || $canonical->toString() !== $url) {
            throw new LogicException("$source built “{$url}”, which is not an absolute https URL in canonical form.");
        }

        return $url;
    }

    /**
     * Whether an absolute URL leads off a site: its host is not the site's.
     *
     * A site without an absolute base URL has no host of its own to compare with, so every
     * absolute URL counts as leading off it.
     */
    public static function isExternal(CanonicalUrl $url, int $siteId): bool
    {
        if (!$url->isAbsolute()) {
            return false;
        }

        $baseUrl = Craft::$app->getSites()->getSiteById($siteId, true)?->getBaseUrl();
        $siteHost = null;

        if ($baseUrl !== null) {
            try {
                $site = CanonicalUrl::parse($baseUrl);
                $siteHost = $site->isAbsolute() ? self::host($site) : null;
            } catch (InvalidArgumentException) {
                // A base URL that is not an http(s) URL (e.g. `@web/`, unresolved) names no host.
                $siteHost = null;
            }
        }

        return $siteHost === null || self::host($url) !== $siteHost;
    }

    /**
     * The canonical host (with any port) of an absolute canonical URL.
     */
    private static function host(CanonicalUrl $url): string
    {
        return (string)parse_url($url->toString(), PHP_URL_HOST) . ':' . (string)parse_url($url->toString(), PHP_URL_PORT);
    }
}
