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

        return [{
            id,
            projectId: String(item?.project_id || ''),
            project: String(item?.project || ''),
            label,
        }];
    });
}
