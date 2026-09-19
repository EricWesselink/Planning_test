<section class="vakman-week-board" aria-label="Mijn week">
    <h2 class="vakman-week-pane-title">Mijn week</h2>
    <div class="vakman-week-board-grid">
        @foreach ($agenda['days'] as $day)
            <div class="vakman-week-col{{ $day['is_today'] ? ' is-today' : '' }}">
                <header class="vakman-week-col-head">
                    @if ($day['is_today'])
                        <span class="vakman-week-col-today">Vandaag</span>
                    @endif
                    <span class="vakman-week-col-day">{{ $day['weekday_full'] }}</span>
                    <span class="vakman-week-col-date">{{ $day['date_label'] }}</span>
                </header>
                <div class="vakman-week-col-body">
                    @forelse ($day['jobs'] as $job)
                        <article class="vakman-week-job">
                            <button
                                type="button"
                                class="vakman-week-job-select"
                                data-job-target="{{ $job['card_id'] }}"
                            >
                                <span class="vakman-week-job-title">{{ $job['project_name'] }}</span>
                                @if (($job['city'] ?? '') !== '')
                                    <span class="vakman-week-job-city">{{ $job['city'] }}</span>
                                @endif
                                <span class="vakman-week-job-time">{{ $job['time_label'] }}</span>
                                @if (($job['headline'] ?? '') !== '')
                                    <span class="vakman-week-job-work">{{ $job['headline'] }}</span>
                                @endif
                                @if (($job['colleagues'] ?? []) !== [])
                                    <span class="vakman-week-job-met">Met: {{ implode(' · ', $job['colleagues']) }}</span>
                                @endif
                            </button>
                            @if ($job['werkbon_url'] ?? null)
                                <a href="{{ $job['werkbon_url'] }}" class="vakman-week-job-bon">Werkbon</a>
                            @endif
                        </article>
                    @empty
                        @if (! empty($day['absence']))
                            <p class="vakman-week-col-empty is-away">
                                <span>{{ $day['absence']['label'] }}</span>
                            </p>
                        @else
                            <p class="vakman-week-col-empty">
                                <span>Vrij</span>
                                <span>Geen planning</span>
                            </p>
                        @endif
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
</section>
