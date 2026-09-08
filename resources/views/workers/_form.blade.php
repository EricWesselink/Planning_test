@php
    $worker ??= new \App\Models\Worker;
@endphp
<div>
    <label class="text-xs uppercase tracking-wide text-nicon-muted">Naam van het team</label>
    <input type="text" name="name" value="{{ old('name', $worker->name) }}" required class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
</div>
<div class="grid gap-3 sm:grid-cols-2">
    <div>
        <label class="text-xs uppercase tracking-wide text-nicon-muted">Type</label>
        <select name="employment_type" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
            @foreach (\App\Enums\EmploymentType::cases() as $type)
                <option value="{{ $type->value }}" @selected(old('employment_type', $worker->employment_type?->value ?? 'eigen') === $type->value)>{{ $type->label() }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="text-xs uppercase tracking-wide text-nicon-muted">Bedrijf</label>
        <input type="text" name="company" value="{{ old('company', $worker->company) }}" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="Alleen bij ZZP / onderaannemer">
    </div>
</div>
<div>
    @include('workers._specialties', ['worker' => $worker])
</div>
<div data-crew-fields class="space-y-3">
    <div class="w-28 sm:w-40">
        <label class="text-xs uppercase tracking-wide text-nicon-muted">Personen</label>
        <input type="number" name="people_count" value="{{ old('people_count', $worker->peopleCount()) }}" min="1" max="50" required data-crew-count class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
    </div>
    @include('workers._crew-member-rows', ['worker' => $worker])
</div>
<div>
    <label class="text-xs uppercase tracking-wide text-nicon-muted">E-mail</label>
    <input type="email" name="email" value="{{ old('email', $worker->email) }}" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="naam@niconvloeren.nl">
</div>
<div>
    <label class="text-xs uppercase tracking-wide text-nicon-muted">Adres</label>
    <input type="text" name="address" value="{{ old('address', $worker->address) }}" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="Straat 12">
</div>
<div class="grid gap-3 sm:grid-cols-2">
    <div>
        <label class="text-xs uppercase tracking-wide text-nicon-muted">Postcode</label>
        <input type="text" name="postal_code" value="{{ old('postal_code', $worker->postal_code) }}" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="8013 PM">
    </div>
    <div>
        <label class="text-xs uppercase tracking-wide text-nicon-muted">Plaats</label>
        <input type="text" name="city" value="{{ old('city', $worker->city) }}" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="Zwolle">
    </div>
</div>
<div>
    <label class="text-xs uppercase tracking-wide text-nicon-muted">Contactpersoon</label>
    <input type="text" name="contact_name" value="{{ old('contact_name', $worker->contact_name) }}" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
</div>
<label class="flex items-center gap-2 text-sm">
    <input type="hidden" name="active" value="0">
    <input type="checkbox" name="active" value="1" @checked((int) old('active', $worker->active ?? 1) === 1)>
    Actief
</label>
