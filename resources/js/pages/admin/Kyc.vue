<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Building2, Check, FileQuestion, FileText, ShieldCheck, X } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';

interface DocSummary {
    type: 'aadhaar' | 'pan' | 'gst';
    label: string;
    missing: boolean;
    number: string | null;
    has_file: boolean;
    alternate: { type: string; label: string; number: string | null; has_file: boolean; reason: string } | null;
}

interface KycRow {
    id: number;
    status: 'pending' | 'verified' | 'rejected';
    remarks: string | null;
    submitted_at: string | null;
    has_missing_documents: boolean;
    user: { id: number; name: string; email: string | null; phone: string | null; role: string };
    business: {
        type: string | null;
        type_label: string | null;
        company_name: string | null;
        legal_name: string | null;
        registered_address: string | null;
    } | null;
    documents: DocSummary[];
}

const props = defineProps<{
    documents: { data: KycRow[]; links: { url: string | null; label: string; active: boolean }[] };
    filterStatus: string;
    filterRole: 'all' | 'worker' | 'employer';
    filterMissing: boolean;
    missingPending: number;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin — KYC', href: '/admin/kyc' }] },
});

const statuses = ['pending', 'verified', 'rejected', 'all'];
const roles = ['all', 'worker', 'employer'] as const;

const statusPill: Record<string, string> = {
    verified: 'bg-orange-500/10 text-orange-600 ring-orange-500/20 dark:text-orange-300',
    rejected: 'bg-rose-500/10 text-rose-600 ring-rose-500/20 dark:text-rose-300',
    pending: 'bg-amber-500/10 text-amber-600 ring-amber-500/20 dark:text-amber-300',
};

const statusActive = (s: string) =>
    props.filterStatus === s || (s === 'all' && !['pending', 'verified', 'rejected'].includes(props.filterStatus));

const query = (changes: Record<string, string | boolean | null>) => {
    const current: Record<string, string | boolean | null> = {
        status: statusActive('all') ? 'all' : props.filterStatus,
        role: props.filterRole === 'all' ? null : props.filterRole,
        missing: props.filterMissing ? true : null,
        ...changes,
    };

    return Object.fromEntries(
        Object.entries(current).filter(([, v]) => v !== null && v !== false).map(([k, v]) => [k, v === true ? '1' : v]),
    );
};

const filter = (changes: Record<string, string | boolean | null>) =>
    router.get('/admin/kyc', query(changes), { preserveState: true });

const approve = (id: number) => router.post(`/admin/kyc/${id}/approve`, {}, { preserveScroll: true });

const reject = (id: number) => {
    const remarks = window.prompt('Reason for rejection? (the user sees this)');
    if (remarks) router.post(`/admin/kyc/${id}/reject`, { remarks }, { preserveScroll: true });
};

const date = (iso: string | null) =>
    iso ? new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : '';
</script>

