<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use Craft;
use craft\helpers\Cp;
use GraphQL\Type\Definition\Type;
use Tahadudhiya\SmartLinks\enums\LinkFeature;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ResolvedLink;
use Tahadudhiya\SmartLinks\models\TargetIdentity;
use Tahadudhiya\SmartLinks\models\ValidationError;

/**
 * A link that calls a phone number (`tel:`).
 *
 * Numbers are held as `+` and digits. Spaces, hyphens, dots and brackets are visual separators
 * (RFC 3966) and carry no meaning, so they are removed. Anything else, such as letters or the
 * pause characters `,` and `;`, is refused rather than guessed at. So is a bracketed `(0)` in an
 * international number (`+44 (0)20…`): it is the national trunk prefix, which is not dialled
 * with the country code, so removing only the brackets would give a different number.
 */
class PhoneLinkType implements LinkTypeInterface, LinkResolverInterface, CraftLinkConverterInterface, GqlLinkTypeInterface
{
    /** E.164 allows at most 15 digits; emergency and short numbers have as few as 3. */
    private const MIN_DIGITS = 3;
    private const MAX_DIGITS = 15;

    public function handle(): string
    {
        return 'tel';
    }

    public function displayName(): string
    {
        return Craft::t('smart-links', 'Phone');
    }

    public function supportedFeatures(): array
    {
        return [LinkFeature::TITLE, LinkFeature::CLASS_NAMES, LinkFeature::ID, LinkFeature::ARIA_LABEL, LinkFeature::CUSTOM_ATTRIBUTES];
    }

    public function normalizeData(array $input): LinkTypeDataInterface
    {
        $errors = DataInput::unknownKeys($input, ['number']);
        $number = self::number($input, $errors);
        DataInput::throwIfAny($errors);

        /** @var string $number */
        return new PhoneLinkData($number);
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
        if (!$data instanceof PhoneLinkData) {
            return [new ValidationError('', Code::WRONG_TYPE, 'This is not {type} link data.', ['type' => $this->handle()])];
        }

        return self::canonicalProblems($data->toArray(), fn(array $input) => $this->normalizeData($input));
    }

    public function inputHtml(LinkTypeDataInterface|array|null $value, ?int $siteId): string
    {
        return self::numberInputHtml($value instanceof LinkTypeDataInterface ? $value->toArray() : $value);
    }

    public function targetIdentity(LinkTypeDataInterface $data, int $sourceElementId, int $sourceSiteId): TargetIdentity
    {
        /** @var PhoneLinkData $data */
        return TargetIdentity::create($this->handle(), ['number' => $data->number]);
    }

    public function gqlDataFields(): array
    {
        return ['number' => ['type' => Type::nonNull(Type::string()), 'description' => '“+” and digits.']];
    }

    public function resolver(): LinkResolverInterface
    {
        return $this;
    }

    public function resolve(LinkValue $link, int $siteId): ResolvedLink
    {
        /** @var PhoneLinkData $data */
        $data = $link->data;

        return new ResolvedLink(ResolutionStatus::RESOLVED, 'tel:' . $data->number, false, $data->number);
    }

    public function craftLinkTypes(): array
    {
        return ['tel'];
    }

    public function dataFromCraftLink(string $value): array
    {
        if (!preg_match('/^tel:(.*)$/is', $value, $match)) {
            throw new LinkValidationException([new ValidationError('number', Code::INVALID, 'This is not a phone link.')]);
        }

        return ['number' => rawurldecode($match[1])];
    }

    /**
     * The number under `number`, normalized, or null with the problem reported.
     *
     * @param array<mixed> $input
     * @param list<ValidationError> $errors
     */
    protected static function number(array $input, array &$errors): ?string
    {
        $number = DataInput::text($input, 'number', $errors);

        if ($number === null) {
            // Absent or empty is missing; anything else was reported as the wrong kind.
            if (in_array($input['number'] ?? null, [null, ''], true)) {
                $errors[] = new ValidationError('number', Code::MISSING, 'Enter a phone number.');
            }

            return null;
        }

        // `+44 (0)20 …`: the 0 is dialled only without the country code. Whether to drop it is the
        // author's to say, so the number is refused rather than turned into another one.
        if (preg_match('/^\s*\+.*\(\s*0/u', $number)) {
            $errors[] = new ValidationError('number', Code::INVALID, 'Leave out the “(0)”: with the country code in front, it is not dialled.');

            return null;
        }

        // Visual separators: space, no-break space, hyphen, dot and brackets.
        $digits = (string)preg_replace('/[ \x{00A0}\-.()]/u', '', trim($number));

        if (!preg_match('/^\+?[0-9]+$/', $digits)) {
            $errors[] = new ValidationError('number', Code::INVALID, 'A phone number is digits, with “+” and the country code in front when it has one. Spaces, hyphens, dots and brackets may separate them.');

            return null;
        }

        $count = strlen(ltrim($digits, '+'));

        if ($count < self::MIN_DIGITS || $count > self::MAX_DIGITS) {
            $errors[] = new ValidationError('number', Code::INVALID, 'A phone number has between {min, number} and {max, number} digits.', ['min' => self::MIN_DIGITS, 'max' => self::MAX_DIGITS]);

            return null;
        }

        return $digits;
    }

    /**
     * Problems with data built in code: what its own normalization reports, or that it is not
     * already the normalized form.
     *
     * @param array<string, string|int|bool> $stored
     * @param \Closure(array<mixed>): LinkTypeDataInterface $normalize
     * @return list<ValidationError>
     */
    protected static function canonicalProblems(array $stored, \Closure $normalize): array
    {
        try {
            $normalized = $normalize($stored);
        } catch (LinkValidationException $exception) {
            return $exception->errors;
        }

        return $normalized->toArray() === $stored ? [] : [new ValidationError('number', Code::NOT_CANONICAL, 'The phone number is not in canonical form.')];
    }

    /**
     * @param array<mixed>|null $input
     */
    protected static function numberInputHtml(?array $input): string
    {
        return Cp::textFieldHtml([
            'label' => Craft::t('smart-links', 'Phone number'),
            'instructions' => Craft::t('smart-links', 'Include “+” and the country code to make it work from anywhere.'),
            'id' => 'number',
            'name' => 'number',
            'type' => 'tel',
            'inputmode' => 'tel',
            'value' => DataInput::inputValue($input, 'number'),
        ]);
    }
}
