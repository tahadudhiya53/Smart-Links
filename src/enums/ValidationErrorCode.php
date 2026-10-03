<?php

namespace Tahadudhiya\SmartLinks\enums;

/**
 * What kind of problem a link validation error is, for code that reacts to errors rather than
 * showing them.
 */
enum ValidationErrorCode: string
{
    /** Something required is absent. */
    case MISSING = 'missing';

    /** A value is of the wrong kind, e.g. a number where text belongs. */
    case WRONG_TYPE = 'wrongType';

    /** A key nothing recognises. It is reported rather than discarded. */
    case UNKNOWN_KEY = 'unknownKey';

    /** A link type that is not available. */
    case UNKNOWN_LINK_TYPE = 'unknownLinkType';

    /** A property the link's type does not support. */
    case NOT_SUPPORTED = 'notSupported';

    /** A value of the right kind that breaks a rule for it. */
    case INVALID = 'invalid';

    /** A value that may appear only once appears again. */
    case DUPLICATE = 'duplicate';

    /** A stored value in a format version this code cannot read. */
    case UNSUPPORTED_VERSION = 'unsupportedVersion';

    /** A stored value that is readable but not in its one canonical form. */
    case NOT_CANONICAL = 'notCanonical';
}
