<?php

namespace Tahadudhiya\SmartLinks\models;

use InvalidArgumentException;

/**
 * An http(s) or root-relative URL in the one form every equivalent spelling of it shares.
 *
 * Only provable equivalences are normalized: scheme and host case, a default or empty port, an
 * empty path, dot segments, percent-encoding case and encoded unreserved characters (RFC 3986
 * §6.2.2, §6.2.3), an IDN's ASCII form (UTS #46) and an IPv6 address's RFC 5952 form. Characters
 * a URI cannot contain are percent-encoded, as RFC 3987 maps an IRI to a URI. Path case, trailing
 * slashes, query order and `+` are left alone, because servers may treat them differently.
 *
 * The host is an HTTP host, not any RFC 3986 reg-name: a DNS name, an unambiguous IPv4 address or
 * an IPv6 literal.
 *
 * The same rules decide both whether two URL targets are the same and which URL a health check
 * requests, so a check can never ask about a different URL than the one it reports on.
 */
final class CanonicalUrl
{
    private const DEFAULT_PORTS = ['http' => '80', 'https' => '443'];

    /** What a path may contain besides unreserved characters (RFC 3986 §3.3). */
    private const PATH_CHARACTERS = "!$&'()*+,;=:@/";

    /** What a query or fragment may contain besides unreserved characters (RFC 3986 §3.4, §3.5). */
    private const QUERY_CHARACTERS = "!$&'()*+,;=:@/?";

    private function __construct(
        private readonly ?string $origin,
        private readonly string $path,
        private readonly ?string $query,
        private readonly ?string $fragment,
    ) {
    }

    /**
     * @throws InvalidArgumentException if the URL is not an http(s) or root-relative URL, or is
     * malformed.
     */
    public static function parse(string $url): self
    {
        // Browsers strip these from both ends, so they are not part of the URL there.
        $url = trim($url, "\x00..\x20");

        if ($url === '' || !mb_check_encoding($url, 'UTF-8')) {
            throw new InvalidArgumentException('A URL must be a non-empty UTF-8 string.');
        }

        // Parsers disagree about control characters (C0, DEL and C1) and backslashes inside a
        // URL, which is how a checked URL comes to differ from the one a browser would visit.
        // Percent-encoded, a control character is unambiguous data, and is kept as such.
        if (preg_match('/[\p{Cc}\\\\]/u', $url)) {
            throw new InvalidArgumentException('A URL may not contain control characters or backslashes.');
        }

        // RFC 3986 appendix B. Unmatched parts come back as null, so `?` alone stays distinct
        // from no query at all.
        preg_match('~^(?:([^:/?#]+):)?(?://([^/?#]*))?([^?#]*)(?:\?([^#]*))?(?:#(.*))?$~s', $url, $parts, PREG_UNMATCHED_AS_NULL);
        [, $scheme, $authority, $path, $query, $fragment] = array_pad($parts, 6, null);
        $path ??= '';

        if ($scheme !== null) {
            $scheme = strtolower($scheme);

            if (!isset(self::DEFAULT_PORTS[$scheme])) {
                throw new InvalidArgumentException('Only http and https URLs can be canonicalized.');
            }

            if ($authority === null || $authority === '') {
                throw new InvalidArgumentException('An absolute URL needs a host.');
            }

            $origin = $scheme . '://' . self::authority($authority, $scheme);
            $path = $path === '' ? '/' : $path;
        } else {
            // A protocol-relative URL takes its scheme, and a path-relative one its directory,
            // from whichever page it appears on, so neither identifies anything by itself.
            if ($authority !== null || !str_starts_with($path, '/')) {
                throw new InvalidArgumentException('A relative URL must be root-relative.');
            }

            $origin = null;
        }

        $path = self::removeDotSegments(self::encode($path, self::PATH_CHARACTERS));

        // Removing dot segments can leave a path that starts with `//` (`/.//a` is the path
        // `//a`). Without a host before it, that would read as a protocol-relative URL naming the
        // host `a`. The URL Standard writes such a path as `/.//a`, which is the same path on the
        // same host, so that is its canonical form too.
        if ($origin === null && str_starts_with($path, '//')) {
            $path = '/.' . $path;
        }

        return new self(
            $origin,
            $path,
            $query !== null ? self::encode($query, self::QUERY_CHARACTERS) : null,
            $fragment !== null ? self::encode($fragment, self::QUERY_CHARACTERS) : null,
        );
    }

