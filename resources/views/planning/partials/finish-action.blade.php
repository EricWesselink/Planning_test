<form
    method="POST"
    action="{{ $finished ? route('planning.reactivate', $projectId) : route('planning.finish', $projectId) }}"
    class="plan-finish-form"
    @unless ($finished)
        onsubmit="if (!confirm({{ json_encode($projectName.' verdwijnt uit Actief. Planning, werkzaamheden, uren en inzet blijven bewaard. Je vindt het terug onder Afgerond.') }})) { return false; } window.niconRememberPlanningScroll && window.niconRememberPlanningScroll();"
    @else
        onsubmit="window.niconRememberPlanningScroll && window.niconRememberPlanningScroll();"
    @endunless
>
    @csrf
    <button type="submit" class="plan-finish-btn">{{ $finished ? 'Weer actief zetten' : 'Afronden' }}</button>
</form>
