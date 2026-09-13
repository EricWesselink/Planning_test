<?php

namespace App\Http\Controllers;

use App\Enums\VoucherPriceKind;
use App\Enums\VoucherType;
use App\Enums\WorkUnit;
use App\Mail\WorkerVoucherMail;
use App\Models\AreaTask;
use App\Models\Project;
use App\Models\Voucher;
use App\Models\VoucherLine;
use App\Models\Worker;
use App\Models\WorkProgressEntry;
use App\Services\VoucherDraftService;
use App\Services\VoucherPdfService;
use App\Support\Format;
use App\Support\VoucherActivityGroups;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VoucherController extends Controller
{
    public function create(Request $request, VoucherDraftService $drafts): View|RedirectResponse
    {
        $data = $request->validate([
            'worker_id' => ['required', 'exists:workers,id'],
            'project_id' => ['required', 'exists:projects,id'],
            'type' => ['required', Rule::enum(VoucherType::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $worker = Worker::query()->findOrFail($data['worker_id']);
        $project = Project::query()->findOrFail($data['project_id']);
        $type = VoucherType::from($data['type']);

        Gate::authorize('view', $project);
        Gate::authorize('create', [Voucher::class, $project]);

        $filters = array_filter([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
        ]);

        if ($type === VoucherType::Facturatie) {
            if (Voucher::latestOpdracht($worker->id, $project->id) === null) {
                return redirect()
                    ->route('production.index', $filters)
                    ->withErrors(['type' => 'Maak eerst een opdrachtbon voordat je een bon kunt opmaken.']);
            }

            return redirect()->route('production.index', $filters);
        }

        $draft = $drafts->draft(
            $worker,
            $project,
            $type,
            $data['from'] ?? null,
            $data['to'] ?? null,
            $request->user(),
        );

        return view('vouchers.create', $draft);
    }

    public function store(Request $request, VoucherDraftService $drafts): RedirectResponse
    {
        $this->normalizeDecimals($request);
        $this->dropBlankLines($request);
        $this->applyActivityPrices($request);

        $data = $request->validate($this->lineRules(), $this->lineMessages());

        $worker = Worker::query()->findOrFail($data['worker_id']);
        $project = Project::query()->findOrFail($data['project_id']);
        $type = VoucherType::from($data['type']);

        Gate::authorize('view', $project);
        Gate::authorize('create', [Voucher::class, $project]);

        $this->assertLinesBelongToProject($project, $data['lines']);

        $voucher = $drafts->store(
            $worker,
            $project,
            $type,
            $data['lines'],
            $request->user(),
            $data['notes'] ?? null,
            $data['from'] ?? null,
            $data['to'] ?? null,
        );

        if ($type === VoucherType::Facturatie) {
            return $this->redirectToProductionAfterVoucher(
                $voucher,
                $drafts,
                $type->label().' '.$voucher->number.' is klaar.',
                [
                    'from' => $data['from'] ?? null,
                    'to' => $data['to'] ?? null,
                ],
            );
        }

        return redirect()
            ->route('vouchers.pdf', $voucher);
    }

    public function show(Request $request, Voucher $voucher): View
    {
        $voucher->load(['worker', 'project.customer', 'lines.area', 'parent']);
        Gate::authorize('view', $voucher);

        return view('vouchers.show', [
            'voucher' => $voucher,
            'canEdit' => $request->user()?->can('update', $voucher) ?? false,
            'canSend' => $request->user()?->can('send', $voucher) ?? false,
            'canDelete' => $request->user()?->can('delete', $voucher) ?? false,
        ]);
    }

    public function pdf(Voucher $voucher, VoucherPdfService $pdfs): Response
    {
        $voucher->load(['worker', 'project.customer', 'lines.area', 'parent']);
        Gate::authorize('view', $voucher);

        $data = $pdfs->build($voucher);
        $pdf = Pdf::loadView('vouchers.pdf', $data)
            ->setPaper('a4', 'portrait')
            ->setOption('defaultFont', 'DejaVu Sans');
        $pdf->addInfo([
            'Title' => $data['documentTitle'].' '.$voucher->number,
            'Author' => $data['companyName'],
        ]);

        return $pdf->download($data['filename']);
    }

    public function send(Voucher $voucher, VoucherDraftService $drafts): RedirectResponse
    {
        Gate::authorize('send', $voucher);
        $voucher->load(['worker', 'project', 'lines', 'parent']);

        $email = $voucher->worker?->email;
        if (! filled($email)) {
            return redirect()
                ->route('vouchers.show', $voucher)
                ->withErrors(['email' => 'Vul eerst het e-mailadres van de vakman in.']);
        }

        Mail::to($email)->send(new WorkerVoucherMail($voucher));

        return $this->redirectToProductionAfterVoucher(
            $voucher,
            $drafts,
            $voucher->type->label().' '.$voucher->number.' is verstuurd naar '.$email.'.',
        );
    }

    public function destroy(Voucher $voucher): RedirectResponse
    {
        Gate::authorize('delete', $voucher);
        $voucher->loadMissing(['worker', 'project', 'lines']);

        if ($voucher->type === VoucherType::Opdracht && $voucher->children()->exists()) {
            return back()->withErrors([
                'voucher' => 'Verwijder eerst de bonnen bij deze opdrachtbon.',
            ]);
        }

        $label = $voucher->type->label().' '.$voucher->number;
        $query = array_filter([
            'worker_id' => $voucher->worker_id,
            'project_id' => $voucher->project_id,
        ]);
        $clearsRooms = $voucher->type === VoucherType::Opdracht;

        DB::transaction(function () use ($voucher, $clearsRooms): void {
            if ($clearsRooms) {
                $this->reopenRoomsCoveredByOpdracht($voucher);
            }

            $voucher->delete();
        });

        $status = $label.' is verwijderd.';
        if ($clearsRooms) {
            $status .= ' Ruimtes staan weer open.';
        }

        return redirect()
            ->route('production.index', $query)
            ->with('status', $status);
    }

    private function reopenRoomsCoveredByOpdracht(Voucher $voucher): void
    {
        foreach ($voucher->lines as $line) {
            $areaId = $line->project_area_id ? (int) $line->project_area_id : null;
            $itemId = $line->work_item_id ? (int) $line->work_item_id : null;
            if ($areaId === null || $itemId === null) {
                continue;
            }

            $task = AreaTask::query()
                ->where('project_area_id', $areaId)
                ->where('work_item_id', $itemId)
                ->first();

            if ($task !== null) {
                $task->reopen();

                continue;
            }

            WorkProgressEntry::query()
                ->where('project_area_id', $areaId)
                ->where('work_item_id', $itemId)
                ->delete();
        }
    }

    public function edit(Request $request, Voucher $voucher): View
    {
        $voucher->load(['worker.rates', 'project', 'lines.area']);
        Gate::authorize('update', $voucher);

        $formLines = $this->formLinesFromVoucher($voucher);

        $extra = $this->opdrachtLineFromQuery($request, $voucher);
        if ($extra !== null && ! $this->formLinesContainKey($formLines, $extra)) {
            $formLines[] = $extra;
        }

        return view('vouchers.edit', [
            'voucher' => $voucher,
            'formLines' => $formLines,
        ]);
    }

    public function update(Request $request, Voucher $voucher, VoucherDraftService $drafts): RedirectResponse
    {
        Gate::authorize('update', $voucher);
        $this->normalizeDecimals($request);
        $this->dropBlankLines($request);
        $this->applyActivityPrices($request);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.project_area_id' => ['nullable', 'integer'],
            'lines.*.work_item_id' => ['nullable', 'integer'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit' => ['required', Rule::enum(WorkUnit::class)],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.price_kind' => ['nullable', Rule::enum(VoucherPriceKind::class)],
        ], $this->lineMessages());

        $voucher->loadMissing('project');
        $this->assertLinesBelongToProject($voucher->project, $data['lines']);

        $voucher = $drafts->replaceLines($voucher, $data['lines'], $data['notes'] ?? null, $request->user());

        return redirect()
            ->route('vouchers.show', $voucher)
            ->with('status', $voucher->type->label().' '.$voucher->number.' is aangepast.');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function redirectToProductionAfterVoucher(
        Voucher $voucher,
        VoucherDraftService $drafts,
        string $intro,
        array $query = [],
    ): RedirectResponse {
        $vouchers = Voucher::query()
            ->with('lines')
            ->where('worker_id', $voucher->worker_id)
            ->where('project_id', $voucher->project_id)
            ->get();

        $billing = $drafts->billingSummary($vouchers);
        $bonCount = $vouchers
            ->filter(fn (Voucher $item): bool => $item->type === VoucherType::Facturatie)
            ->count();

        $status = $intro;
        if ($billing['fully_settled']) {
            $status .= ' Volledig afgerekend.';
        } else {
            $parts = [];
            if ($billing['remaining_m2'] > 0.001) {
                $parts[] = Format::qty($billing['remaining_m2'], 2).' m²';
            }
            $parts[] = Format::money($billing['remaining_amount']);
            $status .= ' Nog '.implode(' · ', $parts).' open — je kunt nu de '.Format::bonOrdinal($bonCount + 1).' maken.';
        }

        return redirect()
            ->route('production.index', array_filter([
                'worker_id' => $voucher->worker_id,
                'project_id' => $voucher->project_id,
                ...$query,
            ], fn (mixed $value): bool => $value !== null && $value !== ''))
            ->with('status', $status);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function opdrachtLineFromQuery(Request $request, Voucher $voucher): ?array
    {
        if ($voucher->type !== VoucherType::Opdracht) {
            return null;
        }

        $areaId = $request->filled('area') ? $request->integer('area') : null;
        $itemId = $request->filled('item') ? $request->integer('item') : null;
        if ($areaId === null && $itemId === null) {
            return null;
        }

        $voucher->loadMissing('project');
        $area = $areaId ? $voucher->project->areas()->whereKey($areaId)->first() : null;
        $item = $itemId ? $voucher->project->workItems()->whereKey($itemId)->first() : null;
        abort_if($areaId !== null && $area === null, 404);
        abort_if($itemId !== null && $item === null, 404);

        $quantity = Format::decimalInput($request->query('quantity'));
        $room = $area?->label() ?? '';
        $name = $item?->cardLabel() ?? $item?->name ?? 'Werk';

        return [
            'project_area_id' => $area?->id,
            'work_item_id' => $item?->id,
            'room_label' => $room,
            'description' => $name,
            'quantity' => $quantity === '' || $quantity === null ? null : $quantity,
            'unit' => $item?->unit ?? WorkUnit::SquareMeter,
            'unit_price' => null,
            'amount' => null,
            'price_kind' => VoucherPriceKind::Unit,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function formLinesFromVoucher(Voucher $voucher): array
    {
        $voucher->loadMissing('lines.area');

        return $voucher->lines->map(function (VoucherLine $line): array {
            $payload = [
                'project_area_id' => $line->project_area_id,
                'work_item_id' => $line->work_item_id,
                'room_label' => $line->area?->label() ?? '',
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit' => $line->unit,
                'unit_price' => $line->unit_price,
                'amount' => $line->amount,
                'price_source' => $line->price_source,
                'price_kind' => $line->price_kind,
                'spec_m2' => $line->area?->square_meters,
            ];
            $payload['room_label'] = VoucherActivityGroups::roomLabel($payload);
            $payload['description'] = VoucherActivityGroups::activityName($payload);

            return $payload;
        })->all();
    }

    private function applyActivityPrices(Request $request): void
    {
        $prices = $request->input('activity_prices', []);
        $lines = $request->input('lines', []);
        if (! is_array($prices) || $prices === [] || ! is_array($lines)) {
            return;
        }

        foreach ($prices as $itemId => $byUnit) {
            if (! is_array($byUnit)) {
                continue;
            }
            foreach ($byUnit as $unitKey => $group) {
                if (! is_array($group)) {
                    continue;
                }
                foreach (['unit_price', 'amount', 'quantity'] as $field) {
                    if (array_key_exists($field, $group)) {
                        $prices[$itemId][$unitKey][$field] = Format::decimalInput($group[$field]);
                    }
                }
            }
        }

        $members = [];
        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }
            $itemId = $line['work_item_id'] ?? null;
            if ($itemId === null || $itemId === '') {
                continue;
            }
            $unit = (string) ($line['unit'] ?? '');
            $group = is_array($prices[$itemId][$unit] ?? null) ? $prices[$itemId][$unit] : null;
            if ($group === null && isset($prices[$itemId]) && is_array($prices[$itemId])) {
                $only = array_values(array_filter($prices[$itemId], 'is_array'));
                $group = count($only) === 1 ? $only[0] : null;
            }
            if (! is_array($group)) {
                continue;
            }

            $members[(string) $itemId.'.'.$unit][] = $index;
            if (isset($group['description']) && trim((string) $group['description']) !== '') {
                $lines[$index]['description'] = trim((string) $group['description']);
            }
            if (isset($group['unit']) && $group['unit'] !== '') {
                $lines[$index]['unit'] = $group['unit'];
            }
            if (isset($group['price_kind'])) {
                $lines[$index]['price_kind'] = $group['price_kind'];
            }
            if (array_key_exists('unit_price', $group) && $group['unit_price'] !== '' && $group['unit_price'] !== null) {
                $lines[$index]['unit_price'] = $group['unit_price'];
            }
        }

        foreach ($members as $indexes) {
            $first = $lines[$indexes[0]];
            $itemId = $first['work_item_id'];
            $unit = (string) ($first['unit'] ?? '');
            $group = is_array($prices[$itemId][$unit] ?? null)
                ? $prices[$itemId][$unit]
                : (isset($prices[$itemId]) && is_array($prices[$itemId]) ? reset($prices[$itemId]) : null);
            $kind = (string) ($first['price_kind'] ?? VoucherPriceKind::Unit->value);

            if ($unit === WorkUnit::Hours->value) {
                $hours = is_array($group) && filled($group['quantity'] ?? null)
                    ? round((float) $group['quantity'], 2)
                    : 0.0;
                foreach ($indexes as $i => $index) {
                    $lines[$index]['quantity'] = $i === 0 ? $hours : 0;
                }
            }

            if ($kind !== VoucherPriceKind::Fixed->value) {
                continue;
            }
            $groupAmount = round((float) ((is_array($group) ? ($group['amount'] ?? 0) : 0)), 2);
            $totalQty = 0.0;
            foreach ($indexes as $index) {
                $totalQty += round((float) ($lines[$index]['quantity'] ?? 0), 2);
            }
            $allocated = 0.0;
            $last = count($indexes) - 1;
            foreach ($indexes as $i => $index) {
                $qty = round((float) ($lines[$index]['quantity'] ?? 0), 2);
                if ($i === $last) {
                    $share = round($groupAmount - $allocated, 2);
                } elseif ($totalQty > 0.001) {
                    $share = round($qty / $totalQty * $groupAmount, 2);
                    $allocated += $share;
                } else {
                    $share = 0.0;
                }
                $lines[$index]['amount'] = $share;
            }
        }

        $request->merge(['lines' => $lines]);
    }

    /**
     * @param  list<array<string, mixed>>  $formLines
     * @param  array<string, mixed>  $extra
     */
    private function formLinesContainKey(array $formLines, array $extra): bool
    {
        $key = VoucherLine::lineKey(
            isset($extra['project_area_id']) && $extra['project_area_id'] ? (int) $extra['project_area_id'] : null,
            isset($extra['work_item_id']) && $extra['work_item_id'] ? (int) $extra['work_item_id'] : null,
        );

        foreach ($formLines as $line) {
            $lineKey = VoucherLine::lineKey(
                isset($line['project_area_id']) && $line['project_area_id'] ? (int) $line['project_area_id'] : null,
                isset($line['work_item_id']) && $line['work_item_id'] ? (int) $line['work_item_id'] : null,
            );
            if ($lineKey === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function lineRules(): array
    {
        return [
            'worker_id' => ['required', 'exists:workers,id'],
            'project_id' => ['required', 'exists:projects,id'],
            'type' => ['required', Rule::enum(VoucherType::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.project_area_id' => ['nullable', 'integer'],
            'lines.*.work_item_id' => ['nullable', 'integer'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.room_label' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit' => ['required', Rule::enum(WorkUnit::class)],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.price_kind' => ['nullable', Rule::enum(VoucherPriceKind::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function lineMessages(): array
    {
        return [
            'lines.required' => 'Kies minstens één regel.',
            'lines.min' => 'Kies minstens één regel.',
            'lines.*.description.required' => 'Elke regel heeft een omschrijving nodig.',
            'lines.*.quantity.required' => 'Vul een hoeveelheid in.',
            'lines.*.unit_price.required' => 'Vul een prijs in, of zet eerst een afgesproken prijs bij de vakman.',
            'lines.*.unit_price.min' => 'Een prijs kan niet lager zijn dan 0.',
            'lines.*.amount.min' => 'Een bedrag kan niet lager zijn dan 0.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertLinesBelongToProject(Project $project, array $lines): void
    {
        $areaIds = collect($lines)->pluck('project_area_id')->filter()->unique()->values();
        $itemIds = collect($lines)->pluck('work_item_id')->filter()->unique()->values();

        if ($areaIds->isNotEmpty()) {
            $owned = $project->areas()->whereKey($areaIds)->count();
            abort_unless($owned === $areaIds->count(), 422, 'Een ruimte hoort niet bij dit project.');
        }

        if ($itemIds->isNotEmpty()) {
            $owned = $project->workItems()->whereKey($itemIds)->count();
            abort_unless($owned === $itemIds->count(), 422, 'Een werkzaamheid hoort niet bij dit project.');
        }
    }

    private function normalizeDecimals(Request $request): void
    {
        $lines = $request->input('lines', []);
        if (! is_array($lines)) {
            return;
        }

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }
            foreach (['quantity', 'unit_price', 'amount'] as $field) {
                if (array_key_exists($field, $line)) {
                    $lines[$index][$field] = Format::decimalInput($line[$field]);
                }
            }
        }

        $request->merge(['lines' => $lines]);
    }

    private function dropBlankLines(Request $request): void
    {
        $lines = $request->input('lines', []);
        if (! is_array($lines)) {
            return;
        }

        $kept = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $quantity = trim((string) ($line['quantity'] ?? ''));
            $amount = trim((string) ($line['amount'] ?? ''));
            $kind = (string) ($line['price_kind'] ?? VoucherPriceKind::Unit->value);
            $hasQty = $quantity !== '' && (float) $quantity > 0;
            $hasAmount = $amount !== '' && (float) $amount > 0;
            $hasRoom = filled($line['project_area_id'] ?? null) || filled($line['room_label'] ?? null);
            if ($kind === VoucherPriceKind::Fixed->value) {
                if (! $hasQty && ! $hasAmount && ! $hasRoom) {
                    continue;
                }
            } elseif (! $hasQty && ! $hasRoom) {
                continue;
            }

            $kept[] = $line;
        }

        $request->merge(['lines' => array_values($kept)]);
    }
}
