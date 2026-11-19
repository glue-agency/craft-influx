# SEOmatic

Mapping strategy for the SEO Settings field of
[nystudio107/craft-seomatic](https://github.com/nystudio107/craft-seomatic).

The field holds a whole meta bundle. The row has no source node of its own and
one sub-row per setting a feed can carry, the list SEOmatic's own Feed Me
integration offers without its asset pickers:

| Tab | Settings |
|---|---|
| General | SEO title, description, keywords, image URL, image description, robots, canonical URL |
| X (Twitter) | title, description, image URL, image description |
| Facebook | title, description, image URL, image description |

Robots is a select over SEOmatic's own values (`all`, `none`, `noindex`,
`nofollow`, `nosnippet`, `noarchive`, `noimageindex`).

## Sync semantics

- **Only the mapped settings the item carries change.** A setting whose key the item doesn't have is left as it
  is. The rest of the bundle (sitemap settings,
  an editor's other overrides) is read off the element and written back as it
  was. Feed Me's integration replaces the whole bundle instead, resetting
  everything it doesn't map to the field's defaults.
- **A written setting gets the source that keeps it.** A text setting's source
  becomes Custom and an image URL's becomes Custom URL, as an editor sets them
  before typing a value. With any other source SEOmatic replaces the value when
  it normalizes the bundle.
- **An empty or `null` value clears the setting**, the feed being
  authoritative. The setting then falls back to what SEOmatic's own cascade
  gives it.
- **Change detection compares the mapped settings and their sources only**, so
  an unchanged feed doesn't re-save the element.

Image assets (`seoImageIds` and the like) aren't mapped: the image URL settings
cover a feed that ships images by URL.

## Installation

Nothing to do. The strategy is keyed by class string
(`nystudio107\seomatic\fields\SeoSettings`), so an install without the plugin
never touches it.
