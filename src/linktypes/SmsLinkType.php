<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use Craft;
use craft\helpers\Cp;
use GraphQL\Type\Definition\Type;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ResolvedLink;
use Tahadudhiya\SmartLinks\models\TargetIdentity;
use Tahadudhiya\SmartLinks\models\ValidationError;

/**
 * A link that starts a text message (`sms:`, RFC 5724), optionally with its text.
 *
 * The number follows the phone link's rules. Texting and calling a number are different actions,
 * so they are different link types and different targets.
 */
class SmsLinkType extends PhoneLinkType
{
    public function handle(): string
    {
        return 'sms';
    }

    public function displayName(): string
    {
        return Craft::t('smart-links', 'SMS');
    }

    public function normalizeData(array $input): LinkTypeDataInterface
    {
        $errors = DataInput::unknownKeys($input, ['number', 'body']);
        $number = self::number($input, $errors);
        $body = DataInput::text($input, 'body', $errors);

        if ($body !== null && !DataInput::isMultilineText($body)) {
            $errors[] = new ValidationError('body', Code::INVALID, 'The message must be text without control characters other than line breaks.');
        }

        DataInput::throwIfAny($errors);

        /** @var string $number */
        return new SmsLinkData($number, $body);
    }

    public function dataFromArray(array $stored): LinkTypeDataInterface
    {
        $data = $this->normalizeData($stored);

        if (!DataInput::isStoredForm($data->toArray(), $stored)) {
            throw new LinkValidationException([new ValidationError('number', Code::NOT_CANONICAL, 'The stored phone number is not in canonical form.')]);
        }

        return $data;
    }

    public function validateData(LinkTypeDataInterface $data): array
    {
        if (!$data instanceof SmsLinkData) {
            return [new ValidationError('', Code::WRONG_TYPE, 'This is not {type} link data.', ['type' => $this->handle()])];
        }

        return self::canonicalProblems($data->toArray(), fn(array $input) => $this->normalizeData($input));
    }

    public function inputHtml(LinkTypeDataInterface|array|null $value, ?int $siteId): string
    {
        $input = $value instanceof LinkTypeDataInterface ? $value->toArray() : $value;

        return self::numberInputHtml($input) . Cp::textareaFieldHtml([
            'label' => Craft::t('smart-links', 'Message'),
            'id' => 'body',
            'name' => 'body',
            'rows' => 3,
            'value' => DataInput::inputValue($input, 'body'),
        ]);
    }

    public function gqlDataFields(): array
    {
        return [
            'number' => ['type' => Type::nonNull(Type::string()), 'description' => '“+” and digits.'],
            'body' => Type::string(),
        ];
    }

    public function targetIdentity(LinkTypeDataInterface $data, int $sourceElementId, int $sourceSiteId): TargetIdentity
    {
        // The message is what is sent, not who it is sent to.
        /** @var SmsLinkData $data */
        return TargetIdentity::create($this->handle(), ['number' => $data->number]);
    }

    public function resolve(LinkValue $link, int $siteId): ResolvedLink
    {
        /** @var SmsLinkData $data */
        $data = $link->data;
        $body = $data->body !== null ? '?body=' . rawurlencode($data->body) : '';

        return new ResolvedLink(ResolutionStatus::RESOLVED, 'sms:' . $data->number . $body, false, $data->number);
    }

    public function craftLinkTypes(): array
    {
        return ['sms'];
    }

    public function dataFromCraftLink(string $value): array
    {
        // Craft's SMS links join a message to the number with `&` (or, as RFC 5724 does, `?`).
        if (!preg_match('/^sms:([^?&]*)(?:[?&](.*))?$/is', $value, $match)) {
            throw new LinkValidationException([new ValidationError('number', Code::INVALID, 'This is not an SMS link.')]);
        }

        $data = ['number' => rawurldecode($match[1])];

        if (($match[2] ?? '') !== '') {
            foreach (explode('&', $match[2]) as $pair) {
                [$name, $param] = array_pad(explode('=', $pair, 2), 2, '');
                $name = strtolower(rawurldecode($name));

                if ($name !== 'body') {
                    throw new LinkValidationException([new ValidationError($name, Code::NOT_SUPPORTED, 'Only a message can be converted, not “{key}”.', ['key' => $name])]);
                }

                if (array_key_exists('body', $data)) {
                    throw new LinkValidationException([new ValidationError('body', Code::DUPLICATE, '“{key}” is given more than once.', ['key' => 'body'])]);
                }

                $data['body'] = rawurldecode($param);
            }
        }

        return $data;
    }
}
