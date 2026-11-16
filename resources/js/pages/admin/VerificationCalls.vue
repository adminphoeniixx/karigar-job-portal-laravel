<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, ChevronDown, PhoneCall, Search } from '@lucide/vue';
import { ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

type Paginated<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[] };

interface CallRow {
    id: number;
    status: string;
    outcome: string | null;
    kyc_state: 'not_started' | 'rejected';
    source: 'auto' | 'admin' | 'retry';
    requested_by: string | null;
    attempt: number;
    language: string;
    summary: string | null;
    transcript: string | null;
    failure_reason: string | null;
    duration_seconds: number | null;
    scheduled_at: string | null;
    created_at: string | null;
    user: { id: number; name: string; phone: string | null; role: string } | null;
}

interface AccountRow {
    id: number;
    name: string;
    phone: string | null;
    role: 'worker' | 'employer';
    state: 'not_started' | 'rejected';
    remarks: string | null;
    opted_out: boolean;
    signed_up_at: string | null;
    last_call: CallRow | null;
}

const props = defineProps<{
    tab: 'accounts' | 'calls';
    filterRole: 'all' | 'worker' | 'employer';
    search: string;
    accounts: Paginated<AccountRow> | null;
    notListed: { id: number; name: string; phone: string | null; role: string; reason: string }[];
    calls: Paginated<CallRow> | null;
    auto: { enabled: boolean; since: string | null };
    dialling: boolean;
    verificationEnabled: boolean;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin — AI Verification Calls', href: '/admin/verification-calls' }] },
});

const term = ref(props.search);
const open = ref<number | null>(null);
const calling = ref<number | null>(null);

const go = (changes: Record<string, string | null>) => {
    const current: Record<string, string | null> = {
        tab: props.tab === 'accounts' ? null : props.tab,
        role: props.filterRole === 'all' ? null : props.filterRole,
        q: term.value.trim() || null,
        ...changes,
    };

    router.get('/admin/verification-calls', Object.fromEntries(Object.entries(current).filter(([, v]) => v)) as Record<string, string>, {
        preserveState: true,
    });
};

// Clearing the box (its ✕, or deleting the text) drops the search at once,
// without having to press Enter on an empty field.
watch(term, (value) => {
    if (value.trim() === '' && props.search !== '') {
        go({ q: null });
    }
});

const call = (row: AccountRow) => {
    calling.value = row.id;
    router.post(`/admin/verification-calls/${row.id}`, {}, { preserveScroll: true, onFinish: () => (calling.value = null) });
};

const date = (iso: string | null) =>
    iso ? new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : '';

const stateLabel = { not_started: 'KYC not started', rejected: 'KYC rejected' } as const;

const outcomeLabel: Record<string, string> = {
    will_verify: 'Will verify',
    needs_help: 'Needs help',
    not_interested: 'Not interested',
    callback_later: 'Call back later',
    unclear: 'Unclear',
};

const statusLabel: Record<string, string> = {
    scheduled: 'Booked',
    queued: 'Queued',
    dialing: 'Ringing',
    in_progress: 'On the line',
    completed: 'Completed',
    no_answer: 'No answer',
    busy: 'Busy',
    failed: 'Failed',
    cancelled: 'Cancelled',
};

const pill = (call: CallRow) => {
    if (call.outcome === 'will_verify') return 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/20 dark:text-emerald-300';
    if (call.outcome === 'needs_help') return 'bg-amber-500/10 text-amber-700 ring-amber-500/20 dark:text-amber-300';
    if (['failed', 'cancelled', 'no_answer', 'busy'].includes(call.status) || call.outcome === 'not_interested')
        return 'bg-rose-500/10 text-rose-600 ring-rose-500/20 dark:text-rose-300';

    return 'bg-muted text-muted-foreground ring-border';
};

const callText = (call: CallRow) => {
    const label = call.outcome ? outcomeLabel[call.outcome] : statusLabel[call.status] ?? call.status;

    return call.status === 'scheduled' && call.scheduled_at ? `${label} for ${date(call.scheduled_at)}` : label;
};

const minutes = (s: number | null) => (s === null ? '' : s < 60 ? `${s}s` : `${Math.floor(s / 60)}m ${s % 60}s`);
</script>

