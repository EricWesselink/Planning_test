<div class="person-bar {{ $personBar['double'] ? 'double' : '' }}{{ ! empty($personBar['has_budget_overrun']) ? ' person-bar--over' : '' }}"
     data-shift-type="assignment"
     data-shift-id="{{ $personBar['assignment_id'] }}"
     data-worker-id="{{ $personBar['worker_id'] }}"
     data-project-id="{{ $personBar['project_id'] }}"
     data-work-item-id="{{ $personBar['work_item_id'] }}"
     data-start="{{ $personBar['bar']['start'] }}"
     data-span="{{ $personBar['bar']['span'] }}"
     data-start-offset="{{ $personBar['bar']['start_offset'] ?? 0 }}"
     data-end-offset="{{ $personBar['bar']['end_offset'] ?? 1 }}"
     data-start-date="{{ $personBar['start_date'] }}"
     data-end-date="{{ $personBar['end_date'] }}"
     data-start-time="{{ $personBar['start_time'] ?? '08:00' }}"
     data-end-time="{{ $personBar['end_time'] ?? '16:00' }}"
     data-include-saturday="{{ ! empty($personBar['include_saturday']) ? '1' : '0' }}"
     data-include-sunday="{{ ! empty($personBar['include_sunday']) ? '1' : '0' }}"
     data-people-count="{{ $personBar['people_count'] }}"
     data-planned-hours="{{ $personBar['planned_hours'] ?? 8 }}"
     data-crew-ids="{{ implode(',', $personBar['crew_ids'] ?? []) }}"
     data-ticket-label="{{ $personBar['ticket_label'] ?? 'Werkbon maken' }}"
     title="{{ $personBar['title'] }}"
     style="background: {{ $personBar['color'] }}; top: {{ ($personBarOffset ?? 4) + ($index * 24) }}px; width: calc(({{ $personBar['bar']['span'] }} - {{ $personBar['bar']['start_offset'] ?? 0 }} - (1 - {{ $personBar['bar']['end_offset'] ?? 1 }})) * 100% / {{ $dayCount }} - 2px); left: calc(({{ $personBar['bar']['start'] }} + {{ $personBar['bar']['start_offset'] ?? 0 }}) * 100% / {{ $dayCount }} + 1px);">
    @if (! empty($personBar['has_budget_overrun']))
        <span class="person-bar-overrun" style="left: {{ $personBar['overrun_from'] ?? '100%' }}" aria-hidden="true"></span>
    @endif
    <span class="bar-handle bar-handle-start" data-edge="start"></span>
    <span class="bar-label">{{ $personBar['label'] }}</span>
    <span class="bar-handle bar-handle-end" data-edge="end"></span>
</div>
