"""Signature -> sprite icon name map for the Nova theme.

Keys are the normalised inner markup of the source SVGs (attributes sorted by
BeautifulSoup's own serialisation), values are the sprite symbol names the
theme layout defines.
"""

ICONS = {
    '<path d="M16 18l6-6-6-6M8 6l-6 6 6 6"></path>': 'code',
    '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>': 'shield',
    '<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path>': 'bolt',
    '<rect height="7" rx="1" width="7" x="3" y="3"></rect><rect height="7" rx="1" width="7" x="14" y="3"></rect><rect height="7" rx="1" width="7" x="3" y="14"></rect><rect height="7" rx="1" width="7" x="14" y="14"></rect>': 'modules',
    '<circle cx="12" cy="12" r="10"></circle><path d="M8 12l3 3 5-6"></path>': 'check-circle',
    '<path d="M12 20h9M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>': 'pencil',
    '<rect height="14" rx="2" width="20" x="2" y="3"></rect><path d="M8 21h8M12 17v4"></path>': 'screen',
    '<path d="M4 17l6-6-6-6M12 19h8"></path>': 'terminal',
    '<path d="M4 7V4h16v3M9 20h6M12 4v16"></path>': 'type',
    '<rect height="7" rx="1" width="18" x="3" y="3"></rect><rect height="7" rx="1" width="8" x="3" y="14"></rect><rect height="7" rx="1" width="6" x="15" y="14"></rect>': 'layout',
    '<path d="M12 3l9 5-9 5-9-5 9-5zM3 13l9 5 9-5"></path>': 'layers',
    '<circle cx="12" cy="12" r="9"></circle><circle cx="12" cy="12" r="3"></circle>': 'target',
    '<circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path>': 'clock',
    '<path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"></path>': 'building',
    '<path d="M3 6h18M3 12h18M3 18h18"></path>': 'list',
    '<rect height="16" rx="2" width="13" x="2" y="4"></rect><rect height="12" rx="1.5" width="5" x="17" y="8"></rect>': 'devices',
    '<path d="M3 3v18h18M18 17l-3-3-4 4-5-5"></path>': 'chart',
    '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"></path>': 'users',
    '<path d="M20 6L9 17l-5-5"></path>': 'check',
}


def name_for(svg):
    """Sprite name for a BeautifulSoup <svg> tag, or 'sparkle' when unknown."""
    if svg is None:
        return ''
    inner = ''.join(str(c) for c in svg.contents).strip()
    inner = ' '.join(inner.split())
    for key, value in ICONS.items():
        if ' '.join(key.split()) == inner:
            return value
    # A cog with a long path — matched loosely so a whitespace difference in
    # the source cannot turn it into the unknown-icon fallback.
    if 'M19.4 15a1.65' in inner:
        return 'cog'
    return 'sparkle'
