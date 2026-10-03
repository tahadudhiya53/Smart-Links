<?php

namespace Tahadudhiya\SmartLinks\Tests\unit;

use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SmartLinks\enums\HealthFailure;
use Tahadudhiya\SmartLinks\enums\HealthState;
use Tahadudhiya\SmartLinks\models\CanonicalUrl;
use Tahadudhiya\SmartLinks\models\HealthObservation;
use Tahadudhiya\SmartLinks\models\TargetIdentity;

/**
 * The contracts the rest of Smart Links is built on: when two links point at the same target,
 * when two URLs are the same URL, and what a health observation may claim. Everything derived,
 * from index rows to health, is keyed by these, so they are asserted exactly.
 */
class DomainModelTest extends TestCase
{
    // Target identity

    public function testATargetIdentityHasOneDocumentedSerialization(): void
    {
        $identity = TargetIdentity::create('entry', ['siteId' => 1, 'elementId' => 123]);

        self::assertSame('entry?elementId=123&siteId=1', $identity->key());
        self::assertSame(hash('sha256', 'entry?elementId=123&siteId=1'), $identity->hash());
    }

    public function testTheSameTargetAlwaysHasTheSameIdentity(): void
    {
        // Component order, and whether an ID arrived as an int or as the string a database
        // returns, say nothing about the target.
        $a = TargetIdentity::create('entry', ['elementId' => 123, 'siteId' => 1]);
        $b = TargetIdentity::create('entry', ['siteId' => '1', 'elementId' => '123']);

        self::assertTrue($a->equals($b));
        self::assertSame($a->hash(), $b->hash());
    }

    /**
     * @return array<string, array{TargetIdentity, TargetIdentity}>
     */
    public static function distinctTargets(): array
    {
        return [
            'an entry and an asset with the same ID' => [
                TargetIdentity::create('entry', ['elementId' => 123, 'siteId' => 1]),
                TargetIdentity::create('asset', ['elementId' => 123, 'siteId' => 1]),
            ],
            'an entry and a category with the same ID' => [
                TargetIdentity::create('entry', ['elementId' => 123, 'siteId' => 1]),
                TargetIdentity::create('category', ['elementId' => 123, 'siteId' => 1]),
            ],
            'one entry in two sites' => [
                TargetIdentity::create('entry', ['elementId' => 123, 'siteId' => 1]),
                TargetIdentity::create('entry', ['elementId' => 123, 'siteId' => 2]),
            ],
            'a phone number called and texted' => [
                TargetIdentity::create('tel', ['number' => '+441234567890']),
                TargetIdentity::create('sms', ['number' => '+441234567890']),
            ],
            'a root-relative URL in two sites' => [
                TargetIdentity::create('url', ['url' => '/about', 'siteId' => 1]),
                TargetIdentity::create('url', ['url' => '/about', 'siteId' => 2]),
            ],
            'a URL and an email address' => [
                TargetIdentity::create('url', ['url' => 'mailto:hello@example.com']),
                TargetIdentity::create('email', ['address' => 'hello@example.com']),
            ],
            // Encoding keeps a value from ever reading as a delimiter.
            'a value containing the delimiters' => [
                TargetIdentity::create('custom', ['a' => 'x&b=y']),
                TargetIdentity::create('custom', ['a' => 'x', 'b' => 'y']),
            ],
        ];
    }

