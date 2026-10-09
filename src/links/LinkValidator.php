<?php

namespace Tahadudhiya\SmartLinks\links;

use craft\helpers\StringHelper;
use Tahadudhiya\SmartLinks\enums\LinkFeature;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeDataInterface;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeInterface;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeSet;
use Tahadudhiya\SmartLinks\models\LinkAttributes;
use Tahadudhiya\SmartLinks\models\LinkCollection;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ValidationError;

/**
 * The one owner of the rules a link value must follow.
 *
 * Every error is reported, in a fixed order, and nothing is repaired: the same value always gives
 * the same errors. A link type's own data is checked by the type.
 */
final class LinkValidator
{
    /** Lowercase words joined by hyphens, e.g. `url` or `commerce-product`. */
    private const TYPE_PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/';

    /** Fits the index column the type is projected into. */
    private const TYPE_MAX_LENGTH = 64;

    /** Lowercase UUID v4, the form Craft's `StringHelper::UUID()` generates. */
    private const UID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private const TARGET_KEYWORDS = ['_blank', '_self', '_parent', '_top'];

    /**
     * One character of a token (a target name, a class, an id, a URL suffix): anything but
     * whitespace and control characters (C0, DEL and C1, as for text).
     */
    private const TOKEN_CHARACTER = '[^\\s\\p{Cc}]';

    /** `aria-label` has its own property, so it cannot also be a custom attribute. */
    private const CUSTOM_NAME_PATTERN = '/^(?:data-[a-z0-9][a-z0-9._-]*|aria-(?!label$)[a-z]+)$/';

    public function __construct(
        private readonly LinkTypeSet $types,
    ) {
    }

    /**
     * Whether a string is a valid link type handle. The one definition of the rule, which target
     * identities use too.
     */
    public static function isTypeHandle(string $handle): bool
    {
        return strlen($handle) <= self::TYPE_MAX_LENGTH && preg_match(self::TYPE_PATTERN, $handle) === 1;
    }

    /**
     * @return list<ValidationError>
     */
    public function validateCollection(LinkCollection $value): array
    {
        $errors = [];

        foreach ($value->links as $index => $link) {
            array_push($errors, ...$this->validateLink($link, "links[$index]"));
        }

        return array_merge($errors, $this->validateUniqueUids($value));
    }

    /**
     * A UID identifies one occurrence, so a value cannot hold it twice.
     *
     * @return list<ValidationError>
     */
    public function validateUniqueUids(LinkCollection $value): array
    {
        $errors = [];
        $seen = [];

        foreach ($value->links as $index => $link) {
            if (isset($seen[$link->uid])) {
                $errors[] = new ValidationError("links[$index].uid", Code::DUPLICATE, 'Another link in this value has the same UID.');
            }

            $seen[$link->uid] = true;
        }

        return $errors;
    }

    /**
     * @return list<ValidationError>
     */
    public function validateLink(LinkValue $link, string $path = ''): array
    {
        return $this->validateParts($link->uid, $link->type, $link->data, $link->label, $link->urlSuffix, $link->attributes, $link->presetUid, $path);
    }

