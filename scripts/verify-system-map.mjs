import assert from 'node:assert/strict';
import { test } from 'node:test';
import fs from 'node:fs';
import { fitCamera, zoomCamera, panCamera, project, markerGroups, mapLabels } from '../resources/js/systemMap.js';
import { waypointSprite, waypointFamilies } from '../resources/js/waypointSprites.js';
const size = { width: 1000, height: 600 };
test('fit keeps negative, positive, identical, and zero coordinates visible', () => {
    for (const points of [[], [{x: 0, y: 0}], [{x: -1000, y: 200}, {x: 1400, y: -100}], [{x: 5, y: 3}, {x: 5, y: 3}]]) {
        const camera = fitCamera([...points, { x: null, y: null }], size);
        assert.ok(Number.isFinite(camera.scale));
        for (const point of points) { const p = project(point, camera, size); assert.ok(p.x >= 80 && p.x <= 920 && p.y >= 80 && p.y <= 520); }
    }
});
test('zoom retains the anchored world position and clamps repeated wheel extremes', () => {
    const camera = { x: -10, y: 30, scale: 2 }, point = {x: 50, y: 80};
    const anchor = project(point, camera, size);
    assert.deepEqual(project(point, zoomCamera(camera, 4, anchor, size), size), anchor);
    assert.equal(zoomCamera(camera, 1e9, anchor, size).scale, 128);
    assert.equal(zoomCamera(camera, 1e-9, anchor, size).scale, .05);
    assert.deepEqual(project(point, panCamera(camera, 15, -4), size), {x: anchor.x + 15, y: anchor.y - 4});
});
test('overlapping and touching markers stay individually represented and split on zoom', () => {
    const points = [{symbol:'A', x:0, y:0}, {symbol:'B', x:0, y:0}, {symbol:'C', x:20, y:0}, {symbol:'D', x:40, y:0}];
    assert.equal(markerGroups(points, {x:0,y:0,scale:1}, size).length, 1);
    const groups = markerGroups(points, {x:0,y:0,scale:5}, size);
    assert.equal(groups.length, 3);
    assert.equal(groups.find(g => g.members.length === 2).members.length, 2);
    assert.equal(mapLabels(groups, size).length, 3);
    const chain = Array.from({length:30}, (_, i) => ({symbol:`P${i}`,x:i*20,y:0}));
    const bounded = markerGroups(chain, {x:0,y:0,scale:1}, size);
    assert.ok(bounded.length >= 10, 'A chain must not collapse into one cluster');
    assert.equal(bounded.flatMap(g => g.members).length, chain.length);
});
test('all published waypoint types have pixel families', () => {
    const schemas = JSON.parse(fs.readFileSync(new URL('../docs/api/spacetraders-openapi-2.3.0.json', import.meta.url))).components.schemas;
    assert.deepEqual(Object.keys(waypointFamilies).sort(), schemas.WaypointType.enum.toSorted());
    const designs = new Set();
    for (const type of schemas.WaypointType.enum) { const s = waypointSprite({symbol:'X1-MAP-A', type, traits:null}, 'reset'); assert.ok(s.pixels.length > 20); designs.add(JSON.stringify(s.pixels)); }
    assert.equal(designs.size, schemas.WaypointType.enum.length);
});
test('sprites are deterministic, distinct, neutral when unknown, and keep identity as knowledge grows', () => {
    const point = {symbol:'X1-MAP-A', type:'PLANET', traits:null};
    const neutral = waypointSprite(point, 'reset');
    assert.deepEqual(neutral, waypointSprite(point, 'reset'));
    const identity = s => s.pixels.filter(p => p.y === 0 || p.y === 23);
    const designs = new Set();
    for (const trait of ['FROZEN', 'OCEAN', 'VOLCANIC', 'JUNGLE', 'MEGA_CITY']) {
        const sprite = waypointSprite({...point, traits:[{symbol:trait}]}, 'reset');
        assert.equal(sprite.seed, neutral.seed); assert.deepEqual(identity(sprite), identity(neutral));
        assert.notDeepEqual(sprite.pixels, neutral.pixels); designs.add(JSON.stringify(sprite.pixels));
    }
    assert.equal(designs.size, 5);
    assert.notDeepEqual(neutral, waypointSprite({...point,symbol:'X1-MAP-B'}, 'reset'));
    assert.notDeepEqual(neutral, waypointSprite(point, 'another-reset'));
    assert.equal(waypointSprite({symbol:'X1-MAP-NEW', type:'NEW_TYPE', traits:null}).family, 'unknown');
    assert.deepEqual(neutral, waypointSprite({...point, traits:[]} ,'reset'));
    assert.deepEqual(neutral.pixels, waypointSprite({...point, traits:[{symbol:'MARKETPLACE', description:'Services and spices available.'}]} ,'reset').pixels);
});
