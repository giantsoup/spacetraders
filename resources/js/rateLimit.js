export function rateLimitSummary(snapshot) {
    if (!snapshot) return { level: 'unknown', label: 'Checking', message: 'Waiting for the local request budget.' };
    if (snapshot.mode === 'offline') return { level: 'unknown', label: 'Practice', message: 'Offline practice. These counters do not describe live API traffic.' };
    const buckets = Object.values(snapshot.buckets);
    const cooldown = Math.max(0, ...buckets.map(bucket => bucket.blocked_until - snapshot.as_of));
    if (cooldown > 0) return { level: 'danger', label: 'Cooling down', cooldown: Math.ceil(cooldown), message: 'Requests are paused. Wait for the cooldown before trying more actions.' };
    if (!snapshot.pacing_enabled) return { level: 'warning', label: 'Pacing off', message: 'Automatic request pacing is disabled. Slow down when traffic approaches the reported rate.' };
    if (!buckets.some(bucket => bucket.responses > 0)) return { level: 'unknown', label: 'No API data', message: 'No API responses have been recorded yet. Local capacity is an estimate.' };
    const forecasts = buckets.map(bucket => {
        const needed = Math.min(4, bucket.burst_capacity);
        const drain = bucket.requests_per_second_last_5 - bucket.sustained_per_second;
        return drain > 0 ? Math.max(0, (bucket.available - needed) / drain) : Infinity;
    });
    const soonest = Math.min(...forecasts);
    const pressure = buckets.some(bucket => bucket.available < Math.min(4, bucket.burst_capacity) + 2
        || bucket.requests_per_second_last_5 >= bucket.sustained_per_second * 0.8
        || bucket.requests_per_second_last_60 >= bucket.sustained_per_second * 0.8);
    if (pressure || soonest <= 15) return {
        level: 'warning', label: 'Slow down',
        message: Number.isFinite(soonest) && soonest <= 60
            ? `${soonest <= 1 ? 'Local discovery requests may already need to wait.' : `At the last 5 seconds' pace, local discovery requests may wait in about ${Math.ceil(soonest)} seconds.`} Pause extra reads or stop automation to ease traffic.`
            : 'Traffic is near the local budget. Pause extra reads or stop automation to leave room for manual actions.',
    };
    return { level: 'ok', label: 'Room available', message: 'Recent traffic is below the local budget. Extra traffic can still trigger an upstream limit.' };
}
