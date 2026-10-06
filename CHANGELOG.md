# Release Notes for Smart Links

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

- Smart Links installs as a Craft CMS 5 plugin, with a control panel section reporting its
  installed version. Access follows Craft's “Access Smart Links” permission.
- Smart Links installs its own database tables for the link index, link usage, link health and
  link health history, and drops them again on uninstall. They hold no data yet.
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
- Link presets are read from project config (`smartLinks.presets.<uid>`). A link keeps the preset
  it was made with; a preset that is not allowed or no longer exists is reported, not removed.
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
