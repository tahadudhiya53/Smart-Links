<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use Craft;
use craft\helpers\Cp;
use GraphQL\Type\Definition\Type;
use Tahadudhiya\SmartLinks\enums\LinkFeature;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\helpers\DomainName;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ResolvedLink;
use Tahadudhiya\SmartLinks\models\TargetIdentity;
use Tahadudhiya\SmartLinks\models\ValidationError;

/**
 * A link that starts an email (`mailto:`), optionally with a subject and a body.
 *
 * Only an address's domain is normalized: DNS ignores case, and an IDN's ASCII form is the same
 * domain. The local part is case-sensitive (RFC 5321), so it is kept as authored. Addresses are
 * limited to the common dot-atom form, without quoted local parts, comments or IP literals, so
 * every accepted address means one thing to every mail client.
 */
class EmailLinkType implements LinkTypeInterface, LinkResolverInterface, CraftLinkConverterInterface, GqlLinkTypeInterface
{
    /** RFC 5322 dot-atom: the characters an unquoted local part may contain, in dot-separated runs. */
    private const LOCAL_PART = "/^[A-Za-z0-9!#$%&'*+\\/=?^_`{|}~-]+(?:\\.[A-Za-z0-9!#$%&'*+\\/=?^_`{|}~-]+)*$/";

    private const KEYS = ['address', 'subject', 'body'];

    public function handle(): string
    {
        return 'email';
    }

    public function displayName(): string
    {
        return Craft::t('smart-links', 'Email');
    }

    public function supportedFeatures(): array
    {
        return [LinkFeature::TITLE, LinkFeature::CLASS_NAMES, LinkFeature::ID, LinkFeature::ARIA_LABEL, LinkFeature::CUSTOM_ATTRIBUTES];
    }

    public function normalizeData(array $input): LinkTypeDataInterface
    {
        $errors = DataInput::unknownKeys($input, self::KEYS);
        $address = DataInput::text($input, 'address', $errors);
        $subject = DataInput::text($input, 'subject', $errors);
        $body = DataInput::text($input, 'body', $errors);

        if ($address === null) {
            if (in_array($input['address'] ?? null, [null, ''], true)) {
                $errors[] = new ValidationError('address', Code::MISSING, 'Enter an email address.');
            }
        } else {
            $address = self::address($address, $errors);
        }

        if ($subject !== null && !DataInput::isText($subject)) {
            $errors[] = new ValidationError('subject', Code::INVALID, 'The subject must be text on one line, without control characters.');
        }

        if ($body !== null && !DataInput::isMultilineText($body)) {
            $errors[] = new ValidationError('body', Code::INVALID, 'The body must be text without control characters other than line breaks.');
        }

        DataInput::throwIfAny($errors);

        /** @var string $address */
        return new EmailLinkData($address, $subject, $body);
    }

    public function dataFromArray(array $stored): LinkTypeDataInterface
    {
        if (array_filter($stored, 'is_string') !== $stored) {
            throw new LinkValidationException([new ValidationError('', Code::NOT_CANONICAL, 'Stored email link data is text.')]);
        }

        $data = $this->normalizeData($stored);

        if (!DataInput::isStoredForm($data->toArray(), $stored)) {
            throw new LinkValidationException([new ValidationError('address', Code::NOT_CANONICAL, 'The stored email address is not in canonical form.')]);
        }

        return $data;
    }

    public function validateData(LinkTypeDataInterface $data): array
    {
        if (!$data instanceof EmailLinkData) {
            return [new ValidationError('', Code::WRONG_TYPE, 'This is not {type} link data.', ['type' => $this->handle()])];
        }

        try {
            $normalized = $this->normalizeData($data->toArray());
        } catch (LinkValidationException $exception) {
            return $exception->errors;
        }

        return $normalized->toArray() === $data->toArray() ? [] : [new ValidationError('address', Code::NOT_CANONICAL, 'The email address is not in canonical form.')];
    }

    public function inputHtml(LinkTypeDataInterface|array|null $value, ?int $siteId): string
    {
        $input = $value instanceof EmailLinkData ? $value->toArray() : (is_array($value) ? $value : null);

        return Cp::textFieldHtml([
            'label' => Craft::t('smart-links', 'Email address'),
            'id' => 'address',
            'name' => 'address',
            'type' => 'email',
            'inputmode' => 'email',
            'value' => DataInput::inputValue($input, 'address'),
        ]) . Cp::textFieldHtml([
            'label' => Craft::t('smart-links', 'Subject'),
            'id' => 'subject',
            'name' => 'subject',
            'value' => DataInput::inputValue($input, 'subject'),
        ]) . Cp::textareaFieldHtml([
            'label' => Craft::t('smart-links', 'Message'),
            'id' => 'body',
            'name' => 'body',
            'rows' => 3,
            'value' => DataInput::inputValue($input, 'body'),
        ]);
    }