<template>
    <Head title="Admin — AI Verification Calls" />

    <div class="flex w-full flex-col gap-6 p-4 md:p-6 lg:p-8">
        <PageHeader
            :icon="PhoneCall"
            title="AI Verification Calls"
            description="Karigars and employers whose KYC is not done get a call that explains, step by step, how to verify"
        />

        <div v-if="!dialling" class="flex items-start gap-3 rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-800 dark:text-amber-200">
            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
            <p>No voice provider is set up here, so calls are recorded but nobody's phone rings.</p>
        </div>

        <div class="rounded-2xl border bg-card p-4 text-sm text-muted-foreground">
            <template v-if="auto.enabled">
                <span class="font-medium text-foreground">Automatic calls are on</span>
                <template v-if="auto.since"> since {{ date(auto.since) }}</template>: accounts that signed up after that and are still not verified a day later get
                one call, and so does anyone whose KYC is rejected, a day after the rejection.
            </template>
            <template v-else>
                <span class="font-medium text-foreground">Automatic calls are off.</span> Only the Call button below rings anyone.
            </template>
            <Link href="/admin/settings" class="ml-1 font-medium text-primary hover:underline">Change in Settings</Link>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button
                v-for="t in [
                    { key: 'accounts', label: 'Need verification' },
                    { key: 'calls', label: 'Calls made' },
                ]"
                :key="t.key"
                class="rounded-full px-4 py-1.5 text-sm font-medium transition"
                :class="tab === t.key ? 'bg-primary text-white shadow-md shadow-orange-600/25' : 'border bg-card text-muted-foreground hover:bg-muted'"
                @click="go({ tab: t.key === 'accounts' ? null : t.key })"
            >
                {{ t.label }}
            </button>
            <span class="mx-1 h-6 w-px bg-border" />
            <button
                v-for="r in ['all', 'worker', 'employer'] as const"
                :key="r"
                class="rounded-full px-4 py-1.5 text-sm font-medium transition"
                :class="filterRole === r ? 'bg-foreground text-background' : 'border bg-card text-muted-foreground hover:bg-muted'"
                @click="go({ role: r === 'all' ? null : r })"
            >
                {{ r === 'all' ? 'Everyone' : r === 'worker' ? 'Karigars' : 'Employers' }}
            </button>
            <form class="relative ml-auto w-full sm:w-64" @submit.prevent="go({})">
                <Search class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                <input
                    v-model="term"
                    type="search"
                    placeholder="Name or phone"
                    class="w-full rounded-full border bg-background py-1.5 pr-3 pl-9 text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
                />
            </form>
        </div>

        <!-- Accounts with KYC still to do -->
        <div v-if="accounts" class="overflow-hidden rounded-2xl border bg-card">
            <div v-if="!verificationEnabled" class="px-5 py-16 text-center text-sm text-muted-foreground">KYC verification is switched off in Settings.</div>
            <div v-else-if="accounts.data.length === 0 && notListed.length > 0" class="px-5 py-8 text-sm">
                <p class="mb-3 text-center text-muted-foreground">Nobody to call for “{{ search }}”. These accounts match, but are not on the list:</p>
                <ul class="mx-auto max-w-xl divide-y rounded-xl border">
                    <li v-for="row in notListed" :key="row.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5">
                        <span class="font-medium">{{ row.name }}</span>
                        <span class="text-muted-foreground">{{ row.phone }}</span>
                        <span class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{{ row.role === 'worker' ? 'Karigar' : row.role === 'employer' ? 'Employer' : row.role }}</span>
                        <span class="w-full text-xs text-muted-foreground sm:ml-auto sm:w-auto">{{ row.reason }}</span>
                    </li>
                </ul>
            </div>
            <div v-else-if="accounts.data.length === 0" class="px-5 py-16 text-center text-sm text-muted-foreground">
                {{ search ? `No account matches “${search}”.` : 'Everyone in this filter has sent their KYC.' }}
            </div>
            <ul v-else class="divide-y">
                <li v-for="row in accounts.data" :key="row.id" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ row.name }}</span>
                            <span class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{{ row.role === 'worker' ? 'Karigar' : 'Employer' }}</span>
                            <span
                                class="rounded-full px-2 py-0.5 text-xs ring-1"
                                :class="row.state === 'rejected' ? 'bg-rose-500/10 text-rose-600 ring-rose-500/20 dark:text-rose-300' : 'bg-amber-500/10 text-amber-700 ring-amber-500/20 dark:text-amber-300'"
                            >
                                {{ stateLabel[row.state] }}
                            </span>
                        </div>
                        <div class="mt-0.5 text-sm text-muted-foreground">
                            {{ row.phone ?? 'No phone' }} · signed up {{ date(row.signed_up_at) }}
                            <template v-if="row.state === 'rejected' && row.remarks"> · reason: {{ row.remarks }}</template>
                        </div>
                        <div v-if="row.last_call" class="mt-1 text-xs">
                            <span class="rounded-full px-2 py-0.5 ring-1" :class="pill(row.last_call)">Last call: {{ callText(row.last_call) }}</span>
                            <span class="ml-2 text-muted-foreground">{{ date(row.last_call.created_at) }}</span>
                        </div>
                    </div>
                    <span v-if="row.opted_out" class="text-xs text-muted-foreground">Turned AI calls off</span>
                    <button
                        v-else
                        :disabled="calling === row.id || !row.phone"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-primary/90 disabled:opacity-40"
                        @click="call(row)"
                    >
                        <PhoneCall class="size-3.5" /> {{ calling === row.id ? 'Calling…' : 'AI call' }}
                    </button>
                </li>
            </ul>
        </div>

        <!-- Calls made -->
        <div v-if="calls" class="overflow-hidden rounded-2xl border bg-card">
            <div v-if="calls.data.length === 0" class="px-5 py-16 text-center text-sm text-muted-foreground">No verification calls yet.</div>
            <ul v-else class="divide-y">
                <li v-for="c in calls.data" :key="c.id" class="px-5 py-3.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ c.user?.name ?? 'Deleted account' }}</span>
                        <span v-if="c.user" class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{{ c.user.role === 'worker' ? 'Karigar' : 'Employer' }}</span>
                        <span class="rounded-full px-2 py-0.5 text-xs ring-1" :class="pill(c)">{{ callText(c) }}</span>
                        <span class="text-xs text-muted-foreground">
                            {{ stateLabel[c.kyc_state] }} ·
                            {{ c.source === 'admin' ? `by ${c.requested_by ?? 'admin'}` : c.source === 'retry' ? `try ${c.attempt}` : 'automatic' }}
                            <template v-if="c.duration_seconds"> · {{ minutes(c.duration_seconds) }}</template>
                            · {{ date(c.created_at) }}
                        </span>
                    </div>
                    <p v-if="c.user?.phone" class="mt-0.5 text-sm text-muted-foreground">{{ c.user.phone }}</p>
                    <p v-if="c.summary" class="mt-2 text-sm">{{ c.summary }}</p>
                    <p v-else-if="c.failure_reason" class="mt-2 text-sm text-muted-foreground">Reason: {{ c.failure_reason.replaceAll('_', ' ') }}</p>
                    <button
                        v-if="c.transcript"
                        class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                        @click="open = open === c.id ? null : c.id"
                    >
                        <ChevronDown class="size-3.5 transition" :class="open === c.id && 'rotate-180'" />
                        {{ open === c.id ? 'Hide conversation' : 'Show conversation' }}
                    </button>
                    <pre v-if="open === c.id" class="mt-2 max-h-96 overflow-auto rounded-xl bg-muted p-3 text-xs whitespace-pre-wrap">{{ c.transcript }}</pre>
                </li>
            </ul>
        </div>

        <nav v-if="(accounts ?? calls)?.links && (accounts ?? calls)!.links.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="link in (accounts ?? calls)!.links"
                :key="link.label"
                :href="link.url ?? ''"
                preserve-scroll
                class="rounded-lg border px-3 py-1.5 text-sm"
                :class="[link.active ? 'bg-primary text-white' : 'bg-card', !link.url && 'pointer-events-none opacity-40']"
                v-html="link.label"
            />
        </nav>
    </div>
</template>
