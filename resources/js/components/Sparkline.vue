<script setup lang="ts">
import { computed } from 'vue';
import { areaPath, chartPoints, linePath } from '@/lib/chart';

const props = withDefaults(
    defineProps<{
        points: number[];
        color: string;
        width?: number;
        height?: number;
    }>(),
    { width: 96, height: 36 },
);

const gid = `sp-${Math.random().toString(36).slice(2, 9)}`;
const line = computed(() => linePath(chartPoints(props.points, props.width, props.height)));
const area = computed(() => areaPath(line.value, props.width, props.height));
</script>

<template>
    <svg :width="width" :height="height" :viewBox="`0 0 ${width} ${height}`" fill="none" aria-hidden="true">
        <defs>
            <linearGradient :id="gid" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" :stop-color="color" stop-opacity="0.28" />
                <stop offset="100%" :stop-color="color" stop-opacity="0" />
            </linearGradient>
        </defs>
        <path :d="area" :fill="`url(#${gid})`" />
        <path :d="line" :stroke="color" stroke-width="2" stroke-linecap="round" />
    </svg>
</template>
