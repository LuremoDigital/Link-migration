# Template Impact Guide

Hyper/Typed Link Field values and native Craft Link fields are not API-identical.

## Common Hyper patterns to review

Search for:

```text
.url
.text
.linkText
.target
getLink(
getHtml(
getData(
linkValue
.type
getElement(
hasElement(
Hyper
```

## Typical changes

Hyper often appears as:

```twig
{{ entry.cta.url }}
{{ entry.cta.text }}
{{ entry.cta.target }}
{{ entry.cta.getLink()|raw }}
{% for link in entry.links %}
  {{ link.url }}
{% endfor %}
```

Craft Link fields return a `craft\fields\data\LinkData` object.

Typical native usage:

```twig
{% set link = entry.cta %}
{% if link %}
  <a href="{{ link.url }}"{{ attr(link.attributes) }}>{{ link.label }}</a>
{% endif %}
```

Review anything that depends on:

- `getLink()`
- Hyper link type class checks
- `getElement()` / `hasElement()`
- custom fields attached to a Hyper link type
- embed HTML or provider data
- loops over multi-link Hyper fields
- Typed Link Field `customText`, `linkedUrl`, `getLinkAttributes()`, `getLink()`, `hasElement()`, or `getElement()` APIs
- current or escaped `lenz\\linkfield` class names and legacy `typedlinkfield` plugin references

## Potential runtime and template errors

These differences come directly from the official Hyper and Craft Link field docs and are the most likely causes of post-migration breakage:

### 1. Hyper property names do not match `LinkData`

Hyper docs show common template access patterns like:

```twig
{{ entry.cta.text }}
{{ entry.cta.linkValue }}
{{ entry.cta.linkText }}
```

Craft Link fields return `craft\fields\data\LinkData`, where the common properties are `label`, `value`, `url`, `type`, and `element`.

Update Hyper-style code like this:

```twig
{# Before #}
{{ entry.cta.text }}
{{ entry.cta.linkValue }}

{# After #}
{{ entry.cta.label }}
{{ entry.cta.value }}
```

### 2. Type checks change shape

Hyper type comparisons commonly use full class names:

```twig
{% if entry.cta.type == 'verbb\\hyper\\links\\Entry' %}
```

Craft Link uses short handles:

```twig
{% if entry.cta.type == 'entry' %}
```

If you keep the old comparisons, the template will not enter the expected branch even though the migrated data exists.

### 3. Element helper methods change

Hyper commonly uses:

```twig
{% if entry.cta.hasElement() %}
  {% set linked = entry.cta.getElement() %}
{% endif %}
```

Craft Link exposes the selected relational target as `entry.cta.element` instead.

### 4. Hyper-only features have no native Link equivalent

Hyper supports:

- multi-link fields
- embed links
- site links
- user links
- custom link types
- custom per-link field layouts
- embed helpers like `getHtml()` and `getData()`

Craft's native Link field supports URL, asset, category, email, entry, phone, and SMS links. It does not expose Hyper's or Typed Link Field's embed/site/user/custom-type APIs. Typed Link `customText` becomes native `.label`; `getElement()` and `hasElement()` become `.element` checks. Code that still expects source-only methods or custom fields can error after migration.

### 5. GraphQL consumers may break even if Twig is fixed

Hyper's GraphQL docs describe Hyper values as an array of link interfaces, even for single-link fields.

Craft Link fields default GraphQL output to a rendered string for backward compatibility unless the field is switched to `Full data`.

If you have a headless frontend, audit for:

- array access on a now-single value
- assumptions that GraphQL returns an object tree instead of a string
- class-name based type checks from Hyper
