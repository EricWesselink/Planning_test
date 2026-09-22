export function planningWorkChoices(items) {
    return (Array.isArray(items) ? items : []).flatMap((item) => {
        const id = Number(item?.id);
        if (!Number.isFinite(id) || id <= 0) {
            return [];
        }

        const name = String(item?.name || '').trim();
        const group = String(item?.group || '').trim();
        const notes = String(item?.notes || '').trim();
        let label = name || group || 'Werk';
        if (notes !== '' && !label.toLowerCase().includes(notes.toLowerCase())) {
            label = `${label} — ${notes}`;
        }

        const memberIds = (Array.isArray(item?.member_ids) ? item.member_ids : [id])
            .map((value) => Number(value))
            .filter((value) => Number.isFinite(value) && value > 0);
        const ids = memberIds.includes(id) ? memberIds : [id, ...memberIds];

        return [{
            id,
            projectId: String(item?.project_id || ''),
            project: String(item?.project || ''),
            label,
            memberIds: ids.length > 0 ? ids : [id],
        }];
    });
}

export function planningProjectWorkItems(workItems, projectId) {
    const key = String(projectId ?? '').trim();
    if (key === '' || !workItems || typeof workItems !== 'object') {
        return [];
    }

    const rows = workItems[key] ?? workItems[Number(key)];

    return Array.isArray(rows) ? rows : [];
}

export function planningCheckedWorkIds(choices, openedIds) {
    const opened = new Set(
        (Array.isArray(openedIds) ? openedIds : [])
            .map((id) => Number(id))
            .filter((id) => Number.isFinite(id) && id > 0),
    );

    return (Array.isArray(choices) ? choices : []).flatMap((choice) => {
        if (!choice?.checked) {
            return [];
        }

        const members = new Set(
            (Array.isArray(choice.memberIds) ? choice.memberIds : [choice.id])
                .map((id) => Number(id))
                .filter((id) => Number.isFinite(id) && id > 0),
        );
        const kept = [...opened].filter((id) => members.has(id));
        if (kept.length > 0) {
            return kept;
        }

        const primary = Number(choice.id);

        return Number.isFinite(primary) && primary > 0 ? [primary] : [];
    });
}
