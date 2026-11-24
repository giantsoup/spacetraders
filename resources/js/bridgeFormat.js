import { onMounted, onUnmounted, ref } from 'vue';
import { formatLocalClock, formatLocalTime } from '@/dateTime.js';

const compact = new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 2 });
const exact = new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 });
const dateLabel = new Intl.DateTimeFormat(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
const resetClock = new Intl.DateTimeFormat(undefined, { weekday: 'short', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' });

export const validNumber = value => typeof value === 'number' && Number.isFinite(value);
export const amount = value => validNumber(value) ? exact.format(value).replace(/^-/, '−') : '—';
export const shortAmount = value => validNumber(value) ? compact.format(value).replace(/^-/, '−') : '—';
export const signed = (value, format = amount) => value > 0 ? `+${format(value)}` : format(value);
export const tone = value => value > 0 ? 'bridge-positive' : value < 0 ? 'bridge-negative' : '';
export const words = value => typeof value === 'string' ? value.replaceAll('_', ' ') : '';
export const label = value => {
    const text = words(value);
    return text ? text[0].toUpperCase() + text.slice(1) : '';
};
export const commodity = value => label(value?.toLowerCase());
export const waypoint = value => value?.split('-').at(-1) || 'Unknown';
export const identity = ship => typeof ship === 'string' ? { name: ship, symbol: ship } : {
    name: ship?.name || ship?.display_name || ship?.symbol,
    symbol: ship?.symbol,
};

export function timestamp(value) {
    if (typeof value !== 'string') return NaN;
    const iso = value.replace(' ', 'T');
    return Date.parse(/Z$|[+-]\d{2}:?\d{2}$/.test(iso) ? iso : iso + 'Z');
}

export function elapsed(value, now) {
    const date = timestamp(value);
    if (!Number.isFinite(date)) return '';
    const seconds = Math.max(0, Math.floor((now - date) / 1000));
    if (seconds < 60) return `${seconds} s ago`;
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes} min ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours} h ago`;
    return `${Math.floor(hours / 24)} d ago`;
}

export function until(value, now) {
    const date = timestamp(value);
    if (!Number.isFinite(date)) return '—';
    const seconds = Math.ceil((date - now) / 1000);
    if (seconds <= 0) return 'Checking';
    const hours = Math.floor(seconds / 3600);
    if (hours >= 24) return `${Math.floor(hours / 24)}d ${hours % 24}h`;
    if (hours) return `${hours}h ${Math.floor(seconds / 60) % 60}m`;
    return seconds < 60 ? `${seconds} s` : `${Math.ceil(seconds / 60)} min`;
}

export const fullTime = value => formatLocalTime(value, '—');
export const clock = value => formatLocalClock(value, '—');
export const scheduledTime = value => Number.isFinite(timestamp(value)) ? resetClock.format(timestamp(value)) : '';

export function localDay(value, now) {
    const time = timestamp(value);
    if (!Number.isFinite(time)) return 'Date unavailable';
    const date = new Date(time);
    const today = new Date(now);
    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);
    const caption = dateLabel.format(date);
    if (date.toDateString() === today.toDateString()) return `Today · ${caption}`;
    if (date.toDateString() === yesterday.toDateString()) return `Yesterday · ${caption}`;
    return caption;
}

const shipPattern = /^[A-Z0-9_]+(?:-[A-Z0-9_]+)*-[A-Z0-9]+$/;
const waypointPattern = /^[A-Z0-9-]+$/;

export const bridgeHref = (page, agent, params = {}) => route(`campaign.bridge.${page}`, { ...params, agent: agent || undefined });

export function routeParts(key) {
    if (typeof key !== 'string') return null;
    const [good, source, buyer, extra] = key.split('|');
    if (extra !== undefined || !/^[A-Z0-9_]+$/.test(good) || !waypointPattern.test(source || '') || !waypointPattern.test(buyer || '')) return null;
    return { good, source, buyer };
}

export function routeHref(key, agent, from) {
    const parts = routeParts(key);
    return parts ? bridgeHref('route', agent, { ...parts, from }) : null;
}

export function shipHref(ship, agent, from) {
    return typeof ship === 'string' && shipPattern.test(ship) ? bridgeHref('ship', agent, { ship, from }) : null;
}

export function detailBack(from, tab, agent, fromShip) {
    if (from === 'brief') return { label: 'Brief', href: bridgeHref('brief', agent) };
    if (from === 'log') return { label: 'Log', href: bridgeHref('log', agent) };
    if (from === 'probes' && tab === 'fleet') return { label: 'Probes', href: bridgeHref('probes', agent) };
    if (from === 'ended' && tab === 'routes') return { label: 'Ended routes', href: bridgeHref('routes.ended', agent) };
    if (typeof from === 'string' && from.startsWith('ship:')) {
        const symbol = from.slice('ship:'.length);
        const href = shipHref(symbol, agent);
        if (href) {
            const ship = identity(fromShip);
            return { label: ship.symbol === symbol ? ship.name : symbol, href };
        }
    }
    if (typeof from === 'string' && from.startsWith('route:')) {
        const key = from.slice('route:'.length);
        const href = routeHref(key, agent);
        if (href) return { label: commodity(routeParts(key).good), href };
    }
    return { label: label(tab), href: bridgeHref(tab, agent) };
}

export function currentFrom(url) {
    return new URL(url, 'http://bridge.local').searchParams.get('from');
}

export const strategyHref = agent => route('campaign.strategy', { agent, from: 'bridge' });

export function reviewFor(reviews, kind, grant) {
    if (kind === 'arm_resume' || kind === 'arm_retire') {
        return reviews?.arms?.[grant]?.[kind === 'arm_resume' ? 'resume' : 'retire'] ?? null;
    }
    if (['primary_resume', 'fleet_resume', 'fleet_pause'].includes(kind)) return reviews?.[kind] ?? null;
    return null;
}

export function useSplitView() {
    const split = ref(false);
    let query;
    const update = () => { split.value = query.matches; };
    onMounted(() => {
        query = window.matchMedia('(min-width: 1200px)');
        update();
        query.addEventListener('change', update);
    });
    onUnmounted(() => query?.removeEventListener('change', update));
    return split;
}
