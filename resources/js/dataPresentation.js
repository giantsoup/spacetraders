import { formatLocalTime } from './dateTime.js';

const labels = {
    reserve: 'Protected credit reserve', maxTotal: 'Maximum cost', minTotal: 'Minimum sale proceeds',
    waypointSymbol: 'Destination', shipType: 'Ship type', symbol: 'Item', units: 'Quantity',
    site: 'Mining site', market: 'Sale market', nav: 'Navigation',
    cost_estimate: 'Estimated cost', sale_estimate: 'Estimated sale proceeds',
    fuel_estimate: 'Estimated fuel use', cargo_free: 'Free cargo space',
    quote_limit_warning: 'Price guidance', credit_delta: 'Credit change',
    transaction_total: 'Transaction total', reconciled_at: 'Last reconciled',
};
const creditFields = new Set([
    'reserve', 'maxTotal', 'minTotal', 'credits', 'purchasePrice', 'sellPrice', 'pricePerUnit',
    'totalPrice', 'cost_estimate', 'sale_estimate', 'credit_delta', 'transaction_total',
    'fuel_ceiling', 'fuel_cost', 'sale_proceeds', 'max_spend', 'max_loss', 'scouting_cost', 'minimum_reserve',
]);

export function readableLabel(key) {
    if (Object.hasOwn(labels, key)) return labels[key];
    const words = String(key).replace(/([a-z0-9])([A-Z])/g, '$1 $2').replaceAll('_', ' ').toLowerCase();
    return words.charAt(0).toUpperCase() + words.slice(1);
}

export function assetName(asset) {
    const item = asset && typeof asset === 'object' ? asset : null;
    const symbol = item ? item.symbol || item.type : asset;
    if (typeof symbol !== 'string' || !symbol) return 'Unknown';
    if (!/^[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/.test(symbol)) return symbol;
    const category = symbol.match(/^(MOUNT|MODULE|FRAME|REACTOR|ENGINE)_/)?.[1]
        || (item?.type === symbol && symbol.startsWith('SHIP_') ? 'SHIP' : null);
    const name = item?.name && item.name !== symbol ? readableText(item.name) : symbol.slice(category ? category.length + 1 : 0).split('_').map(word =>
        /^(I|II|III|IV|V|VI|VII|VIII|IX|X|AI|VR|UV)$/.test(word)
            ? word : word.charAt(0) + word.slice(1).toLowerCase(),
    ).join(' ');
    if (!category) return name;
    return `${readableLabel(category)}: ${name.replace(new RegExp(`^${category}(?:\\s*:\\s*|\\s+)`, 'i'), '')}`;
}

export function readableText(text) {
    return String(text ?? '').replace(/(?<![A-Za-z0-9_-])[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+(?![A-Za-z0-9_-])/g, symbol => assetName(symbol));
}

export function readableValue(value, key = '') {
    if (value === null || value === undefined) return 'Unknown';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value === 'number') {
        if (creditFields.has(key)) return `${value.toLocaleString()} CR`;
        if (['condition', 'integrity'].includes(key)) return `${Math.round(value * 100)}%`;
        return value.toLocaleString();
    }
    if (value === '') return 'Not provided';
    const localTime = formatLocalTime(value, null);
    if (localTime) return localTime;
    if (['shipType', 'type'].includes(key)) return assetName({ type: value });
    return /^[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/.test(value) ? assetName(value) : readableText(value);
}

export function cargoSummary(cargo) {
    if (cargo === null || cargo === undefined) return 'Unknown';
    const entries = Object.entries(cargo);
    return entries.length ? entries.map(([symbol, units]) => `${units.toLocaleString()} ${assetName(symbol).toLowerCase()}`).join(', ') : 'None';
}
