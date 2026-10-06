<?php

namespace Tahadudhiya\SmartLinks\helpers;

/**
 * A public DNS name, such as an email domain or a social account's server, in its one canonical
 * spelling.
 */
final class DomainName
{
    /**
     * The name in ASCII and lowercase, or null when it is not a domain name: DNS compares names
     * without regard to case, and an IDN's ASCII form names the same domain (UTS #46, applied as
     * browsers apply it).
     *
     * At least two labels are required, letters, digits and inner hyphens only, and the last
     * label is not numeric, so an IP address or a bare host name is never taken for a domain.
     */
    public static function normalize(string $name): ?string
    {
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || str_ends_with($name, '.')) {
            return null;
        }

        $ascii = idn_to_ascii($name, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46);

        if ($ascii === false) {
            return null;
        }

        $ascii = strtolower($ascii);
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

        if (strlen($ascii) > 253 || !preg_match("/^(?:$label\\.)+$label$/", $ascii) || preg_match('/(?:^|\.)[0-9]+$/', $ascii)) {
            return null;
        }

        return $ascii;
    }
}
