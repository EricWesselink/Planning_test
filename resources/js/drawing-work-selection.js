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
    if (key === 'gietvloer' || label.includes('gietvloer') || key === 'coating') {
        return 'PU gietvloer';
    }
    if (key === 'plinten' || label.includes('plint')) {
        return 'Plinten';
    }

    return 'Overig';
}

const FAMILY_ORDER = ['', 'Marmoleum', 'PVC', 'Tapijt', 'Entreemat', 'PU gietvloer', 'Plinten', 'Overig'];
const ALWAYS_GROUP = new Set(['', 'Marmoleum', 'PVC']);

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
    if (!keys.length) {
        return true;
    }
    const have = rowKeys ?? (area?.works || []).map((work) => work.key);

    return keys.some((key) => have.includes(key));
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
