@extends('layouts.app')

@section('title', 'Nieuwe gebruiker · Nicon Planning')

@section('content')
    <a href="{{ route('users.index') }}" class="text-sm text-nicon-muted">← Gebruikers</a>
    <h1 class="mt-2 text-2xl font-semibold">Nieuwe gebruiker</h1>
    <p class="text-sm text-nicon-muted">Kies Vakman om gelijk een team aan te maken: type, vakkennis, bijzonderheden, en wie met een eigen e-mail mag inloggen.</p>

    @if ($errors->any())
        <ul class="mt-4 text-sm text-nicon-danger list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('users.store') }}" class="mt-6 max-w-3xl border border-nicon-line bg-white p-5 space-y-4">
        @csrf
        @include('users._form', ['user' => $user, 'projects' => $projects, 'requirePassword' => true, 'specialtyCatalog' => $specialtyCatalog ?? null])
        <button class="bg-nicon-orange text-white px-5 py-3 font-medium">Opslaan</button>
    </form>
@endsection
