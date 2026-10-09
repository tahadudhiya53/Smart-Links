<?php

namespace Tahadudhiya\SmartLinks\enums;

use Craft;

/**
 * Why an indexed link occurrence may no longer be what its content holds. The index catches up
 * when the source's queued update runs, or on a rebuild; until then the occurrence is shown as
 * last indexed, and said to be so.
 *
 * The first six say why the element holding it can't be loaded in its site; the next three, why
 * the field it was in is not there now; the last two, that the element is there but the index has
 * not read it as it is.
 */
enum StaleUsage: string
{
    /** The element is in the trash. */
    case SOURCE_TRASHED = 'sourceTrashed';

    /** The element is no longer in the site the occurrence is in. */
    case SOURCE_NOT_IN_SITE = 'sourceNotInSite';

    /** The site the occurrence is in has been deleted. */
    case SITE_DELETED = 'siteDeleted';

    /** The element's type is not available (e.g. the plugin providing it is disabled or removed). */
    case SOURCE_TYPE_UNAVAILABLE = 'sourceTypeUnavailable';

    /** The element is nested, at some depth, in an element whose type is not available, so Craft can't create it. */
    case SOURCE_OWNER_UNAVAILABLE = 'sourceOwnerUnavailable';

    /** The element exists in the site, its type is available, and Craft still did not load it. */
    case SOURCE_UNLOADABLE = 'sourceUnloadable';

    /** The element's field layout no longer has the place the field was in. */
    case FIELD_REMOVED = 'fieldRemoved';

    /** The field in that place has been deleted. */
    case FIELD_DELETED = 'fieldDeleted';

    /** The field in that place is no longer a Smart Links field. */
    case FIELD_CHANGED_TYPE = 'fieldChangedType';

    /** The element was saved since it was indexed, or was never indexed. */
    case SAVED_SINCE = 'savedSince';

    /** A Smart Links value of the element couldn't be read, so its links are as an earlier reading left them. */
    case UNREADABLE = 'unreadable';

    public function label(): string
    {
        return match ($this) {
            self::SOURCE_TRASHED => Craft::t('smart-links', 'The element is in the trash'),
            self::SOURCE_NOT_IN_SITE => Craft::t('smart-links', 'The element is no longer in this site'),
            self::SITE_DELETED => Craft::t('smart-links', 'This site has been deleted'),
            self::SOURCE_TYPE_UNAVAILABLE => Craft::t('smart-links', 'The element’s type is not available'),
            self::SOURCE_OWNER_UNAVAILABLE => Craft::t('smart-links', 'The element is nested in an element whose type is not available'),
            self::SOURCE_UNLOADABLE => Craft::t('smart-links', 'The element can’t be loaded'),
            self::FIELD_REMOVED => Craft::t('smart-links', 'The field is no longer in the element’s layout'),
            self::FIELD_DELETED => Craft::t('smart-links', 'The field has been deleted'),
            self::FIELD_CHANGED_TYPE => Craft::t('smart-links', 'The field is no longer a Smart Links field'),
            self::SAVED_SINCE => Craft::t('smart-links', 'Saved since it was indexed'),
            self::UNREADABLE => Craft::t('smart-links', 'A value of the element can’t be read; shown as last indexed'),
        };
    }
}
