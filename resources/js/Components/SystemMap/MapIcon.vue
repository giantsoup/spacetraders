<script setup>
// Pixel-grid glyphs for map controls. Drawn on a 12px grid and rendered without anti-aliasing.
defineProps({ name: { type: String, required: true } });
const glyphs = {
    plus: 'M5 2h2v8H5z M2 5h8v2H2z',
    minus: 'M2 5h8v2H2z',
    fit: 'M1 1h4v2H3v2H1z M7 1h4v4H9V3H7z M1 7h2v2h2v2H1z M9 7h2v4H7V9h2z',
    ship: 'M5 1h2v2H5z M5 9h2v2H5z M1 5h2v2H1z M9 5h2v2H9z M5 5h2v2H5z',
    list: 'M1 2h2v2H1z M5 2h6v2H5z M1 5h2v2H1z M5 5h6v2H5z M1 8h2v2H1z M5 8h6v2H5z',
    info: 'M5 1h2v2H5z M5 4h2v7H5z',
    deck: 'M1 2h10v1H1z M1 10h10v1H1z M1 3h1v7H1z M10 3h1v7h-1z M3 7h1v2H3z M5 5h1v4H5z M7 4h1v5H7z',
};
</script>
<template><svg class="map-icon" viewBox="0 0 12 12" width="12" height="12" shape-rendering="crispEdges" fill="currentColor" aria-hidden="true" focusable="false"><path :d="glyphs[name]" /></svg></template>
