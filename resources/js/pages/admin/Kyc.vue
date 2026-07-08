<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, FileText, ShieldCheck, X } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';

interface KycRow {
    id: number;
    status: 'pending' | 'verified' | 'rejected';
    masked_pan: string | null;
    masked_aadhaar: string | null;
    remarks: string | null;
    created_at: string;
    user: { id: number; name: string; email: string; role: string };
}

defineProps<{
    documents: { data: KycRow[]; links: { url: string | null; label: string; active: boolean }[] };
    filterStatus: string;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin — KYC', href: '/admin/kyc' }] },
});

const statuses = ['pending', 'verified', 'rejected', 'all'];

const statusPill: Record<string, string> = {
    verified: 'bg-orange-500/10 text-orange-600 ring-orange-500/20 dark:text-orange-300',
    rejected: 'bg-rose-500/10 text-rose-600 ring-rose-500/20 dark:text-rose-300',
    pending: 'bg-amber-500/10 text-amber-600 ring-amber-500/20 dark:text-amber-300',
};

const isActive = (s: string, current: string) =>
    current === s || (s === 'all' && !['pending', 'verified', 'rejected'].includes(current));

const filterBy = (status: string) =>
    router.get('/admin/kyc', status === 'all' ? {} : { status }, { preserveState: true });

const approve = (id: number) => router.post(`/admin/kyc/${id}/approve`, {}, { preserveScroll: true });

const reject = (id: number) => {
    const remarks = window.prompt('Reason for rejection?');
    if (remarks) router.post(`/admin/kyc/${id}/reject`, { remarks }, { preserveScroll: true });
};
</script>

<template>
    <Head title="Admin — KYC" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="ShieldCheck" title="KYC Review" description="Approve or reject PAN/Aadhaar submissions" />

        <!-- Filter pills -->
        <div class="flex flex-wrap gap-2">
            <button
                v-for="s in statuses"
                :key="s"
                class="rounded-full px-4 py-1.5 text-sm font-medium capitalize transition"
                :class="isActive(s, filterStatus)
                    ? 'bg-gradient-to-r from-orange-500 to-rose-600 text-white shadow-md shadow-orange-600/25'
                    : 'border bg-card text-muted-foreground hover:bg-muted'"
                @click="filterBy(s)"
            >
                {{ s }}
            </button>
        </div>

        <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-5 py-3 font-medium">User</th>
                            <th class="px-5 py-3 font-medium">PAN</th>
                            <th class="px-5 py-3 font-medium">Aadhaar</th>
                            <th class="px-5 py-3 font-medium">Docs</th>
                            <th class="px-5 py-3 font-medium">Status</th>
                            <th class="px-5 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in documents.data" :key="row.id" class="border-t transition hover:bg-muted/30">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <span class="flex size-9 items-center justify-center rounded-full bg-gradient-to-br from-orange-500 to-rose-600 text-sm font-bold text-white">
                                        {{ row.user.name.charAt(0).toUpperCase() }}
                                    </span>
                                    <div>
                                        <div class="font-medium">{{ row.user.name }}</div>
                                        <div class="text-xs text-muted-foreground">{{ row.user.email }} · {{ row.user.role }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3.5 font-mono text-xs">{{ row.masked_pan ?? '—' }}</td>
                            <td class="px-5 py-3.5 font-mono text-xs">{{ row.masked_aadhaar ?? '—' }}</td>
                            <td class="px-5 py-3.5">
                                <div class="flex gap-1.5">
                                    <a :href="`/admin/kyc/${row.id}/document/pan`" target="_blank" class="inline-flex items-center gap-1 rounded-lg border px-2 py-1 text-xs text-muted-foreground transition hover:bg-muted">
                                        <FileText class="size-3" /> PAN
                                    </a>
                                    <a :href="`/admin/kyc/${row.id}/document/aadhaar`" target="_blank" class="inline-flex items-center gap-1 rounded-lg border px-2 py-1 text-xs text-muted-foreground transition hover:bg-muted">
                                        <FileText class="size-3" /> Aadhaar
                                    </a>
                                </div>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize ring-1 ring-inset" :class="statusPill[row.status]">
                                    {{ row.status }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button
                                        :disabled="row.status === 'verified'"
                                        class="inline-flex items-center gap-1 rounded-lg bg-orange-500/10 px-2.5 py-1.5 text-xs font-semibold text-orange-600 transition hover:bg-orange-500/20 disabled:opacity-40 dark:text-orange-300"
                                        @click="approve(row.id)"
                                    >
                                        <Check class="size-3.5" /> Approve
                                    </button>
                                    <button
                                        :disabled="row.status === 'rejected'"
                                        class="inline-flex items-center gap-1 rounded-lg bg-rose-500/10 px-2.5 py-1.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-500/20 disabled:opacity-40 dark:text-rose-400"
                                        @click="reject(row.id)"
                                    >
                                        <X class="size-3.5" /> Reject
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="documents.data.length === 0">
                            <td colspan="6" class="px-5 py-16 text-center">
                                <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground">
                                    <ShieldCheck class="size-7" />
                                </div>
                                <p class="mt-4 font-medium">No submissions</p>
                                <p class="mt-1 text-sm text-muted-foreground">Nothing to review in this filter.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
