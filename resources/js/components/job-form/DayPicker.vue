<script setup lang="ts">
// Days of the week as toggles, Monday first: working days, interview days.
import { WEEKDAYS, weekdayKey } from '@/lib/jobDetails';

const props = defineProps<{ modelValue: string[]; label?: string }>();
const emit = defineEmits<{ 'update:modelValue': [days: string[]] }>();

const toggle = (day: string) => {
    const next = props.modelValue.includes(day) ? props.modelValue.filter((d) => d !== day) : [...props.modelValue, day];
    emit('update:modelValue', WEEKDAYS.filter((d) => next.includes(d)));
};
</script>

<template>
    <div class="flex flex-wrap gap-1.5" role="group" :aria-label="label">
        <button
            v-for="day in WEEKDAYS"
            :key="day"
            type="button"
            :aria-pressed="modelValue.includes(day)"
            class="min-w-11 rounded-lg border px-2.5 py-1.5 text-xs font-semibold transition"
            :class="modelValue.includes(day) ? 'border-orange-500 bg-orange-500/10 text-orange-700 dark:text-orange-300' : 'text-muted-foreground hover:border-orange-300 hover:text-foreground'"
            @click="toggle(day)"
        >
            {{ $t(weekdayKey(day)) }}
        </button>
    </div>
</template>