    /**
     * A same-document reference (`#section`) in canonical form: the fragment is encoded and
     * normalized exactly as a URL's fragment is.
     *
     * @throws InvalidArgumentException if it is not `#` followed by a fragment, or is malformed.
     */
    public static function fragmentReference(string $reference): string
    {
        $reference = trim($reference, "\x00..\x20");

        if (!str_starts_with($reference, '#') || strlen($reference) < 2 || !mb_check_encoding($reference, 'UTF-8')) {
            throw new InvalidArgumentException('An anchor must be “#” followed by the name of a place in the page.');
        }

        if (preg_match('/[\p{Cc}\\\\]/u', $reference)) {
            throw new InvalidArgumentException('A URL may not contain control characters or backslashes.');
        }

        return '#' . self::encode(substr($reference, 1), self::QUERY_CHARACTERS);
    }

    public function isAbsolute(): bool
    {
        return $this->origin !== null;
    }

    public function toString(): string
    {
        return ($this->origin ?? '')
            . $this->path
            . ($this->query !== null ? '?' . $this->query : '')
            . ($this->fragment !== null ? '#' . $this->fragment : '');
    }

    /**
     * The URL a health check requests: absolute, and without the fragment, which is never sent
     * to the server. Null for a root-relative URL, which has to be resolved against a site first.
     */
    public function healthUrl(): ?string
    {
        if ($this->origin === null) {
            return null;
        }

        return $this->origin . $this->path . ($this->query !== null ? '?' . $this->query : '');
    }

    /**
     * This root-relative URL on the host of an absolute one (e.g. a site's base URL): the same
     * path, query and fragment, served from that origin. A root-relative path is relative to the
     * host's root, never to a base URL's own path.
     *
     * @throws InvalidArgumentException if this URL is not root-relative or the base is not absolute.
     */
    public function onOriginOf(self $base): self
    {
        if ($this->origin !== null || $base->origin === null) {
            throw new InvalidArgumentException('Only a root-relative URL can be put on the origin of an absolute URL.');
        }

        // Parsed again so the path takes its canonical form for a URL with a host (`/.//a` is the
        // path `//a`).
        return self::parse($base->origin . $this->toString());
    }

    /**
     * SHA-256 of {@see healthUrl()}, the key health is stored under.
     */
    public function healthUrlHash(): ?string
    {
        $url = $this->healthUrl();

        return $url !== null ? hash('sha256', $url) : null;
    }

    private static function authority(string $authority, string $scheme): string
    {
        // Credentials would be stored with the URL and sent with every check.
        if (str_contains($authority, '@')) {
            throw new InvalidArgumentException('A URL may not contain credentials.');
        }

        // Everything after the host's colon is the port, so a second colon is a bad port, not a
        // bad host.
        if (preg_match('/^\[([^\]]*)\](?::(.*))?$/', $authority, $matches)) {
            $host = self::ipv6($matches[1]);
        } elseif (preg_match('/^([^:\[\]]*)(?::(.*))?$/', $authority, $matches)) {
            $host = self::host($matches[1]);
        } else {
            throw new InvalidArgumentException('The URL’s host is not valid.');
        }

        $digits = $matches[2] ?? '';
        $port = ltrim($digits, '0');

        if ($digits !== '' && (!ctype_digit($digits) || $port === '' || strlen($port) > 5 || (int)$port > 65535)) {
            throw new InvalidArgumentException('The URL’s port is not valid.');
        }

        return $port === '' || $port === self::DEFAULT_PORTS[$scheme] ? $host : "$host:$port";
    }

    /**
     * A DNS name or a dotted-quad IPv4 address, which is all an HTTP request can be addressed to
     * by name.
     */
    private static function host(string $host): string
    {
        if ($host === '' || str_contains($host, '%')) {
            throw new InvalidArgumentException('The URL’s host is not valid.');
        }

        // UTS #46 as browsers apply it: maps case, converts an IDN to ASCII, and refuses empty,
        // overlong, hyphen-edged and malformed punycode labels.
        $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46);

        if ($ascii === false) {
            throw new InvalidArgumentException('The URL’s host is not a valid domain name.');
        }

