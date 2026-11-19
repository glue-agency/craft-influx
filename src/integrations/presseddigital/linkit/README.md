# Linkit

Mapping strategy for [presseddigital/linkit](https://github.com/presseddigital/linkit).

A Linkit value is a link type, the value that type addresses, and optionally a
custom text and a new-window flag: the same shape as Craft's own Link field, so
the row works the same way. It has no source node of its own; each slot is a
sub-row.

| Slot | Offered when | Notes |
|---|---|---|
| Link type | always | A select over the link types the field enables, defaulting to the first. |
| Value | always | A URL, an email address, a phone number, or an element ID for an element-typed link. |
| Custom text | the field allows custom text | |
| Open in new window | the field allows a target | A boolean, or an HTML target: `_blank` is on, `_self` is off. |

## Link type

Map it to a node when the feed mixes link types, or pick one as the default when
it doesn't. A feed may name a type by Linkit's model class
(`presseddigital\linkit\models\Email`) or by its short name (`email`, `url`,
`entry`), which resolves to the class of that name in Linkit's models, the
spelling Feed Me's Linkit importer accepts. A type the field doesn't enable
fails the row, not the item.

Element-typed links (entry, asset, category, user, product) take the element's
ID as their value. There is no match-by lookup yet.

## Sync semantics

The feed owns the whole link. A link with no value clears the field, as Linkit
itself does, and a slot the feed doesn't map is written empty. Change detection
compares type, value, custom text and target, so an unchanged link doesn't
re-save the element.

## Installation

Nothing to do. The strategy is keyed by class string
(`presseddigital\linkit\fields\LinkitField`), so an install without the plugin
never touches it.
