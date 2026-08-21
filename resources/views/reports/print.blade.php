<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $report['title'] ?? 'Report' }} — {{ $schoolName }}</title>
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
            color: #1e293b;
            margin: 0;
            padding: 32px 40px;
            font-size: 12px;
        }

        /* ---- Letterhead ---- */
        .letterhead {
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 2px solid #1e293b;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }

        .letterhead img {
            height: 56px;
            width: 56px;
            object-fit: contain;
            flex-shrink: 0;
        }

        .letterhead h1 {
            margin: 0;
            font-size: 17px;
            font-weight: 800;
            letter-spacing: 0.02em;
            color: #1e293b;
        }

        .letterhead p {
            margin: 2px 0 0;
            font-size: 11px;
            color: #64748b;
        }

        /* ---- Report meta (Academic Year / Semester / Section) ---- */
        .meta {
            margin-bottom: 18px;
        }

        .meta p {
            margin: 2px 0;
            font-size: 12.5px;
        }

        .meta p strong {
            display: inline-block;
            min-width: 110px;
            font-weight: 600;
            color: #334155;
        }

        /* ---- Table ---- */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead th {
            background: #1e293b;
            color: #ffffff;
            text-align: left;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            padding: 7px 8px;
        }

        tbody td {
            padding: 6px 8px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 11.5px;
            vertical-align: top;
        }

        tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        .empty {
            text-align: center;
            padding: 24px 0;
            color: #94a3b8;
            font-style: italic;
        }

        .footer {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #94a3b8;
        }

        @media print {
            body { padding: 0 24px; }
            @page { margin: 18mm 14mm; }
        }
    </style>
</head>
<body>

    <div class="letterhead">
        <img src="{{ $schoolLogoUrl ?: asset('logo.png') }}" alt="School Logo">
        <div>
            <h1>{{ $schoolName }}</h1>
            <p>{{ $report['title'] ?? 'Report' }}</p>
        </div>
    </div>

    <div class="meta">
        @if($academicYear)
            <p><strong>Academic Year:</strong> {{ $academicYear }}</p>
        @endif
        @if($semester)
            <p><strong>Semester:</strong> {{ $semester }}</p>
        @endif
        @if($sectionLabel)
            <p><strong>Section:</strong> {{ $sectionLabel }}</p>
        @endif
    </div>

    @if(! $report || empty($report['rows']) || count($report['rows']) === 0)

        <p class="empty">{{ $report['empty_message'] ?? 'No data found for the selected filters.' }}</p>

    @elseif($reportType === 'schedule_by_section')

        {{-- Purpose-built layout matching the school's paper schedule
             format: EDP Code / Subject Code / Subject / Faculty / Room /
             Day & Time — Day+Start+End are merged into one "Day/Time"
             column here since the Section is already named once in the
             header above rather than repeated per row. --}}
        <table>
            <thead>
                <tr>
                    <th>EDP Code</th>
                    <th>Subject Code</th>
                    <th>Subject</th>
                    <th>Faculty</th>
                    <th>Room</th>
                    <th>Day / Time</th>
                </tr>
            </thead>
            <tbody>
                @foreach($report['rows'] as $row)
                    <tr>
                        <td>{{ $row['EDP Code'] ?? '—' }}</td>
                        <td>{{ $row['Subject Code'] ?? '—' }}</td>
                        <td>{{ $row['Subject'] ?? '—' }}</td>
                        <td>{{ $row['Faculty'] ?? '—' }}</td>
                        <td>{{ $row['Room'] ?? '—' }}</td>
                        <td>
                            @if(!empty($row['Day']) && !empty($row['Start']) && !empty($row['End']))
                                {{ $row['Day'] }} · {{ $row['Start'] }}–{{ $row['End'] }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

    @else

        {{-- Every other report type: generic column dump, still under
             the same branded letterhead/meta block above. --}}
        <table>
            <thead>
                <tr>
                    @foreach($report['columns'] as $column)
                        <th>{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($report['rows'] as $row)
                    <tr>
                        @foreach($report['columns'] as $column)
                            <td>{{ $row[$column] ?? '—' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>

    @endif

    <div class="footer">
        <span>Classly — Scheduling System</span>
        <span>Generated: {{ $generatedAt->format('F j, Y g:i A') }}</span>
    </div>

    <script>
        // Auto-open the print dialog once the page (and logo image) has
        // painted — this page exists ONLY to be printed/saved as PDF, so
        // there's no reason to make the person click a second button.
        window.addEventListener('load', () => window.print());
    </script>

</body>
</html>