        $host = strtolower($ascii);
        $name = rtrim($host, '.');
        $labels = explode('.', $name);

        // Resolvers and browsers read a host whose last label is numeric as an IPv4 address,
        // including forms like `127.1` or `0x7f.1`. Only the one unambiguous spelling is allowed.
        if (preg_match('/^(?:0x[0-9a-f]*|[0-9]+)$/', end($labels))) {
            if ($name !== $host || !self::isDottedQuad($name)) {
                throw new InvalidArgumentException('An IPv4 host must be written as four decimal numbers.');
            }

            return $host;
        }

        // A trailing dot is kept: servers and certificates can treat it as a different name.
        // Underscores are allowed because DNS allows them and browsers resolve such names.
        if (strlen($name) > 253 || !preg_match('/^[a-z0-9_-]+(?:\.[a-z0-9_-]+)*$/', $name)) {
            throw new InvalidArgumentException('The URL’s host is not a valid domain name.');
        }

        return $host;
    }

    private static function isDottedQuad(string $host): bool
    {
        return (bool)preg_match('/^(?:(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])\.){3}(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])$/', $host);
    }

    /**
     * In the RFC 5952 form, the one spelling every equivalent IPv6 address shares.
     *
     * It is written here rather than by `inet_ntop()`, whose output is the operating system's,
     * so the same address has the same canonical form, and target hash, on every server.
     */
    private static function ipv6(string $address): string
    {
        $binary = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? inet_pton($address) : false;

        if ($binary === false || strlen($binary) !== 16) {
            throw new InvalidArgumentException('The URL’s IPv6 address is not valid.');
        }

        /** @var list<int> $groups */
        $groups = array_values((array)unpack('n8', $binary));

        // An IPv4-mapped address keeps its IPv4 part in dotted form (RFC 5952 §5).
        if (array_slice($groups, 0, 6) === [0, 0, 0, 0, 0, 0xFFFF]) {
            return '[::ffff:' . implode('.', array_map('ord', str_split(substr($binary, 12)))) . ']';
        }

        // The longest run of two or more zero groups is shortened to `::`, the first one when
        // two are as long (RFC 5952 §4.2). Hex digits are lowercase without leading zeros.
        [$start, $length] = [-1, 1];

        for ($i = 0; $i < 8; $i++) {
            $run = 0;

            while ($i + $run < 8 && $groups[$i + $run] === 0) {
                $run++;
            }

            if ($run > $length) {
                [$start, $length] = [$i, $run];
            }

            $i += $run;
        }

        $hex = array_map('dechex', $groups);

        if ($start === -1) {
            return '[' . implode(':', $hex) . ']';
        }

        return '[' . implode(':', array_slice($hex, 0, $start)) . '::' . implode(':', array_slice($hex, $start + $length)) . ']';
    }

    private static function encode(string $value, string $allowed): string
    {
        $result = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $character = $value[$i];

            if ($character === '%') {
                $hex = substr($value, $i + 1, 2);

                if (strlen($hex) !== 2 || !ctype_xdigit($hex)) {
                    throw new InvalidArgumentException('The URL contains a malformed percent-encoding.');
                }

                // Only an encoded unreserved character means the same as the character itself.
                $decoded = chr((int)hexdec($hex));
                $result .= self::isUnreserved($decoded) ? $decoded : '%' . strtoupper($hex);
                $i += 2;
            } elseif (self::isUnreserved($character) || str_contains($allowed, $character)) {
                $result .= $character;
            } else {
                $result .= '%' . strtoupper(bin2hex($character));
            }
        }

        return $result;
    }

    private static function isUnreserved(string $character): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9\-._~]$/', $character);
    }

    /**
     * RFC 3986 §5.2.4, for a path that starts with `/`.
     */
    private static function removeDotSegments(string $path): string
    {
        $segments = explode('/', substr($path, 1));
        $last = count($segments) - 1;
        $output = [];

        foreach ($segments as $index => $segment) {
            if ($segment === '.' || $segment === '..') {
                if ($segment === '..') {
                    array_pop($output);
                }

                // `/a/..` is `/`, not an empty path.
                if ($index === $last) {
                    $output[] = '';
                }

                continue;
            }

            $output[] = $segment;
        }

        return '/' . implode('/', $output);
    }
}
