export function resourceSummary(current, capacity, absentLabel) {
    const hasCapacity = Number.isFinite(capacity) && capacity > 0;
    const observed = value => Number.isFinite(value) ? value.toLocaleString() : 'Unknown';
    return {
        text: capacity === 0 ? absentLabel : `${observed(current)} / ${observed(capacity)}`,
        hasCapacity,
        percent: hasCapacity && Number.isFinite(current) ? Math.max(0, Math.min(100, current / capacity * 100)) : null,
    };
}

export function equipmentSummary(equipment) {
    if (!Array.isArray(equipment)) return 'Not observed';
    return equipment.length ? `${equipment.length} installed` : 'None installed';
}
