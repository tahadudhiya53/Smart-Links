# Smart Links

**Create. Find. Monitor. Fix.**

Smart Links is a link management and link intelligence plugin for Craft CMS. It is built to sit
alongside Craft's own content tools and add what they do not cover: a site-wide view of the links
your content contains, where each one is used, and whether it still works.

> **Status:** under active development. Smart Links provides a **Smart Links** field with
> built-in link types. Nothing listed under [Planned](#planned) is available.

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
- Creates the database tables that will hold its link index, link usage and link health data.
  They stay empty for now: no feature writes to them yet.
- Adds a **Smart Links** field type, and the link types it offers (see below).

### The Smart Links field

A field that holds an ordered list of structured links.

- **Settings:** which link types authors may add (and in which order they are offered), which
  presets they may choose, one link or many, a minimum and maximum number of links, and default
  links that new elements start with. Settings that are malformed are refused, never converted.
  Default links deploy with project config, and element IDs differ between environments, so
  default links cannot point at elements (entries, categories, assets, users, products).
- **Authoring:** add, edit, duplicate, remove, collapse and reorder links, by drag and drop or
  from each link's action menu (*Move up*, *Move down*). Each link has a type, the type's own
  inputs, a label, an optional preset, and optional attributes: URL suffix, target, `rel`, title,
  CSS classes, ID, ARIA label, download (with a filename), and custom `data-*` / `aria-*`
  attributes. Inputs for attributes a link type does not support are not shown.
- **Copy and paste:** a link, or all of a field's links, can be copied and pasted into any Smart
  Links field. The server checks what is pasted against the link rules and the field it is pasted
  into, as that field's settings are at the time: a link of a type or preset that field does not
  allow is refused, with every reason given, and never turned into another kind of link. A paste
  adds every link or none. Each editor's requests are bound to that editor (its field, its place
  in the field layout, its element and site) and served only to a user Craft lets save that
  element; the default-links editor in a field's settings is bound to that field and served only
  to admins where admin changes are allowed. Nothing is stored by copying or pasting. One copy,
  duplicate or paste runs at a time: while it does, the editor shows it is busy and holds back
  the others, and links added or edited meanwhile are kept as they are. A paste that no longer
  fits when the server answers (the field is full by then, or a duplicated link was removed) adds
  nothing and says why. A failure is reported as what it was: the links refused, no permission,
  an expired form, the server unreachable, or a server error.
- **Presets:** link presets are read from project config, under `smartLinks.presets.<uid>`, each
  with a `name` and nothing else; a malformed definition is a configuration error
  (`InvalidConfigException`), not skipped. A link records the preset it was made with. A preset the field does not allow,
  or that no longer exists, is reported on the link rather than removed. Smart Links has no
  screen for managing presets yet.
- **Validation:** links are checked in full, every problem is reported against the link it is in,
  and nothing is silently corrected or dropped. Invalid input never reaches live content. A
  draft is work in progress, so it keeps a link its author has not finished (or has entered
  wrongly) exactly as entered, and shows it again with its errors; Craft's autosave keeps
  working meanwhile. A full save, applying the draft and publishing all refuse it until it is
  fixed. A stored value that
  can no longer be read (for example because its link type is no longer registered, or it is not
  in a format Smart Links wrote) is kept exactly as it was, whatever its shape, and the element
  cannot be saved in full until an author removes or clears it (clearing asks for confirmation
  first). While it is left as it is, a
  draft still autosaves. Unreadable default links are shown as such on new elements, not
  skipped.
- **Storage:** values live in Craft's own element content, so sites, translation methods, drafts,
  revisions, duplication and copying between sites work as they do for Craft's own fields. Element
  queries support `:empty:` and `:notempty:`; there is no other Smart Links query parameter.
  Links' labels, titles and ARIA labels are added to Craft's search index.
- **Previews:** the element index and read-only views show each link as it renders. A link
  whose target is missing, disabled or has no URL is shown as that status, never as a link. A
  link that cannot be resolved at all is an error, and is reported as one rather than shown as
  something else.
- **Relations:** the elements a field's links point at are saved as Craft relations of the
  element, per site and in link order, as Craft's own Link field does, so `relatedTo` finds the
  content that links to an element, and the elements an entry links to. A draft has its own until
  it is applied. A relation is between elements, whatever their status; one to an element that
  has since been deleted is removed by Craft's garbage collection, and none is written for one.
  As for Craft's Link field, Craft's `relatedTo` `field` criterion does not accept the field:
  Craft limits it to its relation fields and Matrix.
