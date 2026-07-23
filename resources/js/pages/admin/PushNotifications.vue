<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Bell, Check, Send, Users } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

interface Campaign {
    id: number;
    title: string;
    body: string;
    audience: string;
    audience_label: string;
    recipients_count: number;
    sent_count: number;
    failed_count: number;
    created_by: string | null;
    created_at: string | null;
}

interface WorkerHit {
    id: number;
    name: string;
    phone: string | null;
}

const props = defineProps<{
    campaigns: Campaign[];
    cities: string[];
    categories: string[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Push Notifications', href: '/admin/push-notifications' }] } });

const form = useForm({
    title: '',
    body: '',
    audience: 'all',
    worker_id: null as number | null,
    city: '',
    category: '',
    url: '',
});

const audiences = [
    { value: 'all', label: 'All workers' },
    { value: 'worker', label: 'Specific worker' },
    { value: 'city', label: 'By city' },
    { value: 'category', label: 'By category' },
];

// --- Specific-worker live search ---
const workerQuery = ref('');
const workerResults = ref<WorkerHit[]>([]);
const selectedWorker = ref<WorkerHit | null>(null);
let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(workerQuery, (q) => {
    clearTimeout(searchTimer);
    if (selectedWorker.value && q === workerLabel(selectedWorker.value)) return;
    searchTimer = setTimeout(async () => {
        const res = await fetch(`/admin/push-notifications/workers?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json' },
        });
        const data = await res.json();
        workerResults.value = data.workers ?? [];
    }, 250);
});

const workerLabel = (w: WorkerHit) => `${w.name}${w.phone ? ` · ${w.phone}` : ''}`;

const pickWorker = (w: WorkerHit) => {
    selectedWorker.value = w;
    form.worker_id = w.id;
    workerQuery.value = workerLabel(w);
    workerResults.value = [];
};

const canSubmit = computed(() => {
    if (!form.title.trim() || !form.body.trim()) return false;
    if (form.audience === 'worker') return !!form.worker_id;
    if (form.audience === 'city') return !!form.city;
    if (form.audience === 'category') return !!form.category;
    return true;
});

const submit = () => {
    form.post('/admin/push-notifications', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            workerQuery.value = '';
            selectedWorker.value = null;
            workerResults.value = [];
        },
    });
};

const formatDate = (iso: string | null) =>
    iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '';
</script>

<template>
    <Head title="Push Notifications" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="Bell" title="Push Notifications" description="Send a push notification to workers' phones" />

        <!-- Compose -->
        <form class="flex flex-col gap-4 rounded-2xl border bg-card p-5 shadow-sm" @submit.prevent="submit">
            <div>
                <label class="mb-1 block text-sm font-medium">Title</label>
                <input
                    v-model="form.title"
                    maxlength="120"
                    placeholder="e.g. New jobs near you"
                    class="w-full rounded-xl border bg-background px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40"
                />
                <p v-if="form.errors.title" class="mt-1 text-xs text-rose-500">{{ form.errors.title }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">Message</label>
                <textarea
                    v-model="form.body"
                    rows="3"
                    maxlength="500"
                    placeholder="Write the notification message workers will see…"
                    class="w-full rounded-xl border bg-background px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40"
                />
                <p v-if="form.errors.body" class="mt-1 text-xs text-rose-500">{{ form.errors.body }}</p>
            </div>

            <!-- Audience -->
            <div>
                <label class="mb-1.5 block text-sm font-medium">Send to</label>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="a in audiences"
                        :key="a.value"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-xl border px-3.5 py-2 text-sm font-medium transition"
                        :class="form.audience === a.value
                            ? 'border-orange-500 bg-orange-500/10 text-orange-600 dark:text-orange-300'
                            : 'border-border text-muted-foreground hover:bg-muted'"
                        @click="form.audience = a.value"
                    >
                        <Check v-if="form.audience === a.value" class="size-3.5" />
                        {{ a.label }}
                    </button>
                </div>
            </div>

            <!-- Specific worker -->
            <div v-if="form.audience === 'worker'" class="relative">
                <label class="mb-1 block text-sm font-medium">Worker</label>
                <input
                    v-model="workerQuery"
                    placeholder="Search by name or phone…"
                    class="w-full rounded-xl border bg-background px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40"
                />
                <ul
                    v-if="workerResults.length"
                    class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-xl border bg-card shadow-lg"
                >
                    <li
                        v-for="w in workerResults"
                        :key="w.id"
                        class="cursor-pointer px-4 py-2 text-sm hover:bg-muted"
                        @click="pickWorker(w)"
                    >
                        {{ w.name }} <span class="text-muted-foreground">{{ w.phone ? `· ${w.phone}` : '' }}</span>
                    </li>
                </ul>
                <p v-if="form.errors.worker_id" class="mt-1 text-xs text-rose-500">{{ form.errors.worker_id }}</p>
            </div>

            <!-- City -->
            <div v-if="form.audience === 'city'">
                <label class="mb-1 block text-sm font-medium">City</label>
                <select
                    v-model="form.city"
                    class="w-full rounded-xl border bg-background px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40"
                >
                    <option value="" disabled>Select a city…</option>
                    <option v-for="c in props.cities" :key="c" :value="c">{{ c }}</option>
                </select>
                <p v-if="form.errors.city" class="mt-1 text-xs text-rose-500">{{ form.errors.city }}</p>
            </div>

            <!-- Category -->
            <div v-if="form.audience === 'category'">
                <label class="mb-1 block text-sm font-medium">Category / skill</label>
                <select
                    v-model="form.category"
                    class="w-full rounded-xl border bg-background px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40"
                >
                    <option value="" disabled>Select a category…</option>
                    <option v-for="c in props.categories" :key="c" :value="c">{{ c }}</option>
                </select>
                <p v-if="form.errors.category" class="mt-1 text-xs text-rose-500">{{ form.errors.category }}</p>
            </div>

            <!-- Optional deep link -->
            <div>
                <label class="mb-1 block text-sm font-medium">Deep link <span class="font-normal text-muted-foreground">(optional)</span></label>
                <input
                    v-model="form.url"
                    placeholder="e.g. /worker/jobs"
                    class="w-full rounded-xl border bg-background px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40"
                />
                <p class="mt-1 text-xs text-muted-foreground">Screen the app opens when the notification is tapped.</p>
            </div>

            <div class="flex justify-end">
                <button
                    type="submit"
                    :disabled="!canSubmit || form.processing"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-rose-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95 disabled:opacity-50"
                >
                    <Send class="size-4" /> {{ form.processing ? 'Sending…' : 'Send push' }}
                </button>
            </div>
        </form>

        <!-- History -->
        <div>
            <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                <Users class="size-4" /> Recent broadcasts
            </h2>
            <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
                <div
                    v-for="c in props.campaigns"
                    :key="c.id"
                    class="flex flex-col gap-1 border-b px-5 py-4 last:border-0"
                >
                    <div class="flex items-start justify-between gap-3">
                        <span class="font-medium">{{ c.title }}</span>
                        <span class="shrink-0 text-xs text-muted-foreground">{{ formatDate(c.created_at) }}</span>
                    </div>
                    <p class="text-sm text-muted-foreground">{{ c.body }}</p>
                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                        <span class="inline-flex items-center rounded-full bg-muted px-2 py-0.5 font-medium text-foreground">
                            {{ c.audience_label }}
                        </span>
                        <span class="inline-flex items-center rounded-full bg-emerald-500/10 px-2 py-0.5 font-medium text-emerald-600 dark:text-emerald-300">
                            {{ c.sent_count }} sent
                        </span>
                        <span
                            v-if="c.failed_count"
                            class="inline-flex items-center rounded-full bg-rose-500/10 px-2 py-0.5 font-medium text-rose-600 dark:text-rose-300"
                        >
                            {{ c.failed_count }} failed
                        </span>
                        <span class="text-muted-foreground">{{ c.recipients_count }} recipient(s)</span>
                        <span v-if="c.created_by" class="text-muted-foreground">· by {{ c.created_by }}</span>
                    </div>
                </div>
                <div v-if="props.campaigns.length === 0" class="px-5 py-12 text-center text-sm text-muted-foreground">
                    No broadcasts sent yet.
                </div>
            </div>
        </div>
    </div>
</template>
