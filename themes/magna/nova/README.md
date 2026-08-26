# Nova — the Magna product-site theme

Nova is the Magna CMS marketing site turned into a real Magna theme: dark
editorial bands, gradient accents, a card-driven marketing vocabulary, and a
starter site kit that ships all ten pages of the site as **block documents**.

The design rule the whole theme is built around:

> Nothing on this site may be a hand-written template. Every page, every
> section and every block is something an editor can select, edit, reorder
> and delete in the page builder.

That is why there is exactly one theme block view in here. Everything else is
CSS written against the classes the block renderer already emits.

---

## Install

```bash
php artisan magna:site:sync themes/magna/nova/starter/magna-website.json
```

Then activate the theme (Appearance → Themes in the admin, or):

```bash
php artisan tinker --execute="app(\Magna\Themes\ThemeManager::class)->activate('magna/nova');"
```

The sync **upserts** — pages, templates and menus present locally but absent
from the kit are left alone. It creates:

| Kind | Handles |
|---|---|
| Pages | `home`, `why-magna`, `features`, `page-builder`, `for-developers`, `agencies`, `use-cases`, `plugins`, `about`, `compare` |
| Template part | `footer` (kind `part`) |
| Menus | `primary`, `footer_product`, `footer_developers`, `footer_company` |
| Settings | `home_page_slug` → `home` |

Re-run the sync any time; node ids in the kit are stable, so a re-sync
updates the same nodes instead of replacing the document wholesale.

---

## Section vocabulary

Nova's design lives in a set of **section CSS classes**. Type them into the
section's *CSS class* field in the builder's inspector; combine one band
class with one pattern class.

### Bands — what the strip looks like

| Class | Ground |
|---|---|
| `nova-hero` | Dark, grid overlay and a gradient bloom. Adds `nova-hero--short` for a sub-page hero. |
| `nova-problem` | White, hairline rule beneath |
| `nova-why` | Off-white |
| `nova-arch` | White, hairline rules above and below |
| `nova-headless` | White |
| `nova-plugins` | Soft grey |
| `nova-builder` | White, hairline rule above |
| `nova-audiences` | Off-white |
| `nova-faq` | Soft grey |
| `nova-cases` | Dark |
| `nova-cta` | Deepest dark, centred, radial glow |
| `nova-trust` | Dark ribbon, no top padding |

### Patterns — how the blocks inside are drawn

| Class | Effect |
|---|---|
| `nova-cards` | A **features** block becomes elevated cards (the "why Magna" grid) |
| `nova-chips` | A **features** block becomes compact chips |
| `nova-rows` | A **features** block becomes full-width rows with a status pill |
| `nova-steps` | A **features** block becomes numbered steps |
| `nova-layers` | A **features** block becomes stacked layer bars with a tag |
| `nova-marquee` | A **features** block becomes a wrapping ribbon of large labels |
| `nova-cardgrid` | Nested **container** blocks lay out two-up as cards |
| `nova-compare` | Table styling for a wide comparison grid |
| `nova-terminal` | The column holding a **code** block reads as a terminal |
| `nova-grid-2` | Forces a two-column features grid instead of auto-fit |
| `nova-center` | Centres the section's headings, prose and buttons |
| `nova-split` | Two-column row, vertically centred (desktop only) |
| `nova-foot` | A closing line + button under the pattern above it |
| `nova-light` | Marks a band as light so ghost buttons and chips invert |
| `nova-footer-main` | Footer link columns (used by the `footer` template part) |
| `nova-footer-bottom` | Footer baseline row, with a rule above it |

### Continuations

A section's column spans must sum to 12, so a full-width head **above** a
two-column grid cannot be a third column. Nova splits that composition into
two sections and joins them visually:

- `nova-continue--head` — the head; drops its bottom padding
- `nova-continue--tail` — the grid; takes a small top padding

---

## Conventions inside blocks

**Heading level H6 is the eyebrow pill.** Set a heading block to H6 and it
renders as the small uppercase capsule above a headline. No extra class, no
extra block.

