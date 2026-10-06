{{--
    Block: table — one row per line, cells split on a chosen separator.

    Not a repeater of repeaters: BlockField refuses to nest one repeater in
    another, and rows-of-cells is exactly that shape. Delimited text is what
    fits the field model, and it happens to be the better editor experience
    for the common case anyway — a table usually comes FROM a spreadsheet,
    and tab-separated is what the clipboard already holds after copying one.

    Every cell is escaped. Ragged rows are padded rather than dropped: a row
    with a missing cell is a typo in the paste, and swallowing the whole row
    would hide it.
--}}
@php
    $raw = (string) ($block['data']['rows'] ?? '');

    $separators = ['tab' => "\t", 'pipe' => '|', 'comma' => ',', 'semicolon' => ';'];
    $separator = $separators[$block['data']['separator'] ?? 'tab'] ?? "\t";

    $hasHeader = ($block['data']['header'] ?? 'header') === 'header';
    $caption = trim((string) ($block['data']['caption'] ?? ''));

    $rows = [];
    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        // A blank line is spacing in the textarea, not an empty table row.
        if (trim($line) === '') {
            continue;
        }

        $rows[] = array_map('trim', explode($separator, $line));
    }

    $width = 0;
    foreach ($rows as $row) {
        $width = max($width, count($row));
    }

    $head = $hasHeader && $rows !== [] ? array_shift($rows) : null;

    // A table of money read down a column needs a header for the ROW too,
    // or every figure is announced without saying what it is a figure for.
    // Costs no pixels — th[scope=row] is styled back to a cell — and it is
    // the difference between a table that can be read aloud and one that
    // cannot.
    $rowHeader = ($block['data']['rowHeader'] ?? 'data') === 'header';

    // Same for a totals row: tfoot says "this is the sum", where a last
    // <tr> in the body says only "another row".
    $foot = ($block['data']['footer'] ?? 'data') === 'footer' && $rows !== []
        ? array_pop($rows)
        : null;
@endphp
@if($rows !== [] || $head !== null || $foot !== null)
    <div class="magna-block magna-block--table magna-table">
        <table class="magna-table__table">
            @if($caption !== '')
                <caption class="magna-table__caption">{{ $caption }}</caption>
            @endif

            @if($head !== null)
                <thead>
                    <tr>
                        @for($column = 0; $column < $width; $column++)
                            <th scope="col">{{ $head[$column] ?? '' }}</th>
                        @endfor
                    </tr>
                </thead>
            @endif

            <tbody>
                @foreach($rows as $row)
                    <tr>
                        @for($column = 0; $column < $width; $column++)
                            @if($rowHeader && $column === 0)
                                <th scope="row">{{ $row[$column] ?? '' }}</th>
                            @else
                                <td>{{ $row[$column] ?? '' }}</td>
                            @endif
                        @endfor
                    </tr>
                @endforeach
            </tbody>

            @if($foot !== null)
                <tfoot>
                    <tr>
                        @for($column = 0; $column < $width; $column++)
                            @if($rowHeader && $column === 0)
                                <th scope="row">{{ $foot[$column] ?? '' }}</th>
                            @else
                                <td>{{ $foot[$column] ?? '' }}</td>
                            @endif
                        @endfor
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
@endif
