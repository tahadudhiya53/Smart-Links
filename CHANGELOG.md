# Release Notes for Smart Links

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

- Smart Links installs as a Craft CMS 5 plugin, with a control panel section reporting its
  installed version. Access follows Craft's “Access Smart Links” permission.
- Smart Links installs its own database tables for the link index, link usage, indexed sources,
  link health and link health history, and drops them again on uninstall.
- Added the Smart Links field, which holds an ordered list of structured links, with settings for
  allowed link types, allowed presets, single or multiple links, minimum and maximum links, and
  default links. Malformed settings are refused rather than converted.
- The field's editor adds, edits, duplicates, removes, collapses and reorders links (by drag and
  drop, or from the keyboard with *Move up* and *Move down*), and shows only the inputs a link's
  type supports.
- Links can be copied and pasted between Smart Links fields. A paste is checked on the server
  against the link rules and the destination field's current settings: links of a type or preset
  the field does not allow are refused with every reason given, never converted, and a paste adds
  every link or none. Each editor's requests are bound to its field, layout placement, element
  and site, and need a user who may save that element; a field's default-links editor is bound
  to that field and needs an admin. One copy, duplicate or paste runs at a time, with the editor
  shown as busy; links added meanwhile keep their own inputs, and a paste that no longer fits by
  the time the server answers adds nothing. Failures say what went wrong.
- Clearing a stored value that cannot be read asks for confirmation first.
- Added link presets: reusable kinds of link with a name, the link types they are for, default
  settings (URL suffix, target, `rel`, CSS classes, download, custom attributes) and settings they
  lock. Presets live in project config (`smartLinks.presets.<uid>`) and deploy with it.
- Installing Smart Links adds six presets (Primary CTA, Secondary CTA, Text Link, External Link,
  Download Link, Social Link) as ordinary definitions, unless it is installed from project config.
  Uninstalling removes Smart Links' project config.
- Added the *Presets* page to the Smart Links section, to create, edit, enable or disable,
  reorder and delete presets, and the *Manage link presets* permission it needs. Presets cannot be
  changed where administrative changes are not allowed.
- Choosing a preset in the link editor fills in the settings the link has nothing in yet, never
  overwriting what is there, switches the link to a type the preset is for, and shows the
  preset's locked settings as read-only. Choosing another preset takes back only what the last one
  filled in untouched. Nothing on the server applies a preset to a link.
- A link is checked against its preset: a preset that is not allowed, no longer exists, is not
  for the link's type, or whose locked settings the link changed is reported, not removed.
- A disabled preset cannot be newly assigned to a link, however the link arrives (form, paste,
  duplicate, GraphQL, code, drafts and autosaves included). Existing stored links that already
  use it keep it and stay valid, for the same element in the same site and in Craft's own copies
  (drafts, revisions, duplicates). A field cannot allow a preset that is for none of its link
  types, and the editor offers only enabled presets for the field's types.
- Preset definitions in project config must have unique names and places in the order; a
  malformed definition is refused, never repaired. The presets list flags a preset whose link
  types are no longer available or no longer support what it sets.
- Field values are stored in Craft's element content and work with sites, drafts, revisions,
  duplication, copying between sites, and `:empty:`/`:notempty:` element queries. Invalid input is
  never stored; stored content that cannot be read is kept as it was, whatever its shape, and
  reported. Values set in code are a `LinkCollection` or the stored form.
- The field is available in GraphQL: each link as authored, what it renders as, its type's own
  data (one object type per link type), and for element links the element itself, read through
  Craft's own GraphQL resolvers so the schema's permissions apply. Mutations take the field's links
  as authoring input, checked as in the control panel.
- Added built-in link types: URL, Entry, Category, Asset, User, Product (Craft Commerce), Email,
  Phone, SMS, Social and Embed. Each has its own data, editor inputs, validation, canonical stored
  form, resolution and target identity.
