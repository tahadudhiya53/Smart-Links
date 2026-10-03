<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ResolvedLink;

/**
 * Works out where a link leads, now, in a site.
 *
 * Resolving reads the link and the current state of what it points at. It never changes the
 * link. It runs on every render, so it must not make network requests.
 *
 * Every normal outcome is a {@see ResolvedLink}: `RESOLVED` with a URL, or, for a target that
 * leads nowhere, `MISSING` (it no longer exists), `DISABLED` (it exists but is not live) or
 * `NO_URL` (it has no URL in this site). An exception means resolving itself failed, e.g. a
 * fault or an unavailable service, and is never a way of saying a target is missing: it
 * surfaces wherever the link is rendered, including the control panel's previews.
 */
interface LinkResolverInterface
{
    /**
     * @param int $siteId The site the link is being resolved in, which decides e.g. which site's
     * URL of an entry is used, and what a root-relative URL is relative to.
     */
    public function resolve(LinkValue $link, int $siteId): ResolvedLink;
}
