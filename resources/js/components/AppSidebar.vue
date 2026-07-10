<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Bookmark,
    BriefcaseBusiness,
    Building2,
    ChartColumn,
    HardHat,
    ClipboardCheck,
    CreditCard,
    Crown,
    FileText,
    Gauge,
    Layers,
    LayoutGrid,
    LifeBuoy,
    Mail,
    Plus,
    Star,
    TicketPercent,
    Search,
    ShieldCheck,
    Tags,
    Users,
    UserRound,
    UsersRound,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const { t } = useI18n();
const page = usePage();
const role = computed(() => page.props.auth.user?.role ?? 'worker');
const teamRole = computed(() => (page.props.auth as { teamRole?: string | null }).teamRole ?? 'owner');

const navByRole = computed((): Record<string, NavItem[]> => ({
    worker: [
        { title: t('nav.dashboard'), href: dashboard().url, icon: LayoutGrid },
        { title: t('nav.browseJobs'), href: '/worker/jobs', icon: Search },
        { title: t('nav.myApplications'), href: '/worker/applications', icon: FileText },
        { title: t('nav.savedJobs'), href: '/worker/saved', icon: Bookmark },
        { title: t('nav.myProfile'), href: '/worker/profile', icon: UserRound },
        { title: t('nav.kyc'), href: '/kyc', icon: ShieldCheck },
    ],
    employer: [
        { title: t('nav.dashboard'), href: dashboard().url, icon: LayoutGrid },
        { title: t('nav.myJobs'), href: '/employer/jobs', icon: BriefcaseBusiness },
        { title: t('nav.postJob'), href: '/employer/jobs/create', icon: Plus },
        { title: t('nav.shortlisted'), href: '/employer/shortlisted', icon: Star },
        { title: t('nav.findWorkers'), href: '/employer/workers', icon: Users },
        { title: t('nav.team'), href: '/employer/team', icon: UsersRound },
        { title: t('nav.subscription'), href: '/subscription', icon: CreditCard },
        { title: t('nav.companyProfile'), href: '/employer/profile', icon: UserRound },
        { title: t('nav.kyc'), href: '/kyc', icon: ShieldCheck },
    ],
    admin: [
        { title: 'Dashboard', href: dashboard().url, icon: LayoutGrid },
        { title: 'Overview', href: '/admin/overview', icon: Gauge },
        { title: 'Reports', href: '/admin/reports', icon: ChartColumn },
        { title: 'Employers', href: '/admin/employers', icon: Building2 },
        { title: 'Karigars', href: '/admin/karigars', icon: HardHat },
        { title: 'Users', href: '/admin/users', icon: Users },
        { title: 'Job Moderation', href: '/admin/jobs', icon: BriefcaseBusiness },
        { title: 'Escrows', href: '/admin/escrows', icon: ShieldCheck },
        { title: 'KYC Review', href: '/admin/kyc', icon: ClipboardCheck },
        { title: 'Categories', href: '/admin/categories', icon: Tags },
        { title: 'Plans & Limits', href: '/admin/plans', icon: Layers },
        { title: 'Coupons', href: '/admin/coupons', icon: TicketPercent },
        { title: 'Email Templates', href: '/admin/email-templates', icon: Mail },
    ],
}));

const mainNavItems = computed(() => {
    let items = navByRole.value[role.value] ?? navByRole.value.worker;

    // Team members see a trimmed menu: owner-only pages are hidden, and
    // recruiters (applicants-only) also lose job posting.
    if (role.value === 'employer' && teamRole.value !== 'owner') {
        const ownerOnly = ['/employer/team', '/subscription', '/employer/profile', '/kyc'];
        items = items.filter((i) => !ownerOnly.includes(i.href as string));
        if (teamRole.value === 'recruiter') {
            items = items.filter((i) => i.href !== '/employer/jobs/create');
        }
    }

    return items;
});

const planLabel = computed(
    () => ({ worker: t('auth.worker'), employer: t('auth.employer'), admin: 'Admin' })[role.value] ?? 'Member',
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton as-child tooltip="Support">
                        <Link href="/jobs">
                            <LifeBuoy />
                            <span>{{ t('nav.support') }}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>

            <!-- Plan badge (Velocity-style) -->
            <Link
                :href="role === 'employer' ? '/subscription' : dashboard()"
                class="mx-2 mb-1 flex items-center gap-2 rounded-xl bg-gradient-to-r from-orange-600 to-rose-500 px-3 py-2.5 text-white shadow-md transition hover:opacity-95 group-data-[collapsible=icon]:hidden"
            >
                <Crown class="size-5 shrink-0" />
                <div class="leading-tight">
                    <div class="text-sm font-semibold">{{ planLabel }} {{ t('nav.plan') }}</div>
                    <div class="text-[11px] text-white/80">{{ t('nav.manageAccount') }}</div>
                </div>
            </Link>

            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
