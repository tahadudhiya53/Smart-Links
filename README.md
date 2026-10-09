# Smart Links

**Create. Find. Monitor. Fix.**

Smart Links is a link management and link intelligence plugin for Craft CMS. It is built to sit
alongside Craft's own content tools and add what they do not cover: a site-wide view of the links
your content contains, where each one is used, and whether it still works.

> **Status:** under active development. Smart Links provides a **Smart Links** field with
> built-in link types, and a site-wide inventory of the links in those fields. Nothing listed
> under [Planned](#planned) is available.

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
- Adds a **Smart Links** section to the control panel: an overview showing the installed
  version, the **Links** inventory and the **Presets** page. Access to the section is controlled by
  Craft's own **Access Smart Links** user permission; the Links page also needs **View the link
  inventory**, and the Presets page **Manage link presets**.
- Adds a **Smart Links** field type, and the link types it offers (see below).
- Keeps a link index of every link in Smart Links fields across the site, and shows it as the
  link inventory (see [The link inventory](#the-link-inventory)). Link health tables are created
  too; nothing checks links yet, so every link's health is Unknown.

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
- **Presets:** each link can be made with one of the presets the field allows (see
  [Link presets](#link-presets)). A preset the field does not allow, that no longer exists, that
  is not for the link's type, or whose locked settings the link does not keep, is reported on the
  link rather than removed or corrected.
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

### Link presets

A preset is a kind of link authors can choose, such as a primary call to action or a download
link. It says which link types it is for, which settings a link starts with when an author
chooses it, and which of those settings it locks.

- **Built-in presets:** installing Smart Links adds six presets: *Primary CTA*, *Secondary CTA*,
  *Text Link*, *External Link* (URL links; opens in a new window, `rel="external noopener"`),
  *Download Link* (asset and URL links; download on, locked) and *Social Link* (social links;
  opens in a new window). They are ordinary presets: change, disable, reorder or delete them like
  any other. How a call to action or a text link looks is your site's own design, so those hold
  no classes until you give them some. They are not added when Smart Links is installed by
  applying project config from another environment, which brings that environment's presets.
- **Managing presets:** *Smart Links → Presets* lists the presets in the order authors are offered
  them; drag to reorder, and delete after confirming. Each preset has a name (unique), whether it
  is enabled, the link types it is for (none means every type), its defaults (URL suffix, target,
  `rel`, CSS classes, download, and custom `data-*` / `aria-*` attributes) and the settings it
  locks (URL suffix, target, `rel`, CSS classes, download). A title, ID, ARIA label or download
  filename describes one link, so a preset cannot set them. Defaults are checked by the same
  rules as a link's own attributes, and a preset for given link types cannot set or lock what one
  of them lacks. The list flags a preset that no longer passes these checks (for example, one for
  a link type a disabled plugin provided). Every page and action needs the **Manage link
  presets** permission (the pages also need Craft's **Access Smart Links**); in an environment
  where administrative changes are not allowed, presets can be looked at but not changed, and
  every change is refused by the server.
- **Where presets live:** in project config, one file per preset under
  `config/project/smartLinks/presets/`, so they deploy with the rest of your configuration. A
  preset is identified by its UID, which links store; its name is only its label. A definition
  holds `name` (unique, ignoring case) and `sortOrder` (a whole number from 1, unique), and only
  what is set of `enabled`, `types` (link type handles), `urlSuffix`, `attributes` (in the form
  attributes have in stored link content) and `locked`. It holds no IDs, so it means the same in
  every environment. A malformed definition is a configuration error (`InvalidConfigException`),
  never partly read or repaired. Whether its link types are registered, and support what it sets,
  depends on the plugins installed, so that is checked when a preset is saved and shown on the
  presets list, not when it is read; a setting a link's type lacks never reaches the link.
- **Which presets a field offers:** each Smart Links field allows a list of presets, and cannot
  allow one that is for none of its link types. Authors are offered the allowed presets that
  exist, are enabled, and are for at least one of the field's link types.
- **Enabled, disabled, deleted:**
  - An **enabled** preset can be given to any link the field allows it for.
  - A **disabled** preset cannot be newly assigned to a link. Existing stored links that already
    use it keep it. No link can be given it: not a new link, a pasted or duplicated one, a link
    that had another preset or none, a link sent through GraphQL, a form or code, or a new default
    link; each is refused, with a message saying the preset is disabled. This is checked on every
    save, drafts and autosaves included. A link keeps it only if Craft has stored that link (by
    its UID) with that preset for the same element in the same site, for the element a draft or
    revision belongs to, or for the element Craft is copying (when it makes a draft or revision,
    publishes a draft, reverts to a revision, duplicates an element or propagates it to another
    site). Such a link stays valid, renders, can be edited, and can be given an enabled preset
    instead; once it has left the disabled preset it cannot be given it back. It is still held to
    the preset's link types and locked settings. The same link UID in another site, another
    element, or a value copied from another site with Craft's *Copy* action is a new link there,
    so it cannot carry the disabled preset. A field's own default links keep it too, but each new
    element starts with new copies of them, so an author creating an element is asked to choose
    another preset (or none) for those links before saving.
  - A **deleted** preset is removed from project config only. Links made with it keep its UID,
    render as before, and are reported as made with a preset that no longer exists, so they
    cannot be saved in full until they are given another preset or none. Nothing is replaced or
    removed for them.
- **Choosing a preset:** the settings the link has nothing in yet are filled in from the preset,
  and the editor switches the link to the first type the preset is for when its own type is not
  one of them. Settings the preset locks are shown read-only, with the preset named, while it is
  chosen.
- **Locked settings:** while a link has the preset, each locked setting must be exactly the
  preset's (or empty, if the preset does not set it); `rel` and class names are compared as sets
  of words. A link that differs is refused when it is saved, pasted or sent through GraphQL; the
  read-only input in the editor is only a convenience. A lock on a setting the link's type does
  not have (a download on an entry link) does not apply. Locking a setting of a preset already in
  use is not applied to existing links either: the ones that differ are refused on their next
  full save, until an author corrects them.
- **When the rules apply:** like every other link rule, on a full save, publishing, a GraphQL
  mutation and a paste; the disabled-preset rule on every save, drafts included. Craft's autosave
  otherwise keeps a draft's work in progress as it is.

**Precedence.** A link's settings come from these, each overriding the ones before it:

1. **System defaults:** what a link has when nothing is set (no attributes; it opens in the same
   window). One rule always applies on top: a link that opens in a new window is rendered with
   `rel="noopener"`.
2. **Preset defaults:** applied once, when an author chooses the preset, to the settings the link
   has nothing in yet.
3. **Field defaults:** a field's default links. They are the only link settings a field holds:
   complete links an administrator authored, possibly with a preset, that new elements start
   with, as they are. A preset chosen for such a link fills in only what it has nothing in.
4. **The existing link value:** what the link holds when it is opened. It is never rewritten:
   opening the editor, changing a preset, or changing a field's defaults changes no link already
   made.
5. **Explicit author input:** what the author enters. Choosing a preset never overwrites it;
   choosing another preset, or none, takes back only what the last preset filled in and nobody
   has changed since.

The one exception is a **locked setting**, which an administrator configures explicitly: choosing
the preset sets it to the preset's value, visibly, and choosing another preset or none gives the
setting back what it had. A preset is applied to a link only in the editor, when an author
chooses it. Nothing on the server applies presets: links saved from code, GraphQL or a paste hold
exactly what they were given, and are checked against their preset's type and locks.

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

### The link inventory

The **Links** page lists every distinct link target that Smart Links fields use, in every site:
a URL, an entry in a site, an email address and so on, each once however many links point at it.
Smart Links fields are the only content it reads; their values stay the source of truth, and the
index is derived from them, can always be rebuilt from them, and never changes them.

Each row is one target. For each it shows:

- where it leads now, and the target's name (an entry's title, for example), as last indexed;
- its link type;
- the label links give it (the most used, and how many others);
- the first place it is used (a nested entry is shown by the page it is in), and how many others;
- the sites whose content uses it;
- how many links use it (each link in each site counts once), which leads to where it is used
  for people allowed to see that (below);
- its health and when that was last checked: the observation of its URL, or **Unknown** while it
  has none, or **Can't be checked** for targets with no web URL (an email address, for example);
- what became of its target when last indexed: leads somewhere, doesn't exist, disabled, no URL,
  or couldn't be resolved.

Each page is read when it is shown: content indexed between one page and the next can move
targets from one page to another, as in any paged list.

It can be searched (target URLs, names, keys and the labels links give them), filtered by site,
link type, source field, health and target state, and sorted by link, type, usage, health, last
check or target state, 50 targets a page. Filters, sorting and the page are part of the page's
URL (the site filter is `sourceSite`: `site` is Craft's own control panel parameter); a value none
of them can have is refused, never ignored, and a page past the last says so. Filtering by site or field counts
only the links in that site's content or that field: a link in one site's content to an entry's
version in another site counts in the site whose content it is in.

Only canonical, live content counts: drafts, revisions and trashed elements don't, and an
element's links count once a draft of it is applied. Disabled elements do: their links are still
in content. Smart Links fields count wherever they are: entries (nested ones too), categories,
assets, users, addresses and, with Craft Commerce, products.

**Keeping it up to date.** The index follows Craft's own events, each through a queued job:

- saving, deleting, restoring, moving or deleting an element from a site: its links are read again
  in every site, what it no longer holds is removed, and where links to it lead is worked out
  again;
- a Smart Links field added to or taken out of a field layout: every element of that layout is
  read again (moving it within a layout, renaming it, or creating a new field changes no links,
  so nothing is queued);
- a Smart Links field deleted, a field changed into or out of a Smart Links field (by the control
  panel or by applying project config), or a site deleted, changed (its base URL decides where
  links to paths on it lead) or restored: a full rebuild;
- Craft's garbage collection, which deletes trashed content without events: targets left unused
  are removed.

A full rebuild is queued from the **Links** page (with the **Rebuild the link index**
permission) or from the command line:

```bash
php craft smartlinks/reindex          # queue a rebuild (exactly one job; nothing is read here)
php craft smartlinks/reindex --now    # rebuild in this process, with progress; exit 1 if anything couldn't be read
php craft smartlinks/index-status     # what the index holds, and how far it is behind; exit 1 if behind
```

A rebuild reads elements in batches of 100, in ID order, one queue job per batch, each queueing the
next only once its own work is written; then it reads what was left behind it (elements read
before it started that it did not reach, elements that are no longer sources, and elements saved
or added meanwhile), and only then removes, in one step, the links and records of anything that is
no longer a source and the targets nothing uses. The inventory stays available throughout:
nothing is emptied first. A failed batch stops the rebuild there, and retrying that job carries
on from it. Update jobs work the same way: one of more than 100 elements reads 100, then queues
the rest. Repeating any of it changes nothing, and rebuilds or updates running at once are safe:
every write to the index holds one lock, in every process, so nothing removes a target while
another job is pointing a link at it. A job that waited longer than a minute for that lock wrote
nothing; Craft's queue runs it again (up to five times in all), and any other failure fails it
as usual.

A value that can't be read (for example, its link type's plugin was removed) is not taken to hold
no links: its links stay as last indexed, the element is reported, and the job logs it.

**Stale index detection.** The Links page and `smartlinks/index-status` report every way the
index is known to differ from content: elements saved since they were indexed or never indexed,
elements with a value that can't be read, indexed elements that are no longer in use, links of a
field no longer in a layout, targets nothing uses, and targets recorded as leading somewhere whose
element or site has since gone. These come from the index's own keys; no content is read, and
reading the inventory never changes it: only indexing does. Not seen, so rebuild after them:
changes Craft tells plugins nothing about, such as a plugin update that changes a social network
or embed provider's URLs, or edits made directly in the database. Craft dates saves
to the second, so an element saved in the very second it was indexed looks up to date until its
own queued update has read it.

**Who sees what.** **View the link inventory** shows every target and the content using it, in
every site, so grant it only to people who may see all content. **View where links are used** and
**Rebuild the link index** are nested under it, and each needs it too. Rebuilding takes a POST with Craft's CSRF token; guests are sent
to sign in. Sources are linked to their edit page only for people allowed to view them. The
console commands are for whoever runs the command line, not a control panel permission.

### Where a link is used

Each target's usage count on the **Links** page leads to its own page (with the **View where
links are used** permission), listing every place a link to it is used: one row per link, in each
site's content. For each it shows:

- the element holding the link, and what that element is nested in, outermost first (a page, its
  Matrix field, the nested entry, and so on at any depth), each linked to its edit page for
  people allowed to view it; others see the names without links. An owner that can't be loaded is
  named as such, and nothing is put in its place;
- the element's type (entry, category, asset, user, product…);
- the field, by its name and handle as placed in that element's layout, as they are now: renaming
  a field shows at once, with nothing indexed again. When no Smart Links field is in that place
  any more, the field the index recorded is shown as *recorded*, or as deleted;
- the site whose content it is in;
- its position in the field, and the label the link gives it;
- whether the index has caught up with it. Until the element's queued update (or a rebuild) has
  run, a place is shown as last indexed and said to be out of date, saying why: the element is in
  the trash, no longer in that site, in a deleted site, of a type that is not available (its
  plugin removed), nested in an element of such a type, or can't be loaded; the field is no
  longer in the element's layout, has been deleted, or is no longer a Smart Links field; the
  element was saved since it was indexed; or one of its Smart Links values can't be read.

The list can be filtered by the site whose content holds the link (`sourceSite`) and by Smart
Links field (`source`), 50 links a page, in a fixed order (element, site, field, position). A
malformed filter or page is refused (400); a page past the last says so. The site filter is not
called `site` because Craft adds its own `site` to every control panel URL, the site the control
panel is showing; that parameter is Craft's, and never filters the list. A target the index has
never held, or has removed, is not found (404), and so is one it still holds that nothing uses
any more (the next pruning removes it), each with its own message.

A link's place is its element, its site, its place in the element's field layout and the link's
own UID. That stays the same while the link is edited, moved within its field or pointed at
another target, and through any rebuild. A copy of the link in another site's content or another
element (Craft propagating a value, or duplicating an element) is another place; so is the same
URL removed and added again as a new link. Drafts, provisional drafts (autosaves) and revisions
are never places a link is used: a draft's links count once it is applied, and reverting to a
revision brings its links back as the entry's own.

Where links are used is part of the link index: the same pass over content writes both, so a full
index rebuild is the rebuild of usage too, and everything said above about keeping the index up
to date applies to it.

In PHP, `SmartLinks::getInstance()->getUsage()` answers the same question three ways:

```php
use Tahadudhiya\SmartLinks\models\UsageCriteria;
use Tahadudhiya\SmartLinks\SmartLinks;

$usage = SmartLinks::getInstance()->getUsage();

// One indexed target (its ID in the index). An ID the index has no target for is refused.
$page = $usage->ofTarget($indexId, new UsageCriteria(siteId: $siteId, page: 2));

// Links that name an element: by the element itself, never by its URL. Its version in one site,
// or in every site when no site is given; a user has one version, found in every site.
$page = $usage->ofElement($entry->id, $entry->siteId);

// URL links to a URL, compared in canonical form, fragment included. A path (`/about`) is looked
// up in the given site, or in each site.
$page = $usage->ofUrl('https://example.com/pricing');

foreach ($page->items as $link) {
    $link->element;       // the element holding it, or null if it can't be loaded now
    $link->fieldHandle;   // as placed in that element's layout now, or null if no longer there
    $link->siteId;
    $link->position;      // from 1
    $link->stale;         // why it may differ from content now (a StaleUsage), or null
    $link->key();         // its place: element, site, layout element, link UID
}
```

Lookups never match on where links happen to lead: an entry link and a URL link to the entry's
page are different targets, each found its own way, as are two URLs that differ only in their
fragment, and a path and the same path written as a full URL on the site's host. URLs are
compared in the canonical form URL links are stored in, so only spellings that are provably the
same URL match (scheme and host case, default ports, dot segments, percent-encoding of
characters that need none); query order, trailing slashes, repeated slashes and path case all
count. A URL no link could hold is refused. An anchor (`#team`) points into the page it is on, so
it is found by that page with `ofElement()`. Links naming an element that has since been deleted
are still found by it, with their target recorded as no longer existing.

Results are read a page at a time. The elements holding a page's links are loaded together per
element type and site, and what they are nested in level by level the same way, so a page takes
the same few queries however many elements it shows (measured: 10 queries for a page of 10 and
for a page of 50, half of them nested entries).

### What is stored

Smart Links never stores your links in its own tables. Links live in field values, which
Craft saves with each element, site, draft and revision. Smart Links' tables hold only what can
be worked out from that content, or what was observed when a link was checked:

| Table | Holds |
|---|---|
| `smartlinks_index` | Each distinct link target once. |
| `smartlinks_usage` | Each place a target is used: element, site, field and link. |
| `smartlinks_sources` | Each element site the index has read, the version it read, and how many of its values it couldn't read. |
| `smartlinks_health` | The latest health check result for each URL, with its evidence. |
| `smartlinks_health_history` | Earlier health check results, which Smart Links only ever adds to. |

Uninstalling Smart Links drops these five tables and removes its project config (its presets),
and nothing else. Field content is never touched: links keep the UIDs of the presets they were
made with.

## Planned

The direction Smart Links is being built towards. None of this is implemented yet.

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
paste, overlapping operations, failures, choosing presets) is tested in headless Chrome. The test renders the
editor inside DDEV (`ddev exec`, or the command in `SMARTLINKS_RENDER`) and runs it in Chrome on
the host, so Chrome must be installed (or `CHROME_BIN` set):

```bash
composer test-browser
```

The field is also tested in Craft's real control panel, through the host project's own web
server: authoring every link type with Craft's element pickers, saving and reloading, reordering
(also from the keyboard), duplicating, copying and pasting, drafts and revisions, sites, element
card previews, the front end, GraphQL over HTTP, choosing a preset, and a user with limited
permissions. It creates real content in the host project (never written to its project config
YAML), removes it afterwards, and fails unless the database, project config and files are exactly
as they were, with no project config changes pending.

Managing presets is tested first, through the presets pages themselves, before that content
exists: creating, editing, disabling and enabling, reordering and deleting a preset as an admin,
and a user without and then with the *Manage link presets* permission. The web server writes
project config files for those changes; afterwards the test checks they differ only in the time
of the last change and puts that back. It refuses to start while the host's project config has
changes pending. It runs Chrome on the host and needs DDEV:

```bash
composer test-cp
```

## License

Smart Links is licensed under the [Craft License](LICENSE.md).
