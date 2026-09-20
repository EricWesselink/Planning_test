<dialog id="calc-print-dialog" class="calc-print-dialog" aria-labelledby="calc-print-dialog-title">
    <form method="dialog" class="space-y-4">
        <div class="flex items-start justify-between gap-3">
            <h2 id="calc-print-dialog-title" data-print-title class="text-base font-semibold">Print / PDF</h2>
            <button type="button" data-print-cancel class="text-sm text-nicon-muted">Sluiten</button>
        </div>
        <p data-print-error class="text-sm text-nicon-danger" hidden></p>

        <fieldset class="space-y-1">
            <legend class="text-xs font-semibold uppercase tracking-wide text-nicon-muted">Wat wil je exporteren?</legend>
            <label class="calc-print-check"><input type="checkbox" data-print-include value="colored" checked><span>Gekleurde calculatietekeningen</span></label>
            <label class="calc-print-check"><input type="checkbox" data-print-include value="rooms"><span>Ruimtenummers en ruimtenamen</span></label>
            <label class="calc-print-check"><input type="checkbox" data-print-include value="codes" checked><span>Materiaalcodes op de tekening</span></label>
            <label class="calc-print-check"><input type="checkbox" data-print-include value="legend" checked><span>Materiaallegenda</span></label>
            <label class="calc-print-check"><input type="checkbox" data-print-include value="room_list"><span>Ruimtelijst per tekening</span></label>
            <label class="calc-print-check"><input type="checkbox" data-print-include value="area_totals"><span>Materiaaltotalen m²</span></label>
            <label class="calc-print-check"><input type="checkbox" data-print-include value="plinth_totals"><span>Plinttotalen m¹</span></label>
            <label class="calc-print-check"><input type="checkbox" data-print-include value="details"><span>Detailregels calculatie</span></label>
        </fieldset>

        <fieldset class="space-y-1">
            <legend class="text-xs font-semibold uppercase tracking-wide text-nicon-muted">Welke tekeningen?</legend>
            <label class="calc-print-check"><input type="checkbox" data-print-all-drawings checked><span>Alle tekeningen</span></label>
            <div data-print-drawings class="mt-1 max-h-40 space-y-1 overflow-auto"></div>
        </fieldset>

        <fieldset class="space-y-1">
            <legend class="text-xs font-semibold uppercase tracking-wide text-nicon-muted">Materialen</legend>
            <label class="calc-print-check"><input type="radio" name="print-material-mode" data-print-material-all checked><span>Alle materialen</span></label>
            <label class="calc-print-check"><input type="radio" name="print-material-mode" data-print-material-selected><span>Alleen geselecteerde materialen</span></label>
            <div data-print-materials class="mt-1 max-h-40 space-y-1 overflow-auto is-disabled"></div>
        </fieldset>

        <div class="flex flex-wrap justify-end gap-2">
            <button type="button" data-print-print class="border border-nicon-line bg-white px-3 py-1.5 text-sm">Afdrukken</button>
            <button type="button" data-print-pdf class="bg-nicon-orange px-3 py-1.5 text-sm text-white">PDF maken</button>
        </div>
    </form>
</dialog>
