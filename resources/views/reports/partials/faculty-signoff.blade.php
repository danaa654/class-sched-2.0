{{--
    "Confirmed by" (the faculty member) / "Noted by" (Dean or OIC of
    every College this faculty member has a subject under) / "Approved
    by" (every Administrator/Registrar — institution-wide, not
    College-scoped). Included once per faculty — either the single
    facultyMeta case or once per group when several faculty are
    printed together — never for any other report type, since there's
    no single person to attribute "Confirmed by" to on e.g. Master
    Schedule or Schedule by Room.

    Expects:
      $facultyName — string
      $deans       — list<array{college: string, name: string, role: string}>
      $approvers   — list<array{name: string, role: string}>
--}}
<div class="signoff">
    <div class="signoff-col">
        <p class="signoff-label">Confirmed by</p>
        <p class="signoff-name">{{ $facultyName }}</p>
        <p class="signoff-role">Faculty</p>
    </div>
    <div class="signoff-col">
        <p class="signoff-label">Noted by</p>
        @forelse($deans as $dean)
            <div class="signoff-entry">
                <p class="signoff-name">{{ $dean['name'] }}</p>
                <p class="signoff-role">{{ $dean['role'] }}, {{ $dean['college'] }}</p>
            </div>
        @empty
            <div class="signoff-entry">
                <p class="signoff-name">&nbsp;</p>
                <p class="signoff-role">Dean</p>
            </div>
        @endforelse
    </div>
    <div class="signoff-col">
        <p class="signoff-label">Approved by</p>
        @forelse($approvers ?? [] as $approver)
            <div class="signoff-entry">
                <p class="signoff-name">{{ $approver['name'] }}</p>
                <p class="signoff-role">{{ $approver['role'] }}</p>
            </div>
        @empty
            <div class="signoff-entry">
                <p class="signoff-name">&nbsp;</p>
                <p class="signoff-role">Registrar</p>
            </div>
        @endforelse
    </div>
</div>