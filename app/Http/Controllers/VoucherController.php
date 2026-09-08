<?php

namespace App\Http\Controllers;

use App\Enums\VoucherPriceKind;
use App\Enums\VoucherType;
use App\Enums\WorkUnit;
use App\Mail\WorkerVoucherMail;
use App\Models\Project;
use App\Models\Voucher;
use App\Models\VoucherLine;
use App\Models\Worker;
use App\Services\VoucherDraftService;
use App\Support\Format;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ->route('production.index', array_filter([
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'from' => $data['from'] ?? null,
                'to' => $data['to'] ?? null,
            ]))
            ->with('status', $type->label().' '.$voucher->number.' is klaar.');
    }

    public function show(Request $request, Voucher $voucher): View
    {
        $voucher->load(['worker', 'project.customer', 'lines', 'parent']);
        Gate::authorize('view', $voucher);

        return view('vouchers.show', [
            'voucher' => $voucher,
            'canEdit' => $request->user()?->can('update', $voucher) ?? false,
            'canSend' => $request->user()?->can('send', $voucher) ?? false,
        ]);
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

    public function edit(Request $request, Voucher $voucher): View
    {
        $voucher->load(['worker', 'project', 'lines']);
        Gate::authorize('update', $voucher);

        $formLines = $voucher->lines->map(fn ($line) => [
            'project_area_id' => $line->project_area_id,
            'work_item_id' => $line->work_item_id,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit' => $line->unit,
            'unit_price' => $line->unit_price,
            'amount' => $line->amount,
            'price_source' => $line->price_source,
            'price_kind' => $line->price_kind,
        ])->all();

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
        $description = $room !== '' ? $room.': '.$name : $name;

        return [
            'project_area_id' => $area?->id,
            'work_item_id' => $item?->id,
            'description' => $description,
            'quantity' => $quantity === '' || $quantity === null ? null : $quantity,
            'unit' => $item?->unit ?? WorkUnit::SquareMeter,
            'unit_price' => null,
            'amount' => null,
            'price_kind' => VoucherPriceKind::Unit,
        ];
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
            if ($kind === VoucherPriceKind::Fixed->value) {
                if (! $hasQty && ! $hasAmount) {
                    continue;
                }
            } elseif (! $hasQty) {
                continue;
            }

            $kept[] = $line;
        }

        $request->merge(['lines' => array_values($kept)]);
    }
}