> The trade-off, stated plainly: an eyebrow is a label for the headline
> beneath it, not a heading in its own right, so a screen reader announces
> one extra heading per section. It is the convention because no core block
> produces styleable non-heading text — a richtext paragraph is
> indistinguishable from body copy in CSS — and because the alternative,
> folding the eyebrow into the headline's richtext, would make two things an
> editor thinks of separately share one field. The document accessibility
> checker does not flag it (it only reports *descending-to-ascending* level
> jumps), so this note is the record.

**Two-tone headlines are a text block.** A heading block's text field is a
plain, escaped string, so it cannot carry the accent colour on half the
line. Nova's accented headlines are richtext blocks containing
`<h2>Websites changed. <em>Most platforms didn't.</em></h2>` — the `<em>`
takes the accent. It stays fully editable in the builder's richtext editor,
and it degrades to an ordinary italic headline under any other theme.

**Container blocks are cards.** Set the container's *Style* settings
(background `var(--nova-card)`, 1px border `var(--nova-border)`, radius
`var(--nova-radius-lg)`, padding) and you have the site's card. Nest
containers inside a row container for a card grid.

---

## The features block, extended

`views/blocks/features.blade.php` is the only Blade view Nova overrides. It
reads the core item keys and three more, all optional and all ignored by any
other theme:

```jsonc
{
  "icon": "shield",          // sprite name (below), or any short string / emoji
  "title": "Content type builder",
  "description": "Define custom content types with typed fields.",
  "status": "Available",     // pill on the right of a nova-rows item
  "tone": "avail",           // avail | dev | plan — what colours that pill
  "tag": "Opt-in",           // small label on a nova-layers row
  "url": "/features"         // makes the title a link
}
```

### Icon names

The theme layout defines an SVG sprite; a document stores a **name**, never
markup. A name the sprite does not know is printed as text, which is what
keeps an emoji working.

```
check  check-circle  code  shield  bolt  modules  pencil  screen  terminal
type   layout  layers  target  clock  building  list  devices  chart  users
cog    sparkle  arrow-right  arrow-up
```

---

## Chrome

- **Header** is the theme's own, built from the `primary` menu. Publish a
  template part with slug `header` and it replaces the theme's chrome; the
  mobile drawer copies whatever navigation the header actually has, so it
  keeps working either way.
- **Footer** ships as a template part (slug `footer`) so its link columns
  are editable in the builder like any other page. The theme's built-in
  footer is only the fallback for a site that has not designed one.

---

## Tokens

`tokens.json` carries the palette, the layout metrics and the two
typefaces. Every colour has a dark reading, so the site follows the
visitor's colour scheme (or whatever *Pages → Appearance* pins it to). The
theme's own `--nova-*` variables read from those tokens; they are also what
a block's style settings in the builder should reference —
`background: var(--nova-card)` rather than a literal hex.

Fonts are Figtree (display) and Inter (body), loaded from Google Fonts.
Change `typography.displayFamily` / `typography.bodyFamily` to use others;
the `<link>` in the layout is the only place the Google Fonts request lives.

---

## What the theme does not carry

- **SEO titles and meta descriptions.** Neither core nor `magna/pages`
  stores them, and the site-kit format has no field for them. The copy for
  all ten pages is in `website-content/13-seo-metadata.md`; enter it once
  `magna/seo` is installed.
- **Decorative mock panels** from the source HTML (the page-builder
  wireframe, the floating "Open Source" badge). They carry no content and
  could only exist as raw markup, which is exactly what this theme refuses.
  The bands they sat in keep their copy.
- **The `developers` path.** It is claimed at the site root by the
  Marketplace plugin's admin resource, so the marketing page ships as
  `for-developers`.

---

## Regenerating the starter kit

The kit is generated from the source HTML in `website-content/`:

```bash
python website-content/build/convert.py themes/magna/nova/starter/magna-website.json
```

Node ids are deterministic, so regenerating and re-syncing updates the
existing documents rather than replacing them.

---

## Checks

```bash
php artisan magna:theme:check magna/nova
```

Nova passes the restricted-view audit: no raw PHP, no forbidden calls, no
unescaped output outside the layout contract, and no `<script>` in a block
view — the theme's behaviour is one reviewable block at the end of the
layout.