    /**
     * Validates a link's parts before they are a link: the normalizer reports every problem at
     * once, including when the data could not be built at all.
     *
     * @param string|null $typeHandle Null when its absence has already been reported.
     * @param LinkTypeDataInterface|null $data Null when its problems have already been reported.
     * @return list<ValidationError>
     */
    public function validateParts(
        string $uid,
        ?string $typeHandle,
        ?LinkTypeDataInterface $data,
        ?string $label,
        ?string $urlSuffix,
        LinkAttributes $attributes,
        ?string $presetUid,
        string $path = '',
    ): array {
        $errors = [];

        if (!preg_match(self::UID_PATTERN, $uid)) {
            $errors[] = new ValidationError(ValidationError::join($path, 'uid'), Code::INVALID, 'The link’s UID must be a lowercase UUID v4.');
        }

        $type = $typeHandle !== null ? $this->type($typeHandle, ValidationError::join($path, 'type'), $errors) : null;

        if ($type !== null) {
            if ($data !== null) {
                foreach ($type->validateData($data) as $error) {
                    $errors[] = $error->within(ValidationError::join($path, 'data'));
                }
            }

            if ($urlSuffix !== null && !in_array(LinkFeature::URL_SUFFIX, $type->supportedFeatures(), true)) {
                $errors[] = $this->notSupported(ValidationError::join($path, 'urlSuffix'), LinkFeature::URL_SUFFIX->value, $type);
            }
        }

        if ($label !== null && !self::isText($label)) {
            $errors[] = new ValidationError(ValidationError::join($path, 'label'), Code::INVALID, 'The label must be non-empty text without control characters.');
        }

        if ($urlSuffix !== null) {
            array_push($errors, ...$this->validateUrlSuffix($urlSuffix, ValidationError::join($path, 'urlSuffix')));
        }

        array_push($errors, ...$this->validateAttributes($attributes, $type, ValidationError::join($path, 'attributes')));

        if ($presetUid !== null && !StringHelper::isUUID($presetUid)) {
            $errors[] = new ValidationError(ValidationError::join($path, 'presetUid'), Code::INVALID, 'The preset UID is not a valid UID.');
        }

        return $errors;
    }

    /**
     * The rule for a URL suffix, whichever link it is for: it starts with `?` or `#`, continues
     * after it, and has no whitespace or control characters.
     *
     * @return list<ValidationError>
     */
    public function validateUrlSuffix(string $urlSuffix, string $path = ''): array
    {
        if (!preg_match('/^[?#]' . self::TOKEN_CHARACTER . '+$/u', $urlSuffix)) {
            return [new ValidationError($path, Code::INVALID, 'A URL suffix must start with “?” or “#”, continue after it, and contain no whitespace.')];
        }

        return [];
    }

    /**
     * @param LinkTypeInterface|null $type The link's type, to check which attributes apply. Null
     * when the type is unknown, which has been reported already.
     * @return list<ValidationError>
     */
    public function validateAttributes(LinkAttributes $attributes, ?LinkTypeInterface $type, string $path = ''): array
    {
        $errors = [];

        if ($type !== null) {
            foreach ($attributes->usedFeatures() as $feature) {
                if (!in_array($feature, $type->supportedFeatures(), true)) {
                    $errors[] = $this->notSupported(ValidationError::join($path, $feature->value), $feature->value, $type);
                }
            }
        }

        // A browsing context name is a token that does not start with `_`, which is reserved for
        // the keywords, and has no `<` (HTML refuses names with `<` and whitespace).
        if ($attributes->target !== null && !in_array($attributes->target, self::TARGET_KEYWORDS, true) && !preg_match('/^(?!_)[^\s\p{Cc}<]+$/u', $attributes->target)) {
            $errors[] = new ValidationError(ValidationError::join($path, 'target'), Code::INVALID, '“{value}” is not a valid link target.', ['value' => $attributes->target]);
        }

        array_push($errors, ...$this->validateTokens($attributes->rel, '/^[a-z0-9][a-z0-9._:-]*$/', ValidationError::join($path, 'rel'), '“{value}” is not a valid rel value: use lowercase letters, digits and “-._:”.'));
        array_push($errors, ...$this->validateTokens($attributes->class, '/^' . self::TOKEN_CHARACTER . '+$/u', ValidationError::join($path, 'class'), '“{value}” is not a valid class name.'));

        if ($attributes->title !== null && !self::isText($attributes->title)) {
            $errors[] = new ValidationError(ValidationError::join($path, 'title'), Code::INVALID, 'The title must be non-empty text without control characters.');
        }

        if ($attributes->id !== null && !preg_match('/^' . self::TOKEN_CHARACTER . '+$/u', $attributes->id)) {
            $errors[] = new ValidationError(ValidationError::join($path, 'id'), Code::INVALID, 'The id must be a single word without whitespace.');
        }

        if ($attributes->ariaLabel !== null && !self::isText($attributes->ariaLabel)) {
            $errors[] = new ValidationError(ValidationError::join($path, 'ariaLabel'), Code::INVALID, 'The ARIA label must be non-empty text without control characters.');
        }

        if ($attributes->downloadFilename !== null) {
            $filenamePath = ValidationError::join($path, 'downloadFilename');

            if (!$attributes->download) {
                $errors[] = new ValidationError($filenamePath, Code::INVALID, 'A download filename needs the download attribute.');
            }

            if (!self::isText($attributes->downloadFilename)) {
                $errors[] = new ValidationError($filenamePath, Code::INVALID, 'The download filename must be non-empty text without control characters.');
            } elseif (strpbrk($attributes->downloadFilename, '/\\') !== false) {
                $errors[] = new ValidationError($filenamePath, Code::INVALID, 'A download filename cannot contain a path.');
            }
        }

        foreach ($attributes->custom as $name => $value) {
            $namePath = ValidationError::join($path, "custom.$name");

            if (!preg_match(self::CUSTOM_NAME_PATTERN, $name)) {
                $errors[] = new ValidationError($namePath, Code::INVALID, '“{name}” is not an allowed custom attribute: only data-* and aria-* are.', ['name' => $name]);
            } elseif ($value !== '' && !self::isText($value)) {
                // An empty value is meaningful for a data attribute, unlike for the named ones.
                $errors[] = new ValidationError($namePath, Code::INVALID, 'The value of “{name}” must be text without control characters.', ['name' => $name]);
            }
        }

        return $errors;
    }

