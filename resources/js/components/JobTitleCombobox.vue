<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';

export interface JobProfile {
    title: string;
    category: string;
}

const props = withDefaults(
    defineProps<{
        modelValue: string;
        profiles: JobProfile[];
        id?: string;
        placeholder?: string;
        required?: boolean;
        // A job that has been live keeps its title.
        disabled?: boolean;
    }>(),
    { placeholder: '', required: false, disabled: false },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
    // A job profile from the list, or null when the employer adds a title of their own.
    pick: [profile: JobProfile | null];
}>();

// The job title box: the list's titles that match what is typed, with their
// category, and "+ Add" at the end to keep a title the list does not have.
// What is typed is the title as soon as it is typed; picking only tidies it.
const MAX_SHOWN = 8;
const open = ref(false);
// The highlighted row. The "+ Add" row comes after the matches.
const active = ref(-1);
const listId = `job-titles-${Math.random().toString(36).slice(2, 8)}`;

const typed = computed(() => props.modelValue.trim());
const query = computed(() => typed.value.toLowerCase());
const exact = computed(() => props.profiles.find((p) => p.title.toLowerCase() === query.value) ?? null);

// Titles holding what was typed: those starting with it first, then those
// with a word starting with it, then the rest, in the list's own order.
const matches = computed(() => {
    const q = query.value;
    if (!q) return [];

    const rank = (title: string) => {
        const t = title.toLowerCase();
        if (t === q) return 0;
        if (t.startsWith(q)) return 1;
        if (t.split(/[\s/()-]+/).some((word) => word.startsWith(q))) return 2;
        return t.includes(q) ? 3 : -1;
    };

    return props.profiles
        .map((profile, order) => ({ profile, order, rank: rank(profile.title) }))
        .filter((m) => m.rank >= 0)
        .sort((a, b) => a.rank - b.rank || a.order - b.order)
        .slice(0, MAX_SHOWN)
        .map((m) => m.profile);
});

const canAdd = computed(() => typed.value !== '' && exact.value === null);
const rows = computed(() => matches.value.length + (canAdd.value ? 1 : 0));
const showList = computed(() => open.value && rows.value > 0);

// A title split around the typed text, so the matched part can be bold.
const parts = (title: string) => {
    const at = title.toLowerCase().indexOf(query.value);
    if (!query.value || at < 0) return [title, '', ''];
    return [title.slice(0, at), title.slice(at, at + query.value.length), title.slice(at + query.value.length)];
};

const onInput = (event: Event) => {
    emit('update:modelValue', (event.target as HTMLInputElement).value);
    open.value = true;
    active.value = -1;
};

const choose = (profile: JobProfile) => {
    emit('update:modelValue', profile.title);
    emit('pick', profile);
    open.value = false;
};

const addOwn = () => {
    emit('update:modelValue', typed.value);
    emit('pick', null);
    open.value = false;
};

const move = (step: number) => {
    if (!showList.value) {
        open.value = true;
        return;
    }
    active.value = (active.value + step + rows.value) % rows.value;
};

// Enter picks the highlighted row, or the exact match, or adds the typed
// title. It never posts the form from this box.
const onEnter = () => {
    if (showList.value && active.value >= 0 && active.value < matches.value.length) {
        choose(matches.value[active.value]);
    } else if (exact.value) {
        choose(exact.value);
    } else if (canAdd.value) {
        addOwn();
    } else {
        open.value = false;
    }
};

// Leaving the box keeps whatever was typed. An exact match still counts as a
// pick, so its category is filled straight away.
const onBlur = () => {
    open.value = false;
    if (exact.value) choose(exact.value);
};
</script>

<template>
    <div class="relative">
        <input
            :id="id"
            :value="modelValue"
            type="text"
            role="combobox"
            autocomplete="off"
            aria-autocomplete="list"
            :aria-expanded="showList"
            :aria-controls="listId"
            :aria-activedescendant="showList && active >= 0 ? `${listId}-${active}` : undefined"
            :placeholder="placeholder"
            :required="required"
            :disabled="disabled"
            class="h-9 w-full disabled:cursor-not-allowed disabled:opacity-60 min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none transition-[color,box-shadow] placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30 md:text-sm"
            @input="onInput"
            @focus="open = true"
            @blur="onBlur"
            @keydown.down.prevent="move(1)"
            @keydown.up.prevent="move(-1)"
            @keydown.enter.prevent="onEnter"
            @keydown.esc="open = false"
        />
        <!-- mousedown keeps the focus in the box, so a click lands on the row -->
        <ul
            v-if="showList"
            :id="listId"
            role="listbox"
            class="absolute z-30 mt-1 max-h-72 w-full overflow-auto rounded-md border bg-popover p-1 text-sm text-popover-foreground shadow-lg"
            @mousedown.prevent
        >
            <li
                v-for="(p, i) in matches"
                :id="`${listId}-${i}`"
                :key="`${p.category}-${p.title}`"
                role="option"
                :aria-selected="active === i"
                class="flex cursor-pointer items-center justify-between gap-3 rounded px-2.5 py-1.5"
                :class="active === i ? 'bg-muted' : 'hover:bg-muted/60'"
                @mouseenter="active = i"
                @click="choose(p)"
            >
                <span class="truncate">{{ parts(p.title)[0] }}<strong class="font-semibold">{{ parts(p.title)[1] }}</strong>{{ parts(p.title)[2] }}</span>
                <span class="shrink-0 text-xs text-muted-foreground">{{ p.category }}</span>
            </li>
            <li
                v-if="canAdd"
                :id="`${listId}-${matches.length}`"
                role="option"
                :aria-selected="active === matches.length"
                class="flex cursor-pointer items-center gap-1.5 rounded px-2.5 py-1.5 font-medium text-orange-600 dark:text-orange-300"
                :class="[active === matches.length ? 'bg-muted' : 'hover:bg-muted/60', matches.length ? 'mt-1 border-t pt-2' : '']"
                @mouseenter="active = matches.length"
                @click="addOwn"
            >
                <Plus class="size-3.5 shrink-0" />
                <span class="truncate">{{ $t('jobForm.titleAdd', { title: typed }) }}</span>
            </li>
        </ul>
    </div>
</template>
