<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string[];
        suggestions?: string[];
        placeholder?: string;
        id?: string;
    }>(),
    { suggestions: () => [], placeholder: 'Type a skill and press Enter…' },
);

const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>();

const draft = ref('');
const listId = `skill-tags-${Math.random().toString(36).slice(2, 8)}`;

const remaining = computed(() =>
    props.suggestions.filter((s) => !props.modelValue.some((v) => v.toLowerCase() === s.toLowerCase())),
);

const add = (raw: string) => {
    const value = raw.trim().replace(/,+$/, '').trim();
    if (!value) return;
    if (!props.modelValue.some((v) => v.toLowerCase() === value.toLowerCase())) {
        emit('update:modelValue', [...props.modelValue, value]);
    }
    draft.value = '';
};

const remove = (skill: string) => {
    emit('update:modelValue', props.modelValue.filter((v) => v !== skill));
};

// Datalist picks fire an input event with the full suggestion — add instantly.
const onInput = () => {
    if (draft.value.includes(',')) {
        draft.value.split(',').forEach(add);
        return;
    }
    if (remaining.value.some((s) => s.toLowerCase() === draft.value.trim().toLowerCase())) {
        add(draft.value);
    }
};

const onBackspace = () => {
    if (draft.value === '' && props.modelValue.length) {
        remove(props.modelValue[props.modelValue.length - 1]);
    }
};
</script>

<template>
    <div
        class="flex min-h-9 w-full flex-wrap items-center gap-1.5 rounded-md border border-input bg-transparent px-2 py-1.5 text-sm shadow-sm focus-within:border-orange-500 focus-within:ring-2 focus-within:ring-orange-500/20"
    >
        <span
            v-for="skill in modelValue"
            :key="skill"
            class="inline-flex items-center gap-1 rounded-full bg-orange-500/10 py-0.5 pl-2.5 pr-1 text-xs font-semibold text-orange-600 ring-1 ring-inset ring-orange-500/20 dark:text-orange-300"
        >
            {{ skill }}
            <button
                type="button"
                class="rounded-full p-0.5 transition hover:bg-orange-500/20"
                :aria-label="`Remove ${skill}`"
                @click="remove(skill)"
            >
                <X class="size-3" />
            </button>
        </span>
        <input
            :id="id"
            v-model="draft"
            :list="listId"
            :placeholder="modelValue.length ? 'Add more…' : placeholder"
            class="min-w-28 flex-1 bg-transparent py-0.5 outline-none placeholder:text-muted-foreground"
            @input="onInput"
            @keydown.enter.prevent="add(draft)"
            @keydown.backspace="onBackspace"
            @blur="add(draft)"
        />
        <datalist :id="listId">
            <option v-for="s in remaining" :key="s" :value="s" />
        </datalist>
    </div>
</template>
