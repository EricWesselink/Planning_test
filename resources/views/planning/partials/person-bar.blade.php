<div class="person-bar {{ $personBar['double'] ? 'double' : '' }}{{ ! empty($personBar['has_budget_overrun']) ? ' person-bar--over' : '' }}{{ ! empty($personBar['ticket_mark']) ? ' person-bar--ticket' : '' }}{{ ! empty($personBar['is_provisional']) ? ' person-bar--provisional' : '' }}{{ ! empty($personBar['is_internal']) ? ' person-bar--internal' : '' }}"
     data-shift-type="assignment"
     data-shift-id="{{ $personBar['assignment_id'] }}"
     data-worker-id="{{ $personBar['worker_id'] }}"
     data-project-id="{{ $personBar['project_id'] }}"
     data-work-item-id="{{ $personBar['work_item_id'] }}"
     data-work-item-ids="{{ implode(',', $personBar['work_item_ids'] ?? array_filter([(int) ($personBar['work_item_id'] ?? 0)])) }}"
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
     data-provisional="{{ ! empty($personBar['is_provisional']) ? '1' : '0' }}"
     data-internal="{{ ! empty($personBar['is_internal']) ? '1' : '0' }}"
     data-business-unit="{{ $personBar['business_unit'] ?? '' }}"
     data-description="{{ $personBar['description'] ?? '' }}"
     data-notes="{{ $personBar['notes'] ?? '' }}"
     data-locked="{{ ! empty($personBar['locked']) ? '1' : '0' }}"
     data-people-count="{{ $personBar['people_count'] }}"
     data-planned-hours="{{ $personBar['planned_hours'] ?? 8 }}"
     data-crew-ids="{{ implode(',', $personBar['crew_ids'] ?? []) }}"
     data-foreman-id="{{ $personBar['foreman_id'] ?? '' }}"
     data-work-ticket-holder-id="{{ $personBar['work_ticket_holder_id'] ?? '' }}"
     data-ticket-label="{{ $personBar['ticket_label'] ?? 'Werkbon maken' }}"
     data-ticket-existing="{{ $personBar['ticket'] ?? '' }}"
     data-ticket-show-url="{{ $personBar['ticket_url'] ?? '' }}"
     title="{{ $personBar['title'] }}"
     style="background: {{ $personBar['color'] }}; top: {{ ($personBarOffset ?? 4) + ($index * 24) }}px; width: calc(({{ $personBar['bar']['span'] }} - {{ $personBar['bar']['start_offset'] ?? 0 }} - (1 - {{ $personBar['bar']['end_offset'] ?? 1 }})) * 100% / {{ $dayCount }} - 2px); left: calc(({{ $personBar['bar']['start'] }} + {{ $personBar['bar']['start_offset'] ?? 0 }}) * 100% / {{ $dayCount }} + 1px);">
    @if (! empty($personBar['has_budget_overrun']))
        <span class="person-bar-overrun" style="left: {{ $personBar['overrun_from'] ?? '100%' }}" aria-hidden="true"></span>
    @endif
    @if ($personBar['show_start_handle'] ?? true)
        <span class="bar-handle bar-handle-start" data-edge="start"></span>
    @endif
    @if (! empty($personBar['ticket_mark']))
        <span class="bar-ticket">{{ $personBar['ticket_mark'] }}</span>
    @endif
    <span class="bar-label">{{ $personBar['label'] }}</span>
    @if ($personBar['show_end_handle'] ?? true)
        <span class="bar-handle bar-handle-end" data-edge="end"></span>
    @endif
</div>
