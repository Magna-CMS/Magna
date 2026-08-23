{{--
    Block: code — a preformatted snippet.

    The body is ESCAPED, always. Code is by definition the field an editor
    pastes angle brackets into, so rendering it raw would turn the one block
    guaranteed to contain markup into a script host. There is no highlighting
    here either: the language is published as a `language-*` class, which is
    what a highlighter (server-side or a theme's own script) attaches to.
--}}
@php
    $code = (string) ($block['data']['code'] ?? '');
    $language = (string) ($block['data']['language'] ?? 'plain');
    $filename = trim((string) ($block['data']['filename'] ?? ''));

    // The schema's own option list, so a stored value from an older schema
    // cannot put an arbitrary string into a class attribute.
    $language = in_array($language, [
        'plain', 'bash', 'css', 'html', 'javascript', 'json',
        'php', 'python', 'sql', 'typescript', 'yaml',
    ], true) ? $language : 'plain';
@endphp
@if($code !== '')
    <figure class="magna-block magna-block--code magna-code">
        @if($filename !== '')
            <figcaption class="magna-code__filename">{{ $filename }}</figcaption>
        @endif
        <pre class="magna-code__pre"><code class="language-{{ $language }}">{{ $code }}</code></pre>
    </figure>
@endif
