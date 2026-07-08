<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, Plus, Tags, Trash2, X } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';

interface Category {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
}

defineProps<{ categories: Category[] }>();

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
</script>

<template>
    <Head title="Categories" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="Tags" title="Job Categories" description="The master list employers pick from when posting jobs" />

        <!-- Add -->
        <form class="flex gap-2 rounded-2xl border bg-card p-4 shadow-sm" @submit.prevent="add">
            <div class="flex-1">
                <input
                    v-model="form.name"
                    placeholder="New category name (e.g. Roofing)"
                    class="w-full rounded-xl border bg-background px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/40"
                />
                <p v-if="form.errors.name" class="mt-1 text-xs text-rose-500">{{ form.errors.name }}</p>
            </div>
            <button
                type="submit"
                :disabled="form.processing"
                class="inline-flex h-[42px] shrink-0 items-center gap-1.5 rounded-xl bg-gradient-to-r from-teal-500 to-cyan-600 px-5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95 disabled:opacity-60"
            >
                <Plus class="size-4" /> Add
            </button>
        </form>

        <!-- List -->
        <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div v-for="c in categories" :key="c.id" class="flex items-center justify-between gap-3 border-b px-5 py-3.5 last:border-0">
                <div class="flex items-center gap-3">
                    <span class="font-medium">{{ c.name }}</span>
                    <span
                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset"
                        :class="c.is_active ? 'bg-teal-500/10 text-teal-600 ring-teal-500/20 dark:text-teal-300' : 'bg-muted text-muted-foreground ring-border'"
                    >
                        {{ c.is_active ? 'Active' : 'Hidden' }}
                    </span>
                </div>
                <div class="flex items-center gap-1">
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
            <div v-if="categories.length === 0" class="px-5 py-12 text-center text-sm text-muted-foreground">
                No categories yet — add your first one above.
            </div>
        </div>
    </div>
</template>
