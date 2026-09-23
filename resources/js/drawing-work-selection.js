export function roundBoardQty(value) {
    return Math.round((Number(value) || 0) * 100) / 100;
}

export function formatBoardQty(value) {
    const rounded = roundBoardQty(value);
    const negative = rounded < 0;
    const [whole, decimals] = Math.abs(rounded).toFixed(2).split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return `${negative ? '-' : ''}${grouped},${decimals}`;
}

/**
 * Dutch input: "1,90" is 1.9 and "1.468,44" is 1468.44.
 */
export function parseBoardNumber(value) {
    if (typeof value === 'number') {
        return Number.isFinite(value) ? value : null;
    }
    if (value == null) {
        return null;
    }

    let raw = String(value).trim().replace(/\u00a0/g, '').replace(/€/g, '').trim();
    raw = raw.replace(/m²|m2|m¹|m1|lm/gi, '').replace(/\s/g, '');
    if (raw === '' || raw === '-' || raw === '+') {
        return null;
    }

    const negative = raw.startsWith('-');
    raw = raw.replace(/^[+-]/, '').replace(/[^0-9,.-]/g, '');
    if (raw === '' || raw === '-' || raw === ',' || raw === '.') {
        return null;
    }

    const comma = raw.lastIndexOf(',');
    const dot = raw.lastIndexOf('.');
    if (comma !== -1 && dot !== -1) {
        raw = comma > dot
            ? raw.replace(/\./g, '').replace(',', '.')
            : raw.replace(/,/g, '');
    } else if (comma !== -1) {
        const decimals = raw.length - comma - 1;
        raw = decimals <= 2 ? raw.replace(',', '.') : raw.replace(/,/g, '');
    } else if (dot !== -1 && /^\d{1,3}(\.\d{3})+$/.test(raw)) {
        raw = raw.replace(/\./g, '');
    }

    const number = Number(raw);
    if (!Number.isFinite(number)) {
        return null;
    }

    return negative ? -number : number;
}

export function formatBoardMoney(value) {
    return `€ ${formatBoardQty(value)}`;
}

export function boardLineAmount(quantity, unitPrice) {
    const qty = Math.round(Number(quantity) * 100);
    const price = Math.round(Number(unitPrice) * 100);
    if (!Number.isFinite(qty) || !Number.isFinite(price)) {
        return null;
    }

    return Math.round((qty * price) / 100) / 100;
}

/**
 * One planned activity as a bon line. Quantity and price stay decimal, never integer.
 *
 * @returns {{ok: true, line: object}|{ok: false, message: string}}
 */
export function plannedTicketLine({
    id,
    name,
    quantity,
    unit = 'm2',
    unitPrice = '',
    billing = '',
} = {}) {
    const label = String(name || '').trim() || 'Werkzaamheid';
    const qty = parseBoardNumber(quantity);
    if (qty === null || qty <= 0) {
        return {
            ok: false,
            message: `Vul een hoeveelheid groter dan 0 in voor ${label}, bijvoorbeeld 1.468,44.`,
        };
    }

    let price = null;
    if (billing === 'unit') {
        price = parseBoardNumber(unitPrice);
        if (price === null || price < 0) {
            return {
                ok: false,
                message: `Vul de prijs per ${workUnitLabel(unit)} in voor ${label}, bijvoorbeeld 1,90.`,
            };
        }
    }

    const amount = price === null ? null : boardLineAmount(qty, price);

    return {
        ok: true,
        line: {
            id: Number(id),
            name: label,
            quantity: qty,
            unit,
            unit_price: price,
            amount,
            qty_label: `${formatBoardQty(qty)} ${workUnitLabel(unit)}`,
            price_label: price === null ? '' : formatBoardMoney(price),
            amount_label: amount === null ? '' : formatBoardMoney(amount),
        },
    };
}

export function workUnitLabel(unit) {
    if (unit === 'm1') {
        return 'm¹';
    }
    if (unit === 'm2' || !unit) {
        return 'm²';
    }

    return unit;
}

export function workFilterSummaryLabel(keys) {
    if (!keys.length) {
        return 'Materialen kiezen';
    }

    return `Materialen kiezen (${keys.length})`;
}

