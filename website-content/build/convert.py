"""Turn the hand-written website-content HTML into a Magna site-kit bundle.

Every page becomes a real block document: registered block handles, real
fields, no raw-HTML blocks. Design intent that the block vocabulary cannot
carry as data rides on section CSS classes the Nova theme defines
(nova-why, nova-cards, nova-rows, ...), which is exactly the seam the page
builder exposes as a section's "CSS class" field.
"""

from __future__ import annotations

import html
import json
import re
import sys
from pathlib import Path

from bs4 import BeautifulSoup, NavigableString, Tag

sys.path.insert(0, str(Path(__file__).parent))
from icons import name_for  # noqa: E402

SOURCE = Path(r"C:\Users\jishn\Herd\magna-cms\website-content")

# slug, source file, title. The home page keeps slug "home" and is pointed
# at by the site's home_page_slug setting — a page still needs a slug of
# its own to be addressable and to be found again by a later sync.
PAGES = [
    ("home", "home.html", "Home"),
    ("why-magna", "why-magna.html", "Why Magna"),
    ("features", "features.html", "Features"),
    ("page-builder", "page-builder.html", "Page Builder"),
    # `developers` is taken at the root by the Marketplace plugin's admin
    # resource, so the marketing page claims a slug nothing else can win.
    ("for-developers", "developers.html", "Developers"),
    ("agencies", "agencies.html", "Agencies"),
    ("use-cases", "use-cases.html", "Use Cases"),
    ("plugins", "plugins.html", "Plugins & Extensions"),
    ("about", "about.html", "About"),
    ("compare", "compare.html", "Compare"),
]

# Source page file -> the site path it becomes. Every in-document link is
# rewritten through this, so nothing in the kit still points at a .html file.
LINKS = {
    "index.html": "/",
    "home.html": "/",
    "why-magna.html": "/why-magna",
    "features.html": "/features",
    "page-builder.html": "/page-builder",
    "developers.html": "/for-developers",
    "agencies.html": "/agencies",
    "use-cases.html": "/use-cases",
    "plugins.html": "/plugins",
    "about.html": "/about",
    "compare.html": "/compare",
}

DOC_URL = "https://github.com/Magna-CMS/Magna/"

# The band classes are the SOURCE's own section classes, kept verbatim.
# The theme's stylesheet is the source stylesheet with its selectors pointed
# at the renderer's markup, so a document that names the same bands gets the
# same design rather than an interpretation of it.
BANDS = {
    "hero": "hero",
    "problem": "problem",
    "why": "why",
    "arch": "arch",
    "headless": "headless",
    "cases": "cases",
    "plugins": "plugins",
    "builder": "builder",
    "audiences": "audiences",
    "faq": "faq",
    "cta": "cta",
}

_ids = {"n": 0}


def nid(prefix: str) -> str:
    """A stable, readable node id.

    Deliberately not a ULID: a kit that is re-generated must produce the
    same ids, or every sync would orphan the previous document's nodes and
    a page's revision history would show a total rewrite each time.
    """
    _ids["n"] += 1
    return f"nova-{prefix}-{_ids['n']:04d}"


# --------------------------------------------------------------------------
# text helpers
# --------------------------------------------------------------------------

def txt(el) -> str:
    """Collapsed plain text of an element."""
    if el is None:
        return ""
    return re.sub(r"\s+", " ", el.get_text(" ", strip=True)).strip()


def rewrite_href(href: str | None) -> str:
    if not href:
        return "#"
    href = href.strip()
    if href in LINKS:
        return LINKS[href]
    if "#" in href and href.split("#", 1)[0] in LINKS:
        base, frag = href.split("#", 1)
        target = LINKS[base]
        return ("" if target == "/" else target) + "#" + frag
    return href


def link_url(a: Tag) -> str:
    if a.has_attr("data-doc-link"):
        return DOC_URL
    return rewrite_href(a.get("href"))


