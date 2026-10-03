<?php

namespace Tahadudhiya\SmartLinks\Tests\integration;

use Craft;
use craft\base\Element;
use craft\db\Table;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\DefineUrlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\models\Section;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\errors\LinkResolutionException;
use Tahadudhiya\SmartLinks\events\RegisterEmbedProvidersEvent;
use Tahadudhiya\SmartLinks\events\RegisterSocialNetworksEvent;
use Tahadudhiya\SmartLinks\fields\LinkEditor;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;
use Tahadudhiya\SmartLinks\linktypes\BaseElementLinkType;
use Tahadudhiya\SmartLinks\linktypes\CraftLinkConverterInterface;
use Tahadudhiya\SmartLinks\linktypes\ElementLinkData;
use Tahadudhiya\SmartLinks\linktypes\ElementLinkTypeInterface;
use Tahadudhiya\SmartLinks\linktypes\embed\BaseEmbedProvider;
use Tahadudhiya\SmartLinks\linktypes\EmbedLinkData;
use Tahadudhiya\SmartLinks\linktypes\EmbedLinkType;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeInterface;
use Tahadudhiya\SmartLinks\linktypes\ProductLinkType;
use Tahadudhiya\SmartLinks\linktypes\social\SocialNetwork;
use Tahadudhiya\SmartLinks\linktypes\SocialLinkType;
use Tahadudhiya\SmartLinks\models\CanonicalUrl;
use Tahadudhiya\SmartLinks\models\LinkAttributes;
use Tahadudhiya\SmartLinks\models\LinkCollection;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ResolvedLink;
use Tahadudhiya\SmartLinks\models\ValidationError;
use Tahadudhiya\SmartLinks\services\LinkTypes;
use Tahadudhiya\SmartLinks\SmartLinks;
use Tahadudhiya\SmartLinks\Tests\_support\ElementFixture;
use Tahadudhiya\SmartLinks\Tests\_support\FieldFixture;
use yii\base\Event;

/**
 * The built-in link types in real Craft content: what each accepts and refuses, how it is
 * stored, what it resolves to, what it identifies, and how it reads Craft's own Link values.
 * Element links resolve against real entries, categories, assets, users and Commerce products,
 * in two sites, with drafts, revisions, disabled and deleted targets.
 *
 * Commerce is present in the host project; its absence is tested in a separate PHP process with
 * Commerce disabled.
 */
final class LinkTypesTest extends TestCase
{
    use FieldFixture;
    use ElementFixture;

    /** A field allowing every built-in type. */
    private const ALL = 'smartLinksTestAllTypes';

    /** Entry and URL links, the same in every site. */
    private const SHARED = 'smartLinksTestShared';

    /** Craft's own Link field, to compare relations with. */
    private const CRAFT_LINK = 'smartLinksTestCraftLink';

    private const HANDLES = ['url', 'entry', 'category', 'asset', 'user', 'commerce-product', 'email', 'tel', 'sms', 'social', 'embed'];

    protected static function usesTestTypes(): bool
    {
        return false;
    }

    protected static function extraLayoutFields(): array
    {
        $craftLink = Craft::$app->getFields()->createField(['type' => \craft\fields\Link::class, 'name' => self::CRAFT_LINK, 'handle' => self::CRAFT_LINK, 'types' => ['entry', 'url']]);
        self::assertTrue(Craft::$app->getFields()->saveField($craftLink), Json::encode($craftLink->getErrors()));

        return [
            new CustomField(self::createField(self::ALL, ['types' => self::types()->handles(), 'translationMethod' => SmartLinkField::TRANSLATION_METHOD_SITE])),
            new CustomField(self::createField(self::SHARED, ['types' => ['entry', 'url'], 'translationMethod' => SmartLinkField::TRANSLATION_METHOD_NONE])),
            new CustomField(Craft::$app->getFields()->getFieldByHandle(self::CRAFT_LINK)),
        ];
    }

    public static function setUpBeforeClass(): void
    {
        self::setUpFixture();
        self::setUpElementFixture();
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownFixture();
        self::tearDownElementFixture();
    }

    protected function tearDown(): void
    {
        $this->restoreConsoleRequest();
        Craft::$app->getUser()->setIdentity(null);
        self::resetPluginServices();
    }

    // Registration and extension

    public function testTheBuiltInTypesAreRegisteredThroughTheRegistrationEvent(): void
    {
        $types = self::types();

        self::assertSame(self::handles(), $types->handles());

        foreach (self::handles() as $handle) {
            $type = $types->get($handle);
            self::assertInstanceOf(LinkTypeInterface::class, $type);
            self::assertNotSame('', $type->displayName());
        }

        $elementTypes = array_values(array_filter(self::handles(), static fn(string $handle): bool => $types->get($handle) instanceof ElementLinkTypeInterface));
        self::assertSame(array_values(array_intersect(['entry', 'category', 'asset', 'user', 'commerce-product'], self::handles())), $elementTypes);
    }

    public function testAHandlerCanRemoveABuiltInType(): void
    {
        $remove = static function(RegisterComponentTypesEvent $event): void {
            $event->types = array_values(array_diff($event->types, [EmbedLinkType::class]));
        };

        Event::on(LinkTypes::class, LinkTypes::EVENT_REGISTER_LINK_TYPES, $remove);
        self::resetPluginServices();

        try {
            self::assertNull(self::types()->get('embed'));
            self::assertNotNull(self::types()->get('url'));
        } finally {
            Event::off(LinkTypes::class, LinkTypes::EVENT_REGISTER_LINK_TYPES, $remove);
        }
    }

    public function testADeveloperRegistersTheirOwnLinkTypeWithoutChangingThePlugin(): void
    {
        // Another plugin's element link type, built on the documented base class.
        $custom = new class() extends BaseElementLinkType {
            public function handle(): string
            {
                return 'landing-page';
            }

            public function elementType(): string
            {
                return Entry::class;
            }
        };
        $register = static function(RegisterComponentTypesEvent $event) use ($custom): void {
            $event->types[] = $custom::class;
        };

        Event::on(LinkTypes::class, LinkTypes::EVENT_REGISTER_LINK_TYPES, $register);
        self::resetPluginServices();

        try {
            $target = self::savedEntry('landing-page-target');
            $link = self::normalized(['type' => 'landing-page', 'data' => ['elementId' => (string)$target->id]]);
            $stored = self::storedData($link);

            self::assertSame(['elementId' => (int)$target->id], $stored);
            self::assertSame($target->getUrl(), self::resolve($link)->url);
        } finally {
            Event::off(LinkTypes::class, LinkTypes::EVENT_REGISTER_LINK_TYPES, $register);
        }
    }

    public function testAMalformedRegistrationIsRefusedLoudly(): void
    {
        $register = static function(RegisterSocialNetworksEvent $event): void {
            $event->networks[] = new SocialNetwork('x', 'Another X', '[a-z]+', 'https://x.example/{account}');
        };

        Event::on(SocialLinkType::class, SocialLinkType::EVENT_REGISTER_NETWORKS, $register);

        try {
            $this->expectException(\yii\base\InvalidConfigException::class);
            (new SocialLinkType())->networks();
        } finally {
            Event::off(SocialLinkType::class, SocialLinkType::EVENT_REGISTER_NETWORKS, $register);
        }
    }

    // URL

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedUrls(): iterable
    {
        yield 'absolute, canonicalized' => ['HTTPS://Example.COM:443/a/../b?x=1#Top', 'https://example.com/b?x=1#Top'];
        yield 'surrounding whitespace' => ["  https://example.com/  \n", 'https://example.com/'];
        yield 'internationalized host' => ['https://bücher.example/straße', 'https://xn--bcher-kva.example/stra%C3%9Fe'];
        yield 'root-relative' => ['/about/team?tab=1', '/about/team?tab=1'];
        yield 'anchor' => ['#Our Team', '#Our%20Team'];
        yield 'private host (only health checks refuse it)' => ['http://192.168.0.1/admin', 'http://192.168.0.1/admin'];
        yield 'query and fragment kept as written' => ['https://example.com/?b=2&a=1+x#Frag%20ment', 'https://example.com/?b=2&a=1+x#Frag%20ment'];
        yield 'a path that looks like a scheme' => ['/javascript:alert(1)', '/javascript:alert(1)'];
        yield 'an anchor that looks like a scheme' => ['#javascript:alert(1)', '#javascript:alert(1)'];
    }

    #[DataProvider('acceptedUrls')]
    public function testAUrlIsStoredInItsCanonicalForm(string $input, string $stored): void
    {
        $link = self::normalized(['type' => 'url', 'data' => ['url' => $input]]);

        self::assertSame(['url' => $stored], self::storedData($link));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedUrls(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)', 'Only http and https URLs can be linked to.'];
        yield 'data' => ['data:text/html,<script>alert(1)</script>', 'Only http and https URLs can be linked to.'];
        yield 'vbscript' => ['VBScript:msgbox(1)', 'Only http and https URLs can be linked to.'];
        yield 'file' => ['file:///etc/passwd', 'Only http and https URLs can be linked to.'];
        yield 'email' => ['mailto:hello@example.com', 'Use an Email link for email addresses.'];
        yield 'phone' => ['tel:+15551234567', 'Use a Phone link for phone numbers.'];
        yield 'sms' => ['sms:+15551234567', 'Use an SMS link for text messages.'];
        yield 'protocol-relative' => ['//evil.example/', 'This URL can’t be used: A relative URL must be root-relative.'];
        yield 'path-relative' => ['about', 'This URL can’t be used: A relative URL must be root-relative.'];
        yield 'credentials' => ['https://user:secret@example.com/', 'This URL can’t be used: A URL may not contain credentials.'];
        yield 'ambiguous numeric host' => ['http://127.1/', 'This URL can’t be used: An IPv4 host must be written as four decimal numbers.'];
        yield 'control character' => ["https://exam\x01ple.com/", 'This URL can’t be used: A URL may not contain control characters or backslashes.'];
        yield 'backslash' => ['https://example.com\\@evil.example/', 'This URL can’t be used: A URL may not contain control characters or backslashes.'];
        yield 'empty anchor' => ['#', 'This URL can’t be used: An anchor must be “#” followed by the name of a place in the page.'];
    }

    /**
     * Every spelling of an unsafe or other scheme that a browser might still read as one.
     *
     * @return iterable<string, array{string}>
     */
    public static function unsafeSchemeSpellings(): iterable
    {
        yield 'mixed case' => ['JaVaScRiPt:alert(1)'];
        yield 'leading spaces' => ['   javascript:alert(1)'];
        yield 'leading control character' => ["\x01javascript:alert(1)"];
        yield 'tab inside' => ["java\tscript:alert(1)"];
        yield 'newline inside' => ["java\nscript:alert(1)"];
        yield 'percent-encoded letter' => ['%6Aavascript:alert(1)'];
        yield 'HTML entity' => ['javascript&#58;alert(1)'];
        yield 'zero-width space first' => ["\u{200B}javascript:alert(1)"];
        yield 'no-break space first' => ["\u{00A0}javascript:alert(1)"];
        yield 'fullwidth colon' => ['javascript：alert(1)'];
        yield 'mailto in capitals' => ['  MAILTO:hello@example.com'];
        yield 'scheme without slashes' => ['https:evil.example'];
        yield 'one slash' => ['https:/evil.example'];
        yield 'backslashes' => ['https:\\\\evil.example'];
        yield 'data with base64' => ['data:text/html;base64,PHNjcmlwdD4='];
    }