export function shortWorkLabel(label) {
    const raw = String(label || '').trim();
    if (raw === '') {
        return '';
    }
    if (/primen|primer/i.test(raw) && /egal/i.test(raw)) {
        return 'Primer & Egaliseren';
    }

    let text = raw.replace(/,?\s*(linoleum|pvc\s*\/?\s*vinyl|pvc\s*-?\s*lvt|pvc|lvt|entreemat|coating|tapijt)\s*$/i, '').trim();
    if (/^marmoleum\s+/i.test(text)) {
        text = text.replace(/^marmoleum\s+/i, '').trim();
        const match = text.match(/^([^,]+?),?\s+(\d{4,6})\s+(.+)$/i);
        if (match) {
            return `${match[1].replace(/,$/, '').trim()} ${match[2]} – ${match[3].trim()}`;
        }

        return text;
    }
    if (/^coral\s+/i.test(text)) {
        const match = text.match(/^(coral\s+[^,]+)(?:,\s*\d{3,6})?\s*[–-]?\s*(.+)$/i);
        if (match) {
            return `${match[1].trim()} – ${match[2].trim()}`;
        }
    }
    if (/gietvloer/i.test(text)) {
        return 'PU gietvloer';
    }
    if (/^plinten?/i.test(text)) {
        return text.replace(/\s+wit$/i, '') || 'Plinten';
    }

    return text;
}

export function workFamily(filter) {
    const key = String(filter?.color_key || '');
    const workKey = String(filter?.key || '');
    const label = String(filter?.label || '').toLowerCase();
    if (workKey.startsWith('winkel:') || key === 'winkel') {
        return 'Winkelwerk';
    }
    if (workKey === 'ondergrond' || key === 'ondergrond' || (/primen|primer/.test(label) && /egal/.test(label))) {
        return '';
    }
    if (label.includes('marmoleum')) {
        return 'Marmoleum';
    }
    if (key === 'pvc' || /\bpvc\b/.test(label) || label.includes('lvt') || label.includes('taraflex')) {
        return 'PVC';
    }
    if (key === 'entreemat' || label.includes('entreemat') || label.includes('coral')) {
        return 'Entreemat';
    }
    if (key === 'tapijt' || label.includes('tapijt')) {
        return 'Tapijt';
    }
    if (key === 'gietvloer' || label.includes('gietvloer')) {
        return 'PU gietvloer';
    }
    if (key === 'coating' || label.includes('coating')) {
        return 'Coating';
    }
    if (key === 'plinten' || label.includes('plint')) {
        return 'Plinten';
    }

    return 'Overig';
}

const FAMILY_ORDER = ['', 'Marmoleum', 'PVC', 'Tapijt', 'Entreemat', 'PU gietvloer', 'Coating', 'Plinten', 'Winkelwerk', 'Overig'];
const ALWAYS_GROUP = new Set(['', 'Marmoleum', 'PVC', 'Winkelwerk']);

export function groupedWorkFilters(filters) {
    const buckets = new Map();
    filters.forEach((filter) => {
        const family = workFamily(filter);
        if (!buckets.has(family)) {
            buckets.set(family, []);
        }
        buckets.get(family).push(filter);
    });

    const overig = buckets.get('Overig') || [];
    [...buckets.entries()].forEach(([name, items]) => {
        if (name === 'Overig' || ALWAYS_GROUP.has(name) || items.length >= 2) {
            return;
        }
        overig.push(...items);
        buckets.delete(name);
    });
    if (overig.length) {
        buckets.set('Overig', overig);
    }

    return FAMILY_ORDER
        .filter((name) => buckets.has(name) && buckets.get(name).length)
        .map((name) => ({ name, items: buckets.get(name) }));
}

export function areaMatchesWorkKeys(area, keys, rowKeys = null) {
    const roomKeys = roomWorkKeys(keys);
    if (!roomKeys.length) {
        return true;
    }
    const have = rowKeys ?? (area?.works || []).map((work) => work.key);

    return roomKeys.some((key) => have.includes(key));
}

export function roomWorkKeys(keys = []) {
    return (keys || []).filter((key) => !String(key).startsWith('winkel:'));
}

