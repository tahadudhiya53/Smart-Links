# Smart Links

**Create. Find. Monitor. Fix.**

Smart Links is a link management and link intelligence plugin for Craft CMS. It is built to sit
alongside Craft's own content tools and add what they do not cover: a site-wide view of the links
your content contains, where each one is used, and whether it still works.

> **Status:** under active development. Smart Links currently installs and loads as a Craft
> plugin with a control panel section. It does not yet provide any link management features.
> Nothing listed under [Planned](#planned) is available.

## Requirements

- Craft CMS 5.9.0 or later
- PHP 8.2 or later

## Installation

Smart Links is not yet published. To install it from a local checkout, add the checkout as a
Composer path repository in your Craft project:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "plugins/Smart-Links"
        }
    ]
}
```

Then require and install it:

```bash
composer require tahadudhiya53/craft-smart-links
php craft plugin/install smart-links
```

## What it does today

- Installs as a Craft CMS 5 plugin.
- Adds a **Smart Links** section to the control panel showing the installed version. Access is
  controlled by Craft's own **Access Smart Links** user permission.

Smart Links creates no database tables yet.

## Planned

The direction Smart Links is being built towards. None of this is implemented yet.

- A link field for structured link authoring, supporting several link types.
- A site-wide link inventory showing where each link is used.
- Link health monitoring, run in the background, that distinguishes broken links from redirects
  and from targets that were unavailable or blocked when checked.
- Link auditing, previewable bulk replacement, and migration from other link fields.

## Development

The plugin carries its own development tooling:

```bash
composer install
composer test              # unit tests
composer phpstan           # static analysis
composer check-cs          # coding standards
```

Integration tests boot the surrounding Craft project, so run them from inside it (for example
through DDEV) with Smart Links installed:

```bash
composer test-integration
```

## License

Smart Links is licensed under the [Craft License](LICENSE.md).