<template>
    <Head title="Admin — KYC" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="ShieldCheck" title="KYC Review" description="Check documents by hand, then approve or reject" />

        <div class="flex flex-wrap items-center gap-2">
            <button
                v-for="s in statuses"
                :key="s"
                class="rounded-full px-4 py-1.5 text-sm font-medium capitalize transition"
                :class="statusActive(s) ? 'bg-primary text-white shadow-md shadow-orange-600/25' : 'border bg-card text-muted-foreground hover:bg-muted'"
                @click="filter({ status: s === 'all' ? 'all' : s })"
            >
                {{ s }}
            </button>
            <span class="mx-1 h-6 w-px bg-border" />
            <button
                v-for="r in roles"
                :key="r"
                class="rounded-full px-4 py-1.5 text-sm font-medium capitalize transition"
                :class="filterRole === r ? 'bg-foreground text-background' : 'border bg-card text-muted-foreground hover:bg-muted'"
                @click="filter({ role: r === 'all' ? null : r })"
            >
                {{ r === 'all' ? 'Everyone' : r + 's' }}
            </button>
            <span class="mx-1 h-6 w-px bg-border" />
            <button
                class="inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 text-sm font-medium transition"
                :class="filterMissing ? 'bg-amber-600 text-white' : 'border bg-card text-muted-foreground hover:bg-muted'"
                @click="filter({ missing: !filterMissing })"
            >
                <FileQuestion class="size-4" /> Document not available
                <span v-if="missingPending" class="rounded-full bg-amber-500/20 px-1.5 text-xs">{{ missingPending }} pending</span>
            </button>
        </div>

        <div class="grid gap-4">
            <article v-for="row in documents.data" :key="row.id" class="rounded-2xl border bg-card p-5 shadow-sm">
                <header class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="flex size-10 items-center justify-center rounded-full bg-primary text-sm font-bold text-white">
                            {{ row.user.name?.charAt(0).toUpperCase() }}
                        </span>
                        <div>
                            <div class="font-semibold">{{ row.user.name }}</div>
                            <div class="text-xs text-muted-foreground">
                                <span class="capitalize">{{ row.user.role }}</span> · {{ row.user.phone ?? row.user.email }} · {{ date(row.submitted_at) }}
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span v-if="row.has_missing_documents" class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-amber-500/20 ring-inset dark:text-amber-300">
                            <FileQuestion class="size-3" /> Alternate document
                        </span>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize ring-1 ring-inset" :class="statusPill[row.status]">
                            {{ row.status }}
                        </span>
                    </div>
                </header>

                <div v-if="row.business" class="mt-4 rounded-xl bg-muted/40 p-3 text-sm">
                    <div class="mb-1 flex items-center gap-1.5 text-xs font-semibold text-muted-foreground uppercase">
                        <Building2 class="size-3.5" /> {{ row.business.type_label ?? 'Business type not given (older submission)' }}
                    </div>
                    <div v-if="row.business.legal_name" class="font-medium">{{ row.business.legal_name }}</div>
                    <div v-if="row.business.company_name && row.business.company_name !== row.business.legal_name" class="text-xs text-muted-foreground">
                        Shown as: {{ row.business.company_name }}
                    </div>
                    <div v-if="row.business.registered_address" class="text-xs text-muted-foreground">{{ row.business.registered_address }}</div>
                </div>

                <ul class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <li
                        v-for="d in row.documents"
                        :key="d.type"
                        class="rounded-xl border p-3 text-sm"
                        :class="d.missing ? 'border-amber-500/40 bg-amber-500/5' : ''"
                    >
                        <div class="text-xs font-semibold text-muted-foreground uppercase">{{ d.label }}</div>
                        <template v-if="d.missing && d.alternate">
                            <div class="mt-1 font-medium">Not available → {{ d.alternate.label }}</div>
                            <div v-if="d.alternate.number" class="font-mono text-xs">{{ d.alternate.number }}</div>
                            <p class="mt-1 text-xs text-muted-foreground italic">“{{ d.alternate.reason }}”</p>
                            <a
                                v-if="d.alternate.has_file"
                                :href="`/admin/kyc/${row.id}/document/alt-${d.type}`"
                                target="_blank"
                                class="mt-2 inline-flex items-center gap-1 rounded-lg border bg-card px-2 py-1 text-xs text-muted-foreground transition hover:bg-muted"
                            >
                                <FileText class="size-3" /> View {{ d.alternate.label }}
                            </a>
                        </template>
                        <template v-else>
                            <div class="mt-1 font-mono">{{ d.number ?? '—' }}</div>
                            <a
                                v-if="d.has_file"
                                :href="`/admin/kyc/${row.id}/document/${d.type}`"
                                target="_blank"
                                class="mt-2 inline-flex items-center gap-1 rounded-lg border bg-card px-2 py-1 text-xs text-muted-foreground transition hover:bg-muted"
                            >
                                <FileText class="size-3" /> View document
                            </a>
                            <div v-else class="mt-2 text-xs text-rose-600">No file uploaded</div>
                        </template>
                    </li>
                </ul>

                <footer class="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <p v-if="row.remarks" class="text-xs text-muted-foreground">Remarks: {{ row.remarks }}</p>
                    <span v-else />
                    <div class="flex items-center gap-1.5">
                        <button
                            :disabled="row.status === 'verified'"
                            class="inline-flex items-center gap-1 rounded-lg bg-orange-500/10 px-3 py-1.5 text-xs font-semibold text-orange-600 transition hover:bg-orange-500/20 disabled:opacity-40 dark:text-orange-300"
                            @click="approve(row.id)"
                        >
                            <Check class="size-3.5" /> Approve
                        </button>
                        <button
                            :disabled="row.status === 'rejected'"
                            class="inline-flex items-center gap-1 rounded-lg bg-rose-500/10 px-3 py-1.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-500/20 disabled:opacity-40 dark:text-rose-400"
                            @click="reject(row.id)"
                        >
                            <X class="size-3.5" /> Reject
                        </button>
                    </div>
                </footer>
            </article>

            <div v-if="documents.data.length === 0" class="rounded-2xl border bg-card px-5 py-16 text-center">
                <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground">
                    <ShieldCheck class="size-7" />
                </div>
                <p class="mt-4 font-medium">No submissions</p>
                <p class="mt-1 text-sm text-muted-foreground">Nothing to review in this filter.</p>
            </div>
        </div>

        <nav v-if="documents.links.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="link in documents.links"
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