    #[DataProvider('distinctTargets')]
    public function testDifferentTargetsNeverShareAnIdentity(TargetIdentity $a, TargetIdentity $b): void
    {
        self::assertNotSame($a->key(), $b->key());
        self::assertNotSame($a->hash(), $b->hash());
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string}>
     */
    public static function malformedIdentities(): array
    {
        return [
            'an empty type' => ['', ['url' => 'https://example.com/'], 'link type handle'],
            'an uppercase type' => ['Entry', ['elementId' => 1], 'link type handle'],
            'a type with a delimiter' => ['entry?x', ['elementId' => 1], 'link type handle'],
            'an overlong type' => [str_repeat('a', 65), ['elementId' => 1], 'link type handle'],
            'no components' => ['entry', [], 'at least one component'],
            'a component name with a delimiter' => ['entry', ['element=Id' => 1], 'component name'],
            'a component name that is not camelCase' => ['entry', ['ElementId' => 1], 'component name'],
            'an empty value' => ['email', ['address' => ''], 'non-empty UTF-8'],
            'a value that is not UTF-8' => ['email', ['address' => "\xFF"], 'non-empty UTF-8'],
            'an element ID of zero' => ['entry', ['elementId' => 0, 'siteId' => 1], 'positive ID'],
            'a negative site ID' => ['entry', ['elementId' => 1, 'siteId' => -1], 'positive ID'],
            'an element ID that is not a number' => ['entry', ['elementId' => 'abc'], 'positive ID'],
            'an element ID with leading zeros' => ['entry', ['elementId' => '012'], 'positive ID'],
            // Values with no single reading are refused, so none can name a target never given.
            'a null value' => ['email', ['address' => null], 'string or an integer'],
            'true, which would read as "1"' => ['entry', ['elementId' => true], 'string or an integer'],
            'false, which would read as nothing' => ['email', ['address' => false], 'string or an integer'],
            'a float' => ['entry', ['elementId' => 1.5], 'string or an integer'],
            'a list' => ['email', ['address' => ['a@example.com']], 'string or an integer'],
        ];
    }

    /**
     * @param array<string, mixed> $components
     */
    #[DataProvider('malformedIdentities')]
    public function testAMalformedIdentityIsRefused(string $type, array $components, string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        TargetIdentity::create($type, $components);
    }

