<?php

namespace Tahadudhiya\SmartLinks\models;

use craft\base\ElementInterface;
use InvalidArgumentException;
use Tahadudhiya\SmartLinks\links\LinkValidator;

/**
 * What a link points at, as opposed to where that currently leads.
 *
 * Two links have the same target exactly when their identities have the same key. The link type
 * decides which components identify its targets and normalizes their values. This class only
 * guarantees that the same type and components always give the same key, in any order, and that
 * anything else never does.
 *
 * Key format: `<type>?<name>=<value>&…`, names in byte order, values percent-encoded as in RFC
 * 3986, so no value can be mistaken for a delimiter. For example `entry?elementId=12&siteId=1`.
 */
final class TargetIdentity
{
    private const COMPONENT_PATTERN = '/^[a-z][a-zA-Z0-9]*$/';

    /**
     * The longest key the index can store whole (`smartlinks_index.targetKey`, a MySQL TEXT
     * column). A longer one would be refused by a strict database and cut short by a lenient one,
     * so it is refused here, whatever the database does.
     */
    public const MAX_KEY_BYTES = 65535;

    /** Components with a fixed meaning, which the index projects into integer columns. */
    private const ID_COMPONENTS = ['elementId', 'siteId'];

    /**
     * @param array<string, string> $components Sorted by name.
     */
    private function __construct(
        public readonly string $type,
        public readonly array $components,
    ) {
    }

    /**
     * @param array<string, string|int> $components Integers are taken in their decimal form, so
     * an element ID read back from the database as a string identifies the same target.
     * @throws InvalidArgumentException if the type or a component is malformed, or the key would
     * be longer than the index can store.
     */
    public static function create(string $type, array $components): self
    {
        if (!LinkValidator::isTypeHandle($type)) {
            throw new InvalidArgumentException("“{$type}” is not a valid link type handle.");
        }

        if ($components === []) {
            throw new InvalidArgumentException('A target identity needs at least one component.');
        }

        $normalized = [];

        foreach ($components as $name => $value) {
            if (!preg_match(self::COMPONENT_PATTERN, (string)$name)) {
                throw new InvalidArgumentException("“{$name}” is not a valid target identity component name.");
            }

            // Only text and integers have one reading; `true` would read as `"1"` and null as
            // nothing, naming a target that was never given.
            /** @phpstan-ignore function.alreadyNarrowedType, booleanAnd.alwaysFalse (PHPDoc types are not enforced at runtime, and this is the check.) */
            if (!is_string($value) && !is_int($value)) {
                throw new InvalidArgumentException("The target identity component “{$name}” must be a string or an integer, not " . get_debug_type($value) . '.');
            }

            $value = (string)$value;

            if ($value === '' || !mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException("The target identity component “{$name}” must be a non-empty UTF-8 string.");
            }

            if (in_array($name, self::ID_COMPONENTS, true) && !preg_match('/^[1-9][0-9]*$/', $value)) {
                throw new InvalidArgumentException("The target identity component “{$name}” must be a positive ID.");
            }

            $normalized[$name] = $value;
        }

        ksort($normalized, SORT_STRING);
        $identity = new self($type, $normalized);

        if (strlen($identity->key()) > self::MAX_KEY_BYTES) {
            throw new InvalidArgumentException(sprintf('A target identity’s key can be at most %d bytes.', self::MAX_KEY_BYTES));
        }

        return $identity;
    }

    /**
     * An element target. A localized element type is linked in one of its sites, so the site is
     * required for it and refused for any other.
     *
     * @param class-string<ElementInterface> $elementType
     * @throws InvalidArgumentException if the site does not match whether the type is localized.
     */
    public static function forElement(string $type, string $elementType, int $elementId, ?int $siteId): self
    {
        if (!is_subclass_of($elementType, ElementInterface::class)) {
            throw new InvalidArgumentException("“{$elementType}” is not an element type.");
        }

        if ($elementType::isLocalized() !== ($siteId !== null)) {
            throw new InvalidArgumentException($siteId === null
                ? "A “{$type}” target needs the site whose version of the element it links to."
                : "“{$type}” elements are not localized, so a target cannot name a site.");
        }

        return self::create($type, $siteId === null ? ['elementId' => $elementId] : ['elementId' => $elementId, 'siteId' => $siteId]);
    }

    /**
     * A URL target. An absolute URL means the same in every site, so it never carries one. A
     * root-relative URL means something different in each, so it always does.
     *
     * @throws InvalidArgumentException if the site does not match whether the URL is absolute.
     */
    public static function forUrl(string $type, CanonicalUrl $url, ?int $siteId): self
    {
        if ($url->isAbsolute() === ($siteId !== null)) {
            throw new InvalidArgumentException($siteId === null
                ? 'A root-relative URL target needs the site it resolves in.'
                : 'An absolute URL target cannot name a site: it is the same URL in every site.');
        }

        return self::create($type, $siteId === null ? ['url' => $url->toString()] : ['url' => $url->toString(), 'siteId' => $siteId]);
    }

    /**
     * The canonical serialization. This, not its hash, is the identity.
     */
    public function key(): string
    {
        $pairs = [];

        foreach ($this->components as $name => $value) {
            $pairs[] = $name . '=' . rawurlencode($value);
        }

        return $this->type . '?' . implode('&', $pairs);
    }

    /**
     * SHA-256 of the key: a fixed-length stand-in that can be uniquely indexed, which the key
     * itself, being unbounded, cannot be portably.
     */
    public function hash(): string
    {
        return hash('sha256', $this->key());
    }

    public function equals(self $other): bool
    {
        return $this->key() === $other->key();
    }
}