export function shopWorkActivityIds(keys = []) {
    return (keys || [])
        .map((key) => String(key))
        .filter((key) => key.startsWith('winkel:'))
        .map((key) => Number(key.slice(7)))
        .filter((id) => Number.isFinite(id) && id > 0);
}

function workQuantity(area, work, keys) {
    if (work.quantity != null && work.quantity !== '') {
        return Number(work.quantity) || 0;
    }
    if (keys.length === 1) {
        return Number(area?.m2) || 0;
    }

    return 0;
}

/**
 * Netto meetstaat-hoeveelheden per aangevinkt materiaal op de huidige bouwlaag.
 * Dedupliceert dezelfde materiaalregel binnen één ruimte; telt verschillende
 * afwerkingen in dezelfde ruimte wel allebei.
 *
 * @param {object[]} areas
 * @param {string[]} keys
 * @param {object[]} filters
 */
export function measureSelectedWorks(areas, keys, filters = []) {
    const linesByKey = new Map();
    keys.forEach((key) => {
        const filter = filters.find((item) => item.key === key);
        linesByKey.set(key, {
            key,
            label: filter?.label || key,
            quantity: 0,
            unit: 'm2',
        });
    });

    const rooms = [];
    const roomIds = new Set();

    areas.forEach((area) => {
        const seenKeys = new Set();
        let matched = false;
        (area.works || []).forEach((work) => {
            if (!keys.includes(work.key) || seenKeys.has(work.key)) {
                return;
            }
            seenKeys.add(work.key);
            matched = true;
            const line = linesByKey.get(work.key);
            if (!line) {
                return;
            }
            line.quantity = roundBoardQty(line.quantity + workQuantity(area, work, keys));
            if (work.unit) {
                line.unit = work.unit;
            }
        });
        if (matched && !roomIds.has(area.id)) {
            roomIds.add(area.id);
            rooms.push({
                id: area.id,
                number: area.number || '',
                name: area.unique_name || area.name || '',
                floor: area.floor || '',
                floor_id: area.floor_id ?? null,
            });
        }
    });

    const lines = [...linesByKey.values()]
        .map((line) => ({
            ...line,
            quantity: roundBoardQty(line.quantity),
            qty_label: `${formatBoardQty(line.quantity)} ${workUnitLabel(line.unit)}`,
        }))
        .sort((left, right) => right.quantity - left.quantity || left.label.localeCompare(right.label, 'nl'));

    const square = lines.filter((line) => line.unit === 'm2' || !line.unit);
    const others = lines.filter((line) => line.unit && line.unit !== 'm2');
    const totalUnit = square.length || others.length === 0
        ? 'm2'
        : (others.every((line) => line.unit === others[0].unit) ? others[0].unit : 'm2');
    const totalSource = totalUnit === 'm2' ? square : others;
    const total = roundBoardQty(totalSource.reduce((sum, line) => sum + line.quantity, 0));

    return {
        lines,
        rooms,
        total,
        unit: totalUnit,
        label: `${formatBoardQty(total)} ${workUnitLabel(totalUnit)}`,
    };
}

export function uniqueAreasById(areas) {
    const seen = new Set();
    const unique = [];
    (areas || []).forEach((area) => {
        const id = Number(area?.id);
        if (!Number.isFinite(id) || id <= 0 || seen.has(id)) {
            return;
        }
        seen.add(id);
        unique.push(area);
    });

    return unique;
}

function familyRank(line) {
    const family = workFamily({
        key: line?.key,
        label: line?.label,
        color_key: line?.color_key,
    });
    const index = FAMILY_ORDER.indexOf(family);

    return index === -1 ? FAMILY_ORDER.length : index;
}

/**
 * Netto meetstaat-hoeveelheden van handmatig aangeklikte ruimtes.
 * Telt elke project_area één keer; gebruikt area.m2 en area_tasks, nooit de tekenvorm.
 *
 * @param {object[]} areas
 */
