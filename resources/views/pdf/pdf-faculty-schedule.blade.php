@php
    $termLabel = trim(($term->schoolYear?->name ?? '').' · '.($term->semester?->name ?? ''));

    // `days` is stored as a comma-separated string ("Mon,Tue"), not an
    // array — counting it with is_array() always fell through to 1,
    // and Carbon's diffInMinutes() returns a *signed* diff in Carbon 3
    // (the Laravel 12 default), so start/end could come out negative
    // depending on argument order. Both together produced the "-8.0"
    // total seen before this fix.
    $totalHours = 0;
    foreach ($rows as $row) {
        if (!empty($row['start_time']) && !empty($row['end_time'])) {
            $start = \Carbon\Carbon::parse($row['start_time']);
            $end = \Carbon\Carbon::parse($row['end_time']);
            $dayCount = count(array_filter(explode(',', (string) ($row['days'] ?? ''))));
            $totalHours += abs($end->diffInMinutes($start)) / 60 * max($dayCount, 1);
        }
    }
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; margin: 0; padding: 28px 34px; }

        .classly-brand { margin-bottom: 6px; }
        .classly-brand span { font-size: 11px; font-weight: 800; letter-spacing: 0.08em; color: #2563eb; }

        .letterhead { width: 100%; border-bottom: 2px solid #1e293b; padding-bottom: 12px; margin-bottom: 16px; }
        .letterhead img { height: 56px; width: 56px; }
        .letterhead h1 { margin: 0; font-size: 17px; font-weight: 800; letter-spacing: 0.02em; color: #1e293b; }
        .letterhead p { margin: 2px 0 0; font-size: 11px; color: #64748b; }

        table.meta { width: 100%; margin: 0 0 16px; }
        table.meta td { padding: 3px 0; font-size: 12px; }
        table.meta .label { color: #64748b; width: 140px; font-weight: 600; }

        table.schedule { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.schedule thead th {
            background: #1e293b; color: #fff; text-align: left;
            font-size: 10px; text-transform: uppercase; letter-spacing: 0.03em;
            padding: 7px 8px;
        }
        table.schedule tbody td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; font-size: 10.5px; }
        table.schedule tbody tr:nth-child(even) { background: #f8fafc; }

        .totals { margin-top: 14px; font-size: 11.5px; text-align: right; font-weight: 700; color: #1e293b; }

        .footer { margin-top: 24px; font-size: 9px; color: #94a3b8; text-align: right; }
    </style>
</head>
<body>
    <div class="classly-brand"><span>CLASSLY</span></div>

    <table class="letterhead">
        <tr>
            <td style="width:64px;">
                @if($schoolLogoDataUri)
                    <img src="{{ $schoolLogoDataUri }}" alt="School Logo">
                @endif
            </td>
            <td>
                <h1>{{ $schoolName }}</h1>
                <p>Faculty Teaching Schedule</p>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr><td class="label">Faculty</td><td>{{ $faculty->full_name }}</td></tr>
        <tr><td class="label">Faculty ID</td><td>{{ $faculty->faculty_id }}</td></tr>
        <tr><td class="label">College</td><td>{{ $faculty->college?->name ?? '—' }}</td></tr>
        <tr><td class="label">Academic Term</td><td>{{ $termLabel }}</td></tr>
    </table>

    <table class="schedule">
        <thead>
            <tr>
                <th>Subject Code</th>
                <th>Subject Title</th>
                <th>Section</th>
                <th>Room</th>
                <th>Day</th>
                <th>Start</th>
                <th>End</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row['subject_code'] ?? '—' }}</td>
                    <td>{{ $row['subject_title'] ?? '—' }}</td>
                    <td>{{ $row['section'] ?? '—' }}</td>
                    <td>{{ $row['room'] ?? '—' }}</td>
                    <td>{{ $row['days'] ?? '—' }}</td>
                    <td>{{ !empty($row['start_time']) ? \Carbon\Carbon::parse($row['start_time'])->format('g:i A') : '—' }}</td>
                    <td>{{ !empty($row['end_time']) ? \Carbon\Carbon::parse($row['end_time'])->format('g:i A') : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7">No schedule rows found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="totals">Total Teaching Hours: {{ number_format($totalHours, 1) }}</div>

    <div class="footer">Generated: {{ now()->format('F j, Y g:i A') }}</div>
</body>
</html>