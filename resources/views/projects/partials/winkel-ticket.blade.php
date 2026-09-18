@php
    $ticketMode = $ticketMode ?? null;
@endphp
@if ($ticketMode)
    <section class="border border-nicon-line bg-white p-4">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <a href="{{ $ticketMode['planning_url'] }}" class="text-sm text-nicon-orange-dark">← Planning</a>
                <h2 class="mt-1 text-lg font-semibold">{{ $ticketMode['kind_label'] }} maken</h2>
                <p class="text-sm text-nicon-muted">
                    {{ $ticketMode['worker_name'] }}
                    @if ($ticketMode['worker_company'])
                        · {{ $ticketMode['worker_company'] }}
                    @endif
                    · werk uit de winkel
                </p>
            </div>
        </div>
        @if ($errors->any())
            <ul class="mt-3 list-disc pl-5 text-sm text-nicon-danger">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
        @if (! empty($ticketMode['existing']))
            <div class="mt-3 space-y-1 text-sm">
                @foreach ($ticketMode['existing'] as $existing)
                    <a href="{{ $existing['url'] }}" class="text-nicon-orange-dark">{{ $existing['label'] }}</a>
                @endforeach
            </div>
        @endif
        <form method="POST" action="{{ $ticketMode['store_url'] }}" class="mt-4 space-y-4">
            @csrf
            <fieldset class="space-y-2">
                <legend class="text-xs uppercase tracking-wide text-nicon-muted">Wat ze moeten doen</legend>
                <p class="text-sm text-nicon-muted">Dit komt op de bon die de vakman meekrijgt.</p>
                @forelse ($ticketMode['shop_works'] ?? [] as $shopWork)
                    <label class="flex items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            name="shop_work_activity_ids[]"
                            value="{{ $shopWork['activity_id'] }}"
                            class="mt-0.5"
                            @checked(collect(old('shop_work_activity_ids', array_column($ticketMode['shop_works'], 'activity_id')))->contains($shopWork['activity_id']))
                        >
                        <span>
                            {{ $shopWork['name'] }}
                            <span class="text-nicon-muted">· {{ $shopWork['qty_label'] }}</span>
                        </span>
                    </label>
                @empty
                    <p class="text-sm text-nicon-muted">Dit winkelwerk heeft nog geen werkzaamheden.</p>
                @endforelse
            </fieldset>
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="winkel-ticket-notes">Opmerking</label>
                <textarea id="winkel-ticket-notes" name="notes" rows="2" maxlength="2000" class="mt-1 w-full border border-nicon-line px-2 py-1.5" placeholder="Wat ze moeten meenemen of opletten">{{ old('notes') }}</textarea>
            </div>
            @if ($ticketMode['is_external'])
                <fieldset class="space-y-2">
                    <legend class="text-xs uppercase tracking-wide text-nicon-muted">Afrekening</legend>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="billing_method" value="unit" @checked(old('billing_method', 'unit') === 'unit')>
                        Per m² / m¹
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="billing_method" value="hourly" @checked(old('billing_method') === 'hourly')>
                        Uren
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="billing_method" value="fixed" @checked(old('billing_method') === 'fixed')>
                        Vaste prijs
                    </label>
                    <input name="hourly_rate" class="w-full border border-nicon-line px-2 py-1.5" inputmode="decimal" placeholder="Uurtarief" value="{{ old('hourly_rate', $ticketMode['hourly_rate'] !== null ? \App\Support\Format::qty($ticketMode['hourly_rate']) : '') }}">
                    <input name="fixed_price" class="w-full border border-nicon-line px-2 py-1.5" inputmode="decimal" placeholder="Vaste prijs" value="{{ old('fixed_price') }}">
                </fieldset>
            @endif
            @if (! empty($ticketMode['has_measurement_form']))
                <label class="flex items-start gap-2 text-sm">
                    <input type="hidden" name="include_measurement_form" value="0">
                    <input type="checkbox" name="include_measurement_form" value="1" class="mt-0.5" @checked(old('include_measurement_form', $ticketMode['include_measurement_form'] ?? true))>
                    <span>Inmeetformulier toevoegen aan de PDF</span>
                </label>
            @endif
            <button class="bg-nicon-orange px-5 py-3 font-medium text-white">{{ $ticketMode['save_label'] }}</button>
        </form>
    </section>
@endif
