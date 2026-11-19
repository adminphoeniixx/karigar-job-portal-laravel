<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, ChevronDown, EyeOff, Plus, Sparkles, Tags, Trash2, X } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import SkillTagInput from '@/components/SkillTagInput.vue';

interface Category {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    skills: string[] | null;
    job_titles: string[] | null;
}

// A job title employers typed that no category lists yet.
interface CustomTitle {
    id: number;
    title: string;
    employers: number;
    jobs: number;
    // The category its jobs were posted under most.
    category: string | null;
    last_used: string;
}

const props = defineProps<{ categories: Category[]; customTitles: CustomTitle[] }>();

// Skills suggested on the job form once this category is picked, and the job
// titles offered in its title dropdown (picking one suggests this category).
const open = ref<number | null>(null);
const openTitles = ref<number | null>(null);
const skills = reactive<Record<number, string[]>>({});
const titles = reactive<Record<number, string[]>>({});

const sameList = (a: string[] = [], b: string[] = []) => a.length === b.length && a.every((x, i) => x === b[i]);

// Filled again on every reload of the list. A category added on this page
// arrives in the new props after setup, and a title added from the employers'
// list changes a category's titles on the server. An untouched list follows
// the server; one the admin is editing keeps their unsaved tags.
const sent: Record<number, { skills: string[]; titles: string[] }> = {};

watch(
    () => props.categories,
    (list) => {
        for (const c of list) {
            const fresh = { skills: [...(c.skills ?? [])], titles: [...(c.job_titles ?? [])] };
            const last = sent[c.id];

            if (!last || sameList(skills[c.id], last.skills)) skills[c.id] = [...fresh.skills];
            if (!last || sameList(titles[c.id], last.titles)) titles[c.id] = [...fresh.titles];
            sent[c.id] = fresh;
        }
    },
    { immediate: true },
);

const saveSkills = (c: Category) => {
    router.patch(`/admin/categories/${c.id}`, { skills: skills[c.id] }, { preserveScroll: true });
};

const saveTitles = (c: Category) => {
    router.patch(`/admin/categories/${c.id}`, { job_titles: titles[c.id] }, { preserveScroll: true });
};

defineOptions({ layout: { breadcrumbs: [{ title: 'Categories', href: '/admin/categories' }] } });

const form = useForm({ name: '' });