    #[DataProvider('unsafeSchemeSpellings')]
    public function testNoSpellingOfAnotherSchemeGetsThroughAsAUrl(string $input): void
    {
        $errors = self::errors(['type' => 'url', 'data' => ['url' => $input]]);

        self::assertSame(['links[0].data.url', Code::INVALID], [$errors[0][0], $errors[0][1]]);
    }

    #[DataProvider('refusedUrls')]
    public function testAnUnsafeOrAmbiguousUrlIsRefused(string $input, string $message): void
    {
        self::assertSame([['links[0].data.url', Code::INVALID, $message]], self::errors(['type' => 'url', 'data' => ['url' => $input]]));
    }

    public function testAUrlLongerThanTheLimitIsRefused(): void
    {
        $errors = self::errors(['type' => 'url', 'data' => ['url' => 'https://example.com/' . str_repeat('a', 4096)]]);

        self::assertSame([['links[0].data.url', Code::INVALID, 'A URL can be at most 4,096 characters long.']], $errors);
        self::assertSame([['links[0].data.url', Code::MISSING, 'Enter a URL.']], self::errors(['type' => 'url', 'data' => ['url' => '']]));
    }

    public function testAUrlResolvesWithItsSuffixAndKnowsWhetherItLeavesTheSite(): void
    {
        $siteUrl = rtrim((string)Craft::$app->getSites()->getSiteById(self::$primarySiteId)?->getBaseUrl(), '/');
        $own = self::normalized(['type' => 'url', 'data' => ['url' => "$siteUrl/pricing?plan=pro#faq"], 'urlSuffix' => '?utm_source=news']);
        $other = self::normalized(['type' => 'url', 'data' => ['url' => 'https://other.example/'], 'urlSuffix' => '#top']);
        $relative = self::normalized(['type' => 'url', 'data' => ['url' => '/pricing#faq']]);
        $anchor = self::normalized(['type' => 'url', 'data' => ['url' => '#faq']]);

        self::assertEquals(new ResolvedLink(ResolutionStatus::RESOLVED, "$siteUrl/pricing?plan=pro&utm_source=news#faq", false), self::resolve($own));
        self::assertEquals(new ResolvedLink(ResolutionStatus::RESOLVED, 'https://other.example/#top', true), self::resolve($other));
        self::assertEquals(new ResolvedLink(ResolutionStatus::RESOLVED, '/pricing#faq', false), self::resolve($relative));
        self::assertEquals(new ResolvedLink(ResolutionStatus::RESOLVED, '#faq', false), self::resolve($anchor));

        // The second site is on another host, so the first site's URL leaves it.
        self::assertTrue(self::resolve($own, self::$secondSiteId)->external);
    }

    public function testAUrlIdentifiesItsTargetAsTheDataModelSays(): void
    {
        $type = self::types()->get('url');
        self::assertNotNull($type);

        $absolute = $type->normalizeData(['url' => 'https://example.com/a#b']);
        $relative = $type->normalizeData(['url' => '/a']);
        $anchor = $type->normalizeData(['url' => '#b']);

        // An absolute URL is the same target in every site; a path and an anchor are not.
        self::assertSame('url?url=https%3A%2F%2Fexample.com%2Fa%23b', $type->targetIdentity($absolute, 7, 1)->key());
        self::assertTrue($type->targetIdentity($absolute, 7, 1)->equals($type->targetIdentity($absolute, 9, 2)));
        self::assertSame('url?siteId=2&url=%2Fa', $type->targetIdentity($relative, 7, 2)->key());
        self::assertSame('url?elementId=7&siteId=1&url=%23b', $type->targetIdentity($anchor, 7, 1)->key());
    }

    // Email, phone, SMS

    /**
     * @return iterable<string, array{array<string, string>, array<string, string>, string}>
     */
    public static function emailLinks(): iterable
    {
        yield 'domain lowercased, local part kept' => [['address' => ' Jane.Doe+news@Example.COM '], ['address' => 'Jane.Doe+news@example.com'], 'mailto:Jane.Doe%2Bnews@example.com'];
        yield 'internationalized domain' => [['address' => 'info@bücher.example'], ['address' => 'info@xn--bcher-kva.example'], 'mailto:info@xn--bcher-kva.example'];
        yield 'subject and body' => [
            ['address' => 'hello@example.com', 'subject' => 'Hi & welcome', 'body' => "Line 1\nLine 2"],
            ['address' => 'hello@example.com', 'subject' => 'Hi & welcome', 'body' => "Line 1\nLine 2"],
            'mailto:hello@example.com?subject=Hi%20%26%20welcome&body=Line%201%0D%0ALine%202',
        ];
    }