export function measureSelectedRooms(areas) {
    const unique = uniqueAreasById(areas);
    const linesByKey = new Map();

    unique.forEach((area) => {
        const seenKeys = new Set();
        (area.works || []).forEach((work) => {
            const key = String(work?.key || '');
            if (key === '' || seenKeys.has(key)) {
                return;
            }
            seenKeys.add(key);
            const quantity = work.quantity != null && work.quantity !== ''
                ? Number(work.quantity) || 0
                : 0;
            const existing = linesByKey.get(key);
            if (existing) {
                existing.quantity = roundBoardQty(existing.quantity + quantity);
                if (work.unit) {
                    existing.unit = work.unit;
                }
                return;
            }
            linesByKey.set(key, {
                key,
                label: work.label || key,
                quantity: roundBoardQty(quantity),
                unit: work.unit || 'm2',
                color_key: work.color_key || '',
            });
        });
    });

    const lines = [...linesByKey.values()]
        .filter((line) => line.quantity !== 0)
        .map((line) => ({
            ...line,
            quantity: roundBoardQty(line.quantity),
            qty_label: `${formatBoardQty(line.quantity)} ${workUnitLabel(line.unit)}`,
            display_label: shortWorkLabel(line.label) || line.label,
        }))
        .sort((left, right) => familyRank(left) - familyRank(right)
            || left.label.localeCompare(right.label, 'nl'));

    const totalM2 = roundBoardQty(unique.reduce((sum, area) => sum + (Number(area.m2) || 0), 0));
    const rooms = unique.map((area) => ({
        id: area.id,
        number: area.number || '',
        name: area.unique_name || area.name || '',
        floor: area.floor || '',
        floor_id: area.floor_id ?? null,
        m2: Number(area.m2) || 0,
    })).sort((left, right) => String(left.number).localeCompare(String(right.number), 'nl', { numeric: true })
        || String(left.name).localeCompare(String(right.name), 'nl'));

    return {
        lines,
        rooms,
        total: totalM2,
        total_m2: totalM2,
        unit: 'm2',
        label: `${formatBoardQty(totalM2)} m²`,
        m2_label: `${formatBoardQty(totalM2)} m²`,
    };
}

export function roomSelectionSummaryLabel(count, m2) {
    const rooms = count === 1 ? '1 ruimte' : `${count} ruimtes`;

    return `${rooms} · ${formatBoardQty(m2)} m²`;
}

export function isProcessWorkItem(work) {
    const key = String(work?.key || '');
    if (key === 'ondergrond' || key.startsWith('plinten')) {
        return true;
    }
    const family = workFamily(work);

    return family === '' || family === 'Plinten';
}

export function groupedRoomProgressWorks(works) {
    const werkzaamheden = [];
    const materialen = [];
    (works || []).forEach((work) => {
        if (isProcessWorkItem(work)) {
            werkzaamheden.push(work);
        } else {
            materialen.push(work);
        }
    });

    return { werkzaamheden, materialen };
}

export function checkedKeysFromWorkFilter(works, filterKeys) {
    if (!filterKeys?.length) {
        return [];
    }
    const allowed = new Set(filterKeys);

    return (works || [])
        .filter((work) => work.bookable && allowed.has(work.key))
        .map((work) => work.key);
}

export function activeSelectionFromFilter(works, filterKeys, filters = []) {
    if (!filterKeys?.length) {
        return [];
    }
    const byKey = new Map((works || []).map((work) => [work.key, work]));
    const filterByKey = new Map((filters || []).map((item) => [item.key, item]));

    return filterKeys.map((key) => {
        const work = byKey.get(key);
        if (work) {
            return {
                ...work,
                in_rooms: true,
                active_detail: `${work.qty_label} in geselecteerde ruimtes`,
            };
        }
        const filter = filterByKey.get(key);
        const label = shortWorkLabel(filter?.label) || filter?.label || key;

        return {
            key,
            display_label: label,
            label: filter?.label || key,
            in_rooms: false,
            qty_label: '0,00 m²',
            active_detail: 'niet in geselecteerde ruimtes',
            bookable: false,
        };
    });
}

export function activeWorkBarLabel(activeLines) {
    if (!activeLines?.length) {
        return '';
    }
    if (activeLines.length === 1) {
        const line = activeLines[0];
        if (line.in_rooms === false) {
            return line.display_label || line.label || '';
        }

        return `${line.display_label || line.label} · ${line.qty_label}`;
    }

    return `${activeLines.length} onderdelen geselecteerd`;
}

