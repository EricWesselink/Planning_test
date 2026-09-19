<?php

namespace App\Http\Controllers;

use App\Models\Worker;
use App\Services\WorkerWhatsAppContactExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkerWhatsAppContactController extends Controller
{
    public function __invoke(WorkerWhatsAppContactExportService $export): StreamedResponse|RedirectResponse
    {
        Gate::authorize('exportWhatsAppContacts', Worker::class);

        $result = $export->export();
        if ($result['vcf'] === '') {
            $message = $result['skipped'] === []
                ? 'Geen actieve eigen medewerkers met een telefoonnummer.'
                : 'Geen contacten geëxporteerd. Geen telefoonnummer bij: '.implode(', ', $result['skipped']).'.';

            return redirect()
                ->route('workers.index')
                ->withErrors(['whatsapp_contacts' => $message]);
        }

        if ($result['skipped'] !== []) {
            session()->flash(
                'whatsapp_contacts_skipped',
                'Niet opgenomen (geen telefoonnummer): '.implode(', ', $result['skipped']).'.',
            );
        }

        $vcf = $result['vcf'];

        return response()->streamDownload(
            static fn () => print ($vcf),
            WorkerWhatsAppContactExportService::FILENAME,
            ['Content-Type' => 'text/vcard; charset=UTF-8'],
        );
    }
}