    public function testAKeyIsNeverLongerThanTheIndexCanStoreWhole(): void
    {
        // `email?address=` and the value: exactly as long as the column holds, then one byte more.
        $room = TargetIdentity::MAX_KEY_BYTES - strlen('email?address=');
        self::assertSame(TargetIdentity::MAX_KEY_BYTES, strlen(TargetIdentity::create('email', ['address' => str_repeat('a', $room)])->key()));

        // Percent-encoding counts: a value that fits as given can be too long once encoded.
        foreach ([str_repeat('a', $room + 1), str_repeat('&', intdiv($room, 3) + 1)] as $value) {
            try {
                TargetIdentity::create('email', ['address' => $value]);
                self::fail('A key too long for the index was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('at most 65535 bytes', $exception->getMessage());
            }
        }
    }

    public function testAnElementTargetNamesASiteExactlyWhenItsTypeIsLocalized(): void
    {
        foreach ([['entry', Entry::class], ['asset', Asset::class], ['category', Category::class]] as [$type, $elementType]) {
            self::assertSame("$type?elementId=5&siteId=2", TargetIdentity::forElement($type, $elementType, 5, 2)->key());
        }

        self::assertSame('user?elementId=5', TargetIdentity::forElement('user', User::class, 5, null)->key());
    }

    /**
     * @return array<string, array{string, string, int|null}>
     */
    public static function elementTargetsWithTheWrongSite(): array
    {
        return [
            'an entry without its site' => ['entry', Entry::class, null],
            'an asset without its site' => ['asset', Asset::class, null],
            'a user with a site' => ['user', User::class, 1],
            'something that is not an element' => ['thing', \stdClass::class, 1],
        ];
    }

    #[DataProvider('elementTargetsWithTheWrongSite')]
    public function testAnElementTargetCannotLeaveOutOrInventItsSite(string $type, string $elementType, ?int $siteId): void
    {
        $this->expectException(InvalidArgumentException::class);

        TargetIdentity::forElement($type, $elementType, 5, $siteId);
    }

    public function testAnAbsoluteUrlIsTheSameTargetInEverySiteAndARelativeOneIsNot(): void
    {
        $absolute = CanonicalUrl::parse('https://example.com/about');
        $relative = CanonicalUrl::parse('/about');

        self::assertSame('url?url=https%3A%2F%2Fexample.com%2Fabout', TargetIdentity::forUrl('url', $absolute, null)->key());
        self::assertNotSame(TargetIdentity::forUrl('url', $relative, 1)->key(), TargetIdentity::forUrl('url', $relative, 2)->key());
    }

    public function testAUrlTargetCannotLeaveOutOrInventItsSite(): void
    {
        foreach ([[CanonicalUrl::parse('https://example.com/'), 1], [CanonicalUrl::parse('/about'), null]] as [$url, $siteId]) {
            try {
                TargetIdentity::forUrl('url', $url, $siteId);
                self::fail("{$url->toString()} was accepted with the wrong site.");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // URL canonicalization

    /**
     * @return array<string, array{string, string}>
     */
    public static function equivalentUrls(): array
    {
        return [
            'scheme and host case' => ['HTTPS://Example.COM/About', 'https://example.com/About'],
            'the default http port' => ['http://example.com:80/', 'http://example.com/'],
            'the default https port' => ['https://example.com:443/', 'https://example.com/'],
            'an empty port' => ['https://example.com:/', 'https://example.com/'],
            'leading zeros in a port' => ['https://example.com:08080/', 'https://example.com:8080/'],
            'an empty path' => ['https://example.com', 'https://example.com/'],
            'dot segments' => ['https://example.com/a/./b/../c', 'https://example.com/a/c'],
            'a trailing dot segment' => ['https://example.com/a/..', 'https://example.com/'],
            'an encoded unreserved character' => ['https://example.com/%7Euser/%41', 'https://example.com/~user/A'],
            'lowercase percent-encoding' => ['https://example.com/a%2fb', 'https://example.com/a%2Fb'],
            'an encoded dot segment' => ['https://example.com/a/%2E%2E/b', 'https://example.com/b'],
            'a space' => ['https://example.com/a b', 'https://example.com/a%20b'],
            'a non-ASCII path' => ['https://example.com/über', 'https://example.com/%C3%BCber'],
            'an internationalized host' => ['https://Bücher.Example/', 'https://xn--bcher-kva.example/'],
            'an IPv6 host' => ['http://[2001:DB8::1]:80/', 'http://[2001:db8::1]/'],
            'an uncompressed IPv6 host' => ['http://[2001:db8:0:0:0:0:0:1]/', 'http://[2001:db8::1]/'],
            'an IPv6 host with a port' => ['http://[::1]:8080/a', 'http://[::1]:8080/a'],
            'an IPv4 host' => ['http://192.168.0.1:80/', 'http://192.168.0.1/'],
            'the lowest IPv4 address' => ['http://0.0.0.0/', 'http://0.0.0.0/'],
            'the highest IPv4 address' => ['http://255.255.255.255/', 'http://255.255.255.255/'],
            'an IPv4 address with a zero octet' => ['http://10.0.0.1/', 'http://10.0.0.1/'],
            'a loopback IPv4 address' => ['http://127.0.0.1/', 'http://127.0.0.1/'],
            'an IPv4-mapped IPv6 address' => ['http://[::FFFF:1.2.3.4]/', 'http://[::ffff:1.2.3.4]/'],
            'the lowest port' => ['https://example.com:1/', 'https://example.com:1/'],
            'the highest port' => ['https://example.com:65535/', 'https://example.com:65535/'],
            'a name whose last label is not numeric' => ['http://1.2.3.a/', 'http://1.2.3.a/'],
            'an uppercase punycode host' => ['https://XN--BCHER-KVA.example/', 'https://xn--bcher-kva.example/'],
            'an underscore in the host' => ['https://my_site.example.com/', 'https://my_site.example.com/'],
            'a non-ASCII query and fragment' => ['https://example.com/?q=é#ü', 'https://example.com/?q=%C3%A9#%C3%BC'],
            'an encoded slash' => ['https://example.com/%2f', 'https://example.com/%2F'],
            'three dots, which are not a dot segment' => ['https://example.com/...', 'https://example.com/...'],
            'no path before a query' => ['https://example.com?a=1', 'https://example.com/?a=1'],
            'surrounding whitespace' => ["  https://example.com/ \n", 'https://example.com/'],
            'a root-relative URL' => ['/a/./b/%7e', '/a/b/~'],
            'the root' => ['/', '/'],
            'a root-relative path' => ['/foo/bar', '/foo/bar'],
            'a root-relative dot segment' => ['/a/./b', '/a/b'],
            'a root-relative parent segment' => ['/a/../b', '/b'],
            'parent segments up to the root' => ['/a/b/../../c', '/c'],
            'parent segments beyond the root' => ['/../../c', '/c'],
            'a parent segment in an absolute URL' => ['https://example.com/a/../b', 'https://example.com/b'],
            'an absolute URL without a path' => ['http://example.com', 'http://example.com/'],
            'mixed-case encoded dot segments' => ['/a/%2e/b/%2E%2e/c', '/a/c'],
            'a half-encoded parent segment' => ['/a/.%2E/b', '/b'],
            // Removing dot segments can leave a path starting with `//`. With no host before it,
            // it is written as the URL Standard writes it, `/.//`, so it stays on the same host
            // rather than reading as a protocol-relative URL naming the host `evil.com`.
            'a dot segment before a repeated slash' => ['/.//evil.com', '/.//evil.com'],
            'a parent segment before a repeated slash' => ['/a/..//evil.com', '/.//evil.com'],
            'an encoded dot segment before a repeated slash' => ['/%2e//evil.com', '/.//evil.com'],
            'an encoded parent segment before a repeated slash' => ['/%2e%2e//evil.com', '/.//evil.com'],
            'an uppercase encoded parent segment before a repeated slash' => ['/%2E%2E//evil.com/x?q=1#f', '/.//evil.com/x?q=1#f'],
            'only slashes after a dot segment' => ['/.//', '/.//'],
            'a repeated slash after the host' => ['https://example.com/.//evil.com', 'https://example.com//evil.com'],
            'dot segments in a query, which are not a path' => ['https://example.com/?a=/../b', 'https://example.com/?a=/../b'],
            'an encoded slash, question mark and hash' => ['https://example.com/a%2fb?c=%3f%23#%2f', 'https://example.com/a%2Fb?c=%3F%23#%2F'],
            // Percent-encoded, a control character is unambiguous data, not a control character.
            'an encoded NUL' => ['/a%00b', '/a%00b'],
            'an encoded line break' => ['https://example.com/?q=%0a', 'https://example.com/?q=%0A'],
            'an encoded DEL' => ['/a%7f', '/a%7F'],
            'an encoded C1 control character' => ['/a%c2%85', '/a%C2%85'],
            'a Unicode space, which is text' => ["/a\u{3000}b", '/a%E3%80%80b'],
            'a line separator, which is text' => ["/a\u{2028}b", '/a%E2%80%A8b'],
            'a no-break space, which is text' => ["/a\u{A0}b", '/a%C2%A0b'],
            // RFC 5952, written the same way on every server.
            'an IPv6 address with two equal zero runs' => ['http://[1:0:0:2:0:0:3:4]/', 'http://[1::2:0:0:3:4]/'],
            'an IPv6 address with a longer second zero run' => ['http://[1:0:0:2:0:0:0:3]/', 'http://[1:0:0:2::3]/'],
            'an IPv6 address with one zero group' => ['http://[2001:db8:0:1:1:1:1:1]/', 'http://[2001:db8:0:1:1:1:1:1]/'],
            'an IPv6 address with leading zeros' => ['http://[2001:0DB8:0000:0000:0000:0000:0000:0001]/', 'http://[2001:db8::1]/'],
            'the unspecified IPv6 address' => ['http://[0:0:0:0:0:0:0:0]/', 'http://[::]/'],
            'an IPv6 address ending in zeros' => ['http://[2001:db8:0:0:0:0:0:0]/', 'http://[2001:db8::]/'],
            'a deprecated IPv4-compatible IPv6 address' => ['http://[::1.2.3.4]/', 'http://[::102:304]/'],
        ];
    }

    #[DataProvider('equivalentUrls')]
    public function testEquivalentUrlsCanonicalizeToOneForm(string $url, string $canonical): void
    {
        self::assertSame($canonical, CanonicalUrl::parse($url)->toString());
        // Canonicalizing is idempotent: the canonical form is its own canonical form.
        self::assertSame($canonical, CanonicalUrl::parse($canonical)->toString());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function differentUrls(): array
    {
        return [
            'path case' => ['https://example.com/About', 'https://example.com/about'],
            'a trailing slash' => ['https://example.com/about', 'https://example.com/about/'],
            'query order' => ['https://example.com/?a=1&b=2', 'https://example.com/?b=2&a=1'],
            'an empty query' => ['https://example.com/?', 'https://example.com/'],
            'a plus and an encoded space' => ['https://example.com/?q=a+b', 'https://example.com/?q=a%20b'],
            'an encoded and a literal slash' => ['https://example.com/a%2Fb', 'https://example.com/a/b'],
            'a non-default port' => ['https://example.com:8443/', 'https://example.com/'],
            'the scheme' => ['http://example.com/', 'https://example.com/'],
            'a trailing dot in the host' => ['https://example.com./', 'https://example.com/'],
            'repeated slashes' => ['https://example.com/a//b', 'https://example.com/a/b'],
        ];
    }

    #[DataProvider('differentUrls')]
    public function testUrlsAServerMayTreatDifferentlyStayDifferent(string $a, string $b): void
    {
        self::assertNotSame(CanonicalUrl::parse($a)->toString(), CanonicalUrl::parse($b)->toString());
        self::assertNotSame(CanonicalUrl::parse($a)->healthUrlHash(), CanonicalUrl::parse($b)->healthUrlHash());
    }

    /**
     * @return array<string, array{string, string}> Each URL and the reason it must be refused for.
     */
    public static function refusedUrls(): array
    {
        return [
            'empty' => ['   ', 'non-empty UTF-8'],
            'another scheme' => ['ftp://example.com/', 'Only http and https'],
            'a mailto URL' => ['mailto:hello@example.com', 'Only http and https'],
            'a javascript URL' => ['javascript:alert(1)', 'Only http and https'],
            'a data URL' => ['data:text/html,hello', 'Only http and https'],
            'a file URL' => ['file:///etc/passwd', 'Only http and https'],
            'a tel URL' => ['tel:+441234567890', 'Only http and https'],
            'an empty password' => ['https://user:@example.com/', 'credentials'],
            'an empty user' => ['https://@example.com/', 'credentials'],
            'protocol-relative' => ['//example.com/', 'root-relative'],
            'path-relative' => ['about/us', 'root-relative'],
            'an anchor alone' => ['#top', 'root-relative'],
            'no host' => ['https:///path', 'needs a host'],
            'credentials' => ['https://user:secret@example.com/', 'credentials'],
            'a backslash' => ['https://example.com\\@evil.test/', 'control characters or backslashes'],
            'a control character' => ["https://example.com/a\tb", 'control characters or backslashes'],
            'a line break inside' => ["https://example.com/a\nb", 'control characters or backslashes'],
            'a NUL inside' => ["/a\x00b", 'control characters or backslashes'],
            'DEL' => ["https://example.com/a\x7Fb", 'control characters or backslashes'],
            'a C1 control character' => ["https://example.com/a\u{85}b", 'control characters or backslashes'],
            'the first C1 control character' => ["/a\u{80}", 'control characters or backslashes'],
            'the last C1 control character' => ["https://example.com/?q=\u{9F}", 'control characters or backslashes'],
            'a C1 control character in the host' => ["https://exa\u{85}mple.com/", 'control characters or backslashes'],
            'a protocol-relative URL without a path' => ['//example.com', 'root-relative'],
            'malformed percent-encoding' => ['https://example.com/%zz', 'malformed percent-encoding'],
            'a truncated percent-encoding' => ['https://example.com/%4', 'malformed percent-encoding'],
            'port zero' => ['https://example.com:0/', 'port is not valid'],
            'a port out of range' => ['https://example.com:65536/', 'port is not valid'],
            'a percent-encoded host' => ['https://ex%61mple.com/', 'host is not valid'],
            'an invalid IPv6 host' => ['https://[not-an-ip]/', 'IPv6 address'],
            'an empty host label' => ['https://example..com/', 'valid domain name'],
            'invalid UTF-8' => ["https://example.com/\xFF", 'non-empty UTF-8'],
            'a shortened IPv4 address' => ['http://127.1/', 'IPv4 host'],
            'a hexadecimal IPv4 address' => ['http://0x7f.0.0.1/', 'IPv4 host'],
            'a single-number IPv4 address' => ['http://2130706433/', 'IPv4 host'],
            'an IPv4 address with leading zeros' => ['http://010.0.0.1/', 'IPv4 host'],
            'a leading zero in the first octet' => ['http://01.2.3.4/', 'IPv4 host'],
            'leading zeros in every octet' => ['http://001.002.003.004/', 'IPv4 host'],
            'an octet out of range' => ['http://1.2.3.999/', 'IPv4 host'],
            'five octets' => ['http://1.2.3.4.5/', 'IPv4 host'],
            'a name ending in a hexadecimal label' => ['http://example.0x/', 'IPv4 host'],
            'a name ending in a numeric label' => ['http://example.123/', 'IPv4 host'],
            'a negative port' => ['https://example.com:-1/', 'port is not valid'],
            'a port that is not a number' => ['https://example.com:8a/', 'port is not valid'],
            'two ports' => ['https://example.com:1:2/', 'port is not valid'],
            'a label ending in a hyphen' => ['https://a-.example/', 'valid domain name'],
            'a host over 253 characters' => ['https://' . str_repeat('a.', 127) . 'aa/', 'valid domain name'],
            'an IPv4 address with a trailing dot' => ['http://1.2.3.4./', 'IPv4 host'],
            'an IPv4 address out of range' => ['http://256.1.1.1/', 'IPv4 host'],
            'an IPv6 zone' => ['http://[fe80::1%25eth0]/', 'IPv6 address'],
            'malformed punycode' => ['https://xn--zz.example/', 'valid domain name'],
            'a label starting with a hyphen' => ['https://-a.example/', 'valid domain name'],
            'an overlong label' => ['https://' . str_repeat('a', 64) . '.example/', 'valid domain name'],
            'a character DNS names cannot contain' => ['https://ex!ample.com/', 'valid domain name'],
            'a space in the host' => ['https://exa mple.com/', 'valid domain name'],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function testAUrlThatCannotBeCanonicalizedIsRefusedRatherThanGuessed(string $url, string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        CanonicalUrl::parse($url);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rootRelativeUrls(): array
    {
        return array_map(static fn(string $url): array => [$url], [
            'a dot segment' => '/.//evil.com',
            'a parent segment' => '/a/..//evil.com',
            'an encoded dot segment' => '/%2e//evil.com',
            'an encoded parent segment' => '/%2e%2e//evil.com',
            'parent segments beyond the root' => '/../..//evil.com/x',
            'an ordinary path' => '/a//b',
        ]);
    }

    #[DataProvider('rootRelativeUrls')]
    public function testARootRelativeUrlNeverComesToNameAHost(string $url): void
    {
        $canonical = CanonicalUrl::parse($url);

        // A browser resolves it on the page's own host, and so does its canonical form.
        self::assertFalse($canonical->isAbsolute());
        self::assertStringStartsNotWith('//', $canonical->toString());
        self::assertNull(parse_url('https://site.example' . $canonical->toString(), PHP_URL_PORT));
        self::assertSame('site.example', parse_url('https://site.example' . $canonical->toString(), PHP_URL_HOST));
        self::assertSame($canonical->toString(), CanonicalUrl::parse($canonical->toString())->toString());

        // Its identity is a path in a site, never the same target as a URL on another host.
        $identity = TargetIdentity::forUrl('url', $canonical, 1);
        self::assertStringStartsWith('url?siteId=1&url=%2F', $identity->key());
        self::assertStringStartsNotWith('url?siteId=1&url=%2F%2F', $identity->key());
        self::assertNotSame(TargetIdentity::forUrl('url', CanonicalUrl::parse('https://evil.com/'), null)->key(), $identity->key());
    }

    public function testTheHealthUrlIsTheCanonicalUrlWithoutItsFragment(): void
    {
        // A fragment never reaches the server, so it cannot change what a check sees. It still
        // tells two link targets apart.
        $url = CanonicalUrl::parse('https://Example.com/a?b=1#Section');

        self::assertSame('https://example.com/a?b=1#Section', $url->toString());
        self::assertSame('https://example.com/a?b=1', $url->healthUrl());
        self::assertSame(hash('sha256', 'https://example.com/a?b=1'), $url->healthUrlHash());
        self::assertSame($url->healthUrlHash(), CanonicalUrl::parse('https://example.com/a?b=1#other')->healthUrlHash());
    }

    public function testARootRelativeUrlHasNoHealthUrlUntilItIsResolved(): void
    {
        $url = CanonicalUrl::parse('/about');

        self::assertFalse($url->isAbsolute());
        self::assertNull($url->healthUrl());
        self::assertNull($url->healthUrlHash());
    }

    // Health observations

    /**
     * Every state paired with no failure and with each failure, and whether the pair is valid.
     *
     * @return array<string, array{HealthState, HealthFailure|null, bool}>
     */
    public static function stateAndFailureCombinations(): array
    {
        $combinations = [];

        foreach (HealthState::cases() as $state) {
            $combinations["{$state->value} with a response"] = [$state, null, true];

            foreach (HealthFailure::cases() as $failure) {
                $combinations["{$state->value} with {$failure->value}"] = [$state, $failure, $failure->state() === $state];
            }
        }

        return $combinations;
    }

    #[DataProvider('stateAndFailureCombinations')]
    public function testAnObservationOnlyClaimsWhatItsEvidenceSupports(HealthState $state, ?HealthFailure $failure, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('failed with');
        }

        $observation = new HealthObservation(
            state: $state,
            finalUrl: 'https://example.com/',
            dateChecked: new DateTimeImmutable(),
            statusCode: $failure === null ? self::statusFor($state) : null,
            failure: $failure,
            redirects: $state === HealthState::REDIRECT || $failure === HealthFailure::TOO_MANY_REDIRECTS
                ? [['url' => 'https://example.com/old', 'statusCode' => 301]]
                : [],
        );

        self::assertSame($state, $observation->state);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function unsupportedObservations(): array
    {
        $hop = ['url' => 'https://example.com/old', 'statusCode' => 301];

        return [
            'neither a status code nor a failure' => [['state' => HealthState::UNKNOWN], 'either a final status code or a failure'],
            'both a status code and a failure' => [['state' => HealthState::UNAVAILABLE, 'statusCode' => 503, 'failure' => HealthFailure::TIMEOUT], 'either a final status code or a failure'],
            'a status code below 100' => [['state' => HealthState::UNKNOWN, 'statusCode' => 99], 'not an HTTP status code'],
            'a status code above 599' => [['state' => HealthState::UNKNOWN, 'statusCode' => 600], 'not an HTTP status code'],
            'a timeout that is not unavailable' => [['state' => HealthState::BROKEN, 'failure' => HealthFailure::TIMEOUT], 'failed with'],
            'a DNS failure that is not unavailable' => [['state' => HealthState::UNKNOWN, 'failure' => HealthFailure::DNS_FAILED], 'failed with'],
            'a refusal that claims unavailability' => [['state' => HealthState::UNAVAILABLE, 'failure' => HealthFailure::REFUSED_BY_POLICY], 'failed with'],
            'healthy after a redirect' => [['state' => HealthState::HEALTHY, 'statusCode' => 200, 'redirects' => [$hop]], 'is a redirect, not healthy'],
            'a redirect without redirects' => [['state' => HealthState::REDIRECT, 'statusCode' => 200], 'needs the redirects'],
            'too many redirects without redirects' => [['state' => HealthState::UNKNOWN, 'failure' => HealthFailure::TOO_MANY_REDIRECTS], 'needs the redirects'],
            'a redirect hop that is not a redirect' => [['state' => HealthState::REDIRECT, 'statusCode' => 200, 'redirects' => [['url' => 'https://example.com/old', 'statusCode' => 200]]], 'Each redirect must be'],
            'a redirect hop without a URL' => [['state' => HealthState::REDIRECT, 'statusCode' => 200, 'redirects' => [['statusCode' => 301]]], 'Each redirect must be'],
            'a redirect hop with an empty URL' => [['state' => HealthState::REDIRECT, 'statusCode' => 200, 'redirects' => [['url' => '', 'statusCode' => 301]]], 'Each redirect must be'],
            'a redirect hop with a string status' => [['state' => HealthState::REDIRECT, 'statusCode' => 200, 'redirects' => [['url' => 'https://example.com/old', 'statusCode' => '301']]], 'Each redirect must be'],
            'a redirect hop with extra data' => [['state' => HealthState::REDIRECT, 'statusCode' => 200, 'redirects' => [$hop + ['body' => '…']]], 'Each redirect must be'],
            'a redirect hop that is not a list entry' => [['state' => HealthState::REDIRECT, 'statusCode' => 200, 'redirects' => ['https://example.com/old']], 'Each redirect must be'],
            'no URL' => [['state' => HealthState::HEALTHY, 'statusCode' => 200, 'finalUrl' => ''], 'final URL must be'],
            'a final URL that is not canonical' => [['state' => HealthState::HEALTHY, 'statusCode' => 200, 'finalUrl' => 'HTTPS://Example.com/'], 'final URL must be'],
            'a final URL with a fragment' => [['state' => HealthState::HEALTHY, 'statusCode' => 200, 'finalUrl' => 'https://example.com/#top'], 'final URL must be'],
            'a relative final URL' => [['state' => HealthState::HEALTHY, 'statusCode' => 200, 'finalUrl' => '/about'], 'final URL must be'],
            'a final URL that could never be checked' => [['state' => HealthState::UNKNOWN, 'failure' => HealthFailure::REFUSED_BY_POLICY, 'finalUrl' => 'ftp://example.com/'], 'final URL must be'],
            'redirects keyed rather than listed' => [['state' => HealthState::REDIRECT, 'statusCode' => 200, 'redirects' => ['first' => $hop]], 'must be a list'],
            'redirects out of order' => [['state' => HealthState::REDIRECT, 'statusCode' => 200, 'redirects' => [1 => $hop, 0 => $hop]], 'must be a list'],
            'a redirect hop below 300' => [['state' => HealthState::REDIRECT, 'statusCode' => 200, 'redirects' => [['url' => 'https://example.com/old', 'statusCode' => 299]]], 'Each redirect must be'],
            'a redirect hop above 399' => [['state' => HealthState::REDIRECT, 'statusCode' => 200, 'redirects' => [['url' => 'https://example.com/old', 'statusCode' => 400]]], 'Each redirect must be'],
            'a redirect hop that is not canonical' => [['state' => HealthState::REDIRECT, 'statusCode' => 200, 'redirects' => [['url' => 'https://example.com:443/old', 'statusCode' => 301]]], 'Each redirect must be'],
        ];
    }

    /**
     * @return array<string, array{int, int}> A final status code and a redirect status at the
     * edges of their ranges.
     */
    public static function boundaryStatusCodes(): array
    {
        return [
            'the lowest status code and redirect status' => [100, 300],
            'the highest status code and redirect status' => [599, 399],
        ];
    }

    #[DataProvider('boundaryStatusCodes')]
    public function testStatusCodesAreAcceptedUpToTheEdgesOfTheirRanges(int $statusCode, int $redirectStatus): void
    {
        $hops = [['url' => 'https://example.com/old', 'statusCode' => $redirectStatus], ['url' => 'https://example.com/older', 'statusCode' => 308]];
        $observation = new HealthObservation(HealthState::UNKNOWN, 'https://example.com/', new DateTimeImmutable(), $statusCode, redirects: $hops);

        self::assertSame($statusCode, $observation->statusCode);
        // The redirects that explain it are kept, in the order they were followed.
        self::assertSame($hops, $observation->redirects);
    }

    public function testWhichStateAResponseDeservesIsLeftToTheClassificationPolicy(): void
    {
        // An observation records evidence; it does not classify it. A 403 may be judged Blocked
        // or Broken by the health checker's policy, and either is a consistent observation.
        foreach ([HealthState::BLOCKED, HealthState::BROKEN, HealthState::UNKNOWN] as $state) {
            $observation = new HealthObservation($state, 'https://example.com/', new DateTimeImmutable(), 403);

            self::assertSame(403, $observation->statusCode);
            self::assertNull($observation->failure);
        }

        // So is a redirect that ends in a missing page, whether it is judged Redirect or Broken:
        // the observation only guarantees the evidence behind the chosen state is complete.
        $hops = [['url' => 'https://example.com/old', 'statusCode' => 301]];

        foreach ([HealthState::REDIRECT, HealthState::BROKEN] as $state) {
            $observation = new HealthObservation($state, 'https://example.com/new', new DateTimeImmutable(), 404, redirects: $hops);

            self::assertSame($state, $observation->state);
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('unsupportedObservations')]
    public function testAnObservationTheEvidenceDoesNotSupportIsRefused(array $arguments, string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        new HealthObservation(...$arguments + ['finalUrl' => 'https://example.com/', 'dateChecked' => new DateTimeImmutable()]);
    }

    public function testAnObservationCannotBeChangedOnceMade(): void
    {
        $observation = new HealthObservation(HealthState::HEALTHY, 'https://example.com/', new DateTimeImmutable(), 200);

        $this->expectException(\Error::class);

        /** @phpstan-ignore property.readOnlyAssignOutOfClass (Proving the property is read-only is the point.) */
        $observation->state = HealthState::BROKEN;
    }

    private static function statusFor(HealthState $state): int
    {
        return match ($state) {
            HealthState::HEALTHY, HealthState::REDIRECT => 200,
            HealthState::BROKEN => 404,
            HealthState::UNAVAILABLE => 503,
            HealthState::BLOCKED => 403,
            HealthState::UNKNOWN => 418,
        };
    }
}
