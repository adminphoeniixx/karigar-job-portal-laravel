<script setup lang="ts" generic="T extends string | boolean">
// One choice out of a few, as tappable chips (WorkIndia-style). The option
// the form filled in from similar jobs carries a small "most chosen" tag.
export interface Choice<V> {
    value: V;
    label: string;
    desc?: string;
}

defineProps<{
    modelValue: T | null | undefined;
    options: Choice<T>[];
    // The value filled in from similar jobs, tagged on its chip.
    suggested?: T | null;
    suggestedLabel?: string;
    name: string;
    // Cards with a line under the label instead of compact chips.
    cards?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: T] }>();
</script>

<template>
    <div :class="cards ? 'grid gap-3 sm:grid-cols-2' : 'flex flex-wrap gap-2'" role="radiogroup">
        <label
            v-for="o in options"
            :key="String(o.value)"
            class="cursor-pointer transition focus-within:ring-2 focus-within:ring-orange-500/30"
            :class="[
                cards ? 'flex flex-col gap-1 rounded-xl border p-4' : 'inline-flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-sm font-medium',
                modelValue === o.value
                    ? cards
                        ? 'border-orange-500 bg-orange-500/5 ring-2 ring-orange-500/20'
                        : 'border-orange-500 bg-orange-500/10 text-orange-700 dark:text-orange-300'
                    : cards
                      ? 'hover:border-orange-300'
                      : 'text-muted-foreground hover:border-orange-300 hover:text-foreground',
            ]"
        >
            <input type="radio" class="sr-only" :name="name" :checked="modelValue === o.value" @change="emit('update:modelValue', o.value)" />
            <span :class="cards ? 'flex flex-wrap items-center gap-2 text-sm font-semibold' : 'contents'">
                {{ o.label }}
                <span
                    v-if="suggestedLabel && suggested !== undefined && suggested !== null && suggested === o.value"
                    class="rounded-full bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-emerald-700 dark:text-emerald-300"
                >
                    {{ suggestedLabel }}
                </span>
            </span>
            <span v-if="cards && o.desc" class="text-xs text-muted-foreground">{{ o.desc }}</span>
        </label>
    </div>
</template>
