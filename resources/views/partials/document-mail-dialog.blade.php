<dialog id="{{ $id }}" class="document-mail-dialog">
    <form method="POST" action="{{ $action }}" class="document-mail-form">
        @csrf
        @foreach ($hidden ?? [] as $name => $value)
            @if (is_array($value))
                @foreach ($value as $item)
                    <input type="hidden" name="{{ $name }}" value="{{ $item }}">
                @endforeach
            @else
                <input type="hidden" name="{{ $name }}" value="{{ $value }}" @if ($name === 'inmeetformulier') id="ticket-mail-measurement" @endif>
            @endif
        @endforeach
        <h2>E-mailen</h2>
        <label>
            Aan
            <input type="email" name="recipient" required maxlength="255" value="{{ old('recipient', $recipient ?? '') }}" autocomplete="email">
        </label>
        <label>
            CC
            <input type="email" name="cc" maxlength="255" value="{{ old('cc') }}" autocomplete="email">
        </label>
        <label>
            Onderwerp
            <input type="text" name="subject" required maxlength="255" value="{{ old('subject', $subject) }}">
        </label>
        <label>
            Bericht
            <textarea name="body" required maxlength="5000" rows="8">{{ old('body', $body) }}</textarea>
        </label>
        <p class="document-mail-attachment">Bijlage: <strong data-mail-attachment>{{ $attachment }}</strong></p>
        <div class="document-mail-actions">
            <button type="button" data-mail-cancel>Annuleren</button>
            <button type="submit">E-mail versturen</button>
        </div>
    </form>
</dialog>
<style>
    .document-mail-dialog {
        border: 1px solid #d7d2c8;
        padding: 0;
        width: min(32rem, calc(100vw - 2rem));
    }
    .document-mail-dialog::backdrop {
        background: rgb(0 0 0 / 35%);
    }
    .document-mail-form {
        display: grid;
        gap: 0.6rem;
        padding: 1rem;
        font: 14px/1.4 sans-serif;
    }
    .document-mail-form h2 {
        margin: 0;
        font-size: 1rem;
    }
    .document-mail-form label {
        display: grid;
        gap: 0.2rem;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .document-mail-form input,
    .document-mail-form textarea {
        font: inherit;
        text-transform: none;
        letter-spacing: 0;
        border: 1px solid #d7d2c8;
        padding: 0.4rem 0.5rem;
        width: 100%;
        box-sizing: border-box;
    }
    .document-mail-attachment {
        margin: 0;
        font-size: 0.85rem;
    }
    .document-mail-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.5rem;
    }
    .document-mail-actions button {
        border: 1px solid #d7d2c8;
        background: #fff;
        padding: 0.35rem 0.8rem;
        cursor: pointer;
    }
    .document-mail-actions button[type="submit"] {
        background: #e26a2c;
        border-color: #e26a2c;
        color: #fff;
    }
</style>
@if ($errors->getBag('document_mail')->any())
    <script>
        document.getElementById(@json($id))?.showModal();
    </script>
@endif
