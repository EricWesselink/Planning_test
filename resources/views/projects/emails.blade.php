@extends('layouts.app')

@section('title', 'E-mails · '.$project->displayTitle())

@section('content')
    <a href="{{ route('projects.show', $project) }}" class="text-sm text-nicon-muted">← {{ $project->displayTitle() }}</a>
    <h1 class="mt-2 text-2xl font-semibold">E-mails</h1>
    <p class="text-sm text-nicon-muted">Verzonden vanuit Nicon Planning.</p>

    @if ($mails->isEmpty())
        <p class="mt-4 text-sm text-nicon-muted">Nog geen e-mails voor dit werk.</p>
    @else
        <ul class="mt-4 divide-y divide-nicon-line border border-nicon-line bg-white">
            @foreach ($mails as $mail)
                <li>
                    <a href="{{ route('projects.emails.show', [$project, $mail]) }}" class="block px-3 py-2 text-sm hover:bg-nicon-sand">
                        <div>{{ $mail->created_at->format('d-m-Y') }} {{ $mail->sender_name }}</div>
                        <div>{{ $mail->listTitle() }}</div>
                        <div class="text-nicon-muted">naar: {{ $mail->recipient }}</div>
                        <div>{{ $mail->status->label() }}</div>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
