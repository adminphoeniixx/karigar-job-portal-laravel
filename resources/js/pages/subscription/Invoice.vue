<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Download, Printer } from '@lucide/vue';
import { computed } from 'vue';

interface Invoice {
    number: string;
    date: string | null;
    plan: { name: string; interval: string; price: number };
    coupon_code: string | null;
    discount: number | null;
    subtotal: number | null;
    gst_percent: number | null;
    gst_amount: number | null;
    total: number | null;
    cgst_amount: number | null;
    sgst_amount: number | null;
    igst_amount: number | null;
    place_of_supply: string | null;
    sac: string | null;
    period: { from: string | null; to: string | null };
    payment_ref: string | null;
}

const props = defineProps<{
    invoice: Invoice;
    seller: { name: string; address: string; gstin: string; email: string };
    buyer: { name: string; address: string; gstin: string | null; email: string | null; phone: string | null };
    pdfUrl: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Subscription', href: '/subscription' }, { title: 'Invoice', href: '#' }] } });

// Invoices issued before the split carry only the combined GST line.
const split = computed(() => props.invoice.cgst_amount !== null || props.invoice.igst_amount !== null);
const intraState = computed(() => Number(props.invoice.cgst_amount ?? 0) > 0);
const halfRate = computed(() => Number(props.invoice.gst_percent ?? 0) / 2);

const inr = (v: string | number | null) => (v == null ? '—' : '₹' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2 }));

const printInvoice = () => window.print();
</script>