/**
 * Werkzaamheden van geselecteerde ruimtes, met restant per area_task (nooit het selectietotaal).
 *
 * @param {object[]} areas
 */
export function selectedRoomProgressWorks(areas) {
    const unique = uniqueAreasById(areas);
    const linesByKey = new Map();

    unique.forEach((area) => {
        const seenKeys = new Set();
        (area.works || []).forEach((work) => {
            const key = String(work?.key || '');
            if (key === '' || seenKeys.has(key)) {
                return;
            }
            seenKeys.add(key);
            const ordered = work.quantity != null && work.quantity !== ''
                ? roundBoardQty(work.quantity)
                : 0;
            const remaining = work.remaining != null && work.remaining !== ''
                ? roundBoardQty(work.remaining)
                : (work.done ? 0 : ordered);
            const completed = work.completed != null && work.completed !== ''
                ? roundBoardQty(work.completed)
                : roundBoardQty(Math.max(0, ordered - remaining));
            const existing = linesByKey.get(key);
            if (existing) {
                existing.ordered = roundBoardQty(existing.ordered + ordered);
                existing.remaining = roundBoardQty(existing.remaining + remaining);
                existing.completed = roundBoardQty(existing.completed + completed);
                existing.rooms += 1;
                if (remaining <= 0) {
                    existing.doneRooms += 1;
                }
                return;
            }
            linesByKey.set(key, {
                key,
                label: work.label || key,
                unit: work.unit || 'm2',
                color_key: work.color_key || '',
                ordered,
                remaining,
                completed,
                rooms: 1,
                doneRooms: remaining <= 0 ? 1 : 0,
            });
        });
    });

    return [...linesByKey.values()]
        .map((line) => {
            const done = line.remaining <= 0;
            const partial = !done && line.completed > 0;
            const unitLabel = workUnitLabel(line.unit);
            const display = shortWorkLabel(line.label) || line.label;
            let status = 'open';
            let statusLabel = `${formatBoardQty(line.remaining)} ${unitLabel}`;
            let detail = display;
            if (done) {
                status = 'done';
                statusLabel = 'reeds gereed';
                detail = `${display} – reeds gereed`;
            } else if (partial) {
                status = 'partial';
                statusLabel = `${formatBoardQty(line.remaining)} ${unitLabel}`;
                detail = `${display} – ${formatBoardQty(line.completed)} / ${formatBoardQty(line.ordered)} ${unitLabel} gereed`;
            }

            return {
                ...line,
                display_label: display,
                qty_label: `${formatBoardQty(done ? line.ordered : line.remaining)} ${unitLabel}`,
                status,
                status_label: statusLabel,
                detail,
                bookable: !done && line.remaining > 0,
            };
        })
        .sort((left, right) => familyRank(left) - familyRank(right)
            || left.label.localeCompare(right.label, 'nl'));
}

export function roomMeasureChipLabel(area) {
    const name = String(area?.unique_name || area?.name || area?.number || '').trim();
    const qty = String(area?.m2_label || '').trim();
    if (name && qty) {
        return `✓ ${name} · ${qty}`;
    }
    if (name) {
        return `✓ ${name}`;
    }
    if (qty) {
        return `✓ ${qty}`;
    }

    return '✓';
}

export function buildOutsourceSelection({
    project = {},
    floor = '',
    keys = [],
    measure = null,
    workerId = null,
    workerName = null,
    source = 'materials',
} = {}) {
    const result = measure || { lines: [], rooms: [], total: 0, unit: 'm2', label: formatBoardQty(0) + ' m²' };
    const totalM2 = result.total_m2 != null
        ? result.total_m2
        : (result.unit === 'm2' ? result.total : 0);

    return {
        source,
        project_id: project.id ?? null,
        project_name: project.name || '',
        project_number: project.number || '',
        floor: floor || '',
        floor_id: result.rooms.find((room) => room.floor_id != null)?.floor_id ?? null,
        materials: result.lines.map((line) => ({
            key: line.key,
            label: line.label,
            quantity: line.quantity,
            unit: line.unit,
        })),
        material_keys: [...keys],
        total_m2: totalM2,
        total_quantity: result.total,
        total_unit: result.unit,
        total_label: result.label,
        rooms: result.rooms.map((room) => ({
            id: room.id,
            number: room.number || '',
            name: room.name || '',
            floor: room.floor || floor || '',
            floor_id: room.floor_id ?? null,
        })),
        worker_id: workerId,
        worker_name: workerName,
    };
}

