<?php

namespace Tahadudhiya\SmartLinks;

use craft\base\Plugin;

/**
 * Smart Links — link management and link intelligence for Craft CMS.
 *
 * The plugin class is bootstrap and registration only. Domain behaviour belongs in services.
 *
 * Craft routes the control panel section to `templates/index.twig` and gates it, together with
 * its nav item, behind the “Access Smart Links” permission it registers for every plugin with a
 * section, so neither a controller nor a permission of the plugin's own is needed yet.
 */
class SmartLinks extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
}
