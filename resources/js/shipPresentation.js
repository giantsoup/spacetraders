// These labels describe observations. Timers never grant command eligibility.
export const observedNumber = value => typeof value === 'number' && Number.isFinite(value) ? value.toLocaleString() : 'Unknown';
export const meterPercent = (current, capacity) => Number.isFinite(current) && Number.isFinite(capacity) && capacity > 0 ? Math.max(0, Math.min(100, current / capacity * 100)) : 0;
export function remaining(instant, now) {
    const deadline = Date.parse(instant);
    if (!Number.isFinite(deadline)) return 'Unknown';
    const seconds = Math.max(0, Math.ceil((deadline - now) / 1000));
    if (!seconds) return 'Checking';
    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}
export function shipPresentation(ship, observedAt, connection, now) {
    const stamp = Date.parse(observedAt);
    const stale = !Number.isFinite(stamp) || now - stamp > 180000 || connection !== 'connected';
    const transit = ship?.nav?.status === 'IN_TRANSIT';
    const arrival = remaining(ship?.nav?.route?.arrival, now);
    const cooldown = ship?.cooldown;
    const deadline = cooldown?.readyAt || cooldown?.expiration;
    const expiry = Date.parse(deadline);
    const readiness = cooldown?.remainingSeconds === 0 && (!Number.isFinite(expiry) || stamp >= expiry) ? 'Ready'
        : deadline ? remaining(deadline, now) : 'Unknown';
    const departure = Date.parse(ship?.nav?.route?.departureTime);
    const end = Date.parse(ship?.nav?.route?.arrival);
    const progress = transit && Number.isFinite(departure) && end > departure ? Math.max(0, Math.min(1, (now - departure) / (end - departure))) : null;
    return { stale, transit, arrival, readiness, progress,
        status: !ship ? 'No ship observed' : transit ? arrival === 'Checking' ? 'Checking arrival' : `Arriving at ${ship.nav.waypointSymbol || 'Unknown'}` : `${ship.nav?.status === 'DOCKED' ? 'Docked' : ship.nav?.status === 'IN_ORBIT' ? 'In orbit' : 'Navigation unknown'} at ${ship.nav?.waypointSymbol || 'Unknown'}`,
        age: Number.isFinite(stamp) ? `${Math.max(0, Math.floor((now - stamp) / 1000))}s ago` : 'Unknown',
    };
}
