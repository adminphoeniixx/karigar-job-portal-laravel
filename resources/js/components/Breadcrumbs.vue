<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { useI18n } from 'vue-i18n';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

type Props = {
    breadcrumbs: BreadcrumbItemType[];
};

defineProps<Props>();

const { t } = useI18n();

// Breadcrumb titles arrive as English literals; translate the known ones.
const known: Record<string, string> = {
    Dashboard: 'nav.dashboard',
    'Browse Jobs': 'nav.browseJobs',
    'My Applications': 'nav.myApplications',
    'Saved Jobs': 'nav.savedJobs',
    'My Profile': 'nav.myProfile',
    'KYC Verification': 'nav.kyc',
    'My Jobs': 'nav.myJobs',
    Applicants: 'applicants.title',
    Shortlisted: 'nav.shortlisted',
    Team: 'nav.team',
    Subscription: 'nav.subscription',
    'Company Profile': 'nav.companyProfile',
    Job: 'applications.job',
    Invoice: 'subscription.taxInvoices',
    Checkout: 'subscription.totalPayable',
    Notifications: 'nav.notifications',
};

const tr = (title: string): string => (known[title] ? t(known[title]) : title);
</script>

<template>
    <Breadcrumb>
        <BreadcrumbList>
            <template v-for="(item, index) in breadcrumbs" :key="index">
                <BreadcrumbItem>
                    <template v-if="index === breadcrumbs.length - 1">
                        <BreadcrumbPage>{{ tr(item.title) }}</BreadcrumbPage>
                    </template>
                    <template v-else>
                        <BreadcrumbLink as-child>
                            <Link :href="item.href">{{ tr(item.title) }}</Link>
                        </BreadcrumbLink>
                    </template>
                </BreadcrumbItem>
                <BreadcrumbSeparator v-if="index !== breadcrumbs.length - 1" />
            </template>
        </BreadcrumbList>
    </Breadcrumb>
</template>
