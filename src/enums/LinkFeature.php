<?php

namespace Tahadudhiya\SmartLinks\enums;

/**
 * An optional part of a link that a link type may or may not support.
 *
 * Not every property applies to every link: an email link has no download, and a phone link no
 * URL suffix. Each link type declares the features it supports, and a link using any other is
 * invalid. The label applies to every link, so it is not a feature.
 */
enum LinkFeature: string
{
    case URL_SUFFIX = 'urlSuffix';
    case TARGET = 'target';
    case REL = 'rel';
    case TITLE = 'title';
    case CLASS_NAMES = 'class';
    case ID = 'id';
    case ARIA_LABEL = 'ariaLabel';

    /** The `download` attribute, and the filename it may suggest. */
    case DOWNLOAD = 'download';

    /** `data-*` and `aria-*` attributes. */
    case CUSTOM_ATTRIBUTES = 'custom';
}
