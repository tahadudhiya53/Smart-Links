<?php

namespace Tahadudhiya\SmartLinks\Tests\unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SmartLinks\enums\LinkFeature;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\errors\LinkResolutionException;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\links\LinkNormalizer;
use Tahadudhiya\SmartLinks\links\LinkRenderer;
use Tahadudhiya\SmartLinks\links\LinkResolver;
use Tahadudhiya\SmartLinks\links\LinkSerializer;
use Tahadudhiya\SmartLinks\links\LinkValidator;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeSet;
use Tahadudhiya\SmartLinks\models\CanonicalUrl;
use Tahadudhiya\SmartLinks\models\LinkAttributes;
use Tahadudhiya\SmartLinks\models\LinkCollection;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ResolvedLink;
use Tahadudhiya\SmartLinks\models\TargetIdentity;
use Tahadudhiya\SmartLinks\models\ValidationError;
use Tahadudhiya\SmartLinks\Tests\_support\linktypes\FakeEmailData;
use Tahadudhiya\SmartLinks\Tests\_support\linktypes\FakeEmailType;
use Tahadudhiya\SmartLinks\Tests\_support\linktypes\FakeEntryData;
use Tahadudhiya\SmartLinks\Tests\_support\linktypes\FakeEntryType;
use Tahadudhiya\SmartLinks\Tests\_support\linktypes\FakeResolver;
use Tahadudhiya\SmartLinks\Tests\_support\linktypes\FakeTypes;
use Tahadudhiya\SmartLinks\Tests\_support\linktypes\FakeUrlData;
use Tahadudhiya\SmartLinks\Tests\_support\linktypes\FakeUrlType;

/**
 * The link core, stage by stage: what an author enters is normalized into a link value, validated,
 * stored and read back exactly, resolved by its type, and rendered. Link types stand in as test
 * types with their own typed data, because the core must work for any type, not a known list.
 */
class LinkCoreTest extends TestCase
{
    private const UID = '3eb992ad-c86b-4d28-abd3-1e09a1b401ff';
    private const OTHER_UID = '9b2f0c4e-1d3a-4e5b-8c7d-6a5f4e3d2c1b';
    private const PRESET_UID = 'c7a3e2f1-5b4d-4a6c-9e8f-0d1c2b3a4f5e';

    private static function validator(): LinkValidator
    {
        return new LinkValidator(FakeTypes::set());
    }

    private static function normalizer(): LinkNormalizer
    {
        return new LinkNormalizer(FakeTypes::set(), self::validator());
    }

    private static function serializer(): LinkSerializer
    {
        return new LinkSerializer(FakeTypes::set(), self::validator());
    }

    private static function renderer(): LinkRenderer
    {
        return new LinkRenderer(self::validator());
    }

    private static function url(string $url): FakeUrlData
    {
        return new FakeUrlData(CanonicalUrl::parse($url));
    }

    /**
     * @param list<ValidationError> $errors
     * @return list<array{string, Code}>
     */
    private static function summary(array $errors): array
    {
        return array_map(static fn(ValidationError $error): array => [$error->path, $error->code], $errors);
    }

    // Normalization: what an author entered, into a link value

