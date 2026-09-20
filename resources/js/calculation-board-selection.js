export function roomMatchesFilter(room, filter) {
    if (filter === 'review') {
        return Boolean(room?.needs_review);
    }
    if (filter === 'floors') {
        return Boolean(room?.has_floor);
    }
    if (filter === 'plinths') {
        return Boolean(room?.has_plinth);
    }

    return true;
}

export function roomMatchesSearch(room, query) {
    const needle = String(query || '').trim().toLowerCase();
    if (needle === '') {
        return true;
    }

    return String(room?.search || '').includes(needle);
}

export function roomMatchesMaterials(room, keys) {
    if (!keys || keys.length === 0) {
        return true;
    }
    const materialKeys = Array.isArray(room?.material_keys) && room.material_keys.length > 0
        ? room.material_keys
        : [room?.material_key];

    return materialKeys.some((key) => keys.includes(String(key || '').toLowerCase()));
}

export function materialLabel(keys, materials) {
    if (!keys || keys.length === 0) {
        return 'Alle materialen';
    }
    if (keys.length === 1) {
        const match = (materials || []).find((item) => item.key === keys[0]);

        return match?.label || keys[0];
    }

    return `${keys.length} materialen`;
}

export function roomOverlayContent(room) {
    const number = String(room?.number || '').trim();
    const name = String(room?.name || '').trim();
    const code = String(room?.floor_codes_label || room?.floor_code || '').trim();
    const finishBits = (room?.floors || [])
        .map((finish) => [finish.product, finish.code, finish.quantity_label].filter(Boolean).join(' '))
        .filter(Boolean);

    return {
        number,
        name,
        code,
        title: [number, name, room?.m2_label, ...(finishBits.length > 0 ? finishBits : [code])].filter(Boolean).join(' · '),
    };
}

export function overlayContrast(hex) {
    const value = String(hex || '').replace('#', '');
    if (!/^[0-9a-f]{6}$/i.test(value)) {
        return { bg: hex || '#e7e5e4', fg: '#1c1917' };
    }
    const red = parseInt(value.slice(0, 2), 16);
    const green = parseInt(value.slice(2, 4), 16);
    const blue = parseInt(value.slice(4, 6), 16);
    const luma = ((0.299 * red) + (0.587 * green) + (0.114 * blue)) / 255;

    return {
        bg: `#${value}`,
        fg: luma > 0.62 ? '#1c1917' : '#fff',
    };
}

export function roomDrawingState(room, options = {}) {
    const selected = String(room?.key) === String(options.selectedKey || '');
    const listVisible = roomMatchesFilter(room, options.filter || 'all')
        && roomMatchesSearch(room, options.search);
    const highlighted = roomMatchesMaterials(room, options.materialKeys);

    return {
        show: listVisible || selected,
        selected,
        highlighted,
        filteredOut: !highlighted && !selected,
        fillAlpha: selected ? 0.28 : (highlighted ? 0.18 : 0.05),
    };
}

export function materialFillBox(box) {
    if (!box) {
        return null;
    }
    const width = Number(box.w) || 0;
    const height = Number(box.h) || 0;
    const padX = Math.min(0.018, Math.max(0.008, width * 0.45));
    const padY = Math.min(0.022, Math.max(0.01, height * 1.1));
    const x = Math.max(0, Number(box.x) - padX);
    const y = Math.max(0, Number(box.y) - padY);

    return {
        x,
        y,
        w: Math.min(1 - x, width + padX * 2),
        h: Math.min(1 - y, height + padY * 2),
    };
}

export function qtyInput(value) {
    if (value == null || value === '') {
        return '';
    }
    const number = Number(value);
    if (!Number.isFinite(number)) {
        return '';
    }

    return String(number).replace('.', ',');
}

export function needsLocalAreaInput(group, canUpdate = false) {
    if (!canUpdate || !group) {
        return false;
    }
    if (group.needs_local_area === true) {
        return true;
    }
    if (group.needs_local_area === false) {
        return false;
    }

    return String(group.role || '') === 'local' && (group.quantity == null || group.quantity === '');
}

export function localAreaPatchBody(finishId, quantity) {
    return {
        floors: [{
            id: Number(finishId),
            quantity,
        }],
    };
}
