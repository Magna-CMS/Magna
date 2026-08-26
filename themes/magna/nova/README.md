# Nova — the Magna product-site theme

Nova is the hand-written Magna CMS marketing site, turned into a Magna theme
without turning into a different design. The stylesheet **is** that site's
stylesheet: the declarations are the original's, unchanged, with their
selectors pointed at the markup the block renderer emits. Where the original
wrote `.why-card`, Nova writes `.why .magna-features__item` and keeps every
value.

The rule the whole theme is built around:

> Nothing on this site may be a hand-written template. Every page, every
> section and every block is something an editor can select, edit, reorder
> and delete in the page builder — and it still looks exactly like the
> original.

That is why there is exactly one theme block view in here. Everything else
is CSS against classes the renderer already produces.

**Verified against the original** at 1440×900, page by page. Document
heights, mine against the hand-written page:

| | | | |
|---|---|---|---|
| compare | 5008 / 5008 | why-magna | 5354 / 5340 |
| home | 10196 / 10173 | developers | 5309 / 5316 |
| features | 6496 / 6505 | agencies | 4026 / 4048 |
| use-cases | 3494 / 3467 | about | 4118 / 4169 |
| plugins | 5127 / 5183 | page-builder | 3906 / 4025 |

On home every band is within 8px. Page Builder's 119px is the decorative
builder wireframe, which carries no content and could only exist as raw
markup.

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

Node ids in the kit are deterministic, so re-running the sync updates the
same nodes instead of rewriting the document.

---

## The section vocabulary

The design lives in **section CSS classes**, and they are the original's own
class names. Type them into the section's *CSS class* field in the builder's
inspector; combine one band with any patterns it needs.

### Bands

| Class | What it is |
|---|---|
| `hero` | The dark hero: grid overlay, gradient mesh, two-up with a card. Add `short` for a sub-page hero (single column, 820px measure). |
| `problem` | White, hairline rule beneath |
| `why` | Off-white; a features block becomes the card grid |
| `arch` | White, rules above and below |
| `headless` | Off-white |
| `plugins` | Soft grey; a features block becomes the numbered steps with the connecting line |
| `builder` | White, rule above |
| `audiences` | Off-white |
| `faq` | Soft grey |
| `cases` | Dark; a features block becomes the use-case chips |
| `cta` | Deepest dark, centred, radial glow |
| `trust` | The dark scrolling ribbon under the hero |

### Patterns

| Class | Effect |
|---|---|
| `split` | Marks a two-column row |
| `problem-grid` / `arch-grid` / `builder-grid` / `headless-grid` | The original's two-column compositions, each with its own ratio and its own card treatment. A grid is independent of the band it sits in — the developers page puts a `headless-grid` inside a `why` band — so the class travels with the section. Kept even when one of the two columns held only decoration. |
| `center` | Centres the section's headings, prose and buttons |
| `grid-2` | Two-up instead of the band's default column count |
| `feat-rows` | A features block becomes full-width rows with a status pill |
| `aud-grid` | Nested container blocks become the two-up card grid |
| `cmp-wrap` | The comparison table's frame |
| `cases-foot` | The closing line and button beneath a pattern |
| `prose` | A long-form column: 780px measure, 16.5px paragraphs, 20px apart |
| `marquee` | The ribbon's scrolling track |
| `head-continue` / `tail-continue` | See below |

### Continuations

A section's column spans must sum to 12, so a full-width head **above** a
two-column grid cannot be a third column. Nova splits that composition into
two sections and joins them seamlessly: `head-continue` drops its bottom
padding, `tail-continue` takes the head's 64px as its top padding.

---

## Conventions inside blocks

**Heading level H6 is the badge.** Set a heading block to H6 and it renders
as the original's pill — accent tint, uppercase, pulsing dot. On a dark band
it switches to the light treatment automatically, the way `.badge.light`
did.

> The trade-off, stated plainly: a badge labels the headline beneath it
> rather than being a heading in its own right, so a screen reader announces
> one extra heading per section. It is the convention because no core block
> produces styleable non-heading text — a richtext paragraph is
> indistinguishable from body copy in CSS — and because folding the badge
> into the headline's richtext would make two things an editor thinks of
> separately share one field.

