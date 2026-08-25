@php
    $termLabel = trim(($term->schoolYear?->name ?? '').' — '.($term->semester?->name ?? ''));
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; color:#1f2937; background:#f4f5f7; padding:24px;">
    <table role="presentation" width="100%" style="max-width:600px; margin:0 auto; background:#ffffff; border-radius:8px; overflow:hidden;">
        <tr>
            <td style="background:#0f1b3d; padding:20px 24px;">
                <span style="color:#ffffff; font-size:20px; font-weight:bold;">CLASSLY</span>
                <div style="color:#9fb0d9; font-size:12px;">Academic Scheduling System</div>
            </td>
        </tr>
        <tr>
            <td style="padding:24px;">
                @if($isUpdate)
                    <p style="margin:0 0 12px; padding:8px 12px; background:#fff7e6; border:1px solid #ffd58a; border-radius:6px; font-size:13px;">
                        Your teaching schedule has been updated. Please review the attached revised schedule.
                    </p>
                @endif

                <p>Dear Mr./Ms. {{ $faculty->last_name }},</p>

                <p>Your finalized teaching schedule for <strong>{{ $termLabel }}</strong> is now available.</p>

                <table role="presentation" width="100%" style="font-size:14px; margin:16px 0;">
                    <tr><td style="padding:4px 0; color:#6b7280;">Faculty</td><td style="padding:4px 0;"><strong>{{ $faculty->full_name }}</strong></td></tr>
                    <tr><td style="padding:4px 0; color:#6b7280;">Faculty ID</td><td style="padding:4px 0;">{{ $faculty->faculty_id }}</td></tr>
                    <tr><td style="padding:4px 0; color:#6b7280;">College</td><td style="padding:4px 0;">{{ $faculty->college?->name ?? '—' }}</td></tr>
                    <tr><td style="padding:4px 0; color:#6b7280;">Academic Term</td><td style="padding:4px 0;">{{ $termLabel }}</td></tr>
                </table>

                <p>Please see the attached PDF for your complete teaching schedule.</p>

                <p>If there are any concerns regarding your schedule, please contact the appropriate academic office.</p>

                <p style="margin-top:24px;">Regards,<br><strong>CLASSLY</strong><br>Academic Scheduling System</p>
            </td>
        </tr>
    </table>
</body>
</html>