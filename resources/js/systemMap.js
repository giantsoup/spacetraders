export const MIN_ZOOM = 0.05;
export const MAX_ZOOM = 128;
const CLOSE_UP_RATIO = 16;
export const clamp = (value, low, high) => Math.max(low, Math.min(high, value));
export const isPlotted = point => Number.isFinite(point.x) && Number.isFinite(point.y);
export function project(point, camera, size) {
    return { x: size.width / 2 + (point.x - camera.x) * camera.scale, y: size.height / 2 - (point.y - camera.y) * camera.scale };
}
export function fitCamera(points, size) {
    const plotted = points.filter(isPlotted);
    if (!plotted.length) return { x: 0, y: 0, scale: 1 };
    const xs = plotted.map(p => p.x), ys = plotted.map(p => p.y);
    const minX = Math.min(...xs), maxX = Math.max(...xs), minY = Math.min(...ys), maxY = Math.max(...ys);
    return { x: (minX + maxX) / 2, y: (minY + maxY) / 2,
        scale: clamp(Math.min(Math.max(80, size.width - (size.width < 700 ? 96 : 180)) / Math.max(40, maxX - minX), Math.max(80, size.height - 180) / Math.max(40, maxY - minY)), MIN_ZOOM, MAX_ZOOM) };
}
export function closeUpZoom(fitScale) { return Math.min(MAX_ZOOM, fitScale * CLOSE_UP_RATIO); }
// Grow the artwork gradually while retaining a 48px touch target at system scale.
export function markerSize(scale, fitScale) { return 8 + 40 * clamp(Math.sqrt(scale / fitScale), 1, 4); }
export function zoomCamera(camera, factor, anchor, size, maxScale = MAX_ZOOM) {
    const scale = clamp(camera.scale * factor, MIN_ZOOM, maxScale);
    return { x: camera.x + (anchor.x - size.width / 2) * (1 / camera.scale - 1 / scale),
        y: camera.y - (anchor.y - size.height / 2) * (1 / camera.scale - 1 / scale), scale };
}
export function wheelZoomFactor(deltaY, deltaMode, height) {
    const pixels = deltaY * (deltaMode === 1 ? 16 : deltaMode === 2 ? height : 1);
    return Math.exp(-clamp(pixels, -200, 200) * .005);
}
export function panCamera(camera, dx, dy) {
    return { ...camera, x: camera.x - dx / camera.scale, y: camera.y + dy / camera.scale };
}
// Bound each cluster's diameter so a chain of nearby points cannot swallow a whole system.
// Separate hit targets in screen space; leader lines retain the actual world coordinates.
export function markerGroups(points, camera, size, diameter = 48) {
    const spacing = diameter + 8;
    const groups = [];
    for (const point of points.filter(isPlotted)) {
        const member = { ...project(point, camera, size), point };
        let group = groups.find(g => g.members.every(m => Math.hypot(m.x - member.x, m.y - member.y) < spacing));
        if (!group) { group = { members: [] }; groups.push(group); }
        group.members.push(member);
    }
    for (const group of groups) {
        group.members.sort((a, b) => a.point.symbol.localeCompare(b.point.symbol));
        group.key = group.members.map(m => m.point.symbol).join('|');
        group.x = group.members.reduce((n, m) => n + m.x, 0) / group.members.length;
        group.y = group.members.reduce((n, m) => n + m.y, 0) / group.members.length;
    }
    for (let pass = 0; pass < 12; pass++) for (let a = 0; a < groups.length; a++) for (let b = a + 1; b < groups.length; b++) {
        const first = groups[a], second = groups[b], dx = second.x - first.x, dy = second.y - first.y;
        const distance = Math.hypot(dx, dy);
        if (distance < spacing) {
            const shift = (spacing - distance) / 2, ux = distance ? dx / distance : 1, uy = distance ? dy / distance : 0;
            first.x -= ux * shift; first.y -= uy * shift; second.x += ux * shift; second.y += uy * shift;
        }
    }
    return groups;
}
export function mapLabels(groups, size, diameter = 48) {
    const radius = diameter / 2;
    const boxes = [];
    return groups.map(group => {
        const text = group.members.length > 1 ? `${group.members.length} locations` : group.members[0].point.symbol;
        const box = { x: group.x - text.length * 3.5, y: group.y + radius + 6, w: text.length * 7, h: 18 };
        const visible = box.x >= 8 && box.x + box.w < size.width - 8 && box.y < size.height - 24
            && !groups.some(other => other.key !== group.key && box.x < other.x + radius + 4 && box.x + box.w > other.x - radius - 4 && box.y < other.y + radius + 4 && box.y + box.h > other.y - radius - 4)
            && !boxes.some(b => Math.abs(b.x + b.w / 2 - box.x - box.w / 2) < (b.w + box.w) / 2 + 12 && Math.abs(b.y - box.y) < 24);
        if (visible) boxes.push(box);
        return { ...group, label: visible ? text : '', labelY: group.y + radius + 18 };
    });
}
