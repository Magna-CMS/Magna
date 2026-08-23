{{--
    Block: callout — a note set apart from the body text.

    The tone is validated against the schema's own list before it reaches a
    class attribute: a stored value from an older schema, or one hand-edited
    into a document, must not be able to write arbitrary text there.

    role="note" rather than role="alert": an alert interrupts a screen reader
    immediately, which is right for something that just happened and wrong
    for a paragraph that was always on the page.
--}}
@php
    $body = trim((string) ($block['data']['body'] ?? ''));
    $heading = trim((string) ($block['data']['heading'] ?? ''));
    $tone = (string) ($block['data']['tone'] ?? 'info');
    $tone = in_array($tone, ['info', 'success', 'warning', 'danger'], true) ? $tone : 'info';
@endphp
@if($body !== '')
    <aside class="magna-block magna-block--callout magna-callout magna-callout--{{ $tone }}" role="note">
        @if($heading !== '')
            <p class="magna-callout__heading">{{ $heading }}</p>
        @endif
        <div class="magna-callout__body">{{ $body }}</div>
    </aside>
@endif