def inline_html(el: Tag) -> str:
    """Inline markup of an element, limited to what the richtext sanitizer keeps.

    Only <em>, <strong>, <br> and <a href> survive; anything else (the
    decorative <svg> the source puts inside links and headings) is dropped
    and its text kept.
    """
    out = []
    for node in el.children:
        if isinstance(node, NavigableString):
            out.append(html.escape(str(node)))
        elif isinstance(node, Tag):
            if node.name in ("em", "strong", "b", "i"):
                tag = "em" if node.name in ("em", "i") else "strong"
                out.append(f"<{tag}>{inline_html(node)}</{tag}>")
            elif node.name == "br":
                out.append("<br>")
            elif node.name == "a":
                out.append(f'<a href="{html.escape(link_url(node))}">{inline_html(node)}</a>')
            elif node.name == "svg":
                continue
            else:
                out.append(inline_html(node))
    return re.sub(r"\s+", " ", "".join(out)).strip()


def has_accent(el: Tag) -> bool:
    return el.find("em") is not None or el.find("br") is not None


# --------------------------------------------------------------------------
# block constructors
# --------------------------------------------------------------------------

def block(handle: str, data, settings=None) -> dict:
    node = {"id": nid(handle), "block": handle, "settings": settings or {}, "data": data}
    return node


def heading(text: str, level: str = "h2", align: str = "left") -> dict:
    return block("heading", {"level": level, "text": text, "align": align})


def prose(body: str) -> dict:
    return block("text", {"body": body})


def paragraphs(els) -> dict | None:
    parts = [f"<p>{inline_html(p)}</p>" for p in els if txt(p)]
    return prose("".join(parts)) if parts else None


def headline(el: Tag, level: str = "h2") -> dict:
    """A section headline.

    The design sets half the headline in the accent colour with <em>. A
    heading block's text field is a plain string and is escaped, so an
    accented headline is a richtext block instead — still fully editable in
    the builder, and the accent survives.
    """
    if has_accent(el):
        return prose(f"<{level}>{inline_html(el)}</{level}>")
    return heading(txt(el), level)


def button(a: Tag, style: str = "primary", size: str = "md") -> dict:
    return block("button", {
        "label": txt(a),
        "url": link_url(a),
        "style": style,
        "size": size,
        "target": "_blank" if a.get("target") == "_blank" else "_self",
    })


BTN_STYLE = {
    "btn-primary": "primary",
    "btn-secondary": "secondary",
    "btn-dark": "secondary",
    "btn-ghost": "ghost",
}


def button_from(a: Tag) -> dict:
    classes = a.get("class") or []
    for key, style in BTN_STYLE.items():
        if key in classes:
            return button(a, style)
    return button(a, "outline")


def features(items: list[dict], layout: str = "icon-grid") -> dict:
    """A features block.

    `items` is a `json` field, so it is stored as a JSON string — that is
    what the core view decodes and what the builder's inspector edits. The
    Nova view reads two extra optional keys (`status`, `tag`) that the core
    view simply ignores.
    """
    return block("features", {
        "items": json.dumps(items, ensure_ascii=False),
        "layout": layout,
    })


def quote(text: str) -> dict:
    return block("quote", {"quote": text, "attribution": "", "source": ""})


