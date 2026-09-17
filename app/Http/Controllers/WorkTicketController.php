<?php

namespace App\Http\Controllers;

use App\Enums\WorkTicketBilling;
use App\Enums\WorkTicketKind;
use App\Models\WorkerAssignment;
use App\Models\WorkTicket;
use App\Services\WorkTicketHoursNotifier;
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
    public function create(WorkerAssignment $assignment): RedirectResponse
    {
        $assignment->load(['worker', 'project']);
        Gate::authorize('create', [WorkTicket::class, $assignment]);

        return redirect()->route('projects.show', [
            'project' => $assignment->project_id,
            'bon' => $assignment->id,
        ]);
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
            ...$pdfs->build($workTicket, $showPrices, embedDrawings: false),
            'canRecordHours' => Gate::allows('recordHours', $workTicket),
            'canEdit' => Gate::allows('update', $workTicket),
        ]);
    }

    public function pdf(WorkTicket $workTicket, WorkTicketPdfService $pdfs): Response|View
    {
        $this->loadTicket($workTicket);
        Gate::authorize('view', $workTicket);

        $showPrices = Gate::allows('viewPrices', $workTicket);
        $data = $pdfs->build($workTicket, $showPrices);
        if (($data['drawingRender'] ?? 'image') === 'browser') {
            return view('work-tickets.print', $data);
        }

        $pdf = Pdf::loadView('work-tickets.pdf', $data)
            ->setPaper('a4', 'portrait')
            ->setOption('defaultFont', 'DejaVu Sans');
        $pdf->addInfo([
            'Title' => $data['documentTitle'].' '.$workTicket->number,
            'Author' => $data['companyName'],
        ]);

        return $pdf->download($data['filename']);
    }

    public function updateHours(Request $request, WorkTicket $workTicket, WorkTicketHoursNotifier $hours): RedirectResponse
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

        $fromVakman = $request->user()?->isVakman() ?? false;
        if ($fromVakman && $workTicket->wasChanged('worked_hours')) {
            $hours->submitted($workTicket, $request->user());
        }

        return redirect()
            ->route('work-tickets.show', $workTicket)
            ->with('status', $fromVakman
                ? 'Uren zijn teruggestuurd. De planner kan nu een bon maken om te factureren.'
                : 'Bestede uren zijn opgeslagen.');
    }

    public function destroy(WorkTicket $workTicket): RedirectResponse
    {
        $this->loadTicket($workTicket);
        Gate::authorize('delete', $workTicket);

        $label = $workTicket->kind->label().' '.$workTicket->number;
        $query = array_filter([
            'worker_id' => $workTicket->worker_id,
            'project_id' => $workTicket->project_id,
        ]);
        $workTicket->delete();

        return redirect()
            ->route('production.index', $query)
            ->with('status', $label.' is verwijderd.');
    }

    private function loadTicket(WorkTicket $ticket): void
    {
        $ticket->load([
            'worker',
            'project.customer',
            'lines.workItem',
            'areas.floor',
            'areas.markers',
            'floors',
            'documents',
            'project.documents',
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
            'selections' => ['required_without_all:floors,extra_work_item_ids,general_work', 'array', 'min:1'],
            'selections.*.floor_id' => ['nullable', 'integer'],
            'selections.*.entire' => ['nullable', 'boolean'],
            'selections.*.area_ids' => ['nullable', 'array'],
            'selections.*.area_ids.*' => ['integer'],
            'selections.*.work_keys' => ['required_with:selections', 'array', 'min:1'],
            'selections.*.work_keys.*' => ['string', 'max:80'],
            'floors' => ['required_without_all:selections,extra_work_item_ids,general_work', 'array'],
            'floors.*.included' => ['nullable'],
            'floors.*.scope' => ['nullable', 'in:entire,rooms'],
            'floors.*.area_ids' => ['nullable', 'array'],
            'floors.*.area_ids.*' => ['integer'],
            'work_item_ids' => ['required_without_all:selections,extra_work_item_ids,general_work', 'array', 'min:1'],
            'work_item_ids.*' => ['integer'],
            'extra_work_item_ids' => ['nullable', 'array'],
            'extra_work_item_ids.*' => ['integer'],
            'general_work' => ['nullable', 'boolean'],
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
            'selections.required' => 'Voeg minstens één selectie toe, of vink algemeen werk aan.',
            'selections.required_without_all' => 'Voeg minstens één selectie toe, of vink algemeen werk aan.',
            'selections.min' => 'Voeg minstens één selectie toe, of vink algemeen werk aan.',
            'floors.required_without_all' => 'Kies ruimtes, of vink algemeen werk aan.',
            'work_item_ids.required_without_all' => 'Kies ruimtes, of vink algemeen werk aan.',
            'selections.*.work_keys.required' => 'Kies minstens één werkzaamheid.',
            'selections.*.work_keys.min' => 'Kies minstens één werkzaamheid.',
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
