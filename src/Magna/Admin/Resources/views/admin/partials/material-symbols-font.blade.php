{{--
    Material Symbols Rounded, served from this installation.

    The panel runs under AdminCspMiddleware, whose policy is "style-src 'self'
    'unsafe-inline'" and "font-src 'self' data:". A stylesheet link to
    fonts.googleapis.com is refused by the first, and the woff2 it points at on
    fonts.gstatic.com by the second — so every icon rendered as its own ligature
    name in plain text next to the heading it belonged to ("shield_with_house",
    "deployed_code", "warning"). The policy is right; the remote font was the
    thing that did not belong.

    The file is a subset built from the ligatures these views actually use, so
    it is 8 KB rather than the 4 MB of the full icon set. NEW ICONS ARE NOT IN
    IT: a ligature added to a view that this subset was not built with renders
    as its name again. Rebuild it after adding one, by collecting the names
    from the views — the ones inside ternaries included — and asking for a
    subset containing exactly those:

        ICONS=$(grep -rho 'msri[^>]*>[^<]*' src/Magna/Admin/Resources/views/admin/ \
          | grep -oP "(?:>\s*|')\K[a-z_]{3,}" | sort -u | paste -sd,)
        UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36'
        CSS=$(curl -s -A "$UA" \
          "https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0..1,0&icon_names=$ICONS")
        curl -s -A "$UA" \
          -o public/fonts/material-symbols/material-symbols-rounded-subset.woff2 \
          "$(echo "$CSS" | grep -oP 'url\(\K[^)]+' | head -1)"
        echo "$ICONS" | tr ',' '\n' > public/fonts/material-symbols/material-symbols-rounded-subset.txt

    The .txt beside the font lists the names it was built from, and
    tests/Feature/Admin/AdminIconFontIsSelfHostedTest.php fails when a view
    uses a ligature that list lacks — the icon that rendered as
    "system_update_alt" in plain text on a live System Info page is the
    incident that test exists to stop repeating. The user agent matters: a
    FULL browser UA, or Google serves raw TTF instead of woff2 and the
    signature check fails. The same test fails if a remote font URL returns
    to an admin view.

    Self-hosting also means the panel's icons survive an install with no route
    to the public internet, which a CMS deployed on a client's own hardware
    routinely is.
--}}
<style>
@font-face {
    font-family: 'Material Symbols Rounded';
    font-style: normal;
    font-weight: 400;
    font-display: block;
    src: url('{{ asset('fonts/material-symbols/material-symbols-rounded-subset.woff2') }}') format('woff2');
}
</style>