**Two-tone headlines are a text block.** A heading block's text field is a
plain, escaped string, so it cannot carry the accent on half the line.
Nova's accented headlines are richtext blocks holding
`<h2>Websites changed. <em>Most platforms didn't.</em></h2>`. In a hero the
`<em>` is the original's gradient text; elsewhere it is the accent colour.
It stays editable in the builder's richtext editor and degrades to an
ordinary italic headline under any other theme.

**Container blocks are cards.** The hero card and the audience cards are
container blocks whose *Style* settings carry the original's values.
Translucent surfaces ride two variables — `var(--card-invert)` and
`var(--border-invert)` — because a style setting may hold `var(--token)` and
nothing else; `rgba()` is refused by the renderer's CSS sanitizer.

---

## The features block, extended

`views/blocks/features.blade.php` is the only Blade view Nova overrides. It
reads the core item keys and three more, all optional and ignored by any
other theme:

```jsonc
{
  "icon": "shield",          // sprite name (below), or any short string / emoji
  "title": "Content type builder",
  "description": "Define custom content types with typed fields.",
  "status": "Available",     // pill on the right of a feat-rows item
  "tone": "avail",           // avail | dev | plan — what colours that pill
  "tag": "Opt-in",           // small label beside the title
  "url": "/features"         // makes the title a link
}
```

### Icon names

The layout defines an SVG sprite; a document stores a **name**, never
markup. A name the sprite does not know prints as text, which is what keeps
an emoji working.

```
check  check-circle  code  shield  bolt  modules  pencil  screen  terminal
type   layout  layers  target  clock  building  list  devices  chart  users
cog    sparkle  arrow-right  arrow-up  close
```

---

## Chrome

- **Header** is the theme's own, built from the `primary` menu: the
  wordmark with its animated mark, the underline-on-hover navigation, the
  translucent scrolled state and the hamburger that folds into a cross.
  Publish a template part with slug `header` and it replaces all of that;
  the mobile drawer copies whatever navigation the header actually has, so
  it keeps working either way.
- **Wordmark**: the site name is drawn the way the original drew it — the
  last word set apart as the small accent suffix ("Magna **CMS**") when
  there is one, the whole name otherwise, so another site still gets a
  wordmark rather than a hardcoded one.
- **Footer** ships as a template part (slug `footer`) so its link columns
  are editable like any other page. The theme's built-in footer is the
  fallback for a site that has not designed one.
- **Visitor chrome only.** The preloader, scroll progress, cursor glow,
  back-to-top and the behaviour script are all suppressed in builder mode:
  the canvas renders through this same layout, and a splash screen over the
  page would be a splash screen over the thing being edited.

---

## Tokens and colour

`tokens.json` carries the original's palette, metrics and the two
typefaces, under the original's own names — `accent`, `accent_2`,
`bg_dark`, `text_muted` and so on — so the ported rules did not have to be
touched. Change a token in Appearance and the site repaints.

**There is no dark reading.** The original site has one palette, and a
theme that repainted it under `prefers-color-scheme: dark` would not be the
same site. Values a token cannot hold (anything with a bracket — every
`rgba()` and every shadow) live in the stylesheet, which is where the
original kept them too.

Fonts are Figtree (display) and Inter (body) from Google Fonts. Change
`typography.display_family` / `body_family` to use others; the `<link>` in
the layout is the only place the request lives.

---

## What the theme does not carry

- **SEO titles and meta descriptions.** Neither core nor `magna/pages`
  stores them, and the site-kit format has no field for them. The copy for
  all ten pages is in `website-content/13-seo-metadata.md`; enter it once
  `magna/seo` is installed.
- **Two decorative mock panels** from the original: the page-builder
  wireframe and the floating "Open Source" badge. They carry no content and
  could only exist as raw markup, which is what this theme refuses. The
  bands they sat in keep all of their copy.
- **The `developers` path**, which is claimed at the site root by the
  Marketplace plugin's admin resource. The marketing page ships as
  `for-developers`.

---

## Regenerating the starter kit

The kit is generated from the source HTML in `website-content/`:

```bash
python website-content/build/convert.py themes/magna/nova/starter/magna-website.json
```

---

## Checks

```bash
php artisan magna:theme:check magna/nova
```

Nova passes the restricted-view audit: no raw PHP, no forbidden calls, no
unescaped output outside the layout contract, and no `<script>` in a block
view — the theme's behaviour is one reviewable block at the end of the
layout.
