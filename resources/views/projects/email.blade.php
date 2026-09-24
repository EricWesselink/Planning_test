@extends('layouts.app')

@section('title', $mail->subject.' · E-mail')

@section('content')
    <a href="{{ route('projects.emails', $project) }}" class="text-sm text-nicon-muted">← E-mails</a>
    <h1 class="mt-2 text-xl font-semibold">{{ $mail->listTitle() }}</h1>
    <dl class="mt-4 space-y-1 text-sm">
        <div><dt class="inline text-nicon-muted">Wanneer</dt> <dd class="inline">{{ $mail->created_at->format('d-m-Y H:i') }}</dd></div>
        <div><dt class="inline text-nicon-muted">Van</dt> <dd class="inline">{{ $mail->sender_name }}</dd></div>
        <div><dt class="inline text-nicon-muted">Aan</dt> <dd class="inline">{{ $mail->recipient }}</dd></div>
        @if ($mail->cc)
            <div><dt class="inline text-nicon-muted">CC</dt> <dd class="inline">{{ $mail->cc }}</dd></div>
        @endif
        <div><dt class="inline text-nicon-muted">Onderwerp</dt> <dd class="inline">{{ $mail->subject }}</dd></div>
        <div><dt class="inline text-nicon-muted">Bijlage</dt> <dd class="inline">{{ $mail->attachment_filename }}</dd></div>
        <div><dt class="inline text-nicon-muted">Status</dt> <dd class="inline">{{ $mail->status->label() }}</dd></div>
    </dl>
    <pre class="mt-4 whitespace-pre-wrap border border-nicon-line bg-white p-3 text-sm">{{ $mail->body }}</pre>
@endsection