<template>
    <Head :title="`Invoice ${invoice.number}`" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4 md:p-6">
        <div class="flex items-center justify-between print:hidden">
            <Link href="/subscription" class="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground transition hover:text-foreground">
                <ArrowLeft class="size-4" /> Back to subscription
            </Link>
            <div class="flex items-center gap-2">
                <a
                    :href="pdfUrl"
                    class="inline-flex items-center gap-1.5 rounded-xl border px-4 py-2 text-sm font-semibold transition hover:bg-muted"
                >
                    <Download class="size-4" /> PDF
                </a>
                <button
                    class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white shadow transition hover:opacity-90"
                    @click="printInvoice"
                >
                    <Printer class="size-4" /> Print
                </button>
            </div>
        </div>

        <!-- Invoice sheet -->
        <div id="invoice-sheet" class="rounded-2xl border bg-card p-6 shadow-sm md:p-10 print:rounded-none print:border-0 print:shadow-none">
            <!-- Header -->
            <div class="flex flex-wrap items-start justify-between gap-4 border-b pb-6">
                <div>
                    <img src="/images/brand/wordmark.png" alt="Super Karigar" width="534" height="230" class="h-14 w-auto" />
                    <div class="mt-3 text-base font-bold">{{ seller.name }}</div>
                    <p v-if="seller.address" class="mt-2 max-w-xs text-xs text-muted-foreground">{{ seller.address }}</p>
                    <p v-if="seller.gstin" class="mt-1 text-xs font-medium">GSTIN: {{ seller.gstin }}</p>
                    <p v-if="seller.email" class="text-xs text-muted-foreground">{{ seller.email }}</p>
                </div>
                <div class="text-right">
                    <div class="text-2xl font-bold tracking-tight">TAX INVOICE</div>
                    <div class="mt-2 text-sm font-semibold">{{ invoice.number }}</div>
                    <div class="text-xs text-muted-foreground">Date: {{ invoice.date }}</div>
                </div>
            </div>

            <!-- Parties -->
            <div class="grid gap-6 border-b py-6 sm:grid-cols-2">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Billed to</div>
                    <div class="mt-1.5 font-semibold">{{ buyer.name }}</div>
                    <div v-if="buyer.address" class="text-sm text-muted-foreground">{{ buyer.address }}</div>
                    <div v-if="buyer.gstin" class="mt-1 text-sm font-medium">GSTIN: {{ buyer.gstin }}</div>
                    <div class="text-sm text-muted-foreground">{{ buyer.phone || buyer.email }}</div>
                </div>
                <div class="sm:text-right">
                    <div class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Payment reference</div>
                    <div class="mt-1.5 text-sm">{{ invoice.payment_ref || '—' }}</div>
                    <div v-if="invoice.place_of_supply" class="mt-2 text-xs text-muted-foreground">
                        Place of supply: <span class="font-medium text-foreground">{{ invoice.place_of_supply }}</span>
                    </div>
                    <div class="mt-1 text-xs text-muted-foreground">Reverse charge: No</div>
                    <div v-if="invoice.period.from" class="mt-2 text-xs text-muted-foreground">
                        Service period: {{ invoice.period.from }} — {{ invoice.period.to }}
                    </div>
                </div>
            </div>

            <!-- Line items -->
            <table class="mt-6 w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <th class="pb-3 font-medium">Description</th>
                        <th class="pb-3 text-right font-medium">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-b">
                        <td class="py-3.5">
                            <div class="font-medium">{{ invoice.plan.name }} plan — subscription</div>
                            <div class="text-xs capitalize text-muted-foreground">
                                Billed {{ invoice.plan.interval }}<template v-if="invoice.sac"> · SAC {{ invoice.sac }}</template>
                            </div>
                        </td>
                        <td class="py-3.5 text-right tabular-nums">{{ inr(invoice.plan.price) }}</td>
                    </tr>
                    <tr v-if="invoice.discount" class="border-b">
                        <td class="py-3">Coupon discount <span v-if="invoice.coupon_code" class="text-xs text-muted-foreground">({{ invoice.coupon_code }})</span></td>
                        <td class="py-3 text-right tabular-nums text-emerald-600">− {{ inr(invoice.discount) }}</td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-3 text-muted-foreground">Taxable value</td>
                        <td class="py-3 text-right tabular-nums">{{ inr(invoice.subtotal) }}</td>
                    </tr>
                    <template v-if="split && intraState">
                        <tr class="border-b">
                            <td class="py-3 text-muted-foreground">CGST ({{ halfRate }}%)</td>
                            <td class="py-3 text-right tabular-nums">{{ inr(invoice.cgst_amount) }}</td>
                        </tr>
                        <tr class="border-b">
                            <td class="py-3 text-muted-foreground">SGST ({{ halfRate }}%)</td>
                            <td class="py-3 text-right tabular-nums">{{ inr(invoice.sgst_amount) }}</td>
                        </tr>
                    </template>
                    <tr v-else-if="split" class="border-b">
                        <td class="py-3 text-muted-foreground">IGST ({{ Number(invoice.gst_percent ?? 0) }}%)</td>
                        <td class="py-3 text-right tabular-nums">{{ inr(invoice.igst_amount) }}</td>
                    </tr>
                    <tr v-else class="border-b">
                        <td class="py-3 text-muted-foreground">GST ({{ Number(invoice.gst_percent ?? 0) }}%)</td>
                        <td class="py-3 text-right tabular-nums">{{ inr(invoice.gst_amount) }}</td>
                    </tr>
                    <tr>
                        <td class="py-4 text-base font-bold">Total</td>
                        <td class="py-4 text-right text-base font-bold tabular-nums">{{ inr(invoice.total) }}</td>
                    </tr>
                </tbody>
            </table>

            <p class="mt-8 border-t pt-4 text-center text-[11px] text-muted-foreground">
                This is a computer-generated tax invoice and does not require a signature.
            </p>
        </div>
    </div>
</template>

<style>
@media print {
    /* Only the invoice sheet should print */
    body * {
        visibility: hidden;
    }
    #invoice-sheet,
    #invoice-sheet * {
        visibility: visible;
    }
    #invoice-sheet {
        position: absolute;
        inset: 0;
        margin: 0;
    }
}
</style>