def section(css_class: str, columns: list[list[dict]], anchor: str = "", spans=None) -> dict:
    """A document section: one row of columns whose spans sum to 12."""
    if spans is None:
        spans = [12] if len(columns) == 1 else [12 // len(columns)] * len(columns)
    settings: dict = {"cssClass": css_class}
    if anchor:
        settings["anchor"] = anchor
    return {
        "id": nid("sec"),
        "type": "section",
        "settings": settings,
        "columns": [
            {"id": nid("col"), "span": span, "settings": {}, "blocks": blocks}
            for span, blocks in zip(spans, columns)
        ],
    }


def container(children: list[dict], style: dict | None = None, tag: str = "div") -> dict:
    node = block("container", {"tag": tag}, {"style": style} if style else {})
    node["children"] = children
    return node


# The source's .aud-card, as builder style settings so an editor can change
# it. Values that carry a function (rgba) cannot ride a style setting — the
# renderer's CSS sanitizer allows var(--token) and nothing else — so the two
# translucent surfaces are tokens the theme defines.
CARD_STYLE = {
    "background": "var(--bg-white)",
    "borderWidth": "1px",
    "borderStyle": "solid",
    "borderColor": "var(--border)",
    "borderRadius": "var(--radius-lg)",
    "paddingTop": "42px",
    "paddingRight": "38px",
    "paddingBottom": "42px",
    "paddingLeft": "38px",
    "direction": "column",
    "childGap": "0px",
}


# --------------------------------------------------------------------------
# fragment converters
# --------------------------------------------------------------------------

def section_head(el: Tag) -> list[dict]:
    out: list[dict] = []
    align = "center" if "center" in (el.get("class") or []) else "left"
    badge = el.find(class_="badge")
    if badge:
        out.append(heading(txt(badge), "h6", align))
    for child in el.find_all(["h1", "h2", "h3"], recursive=False):
        out.append(headline(child, child.name))
    intro = el.find_all("p", recursive=False)
    para = paragraphs(intro)
    if para:
        out.append(para)
    return out


def card_items(grid: Tag, selector: str) -> list[dict]:
    items = []
    for card in grid.select(selector):
        icon_holder = card.find(class_=re.compile(r"(why|shift|path)-icon"))
        item = {
            "icon": name_for(icon_holder.find("svg")) if icon_holder else "",
            "title": txt(card.find(["h3", "h4"])),
            "description": txt(card.find("p")),
        }
        tag = card.find(class_="path-tag")
        if tag:
            item["title"] = item["title"].replace(txt(tag), "").strip()
            item["tag"] = txt(tag)
        items.append(item)
    return items


def chip_items(grid: Tag) -> list[dict]:
    return [
        {"icon": "", "title": txt(chip.find(["h3", "h4"])), "description": txt(chip.find("p"))}
        for chip in grid.select(".case-chip")
    ]


STATUS = ("avail", "dev", "plan")


def row_items(grid: Tag) -> list[dict]:
    items = []
    for row in grid.select(".feat-row"):
        status_el = row.find(class_="status")
        tone = ""
        if status_el:
            for key in STATUS:
                if key in (status_el.get("class") or []):
                    tone = key
        items.append({
            "icon": "",
            "title": txt(row.find(["h3", "h4"])),
            "description": txt(row.find("p")),
            # The label is the source's own words ("Alpha 1.0.0" is not
            # "In development"); the tone is what the theme colours by.
            "status": txt(status_el) if status_el else "",
            "tone": tone,
        })
    return items


def step_items(grid: Tag) -> list[dict]:
    return [
        {
            "icon": txt(step.find(class_="plugin-num")),
            "title": txt(step.find(["h3", "h4"])),
            "description": txt(step.find("p")),
        }
        for step in grid.select(".plugin-step")
    ]


def layer_items(grid: Tag) -> list[dict]:
    items = []
    for layer in grid.select(".arch-layer"):
        tag = layer.find(class_="tag")
        label = txt(tag) if tag else ""
        title = txt(layer)
        if label:
            title = title[: len(title) - len(label)].strip()
        items.append({"icon": "", "title": title, "description": "", "tag": label})
    return items


def check_items(ul: Tag) -> list[dict]:
    return [{"icon": "check", "title": txt(li), "description": ""} for li in ul.find_all("li")]


def aud_card(card: Tag) -> dict:
    children: list[dict] = []
    kicker = card.find(class_="kicker")
    if kicker:
        children.append(heading(txt(kicker), "h6"))
    title = card.find(["h3", "h4"])
    if title:
        children.append(heading(txt(title), "h3"))
    para = paragraphs(card.find_all("p", recursive=False))
    if para:
        children.append(para)
    ul = card.find("ul")
    if ul:
        children.append(features(check_items(ul), "horizontal-list"))
    return container(children, CARD_STYLE)


def card_grid(cards: list[Tag]) -> dict:
    return container(
        [aud_card(card) for card in cards],
        {"direction": "row", "wrap": "wrap", "childGap": "22px"},
    )


def terminal_block(term: Tag) -> dict:
    bar = term.find(class_="term-bar")
    caption = txt(bar.find("span")) if bar and bar.find("span") else ""
    body = term.find(class_="term-body")
    text = body.get_text("", strip=False)
    text = html.unescape(text.replace("\u00a0", " "))
    lines = [line.rstrip() for line in text.splitlines()]
    while lines and not lines[0].strip():
        lines.pop(0)
    while lines and not lines[-1].strip():
        lines.pop()
    code = "\n".join(lines)
    language = "json" if "{" in code else "bash"
    return block("code", {"code": code, "language": language, "filename": caption})


def table_block(table: Tag, caption: str = "") -> dict:
    rows = []
    for tr in table.find_all("tr"):
        cells = [txt(td) for td in tr.find_all(["th", "td"])]
        rows.append(" | ".join(cells))
    return block("table", {
        "rows": "\n".join(rows),
        "separator": "pipe",
        "header": "header" if table.find("thead") else "data",
        "caption": caption,
    })


def faq_block(wrap: Tag) -> dict:
    items = []
    for item in wrap.select(".faq-item"):
        question = item.find(class_="faq-q")
        answer = item.find(class_="faq-a")
        q = txt(question)
        icon = question.find(class_="faq-icon") if question else None
        if icon:
            q = q.replace(txt(icon), "").strip()
        items.append({"question": q, "answer": txt(answer)})
    # `items` is a repeater field: a real array, not a JSON string.
    return block("faq", {"items": items})


# --------------------------------------------------------------------------
# section converters
# --------------------------------------------------------------------------

def convert_hero(sec: Tag) -> dict:
    short = "short" in (sec.get("class") or [])
    css = "hero short" if short else "hero"

    content = sec.find(class_="hero-content")
    blocks: list[dict] = []
    badge = content.find(class_="badge")
    if badge:
        blocks.append(heading(txt(badge), "h6"))

    h1 = content.find("h1")
    sub = content.find("p", recursive=False)

    # The headline is a richtext block rather than a hero block's escaped
    # text field, because half of it is set in the accent colour with <em>
    # and a plain string cannot carry that. Every piece stays separately
    # editable in the builder, and the markup is theme-portable — a site
    # that switches away from Nova still gets a headline, a paragraph and
    # two buttons rather than a marker syntax nothing else understands.
    blocks.append(headline(h1, "h1"))
    if txt(sub):
        blocks.append(prose(f"<p>{inline_html(sub)}</p>"))

    ctas = content.find(class_="hero-btns")
    if ctas:
        for a in ctas.find_all("a"):
            blocks.append(button_from(a))

    hero_sub = content.find(class_="hero-sub")
    if hero_sub:
        items = [{"icon": "check", "title": txt(span), "description": ""}
                 for span in hero_sub.find_all("span", recursive=False)]
        blocks.append(features(items, "horizontal-list"))

    card = sec.find(class_="hero-card")
    if card is None:
        return section(css, [blocks])

    card_children: list[dict] = []
    kicker = card.find(class_="kicker")
    if kicker:
        card_children.append(heading(txt(kicker), "h6"))
    title = card.find(["h3", "h4"])
    if title:
        card_children.append(heading(txt(title), "h3"))
    ul = card.find("ul")
    if ul:
        card_children.append(features(check_items(ul), "horizontal-list"))
    style = dict(CARD_STYLE)
    style["background"] = "var(--card-invert)"
    style["borderColor"] = "var(--border-invert)"
    style["paddingTop"] = "38px"
    style["paddingRight"] = "38px"
    style["paddingBottom"] = "38px"
    style["paddingLeft"] = "38px"
    return section(css, [blocks, [container(card_children, style)]], spans=[7, 5])


def convert_trust(div: Tag) -> dict:
    label = div.find(class_="trust-label")
    terms = div.select(".marquee-track > span")
    seen: list[str] = []
    for term in terms:
        value = txt(term)
        if value and value not in seen:
            seen.append(value)
    blocks: list[dict] = []
    if label:
        blocks.append(heading(txt(label), "h6", "center"))
    # The ribbon scrolls by translating itself half a width, so the list is
    # written out twice — exactly as the original marked it up.
    terms = seen + seen
    blocks.append(features([{"icon": "", "title": t, "description": ""} for t in terms], "horizontal-list"))
    return section("trust marquee", [blocks])


def convert_cta(sec: Tag) -> dict:
    inner = sec.find(class_="cta-inner") or sec.find(class_="container")
    blocks: list[dict] = []
    badge = inner.find(class_="badge")
    if badge:
        blocks.append(heading(txt(badge), "h6", "center"))

    h2 = inner.find("h2")
    if h2 is not None:
        blocks.append(headline(h2, "h2"))

    body = inner.find("p", recursive=False)
    if txt(body):
        blocks.append(prose(f"<p>{inline_html(body)}</p>"))

    btns = inner.find(class_="cta-btns")
    for a in (btns.find_all("a") if btns else []):
        blocks.append(button_from(a))

    tertiary = inner.find(class_="cta-tertiary")
    if tertiary:
        node = button(tertiary, "ghost", "sm")
        # The source left this one as "#". Its label names the page it
        # obviously means, and that page exists, so it gets the link rather
        # than shipping the only dead link on the site.
        if node["data"]["url"] == "#" and "compare" in node["data"]["label"].lower():
            node["data"]["url"] = "/compare"
        blocks.append(node)

    return section("cta", [blocks], anchor=sec.get("id") or "")


def convert_children(nodes: list[Tag]) -> list[tuple[list[dict], str]]:
    """Convert a section's container children into (blocks, extra-class) pairs."""
    out: list[tuple[list[dict], str]] = []
    for el in nodes:
        classes = el.get("class") or []
        blocks: list[dict] = []
        extra = ""

        if "section-head" in classes:
            blocks = section_head(el)
            if "center" in classes:
                extra = "center"
        elif "prose" in classes:
            blocks = convert_prose(el)
            extra = "prose"  # the original's long-form column treatment
        elif "why-grid" in classes:
            blocks = [features(card_items(el, ".why-card"))]
            extra = "grid-2" if "grid-2" in classes else ""
        elif "case-grid" in classes:
            blocks = [features(chip_items(el))]
            extra = "grid-2" if "grid-2" in classes else ""
        elif "feat-rows" in classes:
            blocks = [features(row_items(el), "horizontal-list")]
            extra = "feat-rows"
        elif "plugin-grid" in classes:
            blocks = [features(step_items(el))]
            extra = ""
        elif "aud-grid" in classes:
            blocks = [card_grid(el.select(".aud-card"))]
            extra = "aud-grid"
        elif "cmp-wrap" in classes:
            blocks = [table_block(el.find("table"))]
            extra = "cmp-wrap"
        elif "faq-wrap" in classes:
            blocks = [faq_block(el)]
        elif "cases-foot" in classes:
            # A closing line and its button sit on ONE row in the original,
            # so they go in a container laid out as a row — which is what a
            # container block is for, and leaves both editable.
            inner: list[dict] = []
            para = paragraphs(el.find_all("p", recursive=False))
            if para:
                inner.append(para)
            for a in el.find_all("a"):
                inner.append(button_from(a))
            if inner:
                blocks.append(container(inner, {
                    "direction": "row",
                    "wrap": "wrap",
                    "alignItems": "center",
                    "justifyContent": "space-between",
                    "childGap": "24px",
                }))
            extra = "cases-foot"
        elif "shift-list" in classes:
            blocks = [features(card_items(el, ".shift-item"), "horizontal-list")]
        elif "path-cards" in classes:
            blocks = [features(card_items(el, ".path-card"), "horizontal-list")]
        elif "terminal" in classes:
            blocks = [terminal_block(el)]
        elif "builder-mock" in classes or "plugin-line" in classes:
            continue  # pure decoration; the theme draws its own
        elif el.name in ("h2", "h3", "p", "a", "ul"):
            blocks = convert_loose([el])
        else:
            blocks = convert_loose(list(el.children))

        if blocks:
            out.append((blocks, extra))
    return out


def convert_prose(el: Tag) -> list[dict]:
    """A prose column: paragraphs, sub-headings and pull quotes, in order."""
    blocks: list[dict] = []
    buffer: list[str] = []

    def flush():
        if buffer:
            blocks.append(prose("".join(buffer)))
            buffer.clear()

    for child in el.children:
        if isinstance(child, NavigableString):
            continue
        classes = child.get("class") or []
        if "problem-quote" in classes or "arch-quote" in classes:
            flush()
            blocks.append(quote(txt(child)))
        elif child.name == "p":
            buffer.append(f"<p>{inline_html(child)}</p>")
        elif child.name in ("h2", "h3", "h4"):
            buffer.append(f"<{child.name}>{inline_html(child)}</{child.name}>")
        elif child.name == "ul":
            items = "".join(f"<li>{inline_html(li)}</li>" for li in child.find_all("li"))
            buffer.append(f"<ul>{items}</ul>")
        elif child.name == "a":
            flush()
            blocks.append(button_from(child))
    flush()
    return blocks


def convert_loose(nodes) -> list[dict]:
    """A column of mixed children that has no wrapper class of its own."""
    blocks: list[dict] = []
    buffer: list[str] = []

    def flush():
        if buffer:
            blocks.append(prose("".join(buffer)))
            buffer.clear()

    for child in nodes:
        if isinstance(child, NavigableString):
            continue
        classes = child.get("class") or []
        if "badge" in classes:
            flush()
            blocks.append(heading(txt(child), "h6"))
        elif "section-head" in classes:
            flush()
            blocks.extend(section_head(child))
        elif "builder-points" in classes:
            flush()
            blocks.append(features(check_items(child), "horizontal-list"))
        elif "path-cards" in classes:
            flush()
            blocks.append(features(card_items(child, ".path-card"), "horizontal-list"))
        elif "arch-layers" in classes or "arch-panel" in classes:
            flush()
            grid = child if "arch-layers" in classes else child.find(class_="arch-layers")
            if grid:
                blocks.append(features(layer_items(grid), "icon-grid"))
        elif "arch-quote" in classes or "problem-quote" in classes:
            flush()
            blocks.append(quote(txt(child)))
        elif "terminal" in classes:
            flush()
            blocks.append(terminal_block(child))
        elif "arch-float" in classes or "bm-" in " ".join(classes):
            continue
        elif child.name in ("h1", "h2", "h3", "h4"):
            flush()
            blocks.append(headline(child, child.name if child.name != "h1" else "h2"))
        elif child.name == "p":
            buffer.append(f"<p>{inline_html(child)}</p>")
        elif child.name == "ul":
            items = "".join(f"<li>{inline_html(li)}</li>" for li in child.find_all("li"))
            buffer.append(f"<ul>{items}</ul>")
        elif child.name == "a":
            flush()
            blocks.append(button_from(child))
        elif child.name == "table":
            flush()
            blocks.append(table_block(child))
        elif isinstance(child, Tag):
            nested = convert_loose(list(child.children))
            if nested:
                flush()
                blocks.extend(nested)
    flush()
    return blocks


SPLIT_GRIDS = ("problem-grid", "arch-grid", "headless-grid", "builder-grid", "hero-grid")
DECORATION = {"plugin-line", "arch-float", "builder-mock", "bm-bar", "bm-body"}


def convert_column(el: Tag) -> tuple[list[dict], str]:
    """One side of a two-column grid.

    The element's OWN classes are consulted first — a column may itself be
    the pattern (`.terminal`, `.prose`, `.shift-list`) rather than a plain
    wrapper around one.
    """
    converted = convert_children([el])
    if converted:
        blocks = [b for group, _ in converted for b in group]
        extra = " ".join(dict.fromkeys(x for _, x in converted if x))
        return blocks, extra
    return convert_loose(list(el.children)), ""


def split_columns(grid: Tag) -> tuple[list[list[dict]], str]:
    columns: list[list[dict]] = []
    extras: list[str] = []
    for child in grid.find_all(recursive=False):
        if set(child.get("class") or []) & DECORATION:
            continue
        blocks, extra = convert_column(child)
        if blocks:
            columns.append(blocks)
            if extra:
                extras.append(extra)
    return columns, " ".join(dict.fromkeys(" ".join(extras).split()))


def convert_section(sec: Tag) -> list[dict]:
    classes = sec.get("class") or []
    kind = next((c for c in classes if c in BANDS), "problem")
    band = BANDS[kind]
    anchor = sec.get("id") or ""

    if kind == "hero":
        return [convert_hero(sec)]
    if kind == "cta":
        return [convert_cta(sec)]

    container_el = sec.find("div", class_="container")
    if container_el is None:
        return []

    # A two-column grid may BE the container (class="container arch-grid")
    # or sit inside it. Both shapes exist in the source.
    split = next((c for c in (container_el.get("class") or []) if c in SPLIT_GRIDS), None)
    sections: list[dict] = []

    if split:
        columns, extra = split_columns(container_el)
        if columns:
            # Which two-column grid a band holds is part of the design — the
            # original puts a headless-grid inside a `why` band on the
            # developers page — so the grid's own class travels with the
            # section rather than being flattened to "two columns here".
            layout = f"split {split}" if len(columns) > 1 else split
            css = " ".join(dict.fromkeys(f"{band} {layout} {extra}".split()))
            sections.append(section(css, columns, anchor=anchor))
        return sections

    lead: list[dict] = []
    lead_extra = ""

    def flush_lead(extra_css: str = "") -> None:
        nonlocal lead, lead_extra, anchor
        if not lead:
            return
        css = " ".join(dict.fromkeys(f"{band} {lead_extra} {extra_css}".split()))
        sections.append(section(css, [lead], anchor=anchor))
        lead, lead_extra, anchor = [], "", ""

    for child in container_el.find_all(recursive=False):
        child_classes = child.get("class") or []
        if set(child_classes) & DECORATION:
            continue
        inner_split = next((c for c in child_classes if c in SPLIT_GRIDS), None)
        if inner_split:
            # A section's spans must sum to 12, so a head above a two-column
            # grid cannot be a third column: the head keeps its own full-width
            # section and the grid follows in a continuation section that the
            # theme joins seamlessly to it.
            flush_lead("head-continue")
            columns, extra = split_columns(child)
            if columns:
                layout = f"split {inner_split}" if len(columns) > 1 else inner_split
                tail = "tail-continue" if sections else ""
                css = " ".join(dict.fromkeys(f"{band} {layout} {extra} {tail}".split()))
                sections.append(section(css, columns, anchor=anchor))
                anchor = ""
            continue

        for blocks, extra in convert_children([child]):
            if extra:
                lead_extra = " ".join(dict.fromkeys(f"{lead_extra} {extra}".split()))
            lead.extend(blocks)

    flush_lead()
    return sections


def convert_page(path: Path) -> list[dict]:
    soup = BeautifulSoup(path.read_text(encoding="utf-8"), "lxml")
    sections: list[dict] = []
    for node in soup.body.find_all(["section", "div"], recursive=False):
        if node.name == "section":
            sections.extend(convert_section(node))
        elif "trust" in (node.get("class") or []):
            sections.append(convert_trust(node))
    return sections


# --------------------------------------------------------------------------
# menus
# --------------------------------------------------------------------------

def menu_item(label: str, url: str, children=None) -> dict:
    item = {"label": label, "url": url, "type": "url", "target": "", "children": children or []}
    return item


MENUS = [
    {
        "handle": "primary",
        "name": "Primary",
        "tree": [
            menu_item("Why Magna", "/why-magna"),
            menu_item("Features", "/features"),
            menu_item("Page Builder", "/page-builder"),
            menu_item("Developers", "/for-developers"),
            menu_item("Agencies", "/agencies"),
            menu_item("Plugins", "/plugins"),
            menu_item("Documentation", DOC_URL),
        ],
    },
    {
        "handle": "footer_product",
        "name": "Footer — Product",
        "tree": [
            menu_item("Why Magna", "/why-magna"),
            menu_item("Features", "/features"),
            menu_item("Page Builder", "/page-builder"),
            menu_item("Plugins & Extensions", "/plugins"),
            menu_item("Use Cases", "/use-cases"),
        ],
    },
    {
        "handle": "footer_developers",
        "name": "Footer — Developers",
        "tree": [
            menu_item("Documentation", DOC_URL),
            menu_item("Developers", "/for-developers"),
            menu_item("REST API", "/for-developers#api"),
            menu_item("Build a Plugin", "/for-developers#build-plugin"),
            menu_item("Agencies", "/agencies"),
        ],
    },
    {
        "handle": "footer_company",
        "name": "Footer — Company",
        "tree": [
            menu_item("About", "/about"),
            menu_item("Compare", "/compare"),
        ],
    },
]


def footer_part() -> dict:
    """The site footer, as a template part.

    Chrome built from blocks rather than baked into the layout: the footer
    is where a site puts its own link columns, and every one of them should
    be editable in the builder like any other page. The theme's own footer
    is only the fallback for a site that has not designed one.
    """
    brand = [
        # The home page's own address, not "/": this install's root belongs
        # to the admin panel, and a page is addressable by its slug on every
        # install, so this link is correct either way.
        block("logo", {"media_id": None, "text": "magna", "height": "40px",
                       "alt": "Magna CMS", "url": "/home", "heading": "no"}),
        prose("<p>An extensible, developer-grade CMS for the web as it is today. "
              "Open source, and built to be built on.</p>"),
        prose(f'<p><a href="{DOC_URL}">GitHub</a></p>'),
    ]

    def column(title: str, handle: str) -> list[dict]:
        return [heading(title, "h6"), block("nav", {"menu": handle})]

    return {
        "status": "published",
        "title": "Footer",
        "slug": "footer",
        "locale": "",
        "kind": "part",
        "blocks_data": {
            "schemaVersion": "1.0",
            "sections": [
                section("footer-main", [
                    brand,
                    column("Product", "footer_product"),
                    column("Developers", "footer_developers"),
                    column("Company", "footer_company"),
                ], spans=[6, 2, 2, 2]),
                section("footer-bottom", [[
                    prose('<p>&copy; 2026 Magna CMS. Open source, released under the MIT licence.</p>'),
                ]]),
            ],
        },
    }


def main() -> None:
    pages = []
    for slug, filename, title in PAGES:
        blocks_data = convert_page(SOURCE / filename)
        pages.append({
            "status": "published",
            "title": title,
            "slug": slug,
            "path": slug,
            "locale": "",
            "blocks_data": {"schemaVersion": "1.0", "sections": blocks_data},
        })

    kit = {
        "format": "magna-site-kit",
        "version": 1,
        "pages": pages,
        "templates": [footer_part()],
        "menus": MENUS,
        "settings": {
            "home_page_slug": "home",
            "not_found_page_slug": None,
            "maintenance_mode": False,
            "collection_mounts": [],
        },
    }

    out = Path(sys.argv[1]) if len(sys.argv) > 1 else Path("magna-website.json")
    out.write_text(json.dumps(kit, indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"Wrote {out} — {len(pages)} pages, {sum(len(p['blocks_data']['sections']) for p in pages)} sections.")


if __name__ == "__main__":
    main()