- URLs are limited to `http`/`https`, paths on the site and anchors, in canonical form; unsafe or
  ambiguous URLs (other schemes, credentials, control characters, ambiguous hosts) are refused.
- Element links hold only the element's ID and an optional site, and resolve through Craft's own
  element queries: to the element's URL, or as missing, disabled or without a URL. They follow the
  content's site unless a site is chosen. Drafts, revisions and elements of another type cannot be
  chosen. The editor never shows an element the user may not view.
- The Smart Links field saves the elements its links point at as Craft relations.
- Email addresses, phone and SMS numbers are normalized only where the spelling provably makes no
  difference.
- Social links are a network and an account; the profile URL is built from them. Embed links are
  recognised offline from known providers' URLs; nothing is fetched while authoring or rendering.
- The Commerce product type is available only while Craft Commerce is installed and enabled;
  without it, nothing of Commerce is loaded and stored product links are kept unchanged.
- Added the link index: every link in Smart Links fields, per element and site, with each distinct
  target once, where it leads and what became of it. Only canonical, live content is indexed, from
  entries (nested ones too), categories, assets, users, addresses and Commerce products alike. It follows
  element saves, deletions, restores, URI changes and deletions from a site, Smart Links fields
  joining or leaving field layouts, fields deleted or changed, sites deleted, changed or
  restored, and Craft's garbage collection,
  through queued jobs. It can be rebuilt in full, in batches, without emptying it first.
  Indexing the same content again changes nothing, and every write holds one lock, so updates and
  rebuilds running at once are safe; a job that waited too long for another to finish runs again
  by itself. A value that can't be read keeps its links as last indexed and is reported.
- Added the *Links* page: the link inventory, searchable, filterable by site, link type, source
  field, health and target state, sortable, and paginated, with usage counts, labels, sources,
  sites, health and last check. Malformed filters are refused. The site filter is `sourceSite`,
  apart from the `site` Craft adds to control panel URLs. It reports every way the index is
  known to be behind content, and offers a rebuild.
- Added the *View the link inventory* permission, and *Rebuild the link index* nested under it.
- Added a page for each link target, reached from its usage count on the *Links* page, listing
  every place a link to it is used: the element holding it and what that element is nested in,
  its element type, the field (by its current name and handle where it is placed), the site, the
  link's position and label, with links to edit pages for people allowed to view them. It can be
  filtered by the site whose content holds the link and by field, 50 a page. A place the index has
  not caught up with yet is shown as last indexed and says why (element trashed, no longer in the
  site, in a deleted site, of a type or nested in an element of a type that is not available, or
  not loadable; field removed from the layout, deleted or no longer a Smart Links field; saved
  since; a value that can't be read).
- Added the *View where links are used* permission, nested under *View the link inventory*.
- Added the `usage` service: every place a link target is used, looked up by indexed target, by
  the element links name (never by URL), or by a URL in canonical form (URL links only, fragment
  included), a page at a time, in a fixed number of queries per page.
- Added the `smartlinks/reindex` (`--now` to run it in place of queueing it) and
  `smartlinks/index-status` console commands.
- Link types are registered through `LinkTypes::EVENT_REGISTER_LINK_TYPES`, social networks
  through `SocialLinkType::EVENT_REGISTER_NETWORKS`, and embed providers through
  `EmbedLinkType::EVENT_REGISTER_PROVIDERS`. Element link types can extend `BaseElementLinkType`.
- Each type that has a counterpart in Craft's Link field converts that field's values.
- Default links cannot point at elements, whose IDs differ between environments.
- The control panel never shows an element a link points at to a user who may not view it, or in
  a site they may not work in, and previews never name a target that leads nowhere. Element
  pickers offer only what the user may view.
- A draft keeps a link its author has not finished exactly as entered, so Craft's autosave keeps
  working; full saves, applying the draft and publishing still refuse it.
- A link's actions menu works from the keyboard.
- Added the `links` service, which renders a link as an `<a>` tag in a site, with
  `Links::EVENT_DEFINE_LINK_HTML` to replace the markup.
