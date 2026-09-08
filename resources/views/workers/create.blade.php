@extends('layouts.app')

@section('title', 'Nieuwe vakman · Nicon Planning')

@section('content')
    <a href="{{ route('workers.index') }}" class="text-sm text-nicon-muted">← Vakmensen</a>
    <h1 class="mt-2 text-2xl font-semibold">Nieuwe vakman</h1>
    <p class="text-sm text-nicon-muted">Vakkennis, kleur, NAW en e-mail komen terug in de lijst, de planning en op de vakmanpagina.</p>

    @if ($errors->any())
        <ul class="mt-4 text-sm text-nicon-danger list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('workers.store') }}" class="mt-6 max-w-2xl border border-nicon-line bg-white p-5 space-y-4">
        @csrf
        @include('workers._form', ['worker' => $worker])
        <button class="bg-nicon-orange text-white px-5 py-3 font-medium">Opslaan</button>
    </form>
@endsection
