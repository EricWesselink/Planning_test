<?php

namespace App\Http\Controllers;

use App\Enums\WorkTicketBilling;
use App\Enums\WorkTicketKind;
use App\Models\WorkTicket;
use App\Models\WorkerAssignment;
use App\Services\WorkTicketPdfService;
use App\Services\WorkTicketService;
use App\Support\Format;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkTicketController extends Controller
{
    public function create(WorkerAssignment $assignment, WorkTicketService $tickets): View
    {
        $assignment->load(['worker', 'project']);
        Gate::authorize('create', [WorkTicket::class, $assignment]);

        return view('work-tickets.create', $tickets->draft($assignment));
    }

    public function store(Request $request, WorkerAssignment $assignment, WorkTicketService $tickets): RedirectResponse
    {
        $assignment->load(['worker', 'project']);
        Gate::authorize('create', [WorkTicket::class, $assignment]);
        $this->normalizeDecimals($request);

        $worker = $assignment->worker;
        $external = $worker !== null && WorkTicketKind::forWorker($worker) === WorkTicketKind::Opdrachtbon;

        $data = $request->validate($this->storeRules($external), $this->storeMessages());
        $ticket = $tickets->store($assignment, $data, $request->user());

        return redirect()
            ->route('work-tickets.show', $ticket)
            ->with('status', $ticket->kind->label().' '.$ticket->number.' is klaar.');
    }

    public function show(WorkTicket $workTicket, WorkTicketPdfService $pdfs): View
    {
        $this->loadTicket($workTicket);
        Gate::authorize('view', $workTicket);

        $showPrices = Gate::allows('viewPrices', $workTicket);

        return view('work-tickets.show', [
            ...$pdfs->build($workTicket, $showPrices),
            'canRecordHours' => Gate::allows('recordHours', $workTicket),
            'canEdit' => Gate::allows('update', $workTicket),
        ]);
    }

    public function pdf(WorkTicket $workTicket, WorkTicketPdfService $pdfs): Response
    {
        $this->loadTicket($workTicket);
        Gate::authorize('view', $workTicket);

        $showPrices = Gate::allows('viewPrices', $workTicket);
        $data = $pdfs->build($workTicket, $showPrices);
        $pdf = Pdf::loadView('work-tickets.pdf', $data)
            ->setPaper('a4', 'portrait')
            ->setOption('defaultFont', 'DejaVu Sans');
        $pdf->addInfo([
            'Title' => $data['documentTitle'].' '.$workTicket->number,
            'Author' => $data['companyName'],
        ]);

        return $pdf->download($data['filename']);
    }

    public function updateHours(Request $request, WorkTicket $workTicket): RedirectResponse
    {
        $this->loadTicket($workTicket);
        Gate::authorize('recordHours', $workTicket);
        $this->normalizeDecimals($request);

        $data = $request->validate([
            'worked_hours' => ['required', 'numeric', 'min:0', 'max:10000'],
        ], [
            'worked_hours.required' => 'Vul de bestede uren in.',
            'worked_hours.min' => 'Uren kunnen niet lager zijn dan 0.',
        ]);

        $workTicket->forceFill([
            'worked_hours' => round((float) $data['worked_hours'], 2),
        ])->save();

        return redirect()
            ->route('work-tickets.show', $workTicket)
            ->with('status', 'Bestede uren zijn opgeslagen.');
    }

    private function loadTicket(WorkTicket $ticket): void
    {
        $ticket->load([
            'worker',
            'project.customer',
            'lines.workItem',
            'areas.floor',
            'floors',
            'documents',
            'assignment.crewMembers',
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function storeRules(bool $external): array
    {
        $billing = $external
            ? ['required', Rule::enum(WorkTicketBilling::class)]
            : ['nullable', Rule::enum(WorkTicketBilling::class)];

        return [
            'floors' => ['required', 'array'],
            'floors.*.included' => ['nullable'],
            'floors.*.scope' => ['nullable', 'in:entire,rooms'],
            'floors.*.area_ids' => ['nullable', 'array'],
            'floors.*.area_ids.*' => ['integer'],
            'work_item_ids' => ['required', 'array', 'min:1'],
            'work_item_ids.*' => ['integer'],
            'document_ids' => ['nullable', 'array'],
            'document_ids.*' => ['integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'billing_method' => $billing,
            'hourly_rate' => [
                Rule::requiredIf($external && $this->requestedBilling() === WorkTicketBilling::Hourly),
                'nullable',
                'numeric',
                'min:0',
            ],
            'fixed_price' => [
                Rule::requiredIf($external && $this->requestedBilling() === WorkTicketBilling::Fixed),
                'nullable',
                'numeric',
                'min:0',
            ],
            'unit_prices' => ['nullable', 'array'],
            'unit_prices.*' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function storeMessages(): array
    {
        return [
            'floors.required' => 'Kies minstens één verdieping of ruimte.',
            'work_item_ids.required' => 'Kies minstens één werkzaamheid.',
            'work_item_ids.min' => 'Kies minstens één werkzaamheid.',
            'billing_method.required' => 'Kies hoe deze opdracht wordt afgerekend.',
            'hourly_rate.required' => 'Vul het afgesproken uurtarief in.',
            'fixed_price.required' => 'Vul de afgesproken vaste prijs in.',
        ];
    }

    private function requestedBilling(): ?WorkTicketBilling
    {
        return WorkTicketBilling::tryFrom((string) request()->input('billing_method'));
    }

    private function normalizeDecimals(Request $request): void
    {
        $merge = [];
        foreach (['hourly_rate', 'fixed_price', 'worked_hours'] as $field) {
            if ($request->exists($field)) {
                $merge[$field] = Format::decimalInput($request->input($field));
            }
        }
        $prices = $request->input('unit_prices', []);
        if (is_array($prices)) {
            foreach ($prices as $key => $value) {
                $prices[$key] = Format::decimalInput($value);
            }
            $merge['unit_prices'] = $prices;
        }
        if ($merge !== []) {
            $request->merge($merge);
        }
    }
}