const add = () => {
    form.post('/admin/categories', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};

const toggle = (c: Category) => {
    router.patch(`/admin/categories/${c.id}`, { is_active: !c.is_active }, { preserveScroll: true });
};

const remove = (c: Category) => {
    if (window.confirm(`Delete "${c.name}"?`)) {
        router.delete(`/admin/categories/${c.id}`, { preserveScroll: true });
    }
};

// ── Job titles employers typed ──────────────────────────────────────
// Each row can be tidied before it joins a category, which starts as the one
// its jobs were posted under (when that category is live).
const activeCategories = computed(() => props.categories.filter((c) => c.is_active));
const drafts = reactive<Record<number, { title: string; categoryId: number | '' }>>({});
const customError = ref<string | null>(null);
const busy = ref<number | null>(null);

watch(
    () => props.customTitles,
    (list) => {
        for (const t of list) {
            drafts[t.id] ??= {
                title: t.title,
                categoryId: activeCategories.value.find((c) => c.name === t.category)?.id ?? '',
            };
        }
    },
    { immediate: true },
);

const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`;

const customAction = (t: CustomTitle, action: 'add' | 'hide', data: Record<string, string | number> = {}) => {
    customError.value = null;
    busy.value = t.id;
    router.post(`/admin/categories/custom-titles/${t.id}/${action}`, data, {
        preserveScroll: true,
        onError: (errors) => (customError.value = Object.values(errors)[0] ?? null),
        onFinish: () => (busy.value = null),
    });
};

const addCustom = (t: CustomTitle) => {
    const draft = drafts[t.id];
    customAction(t, 'add', { title: draft.title.trim(), category_id: draft.categoryId });
};
</script>

<template>
    <Head title="Categories" />

    <div class="flex w-full flex-col gap-6 p-4 md:p-6 lg:p-8">
        <PageHeader :icon="Tags" title="Job Categories" description="The master list employers pick from when posting jobs" />

        <!-- Add -->
        <form class="flex gap-2 rounded-2xl border bg-card p-4 shadow-sm" @submit.prevent="add">
            <div class="flex-1">
                <input
                    v-model="form.name"
                    placeholder="New category name (e.g. Roofing)"
                    class="w-full rounded-xl border bg-background px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40"
                />
                <p v-if="form.errors.name" class="mt-1 text-xs text-rose-500">{{ form.errors.name }}</p>
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="inline-flex h-[42px] shrink-0 items-center gap-1.5 rounded-xl bg-primary px-5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95 disabled:opacity-60"
            >
                <Plus class="size-4" /> Add
            </button>
        </form>

        <!-- Job titles employers typed that no category lists yet -->
        <section class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div class="flex flex-col gap-1 border-b px-5 py-4">
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <Sparkles class="size-4 text-orange-500" /> New job titles from employers
                    <span v-if="customTitles.length" class="rounded-full bg-orange-500/10 px-2 py-0.5 text-xs font-semibold text-orange-600 dark:text-orange-300">{{ customTitles.length }}</span>
                </h2>
                <p class="text-xs text-muted-foreground">
                    Titles employers added on the job form that no category lists. Add the good ones to a category, and the next employer finds them in the list. Hide the rest: a hidden title does not come back.
                </p>
                <p v-if="customError" class="text-xs text-rose-500">{{ customError }}</p>
            </div>
            <p v-if="customTitles.length === 0" class="px-5 py-6 text-center text-sm text-muted-foreground">Nothing new right now.</p>
            <template v-for="t in customTitles" :key="t.id">
                <div v-if="drafts[t.id]" class="flex flex-col gap-3 border-b px-5 py-3 last:border-0 md:flex-row md:items-center">
                    <div class="min-w-0 flex-1">
                        <input
                            v-model="drafts[t.id].title"
                            maxlength="60"
                            :aria-label="`Job title: ${t.title}`"
                            class="w-full rounded-lg border bg-background px-3 py-1.5 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-orange-500/40"
                        />
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ plural(t.employers, 'employer') }} · {{ plural(t.jobs, 'job') }} · last {{ t.last_used }}<template v-if="t.category"> · posted under {{ t.category }}</template>
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <select
                            v-model="drafts[t.id].categoryId"
                            :aria-label="`Category for ${t.title}`"
                            class="h-9 w-48 rounded-lg border bg-background px-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40"
                        >
                            <option value="">Add to category…</option>
                            <option v-for="c in activeCategories" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <button
                            :disabled="busy === t.id || drafts[t.id].categoryId === '' || !drafts[t.id].title.trim()"
                            class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary px-3 text-xs font-semibold text-white transition hover:opacity-90 disabled:opacity-50"
                            @click="addCustom(t)"
                        >
                            <Plus class="size-3.5" /> Add
                        </button>
                        <button
                            :disabled="busy === t.id"
                            class="inline-flex h-9 items-center gap-1.5 rounded-lg px-2.5 text-xs font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground disabled:opacity-50"
                            @click="customAction(t, 'hide')"
                        >
                            <EyeOff class="size-3.5" /> Hide
                        </button>
                    </div>
                </div>
            </template>
        </section>

        <!-- List -->
        <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div v-for="c in categories" :key="c.id" class="border-b last:border-0">
            <div class="flex items-center justify-between gap-3 px-5 py-3.5">
                <div class="flex items-center gap-3">
                    <span class="font-medium">{{ c.name }}</span>
                    <span
                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset"
                        :class="c.is_active ? 'bg-orange-500/10 text-orange-600 ring-orange-500/20 dark:text-orange-300' : 'bg-muted text-muted-foreground ring-border'"
                    >
                        {{ c.is_active ? 'Active' : 'Hidden' }}
                    </span>
                </div>
                <div class="flex items-center gap-1">
                    <button
                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground"
                        @click="openTitles = openTitles === c.id ? null : c.id"
                    >
                        Job titles ({{ titles[c.id]?.length ?? 0 }}) <ChevronDown class="size-3.5 transition" :class="openTitles === c.id ? 'rotate-180' : ''" />
                    </button>
                    <button
                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground"
                        @click="open = open === c.id ? null : c.id"
                    >
                        Skills ({{ skills[c.id]?.length ?? 0 }}) <ChevronDown class="size-3.5 transition" :class="open === c.id ? 'rotate-180' : ''" />
                    </button>
                    <button
                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground"
                        @click="toggle(c)"
                    >
                        <component :is="c.is_active ? X : Check" class="size-3.5" /> {{ c.is_active ? 'Hide' : 'Activate' }}
                    </button>
                    <button
                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-500/10 dark:text-rose-400"
                        @click="remove(c)"
                    >
                        <Trash2 class="size-3.5" /> Delete
                    </button>
                </div>
            </div>
            <!-- Job titles offered in the job form's title dropdown -->
            <div v-if="openTitles === c.id" class="flex flex-col gap-3 bg-muted/30 px-5 pb-4 pt-1">
                <p class="text-xs text-muted-foreground">
                    Offered as job profiles on the job form. Picking one, or typing a title that matches, suggests {{ c.name }} and its skills. Add local names too (e.g. Darzi).
                </p>
                <SkillTagInput v-model="titles[c.id]" placeholder="Add a job title and press Enter…" />
                <div class="flex justify-end">
                    <button class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-4 py-2 text-xs font-semibold text-white transition hover:opacity-90" @click="saveTitles(c)">
                        <Check class="size-3.5" /> Save job titles
                    </button>
                </div>
            </div>
            <!-- Skills employers are offered when they post a job in this category -->
            <div v-if="open === c.id" class="flex flex-col gap-3 bg-muted/30 px-5 pb-4 pt-1">
                <p class="text-xs text-muted-foreground">Suggested on the job form once an employer picks {{ c.name }}. They can still type their own.</p>
                <SkillTagInput v-model="skills[c.id]" placeholder="Add a skill and press Enter…" />
                <div class="flex justify-end">
                    <button class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-4 py-2 text-xs font-semibold text-white transition hover:opacity-90" @click="saveSkills(c)">
                        <Check class="size-3.5" /> Save skills
                    </button>
                </div>
            </div>
            </div>
            <div v-if="categories.length === 0" class="px-5 py-12 text-center text-sm text-muted-foreground">
                No categories yet — add your first one above.
            </div>
        </div>
    </div>
</template>