- **GraphQL:** see [GraphQL](#graphql).

In code, a field value is set as a `LinkCollection` (or in the stored form Craft hands between
elements). Links an author or an import supplies are read by the link core first, and set only
if they are valid. Setting `null` means *no links*, so setting the result of invalid input would
empty the field:

```php
$result = SmartLinks::getInstance()->getLinks()->getNormalizer()->normalize($authoredLinks);

if (!$result->isValid()) {
    // Each error has a `path` (e.g. `links[0].data.url`), a `code` and getMessage().
    return $result->errors;
}

$entry->setFieldValue('myLinksField', $result->value);
```

Any other value is read as stored content, never as authoring input.

In templates, a link renders as an `<a>` tag through the plugin's `links` service, in the site it
is rendered for:

```twig
{% set links = craft.app.plugins.getPlugin('smart-links').links %}
{% for link in entry.myLinksField %}
    {{ links.html(link, entry.siteId) }}
{% endfor %}
```

`links.html()` returns null for a link whose target is missing, disabled or has no URL.
`links.render(link, siteId)` returns the final `href`, text and attributes instead, for building
other markup. `Links::EVENT_DEFINE_LINK_HTML` lets the markup be replaced everywhere. A link that
cannot be resolved at all, or is invalid, throws (`LinkResolutionException`,
`LinkValidationException`) rather than rendering something else.

A stored value that can no longer be read is an `InvalidLinkValue`, not a list of links, so the
loop above renders nothing for it; a warning naming the field is logged when it is read, and the
element's editor shows the problem.

### Link types

Every link has a type, which decides what it holds, how it is checked and where it leads. The
types below are built in; a field chooses which of them authors may use. A link holds only what
identifies its target, never a copy of a title or URL: those are read from the target each time
the link is resolved.

| Type | Handle | Data (stored, copied and exported as) | Leads to |
|---|---|---|---|
| URL | `url` | `{url}`: an `http(s)` URL, a path on the site (`/about`) or an anchor (`#team`), in canonical form | The URL, with the link's URL suffix |
| Entry | `entry` | `{elementId, siteId?}` | The entry's URL |
| Category | `category` | `{elementId, siteId?}` | The category's URL |
| Asset | `asset` | `{elementId, siteId?}` | The file's URL; it can be a download |
| User | `user` | `{elementId}` | The user's URL, if the site gives users one (`Element::EVENT_DEFINE_URL`) |
| Product | `commerce-product` | `{elementId, siteId?}` | The product's URL (only while Craft Commerce is installed) |
| Email | `email` | `{address, subject?, body?}` | `mailto:` |
| Phone | `tel` | `{number}`: `+` and digits | `tel:` |
| SMS | `sms` | `{number, body?}` | `sms:` (RFC 5724) |
| Social | `social` | `{network, account}` | The account's profile page |
| Embed | `embed` | `{provider, mediaId}` | The media's page; the provider also builds its player URL |

- **URLs** are checked as browsers read them: only `http` and `https` (never `javascript:`,
  `data:` or other schemes; email, phone and SMS have their own types), no credentials, no
  control characters or backslashes, no ambiguous numeric hosts, at most 4,096 characters.
  Equivalent spellings are stored once (`HTTPS://Example.COM:443/a/../b` is
  `https://example.com/b`). A URL is external when its host is not the site's.
- **Element links** (entry, category, asset, user, product) hold the element's ID and, for
  elements that have a version per site, optionally the site to link to. Without one, a link
  leads to the version in the site of the content it is in, so propagated content links to each
  site's own version; with one, it links across sites. They resolve through Craft's own element
  queries:

  | The target is… | The link… |
  |---|---|
  | live (its element type's default status, e.g. `live` for entries, enabled for users) | leads to its URL, labelled with its title |
  | live, without a URL in that site | leads nowhere: *no URL* |
  | disabled, pending or expired, there or in that site | leads nowhere: *disabled* |
  | deleted (soft or hard), or not in that site | leads nowhere: *missing*; restoring it restores the link |

  An author can choose only a canonical element of the right type: an ID that belongs to a draft,
  a revision or another kind of element is refused, and a link holding one in code never leads to
  it. A link whose target was deleted is still accepted, so the rest of the content can be saved,
  and the editor says the target is gone. The editor never shows an element the user may not
  view, not even when it is pasted: it says only that the link points at one. The element pickers
  offer only what the user may view, as Craft's own entry link type does by default: the
  sections, category groups and product types of their own element index, entries of other
  authors only with the permission to view them, volumes they may view (and only those with
  public URLs), and users only to someone who may view users.
- **Users** have no URL in Craft unless the site defines one, and a user link's default label is
  the user's full name only, never a username or email address.
- **Email** addresses keep their local part as written (it is case-sensitive) and lowercase their
  domain, internationalized domains in their ASCII form. Only plain addresses are accepted: no
  quoted names, comments, IP literals or several addresses. **Phone and SMS** numbers lose only
  spaces, hyphens, dots and brackets; letters and pause characters are refused, and so is a
  bracketed `(0)` after a country code (`+44 (0)20…`), which removing the brackets alone would turn
  into another number.
- **Social** links are a network and an account, never a URL, so a link can only lead to that
  network. Built in: Facebook, Instagram, X, LinkedIn (people and company pages), YouTube, TikTok,
  GitHub, Pinterest, Threads, Bluesky and Mastodon (`@name@server`).
- **Embed** links point at media on a known provider: YouTube, Vimeo (including unlisted videos),
  Spotify and SoundCloud. A pasted URL is recognised by its form alone; nothing is fetched from
  the provider or the URL, ever. Only the media is kept, not a start time or a share tracker. A
  URL that names its media ambiguously (a video given twice) or names a page that is not media
  (an artist's track list, a channel) is refused rather than guessed at. The page and player URLs a
  provider builds must be absolute `https` URLs, or the link fails to resolve rather than render.
  `EmbedLinkType::embedUrl()` gives the provider's player URL (YouTube's is the
  privacy-enhanced `youtube-nocookie.com` player).
- **Commerce products** are offered only while Craft Commerce is installed and enabled. Without
  Commerce, nothing of it is loaded, product links already stored are kept unchanged (shown as
  links that can't be read), and fields list the type as not available until Commerce is back.

A link's data is readable in templates, e.g. `link.data.address` or `link.data.elementId`.

Converting values of Craft's own Link field is part of each type
(`CraftLinkConverterInterface::dataFromCraftLink()`, e.g. `{entry:12@1:url}` or
`mailto:hello@example.com?subject=Hi`), for migrating content later. It gives authoring input,
which is then checked like any other; what a Smart Links type cannot hold (e.g. a `cc` in an email
link) is reported, never dropped.

### GraphQL

A Smart Links field is a list of `SmartLink` objects (a single-link field is one, or null):

- the link as authored: `uid`, `type`, `label`, `urlSuffix`, `presetUid`, `target`, `rel`,
  `title`, `class`, `id`, `ariaLabel`, `download`, `downloadFilename`, `customAttributes`;
- what it renders as in the site of the element it is read from: `status` (`resolved`,
  `missing`, `disabled` or `noUrl`), `url`, `text`, `external` and `html` (the encoded `<a>`
  tag). For a link that leads nowhere, `url`, `text` and `html` are null, and nothing about its
  target is shown;
- `data`: the link type's own data, one object type per link type in the `SmartLinkData` union,
  e.g. `... on SmartLinkData_email { address subject body }`, or
  `... on SmartLinkData_embed { provider mediaId embedUrl }`;
- `element`: for a link to an element, that element, read through Craft's own GraphQL resolver
  for its type, so it is there only when the schema may read it, and it is live in the site the
  link leads to.

```graphql
{
  entry(slug: "home") {
    ... on page_Entry {
      ctaLinks { type label url text status data { ... on SmartLinkData_url { url } } element { id title } }
    }
  }
}
```

A stored value that can no longer be read, or a draft's unfinished one, is a GraphQL error on the
field, not an empty list.

Mutations take the field's whole value as a list of `SmartLinkInput` objects, with the type's data
as a JSON object, and check it exactly as the control panel's editor does: anything the editor
would refuse is refused, and nothing is saved.

```graphql
mutation {
  save_pages_page_Entry(id: 12, ctaLinks: [{type: "url", data: "{\"url\": \"https://example.com/\"}", label: "Read more"}]) { id }
}
```

An empty list clears the field. (Craft's mutation handling fails on `null` for an input-object
argument before the field sees it, so clear with `[]`.) A registered link type shows its data in
GraphQL by implementing `GqlLinkTypeInterface`; without it, its links have every other field, and
a null `data`.

### Registering link types, networks and providers

Plugins and modules add to Smart Links through documented interfaces and events, without
changing it. Smart Links registers its own types through the same event, so a handler can also
remove or replace one.

A link type implements `Tahadudhiya\SmartLinks\linktypes\LinkTypeInterface`: its handle and
name, the attributes it supports, its own typed data (`LinkTypeDataInterface`), which it builds
from author input and from its stored form, validates and gives the editor inputs for, and its
resolver (`LinkResolverInterface`), which finds where a link leads in a site without making
network requests. A target that leads nowhere is a normal result (missing, disabled, or without
a URL); a resolver throws only when resolving itself fails, and that failure is never shown as
a missing link. It must be constructible without arguments, and is registered by class name:

```php
use craft\events\RegisterComponentTypesEvent;
use Tahadudhiya\SmartLinks\services\LinkTypes;
use yii\base\Event;

Event::on(LinkTypes::class, LinkTypes::EVENT_REGISTER_LINK_TYPES, function(RegisterComponentTypesEvent $event) {
    $event->types[] = MyLinkType::class;
});
```

It also says what a link points at (`targetIdentity()`), so links to the same target are counted
together. A link type for another element type extends `BaseElementLinkType` and names its
handle and element type; it then behaves as the built-in element types do. To show the linked
element in GraphQL it also names its element type's Craft GraphQL resolver
(`gqlElementResolver()`) and schema check (`gqlCanQueryElements()`); without them, GraphQL shows
the link but never the element.

Social networks and embed providers are registered the same way, as instances:

```php
use Tahadudhiya\SmartLinks\events\RegisterSocialNetworksEvent;
use Tahadudhiya\SmartLinks\linktypes\social\SocialNetwork;
use Tahadudhiya\SmartLinks\linktypes\SocialLinkType;

Event::on(SocialLinkType::class, SocialLinkType::EVENT_REGISTER_NETWORKS, function(RegisterSocialNetworksEvent $event) {
    // Handle, name, the account's form, and the profile URL.
    $event->networks[] = new SocialNetwork('dribbble', 'Dribbble', '[A-Za-z0-9_-]{2,30}', 'https://dribbble.com/{account}', false);
});
```

An embed provider implements `EmbedProviderInterface` (or extends `BaseEmbedProvider`) and is
added on `EmbedLinkType::EVENT_REGISTER_PROVIDERS`. A network or provider whose URLs are not
valid absolute URLs makes its links fail to resolve rather than render.

### What is stored

Smart Links never stores your links in its own tables. Links live in field values, which
Craft saves with each element, site, draft and revision. Smart Links' tables hold only what can
be worked out from that content, or what was observed when a link was checked:

| Table | Holds |
|---|---|
| `smartlinks_index` | Each distinct link target once. |
| `smartlinks_usage` | Each place a target is used: element, site, field and link. |
| `smartlinks_health` | The latest health check result for each URL, with its evidence. |
| `smartlinks_health_history` | Earlier health check results, which Smart Links only ever adds to. |

Uninstalling Smart Links drops these four tables and nothing else. Field content is never
touched.

## Planned

The direction Smart Links is being built towards. None of this is implemented yet.

- Managing link presets, and preset-driven authoring.
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
composer test-integration  # integration and security suites
```

The link editor's behaviour in a browser (keyboard and drag-and-drop reordering, focus, copy and
paste, overlapping operations, failures) is tested in headless Chrome. The test renders the
editor inside DDEV (`ddev exec`, or the command in `SMARTLINKS_RENDER`) and runs it in Chrome on
the host, so Chrome must be installed (or `CHROME_BIN` set):

```bash
composer test-browser
```

The field is also tested in Craft's real control panel, through the host project's own web
server: authoring every link type with Craft's element pickers, saving and reloading, reordering
(also from the keyboard), duplicating, copying and pasting, drafts and revisions, sites, element
card previews, the front end, GraphQL over HTTP, and a user with limited permissions. It creates
real content in the host project (never written to its project config YAML), removes it
afterwards, and fails unless the database, project config and files are exactly as they were.
It runs Chrome on the host and needs DDEV:

```bash
composer test-cp
```

## License

Smart Links is licensed under the [Craft License](LICENSE.md).
