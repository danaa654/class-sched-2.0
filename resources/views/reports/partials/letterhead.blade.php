{{-- Reusable print letterhead: a small "<Report Title> - CLASSLY" text
     credit line (the system that generated the document), followed by
     the school's own letterhead (logo + school name) — no separate
     Classly icon, just the text credit. Included both once at the very
     top of the document and again at the top of every per-section
     block, so each printed section stands on its own if separated from
     the rest (e.g. someone Ctrl+P's just page 3). --}}
<div class="classly-brand">
    <span> CLASSLY</span>
</div>

<div class="letterhead">
    <img src="{{ $schoolLogoUrl ?: asset('logo.png') }}" alt="School Logo">
    <div>
        <h1>{{ $schoolName }}</h1>
        <p>{{ $report['title'] ?? 'Report' }}</p>
    </div>
</div>