    /**
     * Reports a malformed or unavailable link type handle.
     *
     * @return list<ValidationError>
     */
    public function validateTypeHandle(string $handle, string $path = ''): array
    {
        if (!self::isTypeHandle($handle)) {
            return [new ValidationError($path, Code::INVALID, '“{type}” is not a valid link type handle.', ['type' => $handle])];
        }

        if ($this->types->get($handle) === null) {
            return [new ValidationError($path, Code::UNKNOWN_LINK_TYPE, '“{type}” is not an available link type.', ['type' => $handle])];
        }

        return [];
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function type(string $handle, string $path, array &$errors): ?LinkTypeInterface
    {
        $typeErrors = $this->validateTypeHandle($handle, $path);
        array_push($errors, ...$typeErrors);

        return $typeErrors === [] ? $this->types->get($handle) : null;
    }

    /**
     * @param list<string> $tokens
     * @return list<ValidationError>
     */
    private function validateTokens(array $tokens, string $pattern, string $path, string $invalidMessage): array
    {
        $errors = [];
        $seen = [];

        foreach ($tokens as $index => $token) {
            if (!preg_match($pattern, $token)) {
                $errors[] = new ValidationError("{$path}[$index]", Code::INVALID, $invalidMessage, ['value' => $token]);
            } elseif (isset($seen[$token])) {
                $errors[] = new ValidationError("{$path}[$index]", Code::DUPLICATE, '“{value}” is listed more than once.', ['value' => $token]);
            }

            $seen[$token] = true;
        }

        return $errors;
    }

    private function notSupported(string $path, string $feature, LinkTypeInterface $type): ValidationError
    {
        return new ValidationError($path, Code::NOT_SUPPORTED, '“{feature}” does not apply to {type} links.', ['feature' => $feature, 'type' => $type->handle()]);
    }

    /**
     * Text an author typed: non-empty UTF-8 without control characters (C0, DEL and C1, the same
     * set a resolved default label refuses). Whitespace, including Unicode spaces, is text.
     */
    private static function isText(string $value): bool
    {
        return $value !== '' && mb_check_encoding($value, 'UTF-8') && !preg_match('/\p{Cc}/u', $value);
    }
}