export function floorIdOf(area) {
    if (area?.floor_id == null || area.floor_id === '') {
        return 0;
    }

    return Number(area.floor_id);
}

export function groupRoomsByFloor(rooms) {
    const groups = new Map();
    rooms.forEach((room) => {
        const id = floorIdOf(room);
        if (!groups.has(id)) {
            groups.set(id, []);
        }
        groups.get(id).push(room);
    });

    return groups;
}

export function isEntireFloorPick(allAreas, pickedRooms, floorId, keys) {
    const pool = (allAreas || []).filter((area) => (
        floorIdOf(area) === Number(floorId)
        && areaMatchesWorkKeys(area, keys)
    ));
    if (pool.length === 0) {
        return false;
    }
    const picked = new Set((pickedRooms || []).map((room) => Number(room.id)));

    return pool.every((area) => picked.has(Number(area.id)));
}

export function buildTicketChunk({
    floor = '',
    floorId = 0,
    keys = [],
    rooms = [],
    entire = false,
    filters = [],
} = {}) {
    const measure = measureSelectedWorks(rooms, keys, filters);
    const numbers = rooms
        .map((room) => String(room.number || '').trim())
        .filter(Boolean)
        .sort((left, right) => left.localeCompare(right, 'nl', { numeric: true }));

    return {
        floor_id: Number(floorId) || 0,
        floor: floor || rooms[0]?.floor || '',
        entire: Boolean(entire),
        area_ids: rooms.map((room) => Number(room.id)).filter((id) => Number.isFinite(id) && id > 0),
        work_keys: [...keys],
        rooms_label: entire ? 'Hele verdieping' : numbers.join(', '),
        lines: measure.lines
            .filter((line) => line.quantity > 0)
            .map((line) => ({
                key: line.key,
                label: shortWorkLabel(line.label) || line.label,
                qty_label: line.qty_label,
            })),
    };
}

export function ticketStorePayload(chunks, extras = {}) {
    const extraIds = [...new Set((extras.extra_work_item_ids || [])
        .map((id) => Number(id))
        .filter((id) => Number.isFinite(id) && id > 0))];
    const shopActivityIds = [...new Set((extras.shop_work_activity_ids || [])
        .map((id) => Number(id))
        .filter((id) => Number.isFinite(id) && id > 0))];
    const selections = (chunks || []).map((chunk) => ({
        floor_id: chunk.floor_id,
        entire: chunk.entire ? 1 : 0,
        area_ids: [...(chunk.area_ids || [])],
        work_keys: [...(chunk.work_keys || [])],
    }));
    const payload = { ...extras };
    delete payload.extra_work_item_ids;
    delete payload.shop_work_activity_ids;
    delete payload.general_work;
    if (selections.length) {
        payload.selections = selections;
    }
    if (extraIds.length) {
        payload.extra_work_item_ids = extraIds;
    }
    if (shopActivityIds.length) {
        payload.shop_work_activity_ids = shopActivityIds;
    }
    if (extras.general_work) {
        payload.general_work = 1;
    }

    return payload;
}

export function ticketHasGeneralWork({ extraIds = [], general = false, shopActivityIds = [] } = {}) {
    return extraIds.length > 0 || Boolean(general) || shopActivityIds.length > 0;
}

export function workKeysOnRooms(rooms) {
    const keys = [];
    const seen = new Set();
    (rooms || []).forEach((room) => {
        (room.works || []).forEach((work) => {
            const key = String(work?.key || '');
            if (key === '' || seen.has(key)) {
                return;
            }
            seen.add(key);
            keys.push(key);
        });
    });

    return keys;
}

export function ticketRoomsToPick(areas, { keys = [], floor = null } = {}) {
    return (areas || []).filter((area) => {
        if (floor != null && floor !== '' && (area.floor || '') !== floor) {
            return false;
        }
        if (keys.length && !areaMatchesWorkKeys(area, keys)) {
            return false;
        }

        return Number(area?.id) > 0;
    });
}
