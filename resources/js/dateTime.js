const timestampPattern = /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/;
const formatter = new Intl.DateTimeFormat(undefined, {
    year: 'numeric', month: 'short', day: 'numeric',
    hour: 'numeric', minute: '2-digit', second: '2-digit', timeZoneName: 'short',
});

export const localTimeZone = formatter.resolvedOptions().timeZone;

export function formatLocalTime(value, fallback = 'Unknown') {
    if (typeof value !== 'string' || !timestampPattern.test(value)) return fallback;
    const normalized = value.replace(' ', 'T');
    const timestamp = Date.parse(/Z$|[+-]\d{2}:?\d{2}$/.test(normalized) ? normalized : `${normalized}Z`);
    return Number.isFinite(timestamp) ? formatter.format(timestamp) : fallback;
}

export function formatLocalJson(value) {
    return JSON.stringify(value, (key, entry) => typeof entry === 'string' && timestampPattern.test(entry)
        ? formatLocalTime(entry)
        : entry, 2);
}

export function formatLocalClock(value, fallback = 'Unknown') {
    if (typeof value !== 'string' || !timestampPattern.test(value)) return fallback;
    const normalized = value.replace(' ', 'T');
    const timestamp = Date.parse(/Z$|[+-]\d{2}:?\d{2}$/.test(normalized) ? normalized : `${normalized}Z`);
    return Number.isFinite(timestamp) ? new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit', second: '2-digit', timeZoneName: 'short' }).format(timestamp) : fallback;
}
