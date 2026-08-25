<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Bell, Check, Send, Sparkles, Users, Wand2 } from '@lucide/vue';
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
    { value: 'all', label: 'All karigars' },
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

// --- AI draft panel ---
// Copy only; nothing here sends anything. The admin picks a draft, it lands in
// the title/body fields above, and the normal compose flow takes over — which
// is why this does not touch `form` until they choose one.
interface Variation {
    title: string;
    body: string;
}

const idea = ref('');
const draftLanguage = ref<'hinglish' | 'hindi' | 'english'>('hinglish');
const draftCount = ref(5);
const drafts = ref<Variation[]>([]);
const drafting = ref(false);
const draftError = ref('');
const usedDraft = ref<number | null>(null);

const languages = [
    { value: 'hinglish', label: 'Hinglish' },
    { value: 'hindi', label: 'हिंदी' },
    { value: 'english', label: 'English' },
] as const;

const generateDrafts = async () => {
    if (!idea.value.trim() || drafting.value) return;

    drafting.value = true;
    draftError.value = '';
    drafts.value = [];
    usedDraft.value = null;

    try {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

        const res = await fetch('/admin/push-notifications/suggest', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': token,
            },
            body: JSON.stringify({
                idea: idea.value,
                count: draftCount.value,
                language: draftLanguage.value,
            }),
        });

        if (!res.ok) {
            // 422 comes back as { errors: { idea: [...] } } — the controller
            // hand-builds it, because web routes do not render JSON validation
            // errors. Anything else is the provider having a bad day, which the
            // writer has already logged server-side.
            const payload = await res.json().catch(() => null);
            const firstError = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
            throw new Error((firstError as string) ?? `Could not draft copy (${res.status}).`);
        }

        drafts.value = (await res.json()).variations ?? [];

        if (drafts.value.length === 0) {
            draftError.value = 'Nothing came back. Try rewording the idea.';
        }
    } catch (e) {
        draftError.value = e instanceof Error ? e.message : 'Could not draft copy.';
    } finally {
        drafting.value = false;
    }
};

const useDraft = (draft: Variation, index: number) => {
    form.title = draft.title;
    form.body = draft.body;
    usedDraft.value = index;
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
        <PageHeader :icon="Bell" title="Push Notifications" description="Send a push notification to karigars' phones" />

        <!-- Draft with AI -->
        <section class="flex flex-col gap-4 rounded-2xl border bg-card p-5 shadow-sm">
            <div class="flex items-center gap-2">
                <Sparkles class="size-4 text-orange-500" />
                <h2 class="text-sm font-semibold">Draft with AI</h2>
                <span class="text-xs text-muted-foreground">optional</span>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">What is the notification about?</label>
                <textarea
                    v-model="idea"
                    rows="2"
                    maxlength="500"
                    placeholder="e.g. new plumbing jobs in Jaipur this week"
                    class="w-full rounded-xl border bg-background px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40"
                    @keydown.ctrl.enter="generateDrafts"
                />
            </div>

            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-muted-foreground">Language</label>
                    <div class="flex gap-2">
                        <button
                            v-for="l in languages"
                            :key="l.value"
                            type="button"
                            class="rounded-lg border px-3 py-1.5 text-xs font-medium transition"
                            :class="draftLanguage === l.value
                                ? 'border-orange-500 bg-orange-500/10 text-orange-600 dark:text-orange-300'
                                : 'border-border text-muted-foreground hover:bg-muted'"
                            @click="draftLanguage = l.value"
                        >
                            {{ l.label }}
                        </button>
                    </div>
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-medium text-muted-foreground">How many</label>
                    <input
                        v-model.number="draftCount"
                        type="number"
                        min="1"
                        max="20"
                        class="w-20 rounded-lg border bg-background px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-orange-500/40"
                    />
                </div>

                <button
                    type="button"
                    :disabled="!idea.trim() || drafting"
                    class="ml-auto inline-flex items-center gap-2 rounded-xl bg-orange-500 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-orange-600 disabled:cursor-not-allowed disabled:opacity-50"
                    @click="generateDrafts"
                >
                    <Wand2 class="size-4" />
                    {{ drafting ? 'Writing…' : 'Generate' }}
                </button>
            </div>

            <p v-if="draftError" class="text-xs text-rose-500">{{ draftError }}</p>

            <!-- Drafts. Clicking one fills the compose form below; nothing sends. -->
            <ul v-if="drafts.length" class="flex flex-col gap-2">
                <li
                    v-for="(d, i) in drafts"
                    :key="i"
                    class="flex items-start gap-3 rounded-xl border p-3 transition"
                    :class="usedDraft === i ? 'border-orange-500 bg-orange-500/5' : 'border-border hover:bg-muted/50'"
                >
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold">{{ d.title }}</p>
                        <p class="mt-0.5 text-sm text-muted-foreground">{{ d.body }}</p>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 rounded-lg border px-3 py-1.5 text-xs font-medium transition"
                        :class="usedDraft === i
                            ? 'border-orange-500 text-orange-600 dark:text-orange-300'
                            : 'border-border hover:bg-muted'"
                        @click="useDraft(d, i)"
                    >
                        {{ usedDraft === i ? 'Used' : 'Use this' }}
                    </button>
                </li>
            </ul>
        </section>

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
                    placeholder="Write the notification message karigars will see…"
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
                <label class="mb-1 block text-sm font-medium">Karigar</label>
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
                    class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95 disabled:opacity-50"
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