    public function testAnAuthoredLinkIsNormalizedIntoTypedData(): void
    {
        $result = self::normalizer()->normalizeLink([
            'type' => 'url',
            'data' => ['url' => 'HTTPS://Example.com/about'],
            'label' => 'About us',
            'attributes' => [
                'target' => '_blank',
                'rel' => 'NoFollow  sponsored',
                'class' => 'btn btn-primary',
                'download' => '1',
                'custom' => ['data-track' => 'cta'],
            ],
        ]);

        self::assertSame([], self::summary($result->errors));
        $link = $result->value;
        self::assertInstanceOf(LinkValue::class, $link);
        self::assertInstanceOf(FakeUrlData::class, $link->data);
        self::assertSame('https://example.com/about', $link->data->url->toString());
        self::assertSame('About us', $link->label);
        // Token lists split as HTML splits them; rel keywords compare case-insensitively.
        self::assertSame(['nofollow', 'sponsored'], $link->attributes->rel);
        self::assertSame(['btn', 'btn-primary'], $link->attributes->class);
        self::assertTrue($link->attributes->download);
        self::assertSame(['data-track' => 'cta'], $link->attributes->custom);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $link->uid);
    }

    public function testAnEmptyFieldMeansNotSet(): void
    {
        $link = self::normalizer()->normalizeLink([
            'type' => 'url',
            'data' => ['url' => 'https://example.com/'],
            'label' => '',
            'urlSuffix' => '',
            'presetUid' => '',
            'attributes' => ['target' => '', 'rel' => '', 'title' => '', 'class' => [], 'download' => '0', 'custom' => []],
        ])->value;

        self::assertInstanceOf(LinkValue::class, $link);
        self::assertNull($link->label);
        self::assertNull($link->urlSuffix);
        self::assertTrue($link->attributes->isEmpty());
    }

    public function testAnExistingLinkKeepsItsUid(): void
    {
        $link = self::normalizer()->normalizeLink(['uid' => self::UID, 'type' => 'email', 'data' => ['address' => 'hello@example.com']])->value;

        self::assertInstanceOf(LinkValue::class, $link);
        self::assertSame(self::UID, $link->uid);
    }

    public function testNothingEnteredIsAnEmptyValueNotAnError(): void
    {
        foreach ([null, '', []] as $nothing) {
            $value = self::normalizer()->normalize($nothing);
            self::assertTrue($value->isValid());
            self::assertInstanceOf(LinkCollection::class, $value->value);
            self::assertTrue($value->value->isEmpty());

            $link = self::normalizer()->normalizeLink($nothing);
            self::assertTrue($link->isValid());
            self::assertNull($link->value);
        }
    }

    public function testAnEmptyEntryInAListIsReportedNotSkipped(): void
    {
        $result = self::normalizer()->normalize([
            ['type' => 'email', 'data' => ['address' => 'a@example.com']],
            [],
        ]);

        self::assertNull($result->value);
        self::assertSame([['links[1]', Code::MISSING]], self::summary($result->errors));
    }

    public function testEachTypeNormalizesItsOwnData(): void
    {
        $value = self::normalizer()->normalize([
            ['type' => 'entry', 'data' => ['elementId' => '12', 'siteId' => '2']],
            ['type' => 'email', 'data' => ['address' => 'hello@example.com']],
        ])->value;

        self::assertInstanceOf(LinkCollection::class, $value);
        self::assertEquals(new FakeEntryData(12, 2), $value->links[0]->data);
        self::assertEquals(new FakeEmailData('hello@example.com'), $value->links[1]->data);
    }

    public function testAFeatureTheTypeDoesNotSupportIsRefused(): void
    {
        // Opening a mail client has no target, rel, download or URL suffix.
        $result = self::normalizer()->normalizeLink([
            'type' => 'email',
            'data' => ['address' => 'hello@example.com'],
            'urlSuffix' => '?subject=Hi',
            'attributes' => ['target' => '_blank', 'rel' => 'noopener', 'download' => true, 'title' => 'Email us'],
        ]);

        self::assertSame([
            ['urlSuffix', Code::NOT_SUPPORTED],
            ['attributes.target', Code::NOT_SUPPORTED],
            ['attributes.rel', Code::NOT_SUPPORTED],
            ['attributes.download', Code::NOT_SUPPORTED],
        ], self::summary($result->errors));
    }

    public function testUnknownKeysAreReportedAtEveryLevelRatherThanDropped(): void
    {
        // Externality comes from the resolver, so an author's claim to it is reported too.
        $result = self::normalizer()->normalizeLink([
            'type' => 'url',
            'external' => true,
            'href' => 'https://example.com/',
            'data' => ['url' => 'https://example.com/', 'external' => true],
            'attributes' => ['onclick' => 'alert(1)', 'style' => 'color:red'],
        ]);

        self::assertSame([
            ['external', Code::UNKNOWN_KEY],
            ['href', Code::UNKNOWN_KEY],
            ['attributes.onclick', Code::UNKNOWN_KEY],
            ['attributes.style', Code::UNKNOWN_KEY],
            ['data.external', Code::UNKNOWN_KEY],
        ], self::summary($result->errors));
    }

    public function testEveryProblemIsReportedAtOnceInTheSameOrderEveryTime(): void
    {
        $input = [
            [
                'uid' => 'ABC',
                'type' => 'url',
                'data' => ['url' => 'ftp://example.com/'],
                'label' => "Line\nbreak",
                'urlSuffix' => 'utm=1',
                'attributes' => [
                    'target' => '_new',
                    'rel' => 'no/opener',
                    'class' => 'btn btn',
                    'id' => 'two words',
                    'downloadFilename' => '../secret.pdf',
                    'custom' => ['onclick' => 'x', 'data-ok' => "a\x07"],
                ],
                'presetUid' => 'primary',
            ],
        ];

        $expected = [
            ['links[0].data.url', Code::INVALID],
            ['links[0].uid', Code::INVALID],
            ['links[0].label', Code::INVALID],
            ['links[0].urlSuffix', Code::INVALID],
            ['links[0].attributes.target', Code::INVALID],
            ['links[0].attributes.rel[0]', Code::INVALID],
            ['links[0].attributes.class[1]', Code::DUPLICATE],
            ['links[0].attributes.id', Code::INVALID],
            ['links[0].attributes.downloadFilename', Code::INVALID],
            ['links[0].attributes.downloadFilename', Code::INVALID],
            ['links[0].attributes.custom.onclick', Code::INVALID],
            ['links[0].attributes.custom.data-ok', Code::INVALID],
            ['links[0].presetUid', Code::INVALID],
        ];

        $first = self::normalizer()->normalize($input);
        self::assertNull($first->value);
        self::assertSame($expected, self::summary($first->errors));
        self::assertEquals($first->errors, self::normalizer()->normalize($input)->errors);
    }

    /**
     * @return array<string, array{mixed, list<array{string, Code}>}>
     */
    public static function wrongKinds(): array
    {
        return [
            'a value that is not a list' => [['type' => 'url'], [['', Code::WRONG_TYPE]]],
            'a link that is a list' => [[['url']], [['links[0]', Code::WRONG_TYPE]]],
            'no type' => [[['data' => ['url' => 'https://example.com/']]], [['links[0].type', Code::MISSING]]],
            'a type that is not text' => [[['type' => 5]], [['links[0].type', Code::WRONG_TYPE]]],
            'a malformed type' => [[['type' => 'Url']], [['links[0].type', Code::INVALID]]],
            'an unavailable type' => [[['type' => 'phone']], [['links[0].type', Code::UNKNOWN_LINK_TYPE]]],
            'a label that is not text' => [[['type' => 'email', 'data' => ['address' => 'a@b.c'], 'label' => 5]], [['links[0].label', Code::WRONG_TYPE]]],
            'data that is a list' => [[['type' => 'email', 'data' => ['a@b.c']]], [['links[0].data', Code::WRONG_TYPE]]],
            'attributes that are a list' => [[['type' => 'email', 'data' => ['address' => 'a@b.c'], 'attributes' => ['x']]], [['links[0].attributes', Code::WRONG_TYPE]]],
            'download that is not on or off' => [[['type' => 'url', 'data' => ['url' => 'https://a.example/'], 'attributes' => ['download' => 'yes']]], [['links[0].attributes.download', Code::WRONG_TYPE]]],
            'rel that is not words' => [[['type' => 'url', 'data' => ['url' => 'https://a.example/'], 'attributes' => ['rel' => [1]]]], [['links[0].attributes.rel', Code::WRONG_TYPE]]],
            'custom attributes that are not an object or rows' => [[['type' => 'url', 'data' => ['url' => 'https://a.example/'], 'attributes' => ['custom' => 'data-a']]], [['links[0].attributes.custom', Code::WRONG_TYPE]]],
            'a custom attribute row that is not a row' => [[['type' => 'url', 'data' => ['url' => 'https://a.example/'], 'attributes' => ['custom' => ['x']]]], [['links[0].attributes.custom[0]', Code::WRONG_TYPE]]],
            'a custom attribute row without a name' => [[['type' => 'url', 'data' => ['url' => 'https://a.example/'], 'attributes' => ['custom' => [['value' => 'x']]]]], [['links[0].attributes.custom[0].name', Code::MISSING]]],
            'a custom attribute row with an unknown key' => [[['type' => 'url', 'data' => ['url' => 'https://a.example/'], 'attributes' => ['custom' => [['name' => 'data-a', 'value' => 'b', 'onclick' => 'x']]]]], [['links[0].attributes.custom[0].onclick', Code::UNKNOWN_KEY]]],
            'a custom attribute row value that is not text' => [[['type' => 'url', 'data' => ['url' => 'https://a.example/'], 'attributes' => ['custom' => [['name' => 'data-a', 'value' => ['b']]]]]], [['links[0].attributes.custom[0].value', Code::WRONG_TYPE]]],
            'a custom attribute name given twice' => [[['type' => 'url', 'data' => ['url' => 'https://a.example/'], 'attributes' => ['custom' => [['name' => 'data-a', 'value' => '1'], ['name' => 'data-a', 'value' => '2']]]]], [['links[0].attributes.custom[1].name', Code::DUPLICATE]]],
            'a custom value that is not text' => [[['type' => 'url', 'data' => ['url' => 'https://a.example/'], 'attributes' => ['custom' => ['data-n' => 1]]]], [['links[0].attributes.custom.data-n', Code::WRONG_TYPE]]],
            'a repeated UID' => [[
                ['uid' => self::UID, 'type' => 'email', 'data' => ['address' => 'a@b.c']],
                ['uid' => self::UID, 'type' => 'email', 'data' => ['address' => 'd@e.f']],
            ], [['links[1].uid', Code::DUPLICATE]]],
            'data the type refuses' => [[['type' => 'entry', 'data' => ['elementId' => 'abc']]], [['links[0].data.elementId', Code::INVALID], ['links[0].data.siteId', Code::MISSING]]],
        ];
    }

    /**
     * @param list<array{string, Code}> $expected
     */
    #[DataProvider('wrongKinds')]
    public function testMalformedInputGetsAnErrorAtItsPath(mixed $input, array $expected): void
    {
        $result = self::normalizer()->normalize($input);

        self::assertNull($result->value);
        self::assertSame($expected, self::summary($result->errors));
    }

    public function testAMissingTypeDoesNotHideTheLinksOtherProblems(): void
    {
        $result = self::normalizer()->normalizeLink(['label' => "a\x07", 'attributes' => ['target' => '_new']]);

        self::assertSame([['type', Code::MISSING], ['label', Code::INVALID], ['attributes.target', Code::INVALID]], self::summary($result->errors));
    }

    public function testCustomAttributeRowsAreTheSameAttributesInTheSameOrder(): void
    {
        // A form posts custom attributes as name/value rows; they mean exactly the map.
        $rows = self::normalizer()->normalizeLink(['type' => 'url', 'data' => ['url' => 'https://a.example/'], 'attributes' => ['custom' => [
            ['name' => 'data-b', 'value' => '2'],
            ['name' => 'aria-hidden', 'value' => ''],
            ['name' => 'data-a', 'value' => '1'],
        ]]])->value;
        $map = self::normalizer()->normalizeLink(['type' => 'url', 'data' => ['url' => 'https://a.example/'], 'attributes' => ['custom' => ['data-b' => '2', 'aria-hidden' => '', 'data-a' => '1']]])->value;

        self::assertInstanceOf(LinkValue::class, $rows);
        self::assertInstanceOf(LinkValue::class, $map);
        self::assertSame($map->attributes->custom, $rows->attributes->custom);
        self::assertSame(['data-b', 'aria-hidden', 'data-a'], array_keys($rows->attributes->custom));
    }

    public function testCustomAttributeRowsFollowTheSameNameRules(): void
    {
        $result = self::normalizer()->normalizeLink(['type' => 'url', 'data' => ['url' => 'https://a.example/'], 'attributes' => ['custom' => [['name' => 'onclick', 'value' => 'alert(1)']]]]);

        self::assertSame([['attributes.custom.onclick', Code::INVALID]], self::summary($result->errors));
    }

    public function testAllowedCustomAttributesAreKeptAsGiven(): void
    {
        $link = self::normalizer()->normalizeLink([
            'type' => 'url',
            'data' => ['url' => 'https://example.com/'],
            'attributes' => ['custom' => ['data-empty' => '', 'data-track.id' => 'x', 'aria-describedby' => 'note']],
        ])->value;

        self::assertInstanceOf(LinkValue::class, $link);
        // An empty data attribute is meaningful, so it is kept.
        self::assertSame(['data-empty' => '', 'data-track.id' => 'x', 'aria-describedby' => 'note'], $link->attributes->custom);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forbiddenCustomAttributes(): array
    {
        return [
            'an event handler' => ['onclick'],
            'a destination' => ['href'],
            'styles' => ['style'],
            'aria-label, which has its own property' => ['aria-label'],
            'an uppercase data attribute' => ['data-Track'],
            'a bare data prefix' => ['data-'],
            'a bare aria prefix' => ['aria-'],
        ];
    }

    #[DataProvider('forbiddenCustomAttributes')]
    public function testOnlyDataAndAriaCustomAttributesAreAllowed(string $name): void
    {
        $result = self::normalizer()->normalizeLink(['type' => 'url', 'data' => ['url' => 'https://example.com/'], 'attributes' => ['custom' => [$name => 'x']]]);

        self::assertSame([["attributes.custom.$name", Code::INVALID]], self::summary($result->errors));
    }

    // Validation of values built in code

    /**
     * A link with `$text` in one of the properties authored text is checked for, and the error
     * path that property reports at.
     *
     * @return array<string, array{\Closure(string): LinkValue, string}>
     */
    public static function authoredTextProperties(): array
    {
        $url = static fn(): FakeUrlData => new FakeUrlData(CanonicalUrl::parse('https://example.com/'));

        return [
            'label' => [static fn(string $text): LinkValue => new LinkValue(self::UID, 'url', $url(), label: $text), 'label'],
            'title' => [static fn(string $text): LinkValue => new LinkValue(self::UID, 'url', $url(), attributes: new LinkAttributes(title: $text)), 'attributes.title'],
            'ARIA label' => [static fn(string $text): LinkValue => new LinkValue(self::UID, 'url', $url(), attributes: new LinkAttributes(ariaLabel: $text)), 'attributes.ariaLabel'],
            'download filename' => [static fn(string $text): LinkValue => new LinkValue(self::UID, 'url', $url(), attributes: new LinkAttributes(download: true, downloadFilename: $text)), 'attributes.downloadFilename'],
            'custom attribute value' => [static fn(string $text): LinkValue => new LinkValue(self::UID, 'url', $url(), attributes: new LinkAttributes(custom: ['data-note' => $text])), 'attributes.custom.data-note'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function controlCharacters(): array
    {
        return [
            'a C0 control (BEL)' => ["A\x07B"],
            'a tab' => ["A\tB"],
            'a newline' => ["A\nB"],
            'NUL' => ["A\x00B"],
            'DEL' => ["A\x7FB"],
            'the C1 control U+0085 (NEL)' => ["A\u{0085}B"],
            'the C1 control U+0080' => ["A\u{0080}B"],
            'the C1 control U+009F' => ["A\u{009F}B"],
        ];
    }

    /**
     * @return array<string, array{\Closure(string): LinkValue, string, string}>
     */
    public static function authoredTextWithControlCharacters(): array
    {
        $cases = [];

        foreach (self::authoredTextProperties() as $property => [$build, $path]) {
            foreach (self::controlCharacters() as $character => [$text]) {
                $cases["$property with $character"] = [$build, $path, $text];
            }
        }

        return $cases;
    }

    /**
     * @param \Closure(string): LinkValue $build
     */
    #[DataProvider('authoredTextWithControlCharacters')]
    public function testAuthoredTextRefusesEveryControlCharacter(\Closure $build, string $path, string $text): void
    {
        self::assertSame([[$path, Code::INVALID]], self::summary(self::validator()->validateLink($build($text))));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validUnicodeText(): array
    {
        return [
            'ASCII with a space' => ['About us'],
            'Latin accents' => ['Café'],
            'an umlaut' => ['Über'],
            'Japanese' => ['東京'],
            'Arabic' => ['مرحبا'],
            'an ideographic space (U+3000)' => ["東京\u{3000}駅"],
            'a no-break space (U+00A0)' => ["About\u{00A0}us"],
            'a thin space (U+2009)' => ["10\u{2009}km"],
        ];
    }

    #[DataProvider('validUnicodeText')]
    public function testAuthoredTextAcceptsUnicodeLettersAndSpaces(string $text): void
    {
        foreach (self::authoredTextProperties() as $property => [$build]) {
            self::assertSame([], self::validator()->validateLink($build($text)), $property);
        }
    }

    public function testAuthoredTextAndAResolvedDefaultLabelAgreeOnEveryCharacter(): void
    {
        // What an author may write as a label, a resolver may return as a default label, and the
        // other way round: the two rules differ in nothing.
        $samples = array_merge(array_column(self::controlCharacters(), 0), array_column(self::validUnicodeText(), 0), ["A\u{2028}B"]);

        foreach ($samples as $text) {
            $authored = self::validator()->validateLink(new LinkValue(self::UID, 'url', self::url('https://example.com/'), label: $text)) === [];

            try {
                new ResolvedLink(ResolutionStatus::RESOLVED, '/about', defaultLabel: $text);
                $resolved = true;
            } catch (InvalidArgumentException) {
                $resolved = false;
            }

            self::assertSame($authored, $resolved, json_encode($text) ?: '');
        }
    }

    /**
     * A link with `$token` in one of the properties that are single tokens (no whitespace, no
     * control characters), and the error path that property reports at.
     *
     * @return array<string, array{\Closure(string): LinkValue, string}>
     */
    public static function tokenProperties(): array
    {
        $url = static fn(): FakeUrlData => new FakeUrlData(CanonicalUrl::parse('https://example.com/'));

        return [
            'target' => [static fn(string $token): LinkValue => new LinkValue(self::UID, 'url', $url(), attributes: new LinkAttributes(target: $token)), 'attributes.target'],
            'class' => [static fn(string $token): LinkValue => new LinkValue(self::UID, 'url', $url(), attributes: new LinkAttributes(class: [$token])), 'attributes.class[0]'],
            'id' => [static fn(string $token): LinkValue => new LinkValue(self::UID, 'url', $url(), attributes: new LinkAttributes(id: $token)), 'attributes.id'],
            'rel' => [static fn(string $token): LinkValue => new LinkValue(self::UID, 'url', $url(), attributes: new LinkAttributes(rel: [strtolower($token)])), 'attributes.rel[0]'],
            'URL suffix' => [static fn(string $token): LinkValue => new LinkValue(self::UID, 'url', $url(), urlSuffix: "#$token"), 'urlSuffix'],
        ];
    }

    /**
     * @return array<string, array{\Closure(string): LinkValue, string, string}>
     */
    public static function tokensWithControlCharacters(): array
    {
        $cases = [];

        foreach (self::tokenProperties() as $property => [$build, $path]) {
            foreach (self::controlCharacters() as $character => [$text]) {
                $cases["$property with $character"] = [$build, $path, $text];
            }
        }

        return $cases;
    }

    /**
     * @param \Closure(string): LinkValue $build
     */
    #[DataProvider('tokensWithControlCharacters')]
    public function testTokensRefuseEveryControlCharacter(\Closure $build, string $path, string $token): void
    {
        // The same characters as for text: C0, DEL and C1 alike.
        self::assertSame([[$path, Code::INVALID]], self::summary(self::validator()->validateLink($build($token))));
    }

    public function testTokensAcceptNonAsciiCharacters(): void
    {
        foreach (['target' => 'fenêtre', 'class' => 'überschrift', 'id' => 'été', 'URL suffix' => 'abschnitt-ä'] as $property => $token) {
            self::assertSame([], self::validator()->validateLink(self::tokenProperties()[$property][0]($token)), $property);
        }
    }

    public function testATargetWithAControlCharacterIsRefusedAtEveryStage(): void
    {
        // Authored, it is reported rather than stripped.
        $result = self::normalizer()->normalizeLink(['type' => 'url', 'data' => ['url' => 'https://example.com/'], 'attributes' => ['target' => "a\x01b"]]);
        self::assertNull($result->value);
        self::assertSame([['attributes.target', Code::INVALID]], self::summary($result->errors));

        // Stored, it cannot be read back as valid.
        try {
            self::serializer()->deserialize(['version' => 1, 'links' => [['uid' => self::UID, 'type' => 'url', 'data' => ['url' => 'https://example.com/'], 'attributes' => ['target' => "a\u{0080}b"]]]]);
            self::fail('A stored target with a control character was read.');
        } catch (LinkValidationException $exception) {
            self::assertSame([['links[0].attributes.target', Code::INVALID]], self::summary($exception->errors));
        }

        // Built in code, it is never rendered.
        $this->expectException(LinkValidationException::class);
        self::renderer()->render(new LinkValue(self::UID, 'url', self::url('https://example.com/'), attributes: new LinkAttributes(target: "a\x7Fb")), new ResolvedLink(ResolutionStatus::RESOLVED, 'https://example.com/'));
    }

    public function testAControlCharacterIsReportedNotStripped(): void
    {
        $result = self::normalizer()->normalizeLink(['type' => 'url', 'data' => ['url' => 'https://example.com/'], 'label' => "A\u{0085}B", 'attributes' => ['title' => "A\u{0085}B", 'ariaLabel' => "A\u{0085}B"]]);

        self::assertNull($result->value);
        self::assertSame([['label', Code::INVALID], ['attributes.title', Code::INVALID], ['attributes.ariaLabel', Code::INVALID]], self::summary($result->errors));
    }

    public function testDataOfTheWrongTypeIsRefused(): void
    {
        $errors = self::validator()->validateLink(new LinkValue(self::UID, 'url', new FakeEntryData(1, 1)));

        self::assertSame([['data', Code::WRONG_TYPE]], self::summary($errors));
    }

    public function testTheLinkTypeRuleIsTheOneTargetIdentitiesUse(): void
    {
        foreach (['url', 'commerce-product', 'Entry', 'entry?x', '', str_repeat('a', 64), str_repeat('a', 65)] as $handle) {
            try {
                TargetIdentity::create($handle, ['id' => 'x']);
                $identityAccepts = true;
            } catch (InvalidArgumentException) {
                $identityAccepts = false;
            }

            self::assertSame(LinkValidator::isTypeHandle($handle), $identityAccepts, $handle);
        }
    }

    public function testANewLinkGetsItsOwnUidAndTwoLinksToOneTargetAreTwoOccurrences(): void
    {
        $a = LinkValue::create('url', self::url('https://example.com/'));
        $b = LinkValue::create('url', self::url('https://example.com/'));

        self::assertNotSame($a->uid, $b->uid);
        self::assertSame([], self::validator()->validateCollection(new LinkCollection([$a, $b])));
    }

    public function testALinkReportsTheFeaturesItUses(): void
    {
        $link = new LinkValue(self::UID, 'url', self::url('https://example.com/'), urlSuffix: '#top', attributes: new LinkAttributes(title: 'Top', download: true));

        self::assertSame([LinkFeature::URL_SUFFIX, LinkFeature::TITLE, LinkFeature::DOWNLOAD], $link->usedFeatures());
    }

    // Serialization and deserialization

    private static function fullLink(): LinkValue
    {
        return new LinkValue(
            uid: self::UID,
            type: 'entry',
            data: new FakeEntryData(12, 1),
            label: 'About us',
            urlSuffix: '#team',
            attributes: new LinkAttributes(
                target: '_blank',
                rel: ['noopener', 'noreferrer'],
                title: 'Meet the team',
                class: ['btn', 'md:px-4'],
                id: 'about-link',
                ariaLabel: 'About our team',
                custom: ['data-track' => 'cta', 'aria-describedby' => 'team-note'],
            ),
            presetUid: self::PRESET_UID,
        );
    }

    public function testAValueHasOneCanonicalStoredForm(): void
    {
        self::assertSame([
            'version' => 1,
            'links' => [
                [
                    'uid' => self::UID,
                    'type' => 'entry',
                    'data' => ['elementId' => 12, 'siteId' => 1],
                    'label' => 'About us',
                    'urlSuffix' => '#team',
                    'attributes' => [
                        'target' => '_blank',
                        'rel' => ['noopener', 'noreferrer'],
                        'title' => 'Meet the team',
                        'class' => ['btn', 'md:px-4'],
                        'id' => 'about-link',
                        'ariaLabel' => 'About our team',
                        'custom' => [['name' => 'data-track', 'value' => 'cta'], ['name' => 'aria-describedby', 'value' => 'team-note']],
                    ],
                    'presetUid' => self::PRESET_UID,
                ],
                ['uid' => self::OTHER_UID, 'type' => 'url', 'data' => ['url' => 'https://example.com/']],
            ],
        ], self::serializer()->serialize(new LinkCollection([self::fullLink(), new LinkValue(self::OTHER_UID, 'url', self::url('https://example.com/'))])));
    }

    public function testAnEmptyValueIsStoredAsNothing(): void
    {
        self::assertNull(self::serializer()->serialize(new LinkCollection()));
        self::assertTrue(self::serializer()->deserialize(null)->isEmpty());
    }

    public function testAValueSurvivesPersistenceUnchanged(): void
    {
        $authored = self::normalizer()->normalize([
            ['type' => 'url', 'data' => ['url' => 'https://Example.com/a?b=1#c'], 'label' => 'A', 'attributes' => ['target' => '_blank', 'download' => true, 'downloadFilename' => 'a.pdf']],
            ['type' => 'entry', 'data' => ['elementId' => '5', 'siteId' => '2'], 'urlSuffix' => '?ref=nav'],
            ['type' => 'email', 'data' => ['address' => 'hello@example.com'], 'attributes' => ['custom' => ['data-x' => '']]],
        ])->value;
        self::assertInstanceOf(LinkCollection::class, $authored);

        $stored = self::serializer()->serialize($authored);
        // Through JSON, as Craft stores field content.
        $restored = self::serializer()->deserialize(json_decode(json_encode($stored, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));

        self::assertEquals($authored, $restored);
        self::assertSame($stored, self::serializer()->serialize($restored));
        self::assertSame(5, $restored->links[1]->data->toArray()['elementId']);
    }

    public function testReadingDoesNotDependOnTheOrderOfKeys(): void
    {
        $value = new LinkCollection([self::fullLink()]);
        $stored = self::serializer()->serialize($value);
        self::assertIsArray($stored);

        // What MySQL's JSON type hands back: every object's keys sorted, shortest first.
        $sort = static function(array $array) use (&$sort): array {
            $array = array_map(static fn(mixed $item): mixed => is_array($item) ? $sort($item) : $item, $array);

            if (!array_is_list($array)) {
                uksort($array, static fn(string $a, string $b): int => [strlen($a), $a] <=> [strlen($b), $b]);
            }

            return $array;
        };
        $reordered = $sort($stored);
        self::assertNotSame(array_keys($stored), array_keys($reordered));

        $restored = self::serializer()->deserialize($reordered);

        self::assertEquals($value, $restored);
        // Custom attributes are stored as a list, so their authored order is not lost with it.
        self::assertSame(['data-track', 'aria-describedby'], array_keys($restored->links[0]->attributes->custom));
        self::assertSame($stored, self::serializer()->serialize($restored));
    }

    public function testAnInvalidValueIsNeverStored(): void
    {
        $this->expectException(LinkValidationException::class);
        $this->expectExceptionMessage('links[0].attributes.download: “download” does not apply to email links.');

        self::serializer()->serialize(new LinkCollection([new LinkValue(self::UID, 'email', new FakeEmailData('a@b.c'), attributes: new LinkAttributes(download: true))]));
    }

    /**
     * @return array<string, array{mixed, list<array{string, Code}>}>
     */
    public static function malformedStoredValues(): array
    {
        $link = ['uid' => self::UID, 'type' => 'url', 'data' => ['url' => 'https://example.com/']];
        $value = static fn(array ...$links): array => ['version' => 1, 'links' => $links];

        return [
            'not an array' => ['links', [['', Code::INVALID]]],
            'a bare list of links' => [[$link], [['', Code::INVALID]]],
            'no version' => [['links' => [$link]], [['', Code::INVALID]]],
            'an extra key' => [['version' => 1, 'links' => [$link], 'x' => 1], [['', Code::INVALID]]],
            'an unknown version' => [['version' => 2, 'links' => [$link]], [['version', Code::UNSUPPORTED_VERSION]]],
            'a version as text' => [['version' => '1', 'links' => [$link]], [['version', Code::UNSUPPORTED_VERSION]]],
            'links that are not a list' => [['version' => 1, 'links' => ['a' => $link]], [['links', Code::WRONG_TYPE]]],
            'an empty list, which is stored as nothing' => [['version' => 1, 'links' => []], [['links', Code::NOT_CANONICAL]]],
            'a link that is a list' => [$value(['url']), [['links[0]', Code::WRONG_TYPE]]],
            'an unknown link key' => [$value($link + ['href' => 'x']), [['links[0].href', Code::UNKNOWN_KEY]]],
            'no UID' => [$value(['type' => 'url', 'data' => ['url' => 'https://example.com/']]), [['links[0].uid', Code::MISSING]]],
            'a UID that is not text' => [$value(['uid' => 1] + $link), [['links[0].uid', Code::WRONG_TYPE]]],
            'an unavailable type' => [$value(['type' => 'phone'] + $link), [['links[0].type', Code::UNKNOWN_LINK_TYPE]]],
            'empty data, which is left out' => [$value(['data' => []] + $link), [['links[0].data', Code::NOT_CANONICAL]]],
            'data not in canonical form' => [$value(['data' => ['url' => 'HTTPS://example.com']] + $link), [['links[0].data.url', Code::NOT_CANONICAL]]],
            'empty attributes, which are left out' => [$value($link + ['attributes' => []]), [['links[0].attributes', Code::NOT_CANONICAL]]],
            'download stored as false' => [$value($link + ['attributes' => ['download' => false]]), [['links[0].attributes.download', Code::NOT_CANONICAL]]],
            'an empty rel' => [$value($link + ['attributes' => ['rel' => []]]), [['links[0].attributes.rel', Code::NOT_CANONICAL]]],
            'no custom attributes, which are left out' => [$value($link + ['attributes' => ['custom' => []]]), [['links[0].attributes.custom', Code::NOT_CANONICAL]]],
            'custom attributes stored as a map' => [$value($link + ['attributes' => ['custom' => ['data-a' => 'b']]]), [['links[0].attributes.custom', Code::WRONG_TYPE]]],
            'a custom attribute row that is not a name and value' => [$value($link + ['attributes' => ['custom' => [['name' => 'data-a', 'value' => 'b', 'x' => 1]]]]), [['links[0].attributes.custom[0]', Code::WRONG_TYPE]]],
            'a custom attribute stored twice' => [$value($link + ['attributes' => ['custom' => [['name' => 'data-a', 'value' => '1'], ['name' => 'data-a', 'value' => '2']]]]), [['links[0].attributes.custom[1].name', Code::DUPLICATE]]],
            'an unknown attribute' => [$value($link + ['attributes' => ['onclick' => 'x']]), [['links[0].attributes.onclick', Code::UNKNOWN_KEY]]],
            'rel stored as text' => [$value($link + ['attributes' => ['rel' => 'noopener']]), [['links[0].attributes.rel', Code::WRONG_TYPE]]],
            'an invalid rel value' => [$value($link + ['attributes' => ['rel' => ['NoOpener']]]), [['links[0].attributes.rel[0]', Code::INVALID]]],
            'an unsupported feature' => [$value(['type' => 'email', 'data' => ['address' => 'a@b.c']] + $link + ['urlSuffix' => '#x']), [['links[0].urlSuffix', Code::NOT_SUPPORTED]]],
            'a repeated UID' => [$value($link, $link), [['links[1].uid', Code::DUPLICATE]]],
        ];
    }

    /**
     * @param list<array{string, Code}> $expected
     */
    #[DataProvider('malformedStoredValues')]
    public function testAMalformedStoredValueIsRefusedRatherThanRepaired(mixed $stored, array $expected): void
    {
        try {
            self::serializer()->deserialize($stored);
            self::fail('A malformed stored value was read.');
        } catch (LinkValidationException $exception) {
            self::assertSame($expected, self::summary($exception->errors));
        }
    }

    public function testEveryBrokenStoredLinkIsReportedNotJustTheFirst(): void
    {
        try {
            self::serializer()->deserialize(['version' => 1, 'links' => [['type' => 'url'], ['uid' => self::UID, 'type' => 'phone']]]);
            self::fail('A malformed stored value was read.');
        } catch (LinkValidationException $exception) {
            // The first link has neither a UID nor the URL its type needs; the second an
            // unavailable type.
            self::assertSame([
                ['links[0].uid', Code::MISSING],
                ['links[0].data.url', Code::MISSING],
                ['links[1].type', Code::UNKNOWN_LINK_TYPE],
            ], self::summary($exception->errors));
            self::assertStringContainsString('(and 2 more)', $exception->getMessage());
        }
    }

    // Attributes on their own, as configuration for links not made yet (a preset's defaults)

    public function testAttributesOnTheirOwnAreReadAndStoredExactlyAsWithinALink(): void
    {
        $input = ['target' => '_blank', 'rel' => 'NoOpener  external', 'class' => 'btn btn-primary', 'download' => '1', 'custom' => [['name' => 'data-track', 'value' => 'cta']]];
        $link = self::normalizer()->normalizeLink(['uid' => self::UID, 'type' => 'url', 'data' => ['url' => 'https://example.com/'], 'attributes' => $input]);
        self::assertInstanceOf(LinkValue::class, $link->value);

        [$attributes, $errors] = self::normalizer()->readAttributes($input);
        self::assertSame([], $errors);
        self::assertEquals($link->value->attributes, $attributes);

        $stored = self::serializer()->serializeAttributes($attributes);
        self::assertSame(self::serializer()->serialize(new LinkCollection([$link->value]))['links'][0]['attributes'] ?? null, $stored);
        // Read back through JSON, as project config hands it over.
        self::assertEquals($attributes, self::serializer()->deserializeAttributes(json_decode((string)json_encode($stored), true)));
        self::assertSame([], self::serializer()->serializeAttributes(new LinkAttributes()));
        self::assertEquals(new LinkAttributes(), self::serializer()->deserializeAttributes([]));
    }

    public function testAttributesThatBreakTheRulesAreReadSoTheValidatorCanSayWhy(): void
    {
        [$attributes, $errors] = self::normalizer()->readAttributes(['target' => 'two words', 'rel' => 'a a', 'custom' => 'x', 'href' => '/']);

        // Reading reports only the input's shape; the rules are the validator's.
        self::assertSame([['href', Code::UNKNOWN_KEY], ['custom', Code::WRONG_TYPE]], self::summary($errors));
        self::assertSame('two words', $attributes->target);
        self::assertSame([['target', Code::INVALID], ['rel[1]', Code::DUPLICATE]], self::summary(self::validator()->validateAttributes($attributes, null)));
    }

    /**
     * @return array<string, array{mixed, list<array{string, Code}>}>
     */
    public static function malformedStoredAttributes(): array
    {
        return [
            'not an object' => ['x', [['', Code::WRONG_TYPE]]],
            'an unknown attribute' => [['onclick' => 'x'], [['onclick', Code::UNKNOWN_KEY]]],
            'download stored as off' => [['download' => false], [['download', Code::NOT_CANONICAL]]],
            'an empty rel' => [['rel' => []], [['rel', Code::NOT_CANONICAL]]],
            'custom attributes as a map' => [['custom' => ['data-x' => 'y']], [['custom', Code::WRONG_TYPE]]],
            'a target that breaks the rules' => [['target' => 'two words'], [['target', Code::INVALID]]],
        ];
    }

    /**
     * @param list<array{string, Code}> $expected
     */
    #[DataProvider('malformedStoredAttributes')]
    public function testStoredAttributesAreReadAsStrictlyAsAStoredLinks(mixed $stored, array $expected): void
    {
        try {
            self::serializer()->deserializeAttributes($stored);
            self::fail('Malformed stored attributes were read.');
        } catch (LinkValidationException $exception) {
            self::assertSame($expected, self::summary($exception->errors));
        }
    }

    public function testAttributesThatBreakTheRulesAreNeverStored(): void
    {
        $this->expectException(LinkValidationException::class);
        self::serializer()->serializeAttributes(new LinkAttributes(rel: ['Not Valid']));
    }

    public function testAUrlSuffixFollowsTheSameRuleOnItsOwn(): void
    {
        self::assertSame([], self::validator()->validateUrlSuffix('?utm_source=x'));
        self::assertSame([], self::validator()->validateUrlSuffix('#team'));

        foreach (['utm=x', '?', '# x', "?a\u{85}"] as $suffix) {
            self::assertSame([['urlSuffix', Code::INVALID]], self::summary(self::validator()->validateUrlSuffix($suffix, 'urlSuffix')), $suffix);
            $link = self::normalizer()->normalizeLink(['type' => 'url', 'data' => ['url' => 'https://example.com/'], 'urlSuffix' => $suffix]);
            self::assertSame([['urlSuffix', Code::INVALID]], self::summary($link->errors), $suffix);
        }
    }

    // Resolved and rendered stages

    public function testAResolvedLinkRendersWithItsAttributesInOrder(): void
    {
        $rendered = self::renderer()->render(self::fullLink(), new ResolvedLink(ResolutionStatus::RESOLVED, 'https://example.com/about#team', external: false, defaultLabel: 'About'));

        self::assertNotNull($rendered);
        self::assertSame('https://example.com/about#team', $rendered->href);
        self::assertSame('About us', $rendered->text);
        self::assertSame([
            'target' => '_blank',
            // The new window's noopener is there already, so it is not added again.
            'rel' => 'noopener noreferrer',
            'title' => 'Meet the team',
            'class' => 'btn md:px-4',
            'id' => 'about-link',
            'aria-label' => 'About our team',
            'data-track' => 'cta',
            'aria-describedby' => 'team-note',
        ], $rendered->attributes);
        self::assertFalse($rendered->external);
    }

    public function testANewWindowAlwaysGetsNoopener(): void
    {
        $link = new LinkValue(self::UID, 'url', self::url('https://example.com/'), attributes: new LinkAttributes(target: '_blank', rel: ['sponsored']));
        $rendered = self::renderer()->render($link, new ResolvedLink(ResolutionStatus::RESOLVED, 'https://example.com/', external: true));

        self::assertSame('sponsored noopener', $rendered?->attributes['rel']);
        self::assertTrue($rendered->external);

        // Only a new window: a link staying in this one is left as authored.
        $same = new LinkValue(self::UID, 'url', self::url('https://example.com/'), attributes: new LinkAttributes(target: '_self'));
        $rendered = self::renderer()->render($same, new ResolvedLink(ResolutionStatus::RESOLVED, 'https://example.com/'));
        self::assertNotNull($rendered);
        self::assertArrayNotHasKey('rel', $rendered->attributes);
    }

    public function testADownloadRendersAsAFlagOrItsFilename(): void
    {
        $renderer = self::renderer();
        $resolved = new ResolvedLink(ResolutionStatus::RESOLVED, 'https://example.com/a.pdf');

        self::assertSame(['download' => true], $renderer->render(new LinkValue(self::UID, 'url', self::url('https://example.com/a.pdf'), attributes: new LinkAttributes(download: true)), $resolved)?->attributes);
        self::assertSame(['download' => 'report.pdf'], $renderer->render(new LinkValue(self::UID, 'url', self::url('https://example.com/a.pdf'), attributes: new LinkAttributes(download: true, downloadFilename: 'report.pdf')), $resolved)?->attributes);
    }

    public function testTheTextFallsBackToTheTargetsLabelThenItsUrl(): void
    {
        $link = new LinkValue(self::UID, 'entry', new FakeEntryData(1, 1));

        self::assertSame('Home', self::renderer()->render($link, new ResolvedLink(ResolutionStatus::RESOLVED, '/home', defaultLabel: 'Home'))?->text);
        self::assertSame('/home', self::renderer()->render($link, new ResolvedLink(ResolutionStatus::RESOLVED, '/home'))?->text);
    }

    /**
     * @return array<string, array{ResolutionStatus, string|null, bool, string|null}>
     */
    public static function impossibleResolutions(): array
    {
        return [
            'resolved without a URL' => [ResolutionStatus::RESOLVED, null, false, null],
            'resolved with an empty URL' => [ResolutionStatus::RESOLVED, '', false, null],
            'a URL with a space' => [ResolutionStatus::RESOLVED, 'https://example.com/a b', false, null],
            'a URL with a line break' => [ResolutionStatus::RESOLVED, "https://example.com/\n", false, null],
            'a URL that is not UTF-8' => [ResolutionStatus::RESOLVED, "https://example.com/\xFF", false, null],
            'a URL with a tab' => [ResolutionStatus::RESOLVED, "https://example.com/\ta", false, null],
            'a URL with a carriage return' => [ResolutionStatus::RESOLVED, "https://example.com/\ra", false, null],
            'a URL with NUL' => [ResolutionStatus::RESOLVED, "https://example.com/\x00a", false, null],
            'a URL with DEL' => [ResolutionStatus::RESOLVED, "https://example.com/\x7Fa", false, null],
            'a URL with a C1 control (U+0085)' => [ResolutionStatus::RESOLVED, "https://example.com/\u{0085}a", false, null],
            'a URL with a no-break space (U+00A0)' => [ResolutionStatus::RESOLVED, "https://example.com/\u{00A0}a", false, null],
            'a URL with a thin space (U+2009)' => [ResolutionStatus::RESOLVED, "https://example.com/\u{2009}a", false, null],
            'a URL with a line separator (U+2028)' => [ResolutionStatus::RESOLVED, "https://example.com/\u{2028}a", false, null],
            'a URL with an ideographic space (U+3000)' => [ResolutionStatus::RESOLVED, "https://example.com/\u{3000}a", false, null],
            'missing with a URL' => [ResolutionStatus::MISSING, 'https://example.com/', false, null],
            'disabled with a URL' => [ResolutionStatus::DISABLED, '/about', false, null],
            'no URL, with a URL' => [ResolutionStatus::NO_URL, '/about', false, null],
            'missing yet external' => [ResolutionStatus::MISSING, null, true, null],
            'disabled yet external' => [ResolutionStatus::DISABLED, null, true, null],
            'no URL, yet external' => [ResolutionStatus::NO_URL, null, true, null],
            'an empty default label' => [ResolutionStatus::RESOLVED, '/about', false, ''],
            'a default label with a control character' => [ResolutionStatus::RESOLVED, '/about', false, "About\x07"],
            'a default label with a tab' => [ResolutionStatus::RESOLVED, '/about', false, "About\tus"],
            'a default label with a newline' => [ResolutionStatus::RESOLVED, '/about', false, "About\nus"],
            'a default label with a carriage return' => [ResolutionStatus::RESOLVED, '/about', false, "About\rus"],
            'a default label with NUL' => [ResolutionStatus::RESOLVED, '/about', false, "About\x00"],
            'a default label with DEL' => [ResolutionStatus::RESOLVED, '/about', false, "About\x7F"],
            'a default label with a C1 control (U+0085)' => [ResolutionStatus::RESOLVED, '/about', false, "About\u{0085}us"],
            'a default label that is not UTF-8' => [ResolutionStatus::RESOLVED, '/about', false, "About\xFF"],
        ];
    }

    #[DataProvider('impossibleResolutions')]
    public function testAResolutionCannotBeInAnImpossibleState(ResolutionStatus $status, ?string $url, bool $external, ?string $defaultLabel): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ResolvedLink($status, $url, $external, $defaultLabel);
    }

    public function testEveryPossibleResolutionIsAccepted(): void
    {
        // The URL is trusted resolver output: any href form is accepted without being parsed,
        // and so is non-ASCII text that is not whitespace or a control character.
        foreach (['https://example.com/a?b=1#c', '/about', 'mailto:a@b.c', 'tel:+441234', '#top', 'https://example.com/café', '/über/straße', 'https://bücher.example/'] as $url) {
            self::assertSame($url, (new ResolvedLink(ResolutionStatus::RESOLVED, $url))->url);
        }

        // A label is text: spaces, including Unicode ones, and non-ASCII letters are fine.
        foreach (['About us', "Caf\u{00E9}\u{00A0}Stra\u{00DF}e", "東京\u{3000}駅", 'Line\u{2028}separated'] as $label) {
            self::assertSame($label, (new ResolvedLink(ResolutionStatus::RESOLVED, '/about', defaultLabel: $label))->defaultLabel);
        }

        self::assertTrue((new ResolvedLink(ResolutionStatus::RESOLVED, 'https://other.example/', external: true))->external);

        // A target that no longer leads anywhere can still have a known name, e.g. for a report.
        foreach ([ResolutionStatus::MISSING, ResolutionStatus::DISABLED, ResolutionStatus::NO_URL] as $status) {
            $resolution = new ResolvedLink($status, defaultLabel: 'About');
            self::assertNull($resolution->url);
            self::assertFalse($resolution->external);
            self::assertSame('About', $resolution->defaultLabel);
        }
    }

    public function testACustomAttributeCannotReplaceACoreOne(): void
    {
        // Built in code, bypassing normalization: the renderer still applies the link rules.
        foreach (['href', 'target', 'rel', 'class', 'id', 'download', 'aria-label'] as $core) {
            $link = new LinkValue(self::UID, 'url', self::url('https://example.com/'), attributes: new LinkAttributes(custom: [$core => 'x']));

            try {
                self::renderer()->render($link, new ResolvedLink(ResolutionStatus::RESOLVED, 'https://example.com/'));
                self::fail("A custom “{$core}” attribute was rendered.");
            } catch (LinkValidationException $exception) {
                self::assertSame([["attributes.custom.$core", Code::INVALID]], self::summary($exception->errors));
            }
        }
    }

    public function testUnsetAttributesAreLeftOut(): void
    {
        $rendered = self::renderer()->render(new LinkValue(self::UID, 'url', self::url('https://example.com/')), new ResolvedLink(ResolutionStatus::RESOLVED, 'https://example.com/'));

        // No download (false), no empty rel or class: nothing but the href and text.
        self::assertSame([], $rendered?->attributes);
    }

    // Resolution: each type's own resolver

    /**
     * @return array{LinkTypeSet, FakeResolver, FakeResolver}
     */
    private static function recordingTypes(): array
    {
        $urls = new FakeResolver(static fn(LinkValue $link, int $siteId): ResolvedLink => new ResolvedLink(ResolutionStatus::RESOLVED, "https://example.com/?site=$siteId", external: true));
        $entries = new FakeResolver(static fn(): ResolvedLink => new ResolvedLink(ResolutionStatus::MISSING));

        return [new LinkTypeSet([new FakeUrlType($urls), new FakeEntryType($entries), new FakeEmailType()]), $urls, $entries];
    }

    public function testEachLinkGoesToItsOwnTypesResolverWithItsSite(): void
    {
        [$types, $urls, $entries] = self::recordingTypes();
        $link = new LinkValue(self::UID, 'entry', new FakeEntryData(7, 2));

        $resolved = (new LinkResolver($types))->resolve($link, 3);

        self::assertSame(ResolutionStatus::MISSING, $resolved->status);
        self::assertSame([], $urls->calls);
        self::assertCount(1, $entries->calls);
        // The very link, unchanged, and the site it is being resolved in.
        self::assertSame($link, $entries->calls[0][0]);
        self::assertSame(3, $entries->calls[0][1]);

        // Validating it first stops nothing that is valid: a link with every kind of attribute
        // reaches its resolver just the same.
        $url = new LinkValue(self::UID, 'url', self::url('https://example.com/'), label: 'Home', attributes: new LinkAttributes(target: '_blank', rel: ['noopener'], custom: ['data-x' => 'y']));
        self::assertSame('https://example.com/?site=4', (new LinkResolver($types))->resolve($url, 4)->url);
        self::assertSame([[$url, 4]], $urls->calls);
    }

    public function testResolvingIsDeterministicAndLeavesTheLinkUnchanged(): void
    {
        [$types] = self::recordingTypes();
        $link = self::normalizer()->normalizeLink(['uid' => self::UID, 'type' => 'url', 'data' => ['url' => 'https://example.com/'], 'label' => 'A'])->value;
        self::assertInstanceOf(LinkValue::class, $link);
        $before = self::serializer()->serialize(new LinkCollection([$link]));

        $first = (new LinkResolver($types))->resolve($link, 1);
        $second = (new LinkResolver($types))->resolve($link, 1);
        self::renderer()->render($link, $first);

        self::assertEquals($first, $second);
        self::assertSame($before, self::serializer()->serialize(new LinkCollection([$link])));
    }

    public function testALinkWithoutAnAvailableTypeFailsRatherThanResolving(): void
    {
        [$types] = self::recordingTypes();

        $this->expectException(LinkResolutionException::class);
        $this->expectExceptionMessage('its link type is not available');

        (new LinkResolver($types))->resolve(new LinkValue(self::UID, 'phone', self::url('https://example.com/')), 1);
    }

    public function testAResolverIsNeverGivenAnotherTypesData(): void
    {
        [$types, $urls] = self::recordingTypes();

        try {
            (new LinkResolver($types))->resolve(new LinkValue(self::UID, 'url', new FakeEntryData(1, 1)), 1);
            self::fail('A link with another type’s data was resolved.');
        } catch (LinkResolutionException $exception) {
            self::assertSame(self::UID, $exception->linkUid);
            self::assertSame('url', $exception->linkType);
            $invalid = $exception->getPrevious();
            self::assertInstanceOf(LinkValidationException::class, $invalid);
            self::assertSame([['data', Code::WRONG_TYPE]], self::summary($invalid->errors));
        }

        self::assertSame([], $urls->calls);
    }

    /**
     * Links built in code, bypassing normalization, each with one invalid common property.
     *
     * @return array<string, array{LinkValue, string, Code}>
     */
    public static function invalidLinksBuiltInCode(): array
    {
        $url = static fn(): FakeUrlData => new FakeUrlData(CanonicalUrl::parse('https://example.com/'));
        $with = static fn(LinkAttributes $attributes): LinkValue => new LinkValue(self::UID, 'url', $url(), attributes: $attributes);

        return [
            'a malformed UID' => [new LinkValue('ABC', 'url', $url()), 'uid', Code::INVALID],
            'a label with a control character' => [new LinkValue(self::UID, 'url', $url(), label: "A\x07"), 'label', Code::INVALID],
            'an empty label' => [new LinkValue(self::UID, 'url', $url(), label: ''), 'label', Code::INVALID],
            'a malformed URL suffix' => [new LinkValue(self::UID, 'url', $url(), urlSuffix: 'utm=1'), 'urlSuffix', Code::INVALID],
            'a URL suffix the type does not support' => [new LinkValue(self::UID, 'email', new FakeEmailData('a@b.c'), urlSuffix: '#x'), 'urlSuffix', Code::NOT_SUPPORTED],
            'a reserved target' => [$with(new LinkAttributes(target: '_new')), 'attributes.target', Code::INVALID],
            'an uppercase rel' => [$with(new LinkAttributes(rel: ['NoOpener'])), 'attributes.rel[0]', Code::INVALID],
            'an empty title' => [$with(new LinkAttributes(title: '')), 'attributes.title', Code::INVALID],
            'a repeated class' => [$with(new LinkAttributes(class: ['btn', 'btn'])), 'attributes.class[1]', Code::DUPLICATE],
            'an id with a space' => [$with(new LinkAttributes(id: 'a b')), 'attributes.id', Code::INVALID],
            'an empty ARIA label' => [$with(new LinkAttributes(ariaLabel: '')), 'attributes.ariaLabel', Code::INVALID],
            'a filename without download' => [$with(new LinkAttributes(downloadFilename: 'a.pdf')), 'attributes.downloadFilename', Code::INVALID],
            'a download the type does not support' => [new LinkValue(self::UID, 'entry', new FakeEntryData(1, 1), attributes: new LinkAttributes(download: true)), 'attributes.download', Code::NOT_SUPPORTED],
            'an event-handler attribute' => [$with(new LinkAttributes(custom: ['onclick' => 'alert(1)'])), 'attributes.custom.onclick', Code::INVALID],
            'a malformed preset UID' => [new LinkValue(self::UID, 'url', $url(), presetUid: 'primary'), 'presetUid', Code::INVALID],
        ];
    }

    #[DataProvider('invalidLinksBuiltInCode')]
    public function testAnInvalidLinkNeverReachesItsTypesResolver(LinkValue $link, string $path, Code $code): void
    {
        $resolver = new FakeResolver(static fn(): ResolvedLink => new ResolvedLink(ResolutionStatus::RESOLVED, '/should-not-happen'));
        $types = new LinkTypeSet([new FakeUrlType($resolver), new FakeEntryType($resolver), new FakeEmailType($resolver)]);
        $outcomes = [];

        // Twice, to show the same invalid link is always refused the same way.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                (new LinkResolver($types))->resolve($link, 1);
                self::fail('An invalid link was resolved.');
            } catch (LinkResolutionException $exception) {
                $invalid = $exception->getPrevious();
                self::assertInstanceOf(LinkValidationException::class, $invalid);
                self::assertStringContainsString('the link is invalid', $exception->getMessage());
                self::assertStringContainsString($path, $exception->getMessage());
                $outcomes[] = [$exception->getMessage(), self::summary($invalid->errors)];
            }
        }

        self::assertSame([[$path, $code]], $outcomes[0][1]);
        self::assertSame($outcomes[0], $outcomes[1]);
        self::assertSame([], $resolver->calls);
    }

    public function testAFailingResolverIsReportedNotTurnedIntoAResult(): void
    {
        $failing = new FakeResolver(static fn(): ResolvedLink => throw new \RuntimeException('Database unavailable'));
        $types = new LinkTypeSet([new FakeUrlType($failing)]);

        try {
            (new LinkResolver($types))->resolve(new LinkValue(self::UID, 'url', self::url('https://example.com/')), 1);
            self::fail('A failing resolver produced a result.');
        } catch (LinkResolutionException $exception) {
            self::assertStringContainsString('Database unavailable', $exception->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        }
    }

    /**
     * @return array<string, array{ResolvedLink, bool}>
     */
    public static function resolutions(): array
    {
        return [
            'an external destination' => [new ResolvedLink(ResolutionStatus::RESOLVED, 'https://other.example/', external: true), true],
            'an internal destination' => [new ResolvedLink(ResolutionStatus::RESOLVED, '/about'), true],
            'a missing target' => [new ResolvedLink(ResolutionStatus::MISSING), false],
            'a disabled target' => [new ResolvedLink(ResolutionStatus::DISABLED, defaultLabel: 'Draft page'), false],
            'a target without a URL' => [new ResolvedLink(ResolutionStatus::NO_URL), false],
        ];
    }

    #[DataProvider('resolutions')]
    public function testWhatAResolverFindsIsWhatRenders(ResolvedLink $resolution, bool $renders): void
    {
        $types = new LinkTypeSet([new FakeEntryType(new FakeResolver(static fn(): ResolvedLink => $resolution))]);
        $link = new LinkValue(self::UID, 'entry', new FakeEntryData(1, 1));

        $rendered = self::renderer()->render($link, (new LinkResolver($types))->resolve($link, 1));

        if (!$renders) {
            self::assertNull($rendered);

            return;
        }

        self::assertNotNull($rendered);
        self::assertSame($resolution->url, $rendered->href);
        // Externality is the resolver's finding, never the author's claim.
        self::assertSame($resolution->external, $rendered->external);
    }

    public function testALinkSurvivesEveryStageFromInputToMarkupAttributes(): void
    {
        $input = [[
            'type' => 'url',
            'data' => ['url' => 'HTTPS://Example.com/Report'],
            'label' => 'Annual report',
            'urlSuffix' => '?utm_source=nav',
            'presetUid' => self::PRESET_UID,
            'attributes' => [
                'target' => '_blank',
                'rel' => 'Sponsored',
                'title' => 'Download the report',
                'class' => 'btn btn-primary',
                'id' => 'report-link',
                'ariaLabel' => 'Annual report, PDF',
                'download' => '1',
                'downloadFilename' => 'report.pdf',
                'custom' => ['data-track' => 'report'],
            ],
        ]];

        $normalized = self::normalizer()->normalize($input);
        self::assertSame([], self::summary($normalized->errors));
        self::assertInstanceOf(LinkCollection::class, $normalized->value);
        self::assertSame([], self::validator()->validateCollection($normalized->value));

        $stored = json_encode(self::serializer()->serialize($normalized->value), JSON_THROW_ON_ERROR);
        $restored = self::serializer()->deserialize(json_decode($stored, true, flags: JSON_THROW_ON_ERROR));
        self::assertEquals($normalized->value, $restored);

        $link = $restored->links[0];
        $rendered = self::renderer()->render($link, (new LinkResolver(FakeTypes::set()))->resolve($link, 1));

        self::assertNotNull($rendered);
        self::assertSame('https://example.com/Report?utm_source=nav', $rendered->href);
        self::assertSame('Annual report', $rendered->text);
        self::assertTrue($rendered->external);
        self::assertSame([
            'target' => '_blank',
            'rel' => 'sponsored noopener',
            'title' => 'Download the report',
            'class' => 'btn btn-primary',
            'id' => 'report-link',
            'aria-label' => 'Annual report, PDF',
            'download' => 'report.pdf',
            'data-track' => 'report',
        ], $rendered->attributes);
    }

    public function testErrorPathsComposeFromTheOutsideIn(): void
    {
        $exception = (new LinkValidationException([new ValidationError('url', Code::MISSING, 'x'), new ValidationError('', Code::INVALID, 'y'), new ValidationError('[2]', Code::INVALID, 'z')]))
            ->within('data')
            ->within('links[0]');

        self::assertSame(['links[0].data.url', 'links[0].data', 'links[0].data[2]'], array_map(static fn(ValidationError $error): string => $error->path, $exception->errors));
    }

    public function testAMissingDataValueOrEmptyAttributesAreHandledAsTheyMean(): void
    {
        // No data at all: the type reports what it needed.
        self::assertSame([['data.url', Code::MISSING]], self::summary(self::normalizer()->normalizeLink(['type' => 'url'])->errors));

        // No attributes at all, or an empty set, is simply none.
        $link = self::normalizer()->normalizeLink(['type' => 'url', 'data' => ['url' => 'https://example.com/'], 'attributes' => []])->value;
        self::assertInstanceOf(LinkValue::class, $link);
        self::assertTrue($link->attributes->isEmpty());
    }

    public function testEveryErrorTheCoreReportsHasASourceMessage(): void
    {
        // Run every malformed input these tests use through the real pipeline, and require a
        // translation for each message that actually comes out.
        $messages = require __DIR__ . '/../../src/translations/en/smart-links.php';
        $errors = [];

        foreach (self::wrongKinds() as [$input]) {
            array_push($errors, ...self::normalizer()->normalize($input)->errors);
        }

        foreach (self::forbiddenCustomAttributes() as [$name]) {
            array_push($errors, ...self::normalizer()->normalizeLink(['type' => 'url', 'data' => ['url' => 'https://a.example/'], 'attributes' => ['custom' => [$name => 'x']]])->errors);
        }

        foreach (self::malformedStoredValues() as [$stored]) {
            try {
                self::serializer()->deserialize($stored);
            } catch (LinkValidationException $exception) {
                array_push($errors, ...$exception->errors);
            }
        }

        array_push($errors, ...self::normalizer()->normalizeLink([
            'type' => 'email',
            'bogus' => 1,
            'data' => ['address' => 'a@b.c'],
            'urlSuffix' => '?x',
            'label' => "\x07",
            'presetUid' => 'x',
            'uid' => 'x',
            'attributes' => ['bogus' => 1, 'target' => '_x', 'rel' => 'A', 'class' => 'a a', 'id' => 'a b', 'title' => "\x07", 'ariaLabel' => "\x07", 'downloadFilename' => '/a', 'custom' => ['data-x' => "\x07"]],
        ])->errors);

        $missing = [];

        foreach ($errors as $error) {
            // Test link types report their own messages; only the core's are Smart Links' to translate.
            if (!str_contains($error->path, 'data.') && !array_key_exists($error->message, $messages)) {
                $missing[$error->message] = true;
            }
        }

        self::assertGreaterThan(40, count($errors));
        self::assertSame([], array_keys($missing));
    }

    // Link types

    public function testTwoTypesCannotShareAHandle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Two link types have the handle “url”.');

        new LinkTypeSet([new FakeUrlType(), new FakeUrlType()]);
    }

    public function testATypeSetFindsTypesByHandle(): void
    {
        $types = FakeTypes::set();

        self::assertInstanceOf(FakeEntryType::class, $types->get('entry'));
        self::assertNull($types->get('phone'));
        self::assertSame(['url', 'entry', 'email'], $types->handles());
    }
}