    public function targetIdentity(LinkTypeDataInterface $data, int $sourceElementId, int $sourceSiteId): TargetIdentity
    {
        // The subject and body start a message; they do not change who it goes to.
        /** @var EmailLinkData $data */
        return TargetIdentity::create($this->handle(), ['address' => $data->address]);
    }

    public function gqlDataFields(): array
    {
        return [
            'address' => Type::nonNull(Type::string()),
            'subject' => Type::string(),
            'body' => Type::string(),
        ];
    }

    public function resolver(): LinkResolverInterface
    {
        return $this;
    }

    public function resolve(LinkValue $link, int $siteId): ResolvedLink
    {
        /** @var EmailLinkData $data */
        $data = $link->data;
        [$local, $domain] = self::split($data->address);
        $query = [];

        if ($data->subject !== null) {
            $query[] = 'subject=' . rawurlencode($data->subject);
        }

        // RFC 6068 writes a line break in a body as CRLF.
        if ($data->body !== null) {
            $query[] = 'body=' . rawurlencode((string)preg_replace('/\r\n|\r|\n/', "\r\n", $data->body));
        }

        $url = 'mailto:' . rawurlencode($local) . '@' . $domain . ($query !== [] ? '?' . implode('&', $query) : '');

        // A mail client is not a page off the site, so the link is not external.
        return new ResolvedLink(ResolutionStatus::RESOLVED, $url, false, $data->address);
    }

    public function craftLinkTypes(): array
    {
        return ['email'];
    }

    public function dataFromCraftLink(string $value): array
    {
        if (!preg_match('/^mailto:([^?]*)(?:\?(.*))?$/is', $value, $match)) {
            throw new LinkValidationException([new ValidationError('address', Code::INVALID, 'This is not an email link.')]);
        }

        $data = ['address' => rawurldecode($match[1])];

        foreach (self::queryParams($match[2] ?? '') as $name => $param) {
            // Header names in a mailto URL are case-insensitive (RFC 6068).
            $name = strtolower($name);

            if (!in_array($name, ['subject', 'body'], true)) {
                throw new LinkValidationException([new ValidationError($name, Code::NOT_SUPPORTED, 'Only a subject and a body can be converted, not “{key}”.', ['key' => $name])]);
            }

            if (array_key_exists($name, $data)) {
                throw new LinkValidationException([new ValidationError($name, Code::DUPLICATE, '“{key}” is given more than once.', ['key' => $name])]);
            }

            $data[$name] = $param;
        }

        return $data;
    }

    /**
     * @param list<ValidationError> $errors
     */
    private static function address(string $address, array &$errors): ?string
    {
        // Around an address, whitespace is never part of it.
        $address = trim($address, " \t\r\n");

        if (preg_match('/^mailto:/i', $address)) {
            $errors[] = new ValidationError('address', Code::INVALID, 'Enter the address alone, without “mailto:”.');

            return null;
        }

        $at = strrpos($address, '@');
        $local = $at === false ? '' : substr($address, 0, $at);
        $domain = $at === false ? null : DomainName::normalize(substr($address, $at + 1));

        if ($domain === null || strlen($local) > 64 || !preg_match(self::LOCAL_PART, $local) || strlen("$local@$domain") > 254) {
            $errors[] = new ValidationError('address', Code::INVALID, '“{value}” is not a valid email address.', ['value' => $address]);

            return null;
        }

        return "$local@$domain";
    }

    /**
     * @return array{string, string}
     */
    private static function split(string $address): array
    {
        $at = (int)strrpos($address, '@');

        return [substr($address, 0, $at), substr($address, $at + 1)];
    }

    /**
     * A URL query's parameters, decoded, in order; a name given twice is kept twice.
     *
     * @return \Generator<string, string>
     */
    private static function queryParams(string $query): \Generator
    {
        if ($query === '') {
            return;
        }

        foreach (explode('&', $query) as $pair) {
            [$name, $param] = array_pad(explode('=', $pair, 2), 2, '');

            yield rawurldecode($name) => rawurldecode($param);
        }
    }
}
