// Identity controls geometry and texture. Knowledge controls the palette and explicit overlays only.
export const waypointFamilies = {
    PLANET: 'planet', GAS_GIANT: 'gas', MOON: 'moon', ORBITAL_STATION: 'station', JUMP_GATE: 'gate',
    ASTEROID_FIELD: 'rocks', ASTEROID: 'rock', ENGINEERED_ASTEROID: 'engineered', ASTEROID_BASE: 'base',
    NEBULA: 'nebula', DEBRIS_FIELD: 'debris', GRAVITY_WELL: 'gravity', ARTIFICIAL_GRAVITY_WELL: 'artificial', FUEL_STATION: 'fuel',
};
export function identityHash(value) {
    let hash = 2166136261;
    for (const char of value) hash = Math.imul(hash ^ char.charCodeAt(0), 16777619) >>> 0;
    return hash;
}
export function waypointSprite(point, reset = '') {
    const seed = identityHash(`${reset}|${point.symbol}`);
    const family = waypointFamilies[point.type] || 'unknown';
    const knowledge = [point.description || '', ...(point.traits || []).flatMap(t => [t.symbol || '', t.description || ''])].join(' ').replaceAll('_', ' ').toLowerCase();
    let palette = ['#657485', '#a3b2bf', '#dce4e9'];
    if (/\b(frozen|ice|icy)\b/.test(knowledge)) palette = ['#417fa3', '#89d4e8', '#e0f7ff'];
    else if (/\b(volcanic|volcano|magma|lava)\b/.test(knowledge)) palette = ['#5b4a58', '#d56441', '#ffc068'];
    else if (/\boceans?\b/.test(knowledge)) palette = ['#245b94', '#438bca', '#9ad6e8'];
    else if (/\b(jungle|lush)\b/.test(knowledge)) palette = ['#306d58', '#66a66b', '#b4d58b'];
    const city = /\b(city|urban|metropolis|megacities)\b/.test(knowledge);
    const noise = (x, y) => identityHash(`${seed}:${x}:${y}`);
    const pixels = [];
    for (let y = 1; y < 23; y++) for (let x = 1; x < 23; x++) {
        const dx = x - 11.5, dy = y - 11.5, radius = Math.hypot(dx, dy);
        const n = noise(x, y), circle = radius < 8.5 + (seed % 3) / 3;
        let fill = null;
        switch (family) {
        case 'planet': fill = circle ? palette[n % 13 < 4 ? 1 : dx + dy < -3 ? 2 : 0] : null; break;
        case 'moon': fill = radius < 7 ? palette[Math.hypot(dx + 2, dy - 1) < 2.5 ? 0 : dx + dy < 0 ? 2 : 1] : null; break;
        case 'gas': fill = circle ? palette[(y + seed) % 6 < 2 ? 2 : (y + seed) % 6 < 4 ? 1 : 0] : null; break;
        case 'rock': case 'engineered': case 'base':
            fill = radius < 7 + noise(Math.floor(x / 3), Math.floor(y / 3)) % 3 ? palette[n % 3] : null;
            if (fill && family !== 'rock' && (y === 11 || x === 12)) fill = '#b3a4dc';
            if (family === 'base' && x > 7 && x < 16 && y > 8 && y < 14) fill = y === 10 ? '#ebd7a0' : '#716c94';
            break;
        case 'rocks': case 'debris': fill = n % 17 < 3 && radius < 11 ? (family === 'debris' ? '#b9a490' : palette[n % 3]) : null; break;
        case 'station': case 'fuel':
            if ((Math.abs(dx) < 3 && Math.abs(dy) < 8) || (Math.abs(dy) < 2 && Math.abs(dx) < 11)) fill = palette[n % 3];
            if (x > 8 && x < 15 && y > 8 && y < 15) fill = family === 'fuel' ? '#dfbd71' : '#a99ad5';
            break;
        case 'gate': case 'gravity': case 'artificial':
            if (radius > 6 && radius < 9.5) fill = family === 'gravity' ? '#9a8cc4' : '#77bfce';
            if (family === 'gate' && Math.abs(dx) < 2 && Math.abs(dy) > 7) fill = '#e1c48a';
            if (family === 'artificial' && (x === 3 || x === 20) && Math.abs(dy) < 5) fill = '#d9dfeb';
            break;
        case 'nebula': fill = radius < 9 + n % 3 && n % 4 !== 0 ? ['#5a577e', '#8d73a1', '#b5a2c7'][n % 3] : null; break;
        default: if (Math.abs(dx) + Math.abs(dy) < 9 && Math.abs(dx) + Math.abs(dy) > 6) fill = palette[1];
        }
        if (fill && city && ['planet', 'moon'].includes(family) && n % 11 === 0) fill = '#f8d58b';
        if (fill) pixels.push({ x, y, fill });
    }
    // An identity barcode remains identical as type or trait knowledge grows. Cosmetic only.
    for (let bit = 0; bit < 32; bit++) if ((seed >>> bit) & 1) pixels.push({ x: 4 + bit % 16, y: bit < 16 ? 0 : 23, fill: '#8c9bac' });
    return { family, seed, pixels };
}
