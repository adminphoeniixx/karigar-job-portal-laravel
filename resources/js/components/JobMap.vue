<script setup lang="ts">
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import iconRetinaUrl from 'leaflet/dist/images/marker-icon-2x.png';
import iconUrl from 'leaflet/dist/images/marker-icon.png';
import shadowUrl from 'leaflet/dist/images/marker-shadow.png';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Vite rewrites asset URLs, so the default icon paths must be wired up manually
// (and Leaflet's own URL detection disabled first, or it wins over mergeOptions).
delete (L.Icon.Default.prototype as unknown as { _getIconUrl?: unknown })._getIconUrl;
L.Icon.Default.mergeOptions({ iconUrl, iconRetinaUrl, shadowUrl });

const props = withDefaults(
    defineProps<{
        lat: number | null;
        lng: number | null;
        editable?: boolean;
        height?: string;
        zoom?: number;
    }>(),
    { editable: false, height: '260px', zoom: 13 },
);

const emit = defineEmits<{ move: [lat: number, lng: number] }>();

const el = ref<HTMLElement | null>(null);
let map: L.Map | null = null;
let marker: L.Marker | null = null;

// Centre of India as the fallback view when no location is set yet.
const FALLBACK: [number, number] = [22.9734, 78.6569];

const place = (lat: number, lng: number, pan = true) => {
    if (!map) return;
    if (!marker) {
        marker = L.marker([lat, lng], { draggable: props.editable }).addTo(map);
        if (props.editable) {
            marker.on('dragend', () => {
                const p = marker!.getLatLng();
                emit('move', +p.lat.toFixed(7), +p.lng.toFixed(7));
            });
        }
    } else {
        marker.setLatLng([lat, lng]);
    }
    if (pan) map.setView([lat, lng], Math.max(map.getZoom(), props.zoom));
};

onMounted(() => {
    const hasPoint = props.lat != null && props.lng != null;
    map = L.map(el.value as HTMLElement, { scrollWheelZoom: props.editable }).setView(
        hasPoint ? [props.lat as number, props.lng as number] : FALLBACK,
        hasPoint ? props.zoom : 5,
    );
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(map);

    if (hasPoint) place(props.lat as number, props.lng as number, false);

    if (props.editable) {
        map.on('click', (e: L.LeafletMouseEvent) => {
            place(e.latlng.lat, e.latlng.lng, false);
            emit('move', +e.latlng.lat.toFixed(7), +e.latlng.lng.toFixed(7));
        });
    }
});

watch(
    () => [props.lat, props.lng],
    ([lat, lng]) => {
        if (lat != null && lng != null) place(Number(lat), Number(lng));
    },
);

onBeforeUnmount(() => {
    map?.remove();
    map = null;
    marker = null;
});
</script>

<template>
    <div ref="el" class="z-0 w-full overflow-hidden rounded-xl border" :style="{ height }"></div>
</template>