    /**
     * @param array<string, string> $input
     * @param array<string, string> $stored
     */
    #[DataProvider('emailLinks')]
    public function testAnEmailLinkNormalizesOnlyTheDomain(array $input, array $stored, string $href): void
    {
        $link = self::normalized(['type' => 'email', 'data' => $input]);

        self::assertSame($stored, self::storedData($link));
        self::assertEquals(new ResolvedLink(ResolutionStatus::RESOLVED, $href, false, $stored['address']), self::resolve($link));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, Code}>
     */
    public static function refusedEmails(): iterable
    {
        yield 'no domain' => [['address' => 'jane@'], 'address', Code::INVALID];
        yield 'dotless domain' => [['address' => 'jane@localhost'], 'address', Code::INVALID];
        yield 'space' => [['address' => 'jane doe@example.com'], 'address', Code::INVALID];
        yield 'quoted local part' => [['address' => '"jane"@example.com'], 'address', Code::INVALID];
        yield 'IP literal' => [['address' => 'jane@[192.168.0.1]'], 'address', Code::INVALID];
        yield 'two addresses' => [['address' => 'a@example.com,b@example.com'], 'address', Code::INVALID];
        yield 'header injection' => [['address' => "a@example.com\r\nBcc: x@example.com"], 'address', Code::INVALID];
        yield 'mailto prefix' => [['address' => 'mailto:jane@example.com'], 'address', Code::INVALID];
        yield 'missing' => [['address' => ''], 'address', Code::MISSING];
        yield 'subject on two lines' => [['address' => 'jane@example.com', 'subject' => "Hi\nthere"], 'subject', Code::INVALID];
        yield 'control character in body' => [['address' => 'jane@example.com', 'body' => "Hi\x07"], 'body', Code::INVALID];
        yield 'unknown key' => [['address' => 'jane@example.com', 'cc' => 'x@example.com'], 'cc', Code::UNKNOWN_KEY];
        yield 'non-ASCII local part (not supported)' => [['address' => 'jöhn@example.com'], 'address', Code::INVALID];
        yield 'local part too long' => [['address' => str_repeat('a', 65) . '@example.com'], 'address', Code::INVALID];
        yield 'consecutive dots' => [['address' => 'jane..doe@example.com'], 'address', Code::INVALID];
        yield 'invalid UTF-8' => [['address' => "jane@exa\xC3mple.com"], 'address', Code::INVALID];
        yield 'subject that is not text' => [['address' => 'jane@example.com', 'subject' => ['x']], 'subject', Code::WRONG_TYPE];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('refusedEmails')]
    public function testAnUnsafeOrAmbiguousEmailIsRefused(array $data, string $key, Code $code): void
    {
        $errors = self::errors(['type' => 'email', 'data' => $data]);

        self::assertSame(["links[0].data.$key", $code], [$errors[0][0], $errors[0][1]]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function phoneNumbers(): iterable
    {
        yield 'international with separators' => ['+1 (555) 123-4567', '+15551234567'];
        yield 'dots' => ['0800.123.456', '0800123456'];
        yield 'no-break spaces' => ["+44\u{00A0}20\u{00A0}7946\u{00A0}0958", '+442079460958'];
        yield 'short number' => ['112', '112'];
    }

    #[DataProvider('phoneNumbers')]
    public function testAPhoneNumberLosesOnlyItsVisualSeparators(string $input, string $number): void
    {
        $tel = self::normalized(['type' => 'tel', 'data' => ['number' => $input]]);
        $sms = self::normalized(['type' => 'sms', 'data' => ['number' => $input, 'body' => "See you at 8?\nBye"]]);

        self::assertSame(['number' => $number], self::storedData($tel));
        self::assertEquals(new ResolvedLink(ResolutionStatus::RESOLVED, "tel:$number", false, $number), self::resolve($tel));
        self::assertSame(['number' => $number, 'body' => "See you at 8?\nBye"], self::storedData($sms));
        self::assertSame("sms:$number?body=See%20you%20at%208%3F%0ABye", self::resolve($sms)->url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedPhoneNumbers(): iterable
    {
        yield 'letters' => ['1-800-FLOWERS'];
        yield 'plus inside' => ['+1+2345678'];
        yield 'pause' => ['555123,45'];
        yield 'too short' => ['12'];
        yield 'too long' => ['+1234567890123456'];
        yield 'script' => ['<script>'];
        yield 'trunk prefix after a country code' => ['+44 (0)20 7946 0958'];
        yield 'wait character' => ['+15551234;w123'];
        yield 'extension' => ['+1 555 1234 ext. 5'];
        yield 'double plus' => ['++15551234567'];
        yield 'plus alone' => ['+'];
        yield 'fullwidth digits' => ['＋１５５５１２３４５６７'];
    }

    #[DataProvider('refusedPhoneNumbers')]
    public function testAPhoneNumberWithAnythingButSeparatorsIsRefused(string $input): void
    {
        foreach (['tel', 'sms'] as $type) {
            $errors = self::errors(['type' => $type, 'data' => ['number' => $input]]);
            self::assertSame(['links[0].data.number', Code::INVALID], [$errors[0][0], $errors[0][1]], $type);
        }
    }

    public function testCallingAndTextingANumberAreDifferentTargets(): void
    {
        $tel = self::types()->get('tel');
        $sms = self::types()->get('sms');
        self::assertNotNull($tel);
        self::assertNotNull($sms);

        self::assertSame('tel?number=%2B15551234567', $tel->targetIdentity($tel->normalizeData(['number' => '+1 555 123 4567']), 1, 1)->key());
        // The message is what is sent, not who it goes to.
        self::assertSame('sms?number=%2B15551234567', $sms->targetIdentity($sms->normalizeData(['number' => '+15551234567', 'body' => 'Hi']), 1, 1)->key());
        $email = self::types()->get('email');
        self::assertNotNull($email);
        self::assertSame('email?address=Jane%40example.com', $email->targetIdentity($email->normalizeData(['address' => 'Jane@EXAMPLE.com', 'subject' => 'Hi']), 1, 1)->key());
    }

    // Social

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function socialAccounts(): iterable
    {
        yield 'Facebook' => ['facebook', 'craftcms', 'craftcms', 'https://www.facebook.com/craftcms'];
        yield 'Instagram, with @' => ['instagram', '@craft.cms', 'craft.cms', 'https://www.instagram.com/craft.cms/'];
        yield 'X' => ['x', '@CraftCMS', 'CraftCMS', 'https://x.com/CraftCMS'];
        yield 'LinkedIn person' => ['linkedin', 'jane-doe-123', 'jane-doe-123', 'https://www.linkedin.com/in/jane-doe-123/'];
        yield 'LinkedIn company' => ['linkedin-company', 'pixel-and-tonic', 'pixel-and-tonic', 'https://www.linkedin.com/company/pixel-and-tonic/'];
        yield 'YouTube' => ['youtube', '@CraftCMS', 'CraftCMS', 'https://www.youtube.com/@CraftCMS'];
        yield 'TikTok' => ['tiktok', '@craft_cms', 'craft_cms', 'https://www.tiktok.com/@craft_cms'];
        yield 'GitHub' => ['github', 'craftcms', 'craftcms', 'https://github.com/craftcms'];
        yield 'Pinterest' => ['pinterest', 'craftcms', 'craftcms', 'https://www.pinterest.com/craftcms/'];
        yield 'Threads' => ['threads', '@craft.cms', 'craft.cms', 'https://www.threads.com/@craft.cms'];
        yield 'Bluesky, a domain' => ['bluesky', '@Jane.BSKY.social', 'jane.bsky.social', 'https://bsky.app/profile/jane.bsky.social'];
        yield 'Mastodon, server lowercased' => ['mastodon', '@Jane@Mastodon.Social', 'Jane@mastodon.social', 'https://mastodon.social/@Jane'];
    }

    #[DataProvider('socialAccounts')]
    public function testASocialLinkIsANetworkAndAnAccountNeverAUrl(string $network, string $input, string $account, string $profile): void
    {
        $link = self::normalized(['type' => 'social', 'data' => ['network' => $network, 'account' => $input]]);

        self::assertSame(['network' => $network, 'account' => $account], self::storedData($link));
        $resolved = self::resolve($link);
        self::assertSame($profile, $resolved->url);
        self::assertTrue($resolved->external);
        self::assertSame($profile, CanonicalUrl::parse($profile)->toString());
    }

    /**
     * @return iterable<string, array{array<string, string>, string, Code}>
     */
    public static function refusedSocialLinks(): iterable
    {
        yield 'a URL as the account' => [['network' => 'instagram', 'account' => 'https://instagram.com/craft'], 'account', Code::INVALID];
        yield 'too long for X' => [['network' => 'x', 'account' => 'abcdefghijklmnop'], 'account', Code::INVALID];
        yield 'path in the account' => [['network' => 'github', 'account' => 'craftcms/cms'], 'account', Code::INVALID];
        yield 'Mastodon without a server' => [['network' => 'mastodon', 'account' => '@jane'], 'account', Code::INVALID];
        yield 'unknown network' => [['network' => 'myspace', 'account' => 'tom'], 'network', Code::INVALID];
        yield 'no network' => [['network' => '', 'account' => 'tom'], 'network', Code::MISSING];
        yield 'no account' => [['network' => 'x', 'account' => ''], 'account', Code::MISSING];
        yield 'an @ where the network writes none' => [['network' => 'facebook', 'account' => '@craftcms'], 'account', Code::INVALID];
        yield 'too short for Facebook' => [['network' => 'facebook', 'account' => 'abcd'], 'account', Code::INVALID];
        yield 'space in the account' => [['network' => 'instagram', 'account' => 'craft cms'], 'account', Code::INVALID];
        yield 'GitHub with a trailing hyphen' => [['network' => 'github', 'account' => 'craft-'], 'account', Code::INVALID];
        yield 'LinkedIn with a path' => [['network' => 'linkedin', 'account' => 'in/jane'], 'account', Code::INVALID];
        yield 'Bluesky without a domain' => [['network' => 'bluesky', 'account' => 'jane'], 'account', Code::INVALID];
        yield 'Mastodon on an IP address' => [['network' => 'mastodon', 'account' => 'jane@127.0.0.1'], 'account', Code::INVALID];
        yield 'Mastodon with two servers' => [['network' => 'mastodon', 'account' => 'jane@a.example@b.example'], 'account', Code::INVALID];
        yield 'markup in the account' => [['network' => 'x', 'account' => '"><script>'], 'account', Code::INVALID];
    }

    /**
     * @param array<string, string> $data
     */
    #[DataProvider('refusedSocialLinks')]
    public function testASocialLinkOutsideItsNetworksRulesIsRefused(array $data, string $key, Code $code): void
    {
        $errors = self::errors(['type' => 'social', 'data' => $data]);

        self::assertSame(["links[0].data.$key", $code], [$errors[0][0], $errors[0][1]]);
    }

    public function testDevelopersAddSocialNetworksAndABadOneFailsLoudly(): void
    {
        $register = static function(RegisterSocialNetworksEvent $event): void {
            $event->networks[] = new SocialNetwork('dribbble', 'Dribbble', '[A-Za-z0-9_-]{2,30}', 'https://dribbble.com/{account}', false);
            // A broken definition: its profile URL is not a URL a browser would read one way.
            $event->networks[] = new SocialNetwork('broken', 'Broken', '[a-z]+', 'javascript:alert("{account}")', false);
        };
        Event::on(SocialLinkType::class, SocialLinkType::EVENT_REGISTER_NETWORKS, $register);
        self::resetPluginServices();

        try {
            $link = self::normalized(['type' => 'social', 'data' => ['network' => 'dribbble', 'account' => 'jane']]);
            self::assertSame('https://dribbble.com/jane', self::resolve($link)->url);

            $broken = self::normalized(['type' => 'social', 'data' => ['network' => 'broken', 'account' => 'x']]);
            $this->expectException(LinkResolutionException::class);
            self::resolve($broken);
        } finally {
            Event::off(SocialLinkType::class, SocialLinkType::EVENT_REGISTER_NETWORKS, $register);
        }
    }

    // Embed

    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function mediaUrls(): iterable
    {
        yield 'YouTube watch page, share tracker dropped' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&si=abc&t=42', 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'];
        yield 'YouTube short link' => ['https://youtu.be/dQw4w9WgXcQ?si=xyz', 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'];
        yield 'YouTube Short' => ['https://youtube.com/shorts/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'];
        yield 'Vimeo' => ['https://vimeo.com/76979871', 'vimeo', '76979871', 'https://vimeo.com/76979871', 'https://player.vimeo.com/video/76979871'];
        yield 'Vimeo unlisted' => ['https://vimeo.com/76979871/af1b2c3d4e', 'vimeo', '76979871/af1b2c3d4e', 'https://vimeo.com/76979871/af1b2c3d4e', 'https://player.vimeo.com/video/76979871?h=af1b2c3d4e'];
        yield 'Vimeo player' => ['https://player.vimeo.com/video/76979871?h=af1b2c3d4e', 'vimeo', '76979871/af1b2c3d4e', 'https://vimeo.com/76979871/af1b2c3d4e', 'https://player.vimeo.com/video/76979871?h=af1b2c3d4e'];
        yield 'Spotify, localized page' => ['https://open.spotify.com/intl-de/track/4uLU6hMCjMI75M1A2tKUQC?si=1', 'spotify', 'track/4uLU6hMCjMI75M1A2tKUQC', 'https://open.spotify.com/track/4uLU6hMCjMI75M1A2tKUQC', 'https://open.spotify.com/embed/track/4uLU6hMCjMI75M1A2tKUQC'];
        yield 'YouTube embed page, fragment ignored' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ#t=10', 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'];
        yield 'YouTube over http' => ['http://m.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'];
        yield 'Vimeo, hash as a parameter' => ['https://vimeo.com/76979871?h=af1b2c3d4e', 'vimeo', '76979871/af1b2c3d4e', 'https://vimeo.com/76979871/af1b2c3d4e', 'https://player.vimeo.com/video/76979871?h=af1b2c3d4e'];
        yield 'Spotify player' => ['https://open.spotify.com/embed/episode/4uLU6hMCjMI75M1A2tKUQC', 'spotify', 'episode/4uLU6hMCjMI75M1A2tKUQC', 'https://open.spotify.com/episode/4uLU6hMCjMI75M1A2tKUQC', 'https://open.spotify.com/embed/episode/4uLU6hMCjMI75M1A2tKUQC'];
        yield 'SoundCloud track' => ['https://m.soundcloud.com/forss/flickermood', 'soundcloud', 'forss/flickermood', 'https://soundcloud.com/forss/flickermood', 'https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fforss%2Fflickermood'];
        yield 'SoundCloud playlist' => ['https://soundcloud.com/forss/sets/soulhack', 'soundcloud', 'forss/sets/soulhack', 'https://soundcloud.com/forss/sets/soulhack', 'https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fforss%2Fsets%2Fsoulhack'];
    }

    #[DataProvider('mediaUrls')]
    public function testAnEmbedLinkRecognisesItsProviderOfflineAndKeepsOnlyTheMedia(string $url, string $provider, string $mediaId, string $page, string $embed): void
    {
        $link = self::normalized(['type' => 'embed', 'data' => ['url' => $url]]);

        self::assertSame(['provider' => $provider, 'mediaId' => $mediaId], self::storedData($link));
        self::assertSame($page, self::resolve($link)->url);
        self::assertTrue(self::resolve($link)->external);

        $type = self::types()->get('embed');
        self::assertInstanceOf(EmbedLinkType::class, $type);
        self::assertInstanceOf(EmbedLinkData::class, $link->data);
        self::assertSame($embed, $type->embedUrl($link->data));

        // The stored form is authoring input too, so a stored or copied link can be edited again.
        self::assertEquals($link->data, $type->normalizeData(['provider' => $provider, 'mediaId' => $mediaId]));
    }

    /**
     * @return iterable<string, array{array<string, string>, string, Code}>
     */
    public static function refusedEmbeds(): iterable
    {
        yield 'an arbitrary page' => [['url' => 'https://example.com/video.mp4'], 'url', Code::INVALID];
        yield 'a provider page that is not media' => [['url' => 'https://www.youtube.com/feed/trending'], 'url', Code::INVALID];
        yield 'a malformed video ID' => [['url' => 'https://youtu.be/short'], 'url', Code::INVALID];
        yield 'a look-alike host' => [['url' => 'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ'], 'url', Code::INVALID];
        yield 'an unsafe URL' => [['url' => 'javascript:alert(1)'], 'url', Code::INVALID];
        yield 'an unknown provider' => [['provider' => 'myvideos', 'mediaId' => '1'], 'provider', Code::INVALID];
        yield 'a media ID of the wrong form' => [['provider' => 'youtube', 'mediaId' => '../../etc'], 'mediaId', Code::INVALID];
        yield 'both forms at once' => [['url' => 'https://youtu.be/dQw4w9WgXcQ', 'provider' => 'youtube'], 'url', Code::INVALID];
        yield 'nothing' => [['url' => ''], 'url', Code::MISSING];
        yield 'YouTube with two videos named' => [['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&v=9bZkp7q4AAA'], 'url', Code::INVALID];
        yield 'YouTube video as an array' => [['url' => 'https://www.youtube.com/watch?v[]=dQw4w9WgXcQ'], 'url', Code::INVALID];
        yield 'YouTube on another port' => [['url' => 'https://www.youtube.com:8443/watch?v=dQw4w9WgXcQ'], 'url', Code::INVALID];
        yield 'YouTube Music (not a supported host)' => [['url' => 'https://music.youtube.com/watch?v=dQw4w9WgXcQ'], 'url', Code::INVALID];
        yield 'YouTube with credentials' => [['url' => 'https://evil@youtube.com/watch?v=dQw4w9WgXcQ'], 'url', Code::INVALID];
        yield 'a YouTube channel' => [['url' => 'https://www.youtube.com/@CraftCMS'], 'url', Code::INVALID];
        yield 'Vimeo with two different hashes' => [['url' => 'https://vimeo.com/76979871/af1b2c3d4e?h=0000'], 'url', Code::INVALID];
        yield 'a Vimeo channel' => [['url' => 'https://vimeo.com/channels/staffpicks/76979871'], 'url', Code::INVALID];
        yield 'a Spotify user' => [['url' => 'https://open.spotify.com/user/spotify'], 'url', Code::INVALID];
        yield 'Spotify ID of the wrong length' => [['url' => 'https://open.spotify.com/track/4uLU6hMCjMI75M1A2tKUQ'], 'url', Code::INVALID];
        yield 'a SoundCloud artist' => [['url' => 'https://soundcloud.com/forss'], 'url', Code::INVALID];
        yield 'a SoundCloud artist’s tracks page' => [['url' => 'https://soundcloud.com/forss/tracks'], 'url', Code::INVALID];
        yield 'a SoundCloud artist’s likes page' => [['url' => 'https://soundcloud.com/forss/likes'], 'url', Code::INVALID];
        yield 'a SoundCloud site page' => [['url' => 'https://soundcloud.com/discover/sets/new'], 'url', Code::INVALID];
        yield 'a private SoundCloud track' => [['url' => 'https://soundcloud.com/forss/flickermood/s-AbCdE'], 'url', Code::INVALID];
        yield 'a stored media ID with a path' => [['provider' => 'soundcloud', 'mediaId' => 'forss/../tracks'], 'mediaId', Code::INVALID];
    }

    /**
     * @param array<string, string> $data
     */
    #[DataProvider('refusedEmbeds')]
    public function testAnEmbedLinkOnlyAcceptsMediaItsProvidersRecognise(array $data, string $key, Code $code): void
    {
        $errors = self::errors(['type' => 'embed', 'data' => $data]);

        self::assertSame(["links[0].data.$key", $code], [$errors[0][0], $errors[0][1]]);
    }

    public function testDevelopersAddEmbedProviders(): void
    {
        $provider = new class() extends BaseEmbedProvider {
            public function handle(): string
            {
                return 'company-tv';
            }

            public function name(): string
            {
                return 'Company TV';
            }

            public function isMediaId(string $mediaId): bool
            {
                return (bool)preg_match('/^[0-9]+$/', $mediaId);
            }

            public function pageUrl(string $mediaId): string
            {
                return "https://tv.company.example/watch/$mediaId";
            }

            public function embedUrl(string $mediaId): string
            {
                return "https://tv.company.example/player/$mediaId";
            }

            protected function hosts(): array
            {
                return ['tv.company.example'];
            }

            protected function mediaIdFromPath(string $host, string $path, array $query): ?string
            {
                return preg_match('~^/watch/([^/]+)$~', $path, $match) ? $match[1] : null;
            }
        };
        $register = static function(RegisterEmbedProvidersEvent $event) use ($provider): void {
            $event->providers[] = $provider;
        };
        Event::on(EmbedLinkType::class, EmbedLinkType::EVENT_REGISTER_PROVIDERS, $register);
        self::resetPluginServices();

        try {
            $link = self::normalized(['type' => 'embed', 'data' => ['url' => 'https://tv.company.example/watch/42']]);
            self::assertSame(['provider' => 'company-tv', 'mediaId' => '42'], self::storedData($link));
        } finally {
            Event::off(EmbedLinkType::class, EmbedLinkType::EVENT_REGISTER_PROVIDERS, $register);
        }
    }

    // Elements

    public function testAnElementLinkResolvesThroughCraftsOwnElementQueries(): void
    {
        $live = self::savedEntry('resolve-live');
        $disabled = self::savedEntry('resolve-disabled', enabled: false);
        $trashed = self::savedEntry('resolve-trashed');
        $deleted = self::savedEntry('resolve-deleted');
        Craft::$app->getElements()->deleteElement($trashed);
        Craft::$app->getElements()->deleteElement($deleted, true);

        self::assertEquals(new ResolvedLink(ResolutionStatus::RESOLVED, $live->getUrl() . '#team', false, 'resolve-live'), self::resolve(self::entryLink($live, urlSuffix: '#team')));
        self::assertEquals(new ResolvedLink(ResolutionStatus::DISABLED, defaultLabel: 'resolve-disabled'), self::resolve(self::entryLink($disabled)));
        self::assertEquals(new ResolvedLink(ResolutionStatus::MISSING), self::resolve(self::entryLink($trashed)));
        self::assertEquals(new ResolvedLink(ResolutionStatus::MISSING), self::resolve(self::entryLink($deleted)));

        // The stored link is unchanged, so restoring the entry brings the link back.
        Craft::$app->getElements()->restoreElement($trashed);
        self::assertSame(ResolutionStatus::RESOLVED, self::resolve(self::entryLink($trashed))->status);
    }

    public function testALinkFollowsTheContentsSiteUnlessASiteIsChosen(): void
    {
        $entry = self::savedEntry('site-aware');
        $inSecond = Entry::find()->id($entry->id)->siteId(self::$secondSiteId)->one();
        self::assertNotNull($inSecond);

        $follow = self::entryLink($entry);
        $pinned = self::entryLink($entry, self::$secondSiteId);

        // The same link in each site's content leads to that site's version.
        self::assertSame($entry->getUrl(), self::resolve($follow, self::$primarySiteId)->url);
        self::assertSame($inSecond->getUrl(), self::resolve($follow, self::$secondSiteId)->url);
        self::assertStringStartsWith('https://second.example.test/', (string)$inSecond->getUrl());

        // A chosen site is linked from every site: a cross-site link.
        self::assertSame($inSecond->getUrl(), self::resolve($pinned, self::$primarySiteId)->url);

        // Disabled in one site only: live in the other.
        $entry->setEnabledForSite([self::$primarySiteId => true, self::$secondSiteId => false]);
        self::assertTrue(Craft::$app->getElements()->saveElement($entry));
        self::assertSame(ResolutionStatus::RESOLVED, self::resolve($follow, self::$primarySiteId)->status);
        self::assertSame(ResolutionStatus::DISABLED, self::resolve($follow, self::$secondSiteId)->status);
    }

    public function testATargetThatIsNotInTheLinkedSiteIsMissingThere(): void
    {
        $entry = new Entry(['sectionId' => self::$primaryOnly->id, 'typeId' => self::$entryType->id, 'siteId' => self::$primarySiteId, 'title' => 'primary-only', 'slug' => 'primary-only']);
        self::assertTrue(Craft::$app->getElements()->saveElement($entry), Json::encode($entry->getErrors()));
        $link = self::normalized(['type' => 'entry', 'data' => ['elementId' => (string)$entry->id]]);

        self::assertSame($entry->getUrl(), self::resolve($link, self::$primarySiteId)->url);
        self::assertSame(ResolutionStatus::MISSING, self::resolve($link, self::$secondSiteId)->status);

        // Pinned to the site it is in, it resolves from the other site's content too.
        $pinned = self::normalized(['type' => 'entry', 'data' => ['elementId' => (string)$entry->id, 'siteId' => (string)self::$primarySiteId]]);
        self::assertSame($entry->getUrl(), self::resolve($pinned, self::$secondSiteId)->url);
    }

    public function testATargetWithoutAPageInTheLinkedSiteHasNoUrlThere(): void
    {
        // Categories have pages in the primary site only.
        $category = self::savedCategory('no-page-in-second-site');
        $link = self::normalized(['type' => 'category', 'data' => ['elementId' => (string)$category->id]]);

        self::assertSame($category->getUrl(), self::resolve($link, self::$primarySiteId)->url);
        self::assertEquals(new ResolvedLink(ResolutionStatus::NO_URL, defaultLabel: 'no-page-in-second-site'), self::resolve($link, self::$secondSiteId));
    }

    public function testDraftsAndRevisionsAreNeverLinkTargets(): void
    {
        $entry = self::savedEntry('has-a-draft');
        $creator = User::find()->admin()->one();
        self::assertNotNull($creator);
        $draft = Craft::$app->getDrafts()->createDraft($entry, (int)$creator->id);
        $revisionId = Craft::$app->getRevisions()->createRevision($entry, (int)$creator->id);
        $revision = Entry::find()->id($revisionId)->revisions()->status(null)->one();
        self::assertNotNull($revision);

        foreach ([$draft, $revision] as $notCanonical) {
            $errors = self::errors(['type' => 'entry', 'data' => ['elementId' => (string)$notCanonical->id]]);
            self::assertSame([['links[0].data.elementId', Code::INVALID, "Element {$notCanonical->id} is a draft or revision. Link to the entry itself."]], $errors);

            // Built in code, past the editor: it still never resolves to the draft or revision.
            self::assertSame(ResolutionStatus::MISSING, self::resolve(new LinkValue(self::uid(), 'entry', new ElementLinkData((int)$notCanonical->id)))->status);
        }
    }

    public function testAnElementOfAnotherTypeIsRefusedAndNeverResolved(): void
    {
        $category = self::savedCategory('not-an-entry');

        self::assertSame(
            [['links[0].data.elementId', Code::INVALID, "Element {$category->id} is not one of the entries this link can point at."]],
            self::errors(['type' => 'entry', 'data' => ['elementId' => (string)$category->id]]),
        );
        self::assertSame(ResolutionStatus::MISSING, self::resolve(new LinkValue(self::uid(), 'entry', new ElementLinkData((int)$category->id)))->status);
    }

    public function testAnElementIdThatNoLongerExistsIsKeptAndResolvesAsMissing(): void
    {
        $gone = self::savedEntry('gone-before-editing');
        $id = (int)$gone->id;
        Craft::$app->getElements()->deleteElement($gone, true);

        // A link whose target was deleted can still be saved with the rest of the content.
        $link = self::normalized(['type' => 'entry', 'data' => ['elementId' => (string)$id]]);
        self::assertSame(['elementId' => $id], self::storedData($link));
        self::assertSame(ResolutionStatus::MISSING, self::resolve($link)->status);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, Code}>
     */
    public static function refusedElementData(): iterable
    {
        yield 'nothing chosen' => [['elementId' => ''], 'elementId', Code::MISSING];
        yield 'not an ID' => [['elementId' => 'abc'], 'elementId', Code::INVALID];
        yield 'a leading zero' => [['elementId' => '012'], 'elementId', Code::INVALID];
        yield 'zero' => [['elementId' => 0], 'elementId', Code::INVALID];
        yield 'a site that does not exist' => [['elementId' => 1, 'siteId' => '999999'], 'siteId', Code::INVALID];
        yield 'an unknown key' => [['elementId' => 1, 'title' => 'Copied title'], 'title', Code::UNKNOWN_KEY];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('refusedElementData')]
    public function testMalformedElementDataIsRefused(array $data, string $key, Code $code): void
    {
        $errors = self::errors(['type' => 'entry', 'data' => $data]);

        self::assertSame(["links[0].data.$key", $code], [$errors[0][0], $errors[0][1]]);
    }

    public function testAUserLinkHasNoSiteNoUrlOfItsOwnAndNeverRevealsAnAddress(): void
    {
        $user = User::find()->admin()->one();
        self::assertNotNull($user);
        $link = self::normalized(['type' => 'user', 'data' => ['elementId' => (string)$user->id]]);
        $fullName = $user->fullName !== null && $user->fullName !== '' ? $user->fullName : null;

        self::assertSame(['elementId' => (int)$user->id], self::storedData($link));
        self::assertEquals(new ResolvedLink(ResolutionStatus::NO_URL, defaultLabel: $fullName), self::resolve($link));

        $errors = self::errors(['type' => 'user', 'data' => ['elementId' => (string)$user->id, 'siteId' => (string)self::$primarySiteId]]);
        self::assertSame([['links[0].data.siteId', Code::NOT_SUPPORTED, 'Users are the same in every site, so a link to one cannot name a site.']], $errors);

        // A site that gives users pages, through Craft's own event, gets links to them.
        $defineUrl = static function(DefineUrlEvent $event): void {
            /** @var User $sender */
            $sender = $event->sender;
            $event->url = "https://example.com/authors/{$sender->id}";
        };
        Event::on(User::class, Element::EVENT_DEFINE_URL, $defineUrl);

        try {
            self::assertEquals(new ResolvedLink(ResolutionStatus::RESOLVED, "https://example.com/authors/{$user->id}", false, $fullName), self::resolve($link));
        } finally {
            Event::off(User::class, Element::EVENT_DEFINE_URL, $defineUrl);
        }

        // Craft's default user query finds every enabled user, a suspended one too; a disabled
        // user is not live.
        Db::update(Table::USERS, ['suspended' => true], ['id' => $user->id]);
        self::assertSame(ResolutionStatus::NO_URL, self::resolve($link)->status);
        Db::update(Table::ELEMENTS, ['enabled' => false], ['id' => $user->id]);
        self::assertSame(ResolutionStatus::DISABLED, self::resolve($link)->status);
        Db::update(Table::USERS, ['suspended' => false], ['id' => $user->id]);
        Db::update(Table::ELEMENTS, ['enabled' => true], ['id' => $user->id]);
    }

    public function testAnAssetLinkLeadsToItsFileAndCanDownloadIt(): void
    {
        $public = self::savedAsset(self::$publicVolume, 'Price list 2026.pdf');
        $private = self::savedAsset(self::$privateVolume, 'internal.pdf');
        $link = self::normalized(['type' => 'asset', 'data' => ['elementId' => (string)$public->id], 'attributes' => ['download' => '1', 'downloadFilename' => 'prices.pdf']]);

        $resolved = self::resolve($link);
        self::assertSame($public->getUrl(), $resolved->url);
        self::assertStringStartsWith('https://files.example.test/', (string)$resolved->url);
        self::assertSame(['download' => 'prices.pdf'], SmartLinks::getInstance()->getLinks()->render($link, self::$primarySiteId)?->attributes);

        // A file without a public URL leads nowhere, and says so.
        self::assertSame(ResolutionStatus::NO_URL, self::resolve(self::normalized(['type' => 'asset', 'data' => ['elementId' => (string)$private->id]]))->status);

        // Downloading applies to files only.
        $entry = self::savedEntry('no-download');
        self::assertSame(Code::NOT_SUPPORTED, self::errors(['type' => 'entry', 'data' => ['elementId' => (string)$entry->id], 'attributes' => ['download' => '1']])[0][1]);
    }

    public function testACommerceProductLinkResolvesWhileCommerceIsInstalled(): void
    {
        if (self::$productTypeId === null) {
            self::markTestSkipped('Craft Commerce is not installed and enabled in this run.');
        }

        $type = self::types()->get('commerce-product');
        self::assertInstanceOf(ProductLinkType::class, $type);
        self::assertSame(ProductLinkType::PRODUCT_CLASS, $type->elementType());
        self::assertSame(['product'], $type->craftLinkTypes());

        $live = self::savedProduct('Smart Links mug', true);
        $disabled = self::savedProduct('Smart Links hat', false);
        $link = self::normalized(['type' => 'commerce-product', 'data' => ['elementId' => (string)$live->id]]);

        self::assertSame(['elementId' => (int)$live->id], self::storedData($link));
        self::assertEquals(new ResolvedLink(ResolutionStatus::RESOLVED, $live->getUrl(), false, 'Smart Links mug'), self::resolve($link));
        self::assertSame(ResolutionStatus::DISABLED, self::resolve(self::normalized(['type' => 'commerce-product', 'data' => ['elementId' => (string)$disabled->id]]))->status);
        self::assertSame("commerce-product?elementId={$live->id}&siteId=" . self::$primarySiteId, $type->targetIdentity($link->data, 1, self::$primarySiteId)->key());

        // A product is not an entry, and an entry is not a product.
        self::assertSame(Code::INVALID, self::errors(['type' => 'entry', 'data' => ['elementId' => (string)$live->id]])[0][1]);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function withoutCommerce(): iterable
    {
        yield 'Commerce disabled' => [['CRAFT_DISABLED_PLUGINS' => 'commerce']];
        yield 'Commerce’s code missing' => [['SMARTLINKS_COMMERCE_MISSING' => '1']];
    }

    /**
     * @param array<string, string> $environment
     */
    #[DataProvider('withoutCommerce')]
    public function testWithoutCommerceNothingOfItIsLoadedAndStoredProductLinksAreKept(array $environment): void
    {
        $stored = ['version' => 1, 'links' => [['uid' => self::uid(), 'type' => 'commerce-product', 'data' => ['elementId' => 123]]]];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../_support/commerce-absent.php', Json::encode($stored)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 4),
            // Each way of being without Commerce on its own, whatever this run was started with.
            $environment + array_diff_key(getenv(), ['CRAFT_DISABLED_PLUGINS' => true, 'SMARTLINKS_COMMERCE_MISSING' => true]),
        );
        self::assertIsResource($process);
        $output = (string)stream_get_contents($pipes[1]);
        $errors = (string)stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $errors . $output);

        $result = Json::decode($output);
        self::assertIsArray($result, $output);

        self::assertFalse($result['commerceLoaded']);
        self::assertSame(['url', 'entry', 'category', 'asset', 'user', 'email', 'tel', 'sms', 'social', 'embed'], $result['handles']);
        // A product link stored while Commerce was there is kept exactly, never dropped.
        self::assertTrue($result['kept']);
        self::assertTrue($result['storedAgain']);
        self::assertStringContainsString('commerce-product (not available)', $result['settingsHtml']);
        // Every other type still works, and GraphQL has no product type.
        self::assertSame(['https://example.com/', 'mailto:hello@example.com', 'https://github.com/craftcms', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'], $result['hrefs']);
        self::assertNotContains('SmartLinkData_commerce_product', $result['gqlDataTypes']);
        self::assertContains('SmartLinkData_entry', $result['gqlDataTypes']);
        // No Commerce class was loaded, and Smart Links never asked for one.
        self::assertSame([], $result['commerceClasses']);
        self::assertSame([], array_values(array_filter($result['refused'], static fn(array $attempt): bool => $attempt['bySmartLinks'])));

        if (isset($environment['SMARTLINKS_COMMERCE_MISSING'])) {
            // The simulation took hold: Craft itself looked for Commerce's plugin class, and found none.
            self::assertContains('craft\\commerce\\Plugin', array_column($result['refused'], 'class'));
        }
    }

    public function testElementLinksIdentifyTheirTargetsSiteVersion(): void
    {
        $entry = self::savedEntry('identity');
        $type = self::types()->get('entry');
        $user = self::types()->get('user');
        self::assertNotNull($type);
        self::assertNotNull($user);

        self::assertSame("entry?elementId={$entry->id}&siteId=" . self::$secondSiteId, $type->targetIdentity(new ElementLinkData((int)$entry->id), 1, self::$secondSiteId)->key());
        self::assertSame("entry?elementId={$entry->id}&siteId=" . self::$primarySiteId, $type->targetIdentity(new ElementLinkData((int)$entry->id, self::$primarySiteId), 1, self::$secondSiteId)->key());
        self::assertSame('user?elementId=5', $user->targetIdentity(new ElementLinkData(5), 1, self::$secondSiteId)->key());
    }

    public function testEveryElementTypeIsARelationCraftReadsBothWays(): void
    {
        $user = User::find()->admin()->one();
        self::assertNotNull($user);
        $gone = self::savedEntry('rel-gone');
        $goneId = (int)$gone->id;
        Craft::$app->getElements()->deleteElement($gone, true);
        $targets = [
            'entry' => self::savedEntry('rel-entry'),
            'category' => self::savedCategory('rel-category'),
            'asset' => self::savedAsset(self::$publicVolume, 'rel.pdf'),
            'user' => $user,
        ];

        if (self::$productTypeId !== null) {
            $targets['commerce-product'] = self::savedProduct('Relation mug', true);
        }

        $links = [['type' => 'url', 'data' => ['url' => 'https://example.com/']]];

        foreach ($targets as $type => $target) {
            $links[] = ['type' => $type, 'data' => ['elementId' => (string)$target->id]];
        }

        // A second link to a target, and a link to one that no longer exists, add no relation.
        $links[] = ['type' => 'entry', 'data' => ['elementId' => (string)$targets['entry']->id], 'label' => 'Again'];
        $links[] = ['type' => 'entry', 'data' => ['elementId' => (string)$goneId]];

        $source = self::savedEntry('rel-every-type', [self::ALL => $links]);
        $field = self::allField();
        $targetIds = array_values(array_map(static fn($target): int => (int)$target->id, $targets));
        self::assertSame($targetIds, $field->getRelationTargetIds($source));

        // One row per target, in link order, owned by the field, for the source in its site.
        self::assertSame(
            array_map(static fn($target, int $index): array => [(int)$field->id, self::$primarySiteId, (int)$target->id, $index + 1], $targets, array_keys(array_values($targets))),
            self::relations($source, self::$primarySiteId),
        );

        foreach ($targets as $type => $target) {
            // Reverse: the source is what links to the target.
            self::assertSame([(int)$source->id], array_map('intval', Entry::find()->relatedTo(['targetElement' => $target])->ids()), $type);
            // Forward: the target is what the source links to, in its own element type's query.
            self::assertSame([(int)$target->id], array_map('intval', $target::find()->relatedTo(['sourceElement' => $source])->ids()), $type);
        }
    }

    public function testRelationsFollowTheValueAsItChanges(): void
    {
        $first = self::savedEntry('rel-first');
        $second = self::savedEntry('rel-second');
        $third = self::savedEntry('rel-third');
        $field = self::allField();
        $link = static fn(Entry $entry): array => ['type' => 'entry', 'data' => ['elementId' => (string)$entry->id]];
        $source = self::savedEntry('rel-changing', [self::ALL => [$link($first), $link($second)]]);
        $save = static function(array $links) use ($source): void {
            $source->setFieldValue(self::ALL, self::entered($links));
            self::assertTrue(Craft::$app->getElements()->saveElement($source), Json::encode($source->getErrors()));
        };
        $targets = static fn(): array => array_column(self::relations($source, self::$primarySiteId), 2);

        self::assertSame([(int)$first->id, (int)$second->id], $targets());

        // Reordered.
        $save([$link($second), $link($first)]);
        self::assertSame([(int)$second->id, (int)$first->id], $targets());

        // A target changed, one added, one removed.
        $save([$link($third), ['type' => 'email', 'data' => ['address' => 'a@example.com']]]);
        self::assertSame([(int)$third->id], $targets());
        self::assertSame([], array_map('intval', Entry::find()->relatedTo($first)->ids()));

        // Duplicated: the copy has its own relations, the original keeps its.
        $copy = Craft::$app->getElements()->duplicateElement($source);
        self::assertSame([[(int)$field->id, self::$primarySiteId, (int)$third->id, 1]], self::relations($copy, self::$primarySiteId));
        self::assertEqualsCanonicalizing([(int)$source->id, (int)$copy->id], array_map('intval', Entry::find()->relatedTo($third)->ids()));

        // No links left: no relations left.
        $save([]);
        self::assertSame([], $targets());

        // A deleted source takes its relations with it (Craft's foreign key).
        Craft::$app->getElements()->deleteElement($copy, true);
        self::assertSame([], self::relations($copy, self::$primarySiteId));
    }

    public function testRelationsAreKeptPerSiteAndFollowTheFieldsTranslation(): void
    {
        $inPrimary = self::savedEntry('rel-site-primary');
        $inSecond = self::savedEntry('rel-site-second');
        $shared = self::savedEntry('rel-site-shared');
        $source = self::savedEntry('rel-sites', [
            self::ALL => [['type' => 'entry', 'data' => ['elementId' => (string)$inPrimary->id]]],
            self::SHARED => [['type' => 'entry', 'data' => ['elementId' => (string)$shared->id]]],
        ]);

        // The translated field gets another link in the second site.
        $second = Entry::find()->id($source->id)->siteId(self::$secondSiteId)->one();
        self::assertNotNull($second);
        $second->setFieldValue(self::ALL, self::entered([['type' => 'entry', 'data' => ['elementId' => (string)$inSecond->id, 'siteId' => (string)self::$secondSiteId]]]));
        self::assertTrue(Craft::$app->getElements()->saveElement($second), Json::encode($second->getErrors()));

        $byField = static fn(int $siteId, SmartLinkField $field): array => array_column(array_filter(self::relations($source, $siteId), static fn(array $row): bool => $row[0] === (int)$field->id), 2);
        $sharedField = self::$entryType->getFieldLayout()->getFieldByHandle(self::SHARED);
        self::assertInstanceOf(SmartLinkField::class, $sharedField);

        self::assertSame([(int)$inPrimary->id], $byField(self::$primarySiteId, self::allField()));
        self::assertSame([(int)$inSecond->id], $byField(self::$secondSiteId, self::allField()));
        // The untranslated value is the same in every site, and so are its relations.
        self::assertSame([(int)$shared->id], $byField(self::$primarySiteId, $sharedField));
        self::assertSame([(int)$shared->id], $byField(self::$secondSiteId, $sharedField));

        // Craft reads each site's relations for that site.
        self::assertSame([(int)$source->id], array_map('intval', Entry::find()->relatedTo(['targetElement' => $inSecond, 'sourceSite' => self::$secondSiteId])->ids()));
        self::assertSame([], array_map('intval', Entry::find()->relatedTo(['targetElement' => $inSecond, 'sourceSite' => self::$primarySiteId])->ids()));
    }

    public function testADraftHasItsOwnRelationsUntilItIsApplied(): void
    {
        $before = self::savedEntry('rel-draft-before');
        $after = self::savedEntry('rel-draft-after');
        $creator = User::find()->admin()->one();
        self::assertNotNull($creator);
        $source = self::savedEntry('rel-draft', [self::ALL => [['type' => 'entry', 'data' => ['elementId' => (string)$before->id]]]]);

        $draft = Craft::$app->getDrafts()->createDraft($source, (int)$creator->id);
        $draft->setFieldValue(self::ALL, self::entered([['type' => 'entry', 'data' => ['elementId' => (string)$after->id]]]));
        self::assertTrue(Craft::$app->getElements()->saveElement($draft), Json::encode($draft->getErrors()));

        // The draft's links are the draft's; the live entry still links where it did.
        self::assertSame([(int)$after->id], array_column(self::relations($draft, self::$primarySiteId), 2));
        self::assertSame([(int)$before->id], array_column(self::relations($source, self::$primarySiteId), 2));
        // Drafts are not live content, so they are not found as sources.
        self::assertSame([], array_map('intval', Entry::find()->relatedTo($after)->ids()));

        Craft::$app->getDrafts()->applyDraft($draft);
        self::assertSame([(int)$after->id], array_column(self::relations($source, self::$primarySiteId), 2));
        self::assertSame([(int)$source->id], array_map('intval', Entry::find()->relatedTo($after)->ids()));
    }

    public function testATargetsStateChangesItsRelationOnlyWhenCraftDeletesIt(): void
    {
        $disabled = self::savedEntry('rel-disabled', enabled: false);
        $trashed = self::savedEntry('rel-trashed');
        $deleted = self::savedEntry('rel-deleted');
        $pinned = self::savedEntry('rel-pinned');
        $primaryOnly = new Entry(['sectionId' => self::$primaryOnly->id, 'typeId' => self::$entryType->id, 'siteId' => self::$primarySiteId, 'title' => 'rel-primary-only', 'slug' => 'rel-primary-only']);
        self::assertTrue(Craft::$app->getElements()->saveElement($primaryOnly));
        $link = static fn(Entry $entry, ?int $siteId = null): array => ['type' => 'entry', 'data' => ['elementId' => (string)$entry->id] + ($siteId !== null ? ['siteId' => (string)$siteId] : [])];
        $source = self::savedEntry('rel-states', [self::ALL => [$link($disabled), $link($trashed), $link($deleted), $link($pinned, self::$secondSiteId), $link($primaryOnly)]]);

        $all = [(int)$disabled->id, (int)$trashed->id, (int)$deleted->id, (int)$pinned->id, (int)$primaryOnly->id];
        self::assertSame($all, array_column(self::relations($source, self::$primarySiteId), 2));

        Craft::$app->getElements()->deleteElement($trashed);
        Craft::$app->getElements()->deleteElement($deleted, true);

        // A relation is between elements, whatever their status, trash or site. Craft keeps no
        // foreign key on a relation's target: the relation to a hard-deleted target is an orphan,
        // which Craft's garbage collection removes (its query, as in Gc::_deleteOrphanedRelations()).
        $afterDeleting = array_column(self::relations($source, self::$primarySiteId), 2);
        self::assertSame([(int)$disabled->id, (int)$trashed->id, (int)$deleted->id, (int)$pinned->id, (int)$primaryOnly->id], $afterDeleting);
        $orphans = (new \craft\db\Query())->select('r.targetId')->from(['r' => Table::RELATIONS])
            ->leftJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[r.targetId]]')
            ->where(['e.id' => null, 'r.sourceId' => $source->id])->column();
        self::assertSame([(int)$deleted->id], array_values(array_unique(array_map('intval', $orphans))));

        // Saving the links again writes no relation to a target that is gone.
        $source->setFieldValue(self::ALL, $source->getFieldValue(self::ALL));
        $source->setDirtyFields([self::ALL]);
        self::assertTrue(Craft::$app->getElements()->saveElement($source));
        self::assertSame([(int)$disabled->id, (int)$trashed->id, (int)$pinned->id, (int)$primaryOnly->id], array_column(self::relations($source, self::$primarySiteId), 2));

        // The stored link is untouched, and says missing.
        $value = Entry::find()->id($source->id)->one()?->getFieldValue(self::ALL);
        self::assertInstanceOf(LinkCollection::class, $value);
        self::assertSame((int)$deleted->id, $value->links[2]->data->toArray()['elementId']);
        self::assertSame(ResolutionStatus::MISSING, self::resolve($value->links[2])->status);
    }

    public function testCraftsRelatedToFieldCriterionTreatsCraftsOwnLinkFieldTheSameWay(): void
    {
        $target = self::savedEntry('rel-criterion-target');
        $smart = self::savedEntry('rel-criterion-smart', [self::ALL => [['type' => 'entry', 'data' => ['elementId' => (string)$target->id]]]]);
        $craft = new Entry(['sectionId' => self::$section->id, 'typeId' => self::$entryType->id, 'siteId' => self::$primarySiteId, 'title' => 'rel-criterion-craft', 'slug' => 'rel-criterion-craft']);
        $craft->setFieldValue(self::CRAFT_LINK, ['type' => 'entry', 'value' => sprintf('{entry:%d@%d:url}', $target->id, self::$primarySiteId)]);
        self::assertTrue(Craft::$app->getElements()->saveElement($craft), Json::encode($craft->getErrors()));

        // Both fields write relations Craft's reverse lookup finds.
        self::assertEqualsCanonicalizing([(int)$smart->id, (int)$craft->id], array_map('intval', Entry::find()->relatedTo($target)->ids()));

        // Craft's `field` criterion accepts only its relation fields and Matrix (see
        // ElementRelationParamParser): it refuses its own Link field exactly as it refuses this one.
        self::assertSame([], Entry::find()->relatedTo(['targetElement' => $target, 'field' => self::CRAFT_LINK])->ids());
        self::assertSame([], Entry::find()->relatedTo(['targetElement' => $target, 'field' => self::ALL])->ids());
    }

    public function testTheElementInputShowsOnlyWhatTheUserMayView(): void
    {
        // Element chips are rendered for a control panel request.
        $this->useWebRequest();
        $entry = self::savedEntry('chosen-entry');
        $type = self::types()->get('entry');
        self::assertNotNull($type);
        $gone = self::savedEntry('chosen-gone');
        $goneId = (int)$gone->id;
        Craft::$app->getElements()->deleteElement($gone, true);

        $this->signIn(true);
        $html = $type->inputHtml(new ElementLinkData((int)$entry->id), self::$primarySiteId);
        self::assertStringContainsString('chosen-entry', $html);
        self::assertMatchesRegularExpression('/<input type="hidden" id="elementId" name="elementId" value="' . $entry->id . '"/', $html);
        self::assertStringContainsString('The site the link appears in', $html);

        // Someone who may not view it sees that it is there, not what it is, and the link keeps it.
        $this->signIn(false);
        $html = $type->inputHtml(new ElementLinkData((int)$entry->id), self::$primarySiteId);
        self::assertStringNotContainsString('chosen-entry', $html);
        self::assertStringContainsString("You can’t view the entry this link points at (ID {$entry->id}).", $html);
        self::assertStringContainsString('value="' . $entry->id . '"', $html);

        // A target that no longer exists is named as such, and kept until the author changes it.
        $this->signIn(true);
        $html = $type->inputHtml(new ElementLinkData($goneId), self::$primarySiteId);
        self::assertStringContainsString("The entry this link points at (ID $goneId) no longer exists.", $html);
        self::assertStringContainsString('value="' . $goneId . '"', $html);
    }

    public function testPastingALinkNeverRevealsAnElementTheUserCannotView(): void
    {
        // An entry in a section the author has no permissions for.
        $hidden = new Entry(['sectionId' => self::$primaryOnly->id, 'typeId' => self::$entryType->id, 'siteId' => self::$primarySiteId, 'title' => 'Secret launch plan', 'slug' => 'secret-launch-plan']);
        self::assertTrue(Craft::$app->getElements()->saveElement($hidden), Json::encode($hidden->getErrors()));
        $destination = self::savedEntry('paste-destination');
        $params = self::editorParams(self::editorFor($destination, self::ALL)) + [
            'clipboard' => Json::encode(['version' => 1, 'links' => [['type' => 'entry', 'data' => ['elementId' => (int)$hidden->id]]]]),
            'count' => '0',
            'operation' => '1',
        ];

        $response = $this->runFieldAction('paste', $params, self::author());
        $html = $response->data['links'][0]['html'];
        self::assertStringNotContainsString('Secret launch plan', $html);
        self::assertStringContainsString("You can’t view the entry this link points at (ID {$hidden->id}).", $html);
        self::assertStringContainsString('value="' . $hidden->id . '"', $html);

        // Someone who may view it sees what it is, in a request of their own.
        $this->restoreConsoleRequest();
        $admin = new \Tahadudhiya\SmartLinks\Tests\_support\TestUser();
        $admin->admin = true;
        $response = $this->runFieldAction('paste', $params, $admin);
        self::assertStringContainsString('Secret launch plan', $response->data['links'][0]['html']);
    }

    public function testAProviderOrNetworkThatBuildsAnUnsafeUrlNeverReachesAPage(): void
    {
        $provider = new class() extends BaseEmbedProvider {
            public function handle(): string
            {
                return 'leaky-tv';
            }

            public function name(): string
            {
                return 'Leaky TV';
            }

            public function isMediaId(string $mediaId): bool
            {
                return (bool)preg_match('/^[0-9]+$/', $mediaId);
            }

            public function pageUrl(string $mediaId): string
            {
                // Not https: a page off the web's secure origin.
                return "http://leaky.example/watch/$mediaId";
            }

            public function embedUrl(string $mediaId): string
            {
                return "javascript:alert($mediaId)";
            }

            protected function hosts(): array
            {
                return ['leaky.example'];
            }

            protected function mediaIdFromPath(string $host, string $path, array $query): ?string
            {
                return preg_match('~^/watch/([^/]+)$~', $path, $match) ? $match[1] : null;
            }
        };
        $registerProvider = static function(RegisterEmbedProvidersEvent $event) use ($provider): void {
            $event->providers[] = $provider;
        };
        $registerNetwork = static function(RegisterSocialNetworksEvent $event): void {
            $event->networks[] = new SocialNetwork('plain-http', 'Plain HTTP', '[a-z]+', 'http://plain.example/{account}', false);
        };
        Event::on(EmbedLinkType::class, EmbedLinkType::EVENT_REGISTER_PROVIDERS, $registerProvider);
        Event::on(SocialLinkType::class, SocialLinkType::EVENT_REGISTER_NETWORKS, $registerNetwork);
        self::resetPluginServices();

        try {
            $embed = self::normalized(['type' => 'embed', 'data' => ['url' => 'https://leaky.example/watch/7']]);
            $social = self::normalized(['type' => 'social', 'data' => ['network' => 'plain-http', 'account' => 'jane']]);
            $type = self::types()->get('embed');
            self::assertInstanceOf(EmbedLinkType::class, $type);
            self::assertInstanceOf(EmbedLinkData::class, $embed->data);

            foreach ([$embed, $social] as $link) {
                try {
                    self::resolve($link);
                    self::fail("The {$link->type} link resolved.");
                } catch (LinkResolutionException $exception) {
                    self::assertStringContainsString('not an absolute https URL in canonical form', (string)$exception->getPrevious()?->getMessage());
                }
            }

            $this->expectException(\LogicException::class);
            $type->embedUrl($embed->data);
        } finally {
            Event::off(EmbedLinkType::class, EmbedLinkType::EVENT_REGISTER_PROVIDERS, $registerProvider);
            Event::off(SocialLinkType::class, SocialLinkType::EVENT_REGISTER_NETWORKS, $registerNetwork);
        }
    }

    public function testAPreviewNeverNamesATargetThatLeadsNowhere(): void
    {
        $disabled = self::savedEntry('preview-disabled-secret', enabled: false);
        $live = self::savedEntry('preview-live');
        $source = self::savedEntry('preview-source', [self::ALL => [
            ['type' => 'entry', 'data' => ['elementId' => (string)$disabled->id]],
            ['type' => 'entry', 'data' => ['elementId' => (string)$live->id]],
        ]]);

        $preview = self::allField()->getPreviewHtml($source->getFieldValue(self::ALL), $source);

        self::assertStringNotContainsString('preview-disabled-secret', $preview);
        self::assertStringContainsString('Entry (leads nowhere: its target is disabled)', $preview);
        // A live target is public, and is shown as the link it renders as.
        self::assertStringContainsString('>preview-live</a>', $preview);
    }

    public function testTheElementInputSaysWhenTheTargetIsNotInTheLinkedSiteOrTheUserCannotWorkInIt(): void
    {
        $this->useWebRequest();
        $type = self::types()->get('entry');
        self::assertNotNull($type);
        $primaryOnly = new Entry(['sectionId' => self::$primaryOnly->id, 'typeId' => self::$entryType->id, 'siteId' => self::$primarySiteId, 'title' => 'input-primary-only', 'slug' => 'input-primary-only']);
        self::assertTrue(Craft::$app->getElements()->saveElement($primaryOnly));
        $everywhere = self::savedEntry('input-everywhere');
        $this->signIn(true);

        // Edited in the second site, where the entry is not: said so, and not shown as another site's.
        $html = $type->inputHtml(new ElementLinkData((int)$primaryOnly->id), self::$secondSiteId);
        self::assertStringNotContainsString('input-primary-only', $html);
        self::assertStringContainsString("The entry this link points at (ID {$primaryOnly->id}) is not in the Smart Links second site site, so the link leads nowhere there.", $html);
        self::assertStringContainsString('value="' . $primaryOnly->id . '"', $html);

        // Pinned to the primary site, it is shown from there, whichever site is edited.
        self::assertStringContainsString('input-primary-only', $type->inputHtml(new ElementLinkData((int)$primaryOnly->id, self::$primarySiteId), self::$secondSiteId));

        // A user who may edit entries in the primary site only does not see the second site's version.
        Craft::$app->getUser()->setIdentity(self::author([self::$primarySiteId]));
        $html = $type->inputHtml(new ElementLinkData((int)$everywhere->id, self::$secondSiteId), self::$primarySiteId);
        self::assertStringNotContainsString('input-everywhere', $html);
        self::assertStringContainsString("You can’t view the entry this link points at (ID {$everywhere->id}).", $html);
        self::assertStringContainsString('input-everywhere', $type->inputHtml(new ElementLinkData((int)$everywhere->id), self::$primarySiteId));
    }

    public function testAPickerOffersOnlyWhatTheUserMayView(): void
    {
        // What each element type's picker is given to choose from, for the signed-in user.
        $pickerOf = static function(string $handle): array {
            $type = SmartLinks::getInstance()->getLinkTypes()->getType($handle);
            self::assertInstanceOf(BaseElementLinkType::class, $type);

            return (fn(): array => ['sources' => (array)$this->sources(), 'criteria' => $this->selectionCriteria()])->call($type);
        };
        $craftSources = static fn(string $class): array => array_values(array_map(
            static fn(array $source): string => (string)$source['key'],
            array_filter(Craft::$app->getElementSources()->getSources($class, \craft\services\ElementSources::CONTEXT_INDEX), static fn(array $source): bool => ($source['type'] ?? null) === \craft\services\ElementSources::TYPE_NATIVE),
        ));

        // Entries and categories: exactly the user's own element index sources, which Craft limits
        // to what the user may view in the control panel (it lists everything to the console, so
        // that limit is proven by the control panel test), and only what they may work with.
        $this->signIn(false, ['accessCp']);
        self::assertSame($craftSources(Entry::class), $pickerOf('entry')['sources']);
        self::assertSame(['uri' => ':notempty:', 'editable' => true], $pickerOf('entry')['criteria']);
        self::assertSame($craftSources(Category::class), $pickerOf('category')['sources']);
        self::assertTrue($pickerOf('category')['criteria']['editable']);

        // Users and assets: Smart Links' own rules, the same as for showing a chosen one.
        self::assertSame([], $pickerOf('user')['sources']);
        self::assertSame([], $pickerOf('asset')['sources']);
        $this->signIn(false, ['accessCp', 'viewUsers', 'viewAssets:' . self::$publicVolume->uid, 'viewAssets:' . self::$privateVolume->uid]);
        self::assertSame(['*'], $pickerOf('user')['sources']);
        // A volume without public URLs is never offered: its files lead nowhere.
        self::assertSame(['volume:' . self::$publicVolume->uid], array_values(array_intersect($pickerOf('asset')['sources'], ['volume:' . self::$publicVolume->uid, 'volume:' . self::$privateVolume->uid])));
    }

    public function testElementLinksCannotBeDefaultLinks(): void
    {
        $entry = self::savedEntry('default-target');
        $field = Craft::$app->getFields()->createField([
            'type' => SmartLinkField::class,
            'name' => 'Defaults with an entry',
            'handle' => 'smartLinksTestEntryDefault',
            'types' => ['url', 'entry'],
            'defaultLinksInput' => ['links' => [['type' => 'entry', 'data' => ['entry' => ['elementId' => (string)$entry->id]]]]],
        ]);
        self::assertInstanceOf(SmartLinkField::class, $field);

        self::assertFalse($field->validate());
        self::assertSame(['Link 1: Entry links can’t be default links: element IDs differ between environments.'], $field->getErrors('defaultLinks'));

        // The defaults editor offers only the types defaults can have.
        $field->uid = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
        self::assertSame(['url', 'email', 'tel', 'sms', 'social', 'embed'], array_column(LinkEditor::forSettings($field)->offeredTypes(), 'handle'));
    }

    // Every type

    /**
     * Authoring input for one link of every type. Element IDs are filled in from the fixture.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function oneOfEach(): array
    {
        $entry = self::savedEntry('one-of-each-target');
        $user = User::find()->admin()->one();
        self::assertNotNull($user);
        $links = [
            'url' => ['url' => 'https://example.com/docs?page=2'],
            'entry' => ['elementId' => (string)$entry->id, 'siteId' => (string)self::$secondSiteId],
            'category' => ['elementId' => (string)self::savedCategory('one-of-each')->id],
            'asset' => ['elementId' => (string)self::savedAsset(self::$publicVolume, 'one-of-each.pdf')->id],
            'user' => ['elementId' => (string)$user->id],
            'email' => ['address' => 'hello@example.com', 'subject' => 'Hello'],
            'tel' => ['number' => '+1 555 123 4567'],
            'sms' => ['number' => '+15551234567', 'body' => 'Hi'],
            'social' => ['network' => 'github', 'account' => 'craftcms'],
            'embed' => ['url' => 'https://youtu.be/dQw4w9WgXcQ'],
        ];

        if (self::$productTypeId !== null) {
            $links['commerce-product'] = ['elementId' => (string)self::savedProduct('One of each', true)->id];
        }

        return $links;
    }

    public function testEveryTypeSurvivesStorageAndCraftContentUnchanged(): void
    {
        $serializer = SmartLinks::getInstance()->getLinks()->getSerializer();
        $input = [];

        foreach (self::oneOfEach() as $type => $data) {
            $input[] = ['type' => $type, 'data' => $data, 'label' => "A $type link"];
        }

        $value = self::entered($input);
        self::assertInstanceOf(LinkCollection::class, $value);

        // Through JSON as the database holds it, keys reordered as MySQL does.
        $stored = $serializer->serialize($value);
        $reordered = Json::decode(Json::encode($stored));
        self::assertIsArray($reordered);
        array_walk($reordered['links'], static function(array &$link): void {
            krsort($link['data']);
        });
        self::assertSame($stored, $serializer->serialize($serializer->deserialize($reordered)));

        // Through a real element save in both sites.
        $entry = self::savedEntry('every-type', [self::ALL => $value]);
        $reloaded = Entry::find()->id($entry->id)->siteId(self::$primarySiteId)->one();
        self::assertNotNull($reloaded);
        $reloadedValue = $reloaded->getFieldValue(self::ALL);
        self::assertInstanceOf(LinkCollection::class, $reloadedValue);
        self::assertSame($stored, $serializer->serialize($reloadedValue));

        // Every link resolves and renders (or says where it leads nowhere) in each site.
        foreach ([self::$primarySiteId, self::$secondSiteId] as $siteId) {
            foreach ($reloadedValue->links as $link) {
                self::assertInstanceOf(ResolvedLink::class, SmartLinks::getInstance()->getLinks()->resolve($link, $siteId));
            }
        }

        self::assertCount(count(self::handles()), $reloadedValue);
    }

    public function testEveryTypeRendersItsInputsForTheEditor(): void
    {
        // Element chips are rendered for a control panel request.
        $this->useWebRequest();
        $this->signIn(true);

        foreach (self::oneOfEach() as $handle => $data) {
            $type = self::types()->get($handle);
            self::assertNotNull($type);
            $value = $type->normalizeData($data);

            foreach ([null, $value, $data] as $shown) {
                $html = $type->inputHtml($shown, self::$primarySiteId);
                self::assertNotSame('', $html, $handle);
                self::assertStringNotContainsString('<script', $html, $handle);
            }
        }

        // What an author typed is encoded wherever it is shown again.
        $url = self::types()->get('url')?->inputHtml(['url' => '"><script>alert(1)</script>'], self::$primarySiteId) ?? '';
        self::assertStringNotContainsString('<script>alert(1)', $url);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $url);
    }

    public function testTypeDataIsReadableFromTwig(): void
    {
        $view = Craft::$app->getView();
        $social = self::normalized(['type' => 'social', 'data' => ['network' => 'x', 'account' => '@craftcms']]);
        $email = self::normalized(['type' => 'email', 'data' => ['address' => 'hello@example.com']]);

        self::assertSame('x craftcms', $view->renderString('{{ link.data.network }} {{ link.data.account }}', ['link' => $social]));
        self::assertSame('hello@example.com', $view->renderString('{{ link.data.address }}', ['link' => $email]));
    }

    /**
     * @return iterable<string, array{string, string, string, array<string, mixed>}>
     */
    public static function craftLinkValues(): iterable
    {
        yield 'URL' => ['url', 'url', 'https://example.com/a?b=1', ['url' => 'https://example.com/a?b=1']];
        yield 'entry in a site' => ['entry', 'entry', '{entry:12@2:url}', ['elementId' => 12, 'siteId' => 2]];
        yield 'entry without a site' => ['entry', 'entry', '{entry:12:url}', ['elementId' => 12]];
        yield 'category' => ['category', 'category', '{category:7@1:url}', ['elementId' => 7, 'siteId' => 1]];
        yield 'asset' => ['asset', 'asset', '{asset:3@1:url}', ['elementId' => 3, 'siteId' => 1]];
        yield 'email with a subject' => ['email', 'email', 'mailto:hello@example.com?subject=Hi%20there&body=Line', ['address' => 'hello@example.com', 'subject' => 'Hi there', 'body' => 'Line']];
        yield 'phone' => ['tel', 'tel', 'tel:+1 555 123 4567', ['number' => '+1 555 123 4567']];
        yield 'SMS, Craft style' => ['sms', 'sms', 'sms:+15551234567&body=Hi%20there', ['number' => '+15551234567', 'body' => 'Hi there']];
        yield 'SMS, RFC 5724 style' => ['sms', 'sms', 'sms:+15551234567?body=Hi', ['number' => '+15551234567', 'body' => 'Hi']];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('craftLinkValues')]
    public function testCraftsOwnLinkValuesConvertToAuthoringInput(string $handle, string $craftType, string $value, array $expected): void
    {
        $type = self::types()->get($handle);
        self::assertInstanceOf(CraftLinkConverterInterface::class, $type);
        self::assertContains($craftType, $type->craftLinkTypes());
        self::assertSame($expected, $type->dataFromCraftLink($value));
    }

    public function testWhatACraftLinkValueCannotHoldIsReportedNotDropped(): void
    {
        $email = self::types()->get('email');
        $entry = self::types()->get('entry');
        self::assertInstanceOf(CraftLinkConverterInterface::class, $email);
        self::assertInstanceOf(CraftLinkConverterInterface::class, $entry);

        foreach ([[$email, 'mailto:a@example.com?cc=b@example.com'], [$email, 'mailto:a@example.com?subject=1&subject=2'], [$entry, '{category:12@1:url}'], [$entry, 'https://example.com']] as [$type, $value]) {
            try {
                $type->dataFromCraftLink($value);
                self::fail("“{$value}” was converted.");
            } catch (\Tahadudhiya\SmartLinks\errors\LinkValidationException $exception) {
                self::assertNotSame([], $exception->errors);
            }
        }

        // Craft's Link field has no user links to convert.
        $user = self::types()->get('user');
        self::assertInstanceOf(CraftLinkConverterInterface::class, $user);
        self::assertSame([], $user->craftLinkTypes());
    }

    // Helpers

    /**
     * The built-in handles, in order: the product type only while Commerce is installed and
     * enabled.
     *
     * @return list<string>
     */
    private static function handles(): array
    {
        return ProductLinkType::isAvailable() ? self::HANDLES : array_values(array_diff(self::HANDLES, ['commerce-product']));
    }

    private static function allField(): SmartLinkField
    {
        $field = self::$entryType->getFieldLayout()->getFieldByHandle(self::ALL);
        self::assertInstanceOf(SmartLinkField::class, $field);

        return $field;
    }

    /**
     * An element's relations in a site, as Craft stores them: field, source site, target, order.
     * Read from the database each time.
     *
     * @phpstan-impure
     * @return list<array{int, int, int, int}>
     */
    private static function relations(\craft\base\ElementInterface $source, int $siteId): array
    {
        $rows = (new \craft\db\Query())
            ->select(['fieldId', 'sourceSiteId', 'targetId', 'sortOrder'])
            ->from(Table::RELATIONS)
            ->where(['sourceId' => $source->id, 'sourceSiteId' => $siteId])
            ->orderBy(['fieldId' => SORT_ASC, 'sortOrder' => SORT_ASC])
            ->all();

        return array_map(static fn(array $row): array => array_map('intval', array_values($row)), $rows);
    }

    private static function types(): \Tahadudhiya\SmartLinks\linktypes\LinkTypeSet
    {
        return SmartLinks::getInstance()->getLinkTypes()->getTypeSet();
    }

    private static function uid(): string
    {
        return \craft\helpers\StringHelper::UUID();
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function normalized(array $input): LinkValue
    {
        $result = SmartLinks::getInstance()->getLinks()->getNormalizer()->normalize([$input]);
        $value = $result->value;
        self::assertInstanceOf(LinkCollection::class, $value, Json::encode(array_map(static fn(ValidationError $error): string => "$error->path: {$error->getMessage()}", $result->errors)));

        return $value->links[0];
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array{string, Code, string}>
     */
    private static function errors(array $input): array
    {
        $result = SmartLinks::getInstance()->getLinks()->getNormalizer()->normalize([$input]);
        self::assertNull($result->value);

        return array_map(static fn(ValidationError $error): array => [$error->path, $error->code, $error->getMessage()], $result->errors);
    }

    /**
     * @return array<string, mixed>
     */
    private static function storedData(LinkValue $link): array
    {
        $stored = SmartLinks::getInstance()->getLinks()->getSerializer()->serialize(new LinkCollection([$link]));
        self::assertIsArray($stored);

        return $stored['links'][0]['data'];
    }

    private static function resolve(LinkValue $link, ?int $siteId = null): ResolvedLink
    {
        return SmartLinks::getInstance()->getLinks()->resolve($link, $siteId ?? self::$primarySiteId);
    }

    private static function entryLink(Entry $entry, ?int $siteId = null, ?string $urlSuffix = null): LinkValue
    {
        return LinkValue::create('entry', new ElementLinkData((int)$entry->id, $siteId), urlSuffix: $urlSuffix, attributes: new LinkAttributes());
    }
}
