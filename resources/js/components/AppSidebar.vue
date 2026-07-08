<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Bookmark,
    BriefcaseBusiness,
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
} from '@lucide/vue';
import { computed } from 'vue';
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

const page = usePage();
const role = computed(() => page.props.auth.user?.role ?? 'worker');

const navByRole: Record<string, NavItem[]> = {
    worker: [
        { title: 'Dashboard', href: dashboard().url, icon: LayoutGrid },
        { title: 'Browse Jobs', href: '/worker/jobs', icon: Search },
        { title: 'My Applications', href: '/worker/applications', icon: FileText },
        { title: 'Saved Jobs', href: '/worker/saved', icon: Bookmark },
        { title: 'My Profile', href: '/worker/profile', icon: UserRound },
        { title: 'KYC Verification', href: '/kyc', icon: ShieldCheck },
    ],
    employer: [
        { title: 'Dashboard', href: dashboard().url, icon: LayoutGrid },
        { title: 'My Jobs', href: '/employer/jobs', icon: BriefcaseBusiness },
        { title: 'Post a Job', href: '/employer/jobs/create', icon: Plus },
        { title: 'Shortlisted', href: '/employer/shortlisted', icon: Star },
        { title: 'Find Workers', href: '/employer/workers', icon: Users },
        { title: 'Subscription', href: '/subscription', icon: CreditCard },
        { title: 'Company Profile', href: '/employer/profile', icon: UserRound },
        { title: 'KYC Verification', href: '/kyc', icon: ShieldCheck },
    ],
    admin: [
        { title: 'Dashboard', href: dashboard().url, icon: LayoutGrid },
        { title: 'Overview', href: '/admin/overview', icon: Gauge },
        { title: 'Users', href: '/admin/users', icon: Users },
        { title: 'Job Moderation', href: '/admin/jobs', icon: BriefcaseBusiness },
        { title: 'Escrows', href: '/admin/escrows', icon: ShieldCheck },
        { title: 'KYC Review', href: '/admin/kyc', icon: ClipboardCheck },
        { title: 'Categories', href: '/admin/categories', icon: Tags },
        { title: 'Plans & Limits', href: '/admin/plans', icon: Layers },
        { title: 'Coupons', href: '/admin/coupons', icon: TicketPercent },
        { title: 'Email Templates', href: '/admin/email-templates', icon: Mail },
    ],
};

const mainNavItems = computed(() => navByRole[role.value] ?? navByRole.worker);

const planLabel = computed(
    () => ({ worker: 'Worker', employer: 'Employer', admin: 'Admin' })[role.value] ?? 'Member',
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
                            <span>Support</span>
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
                    <div class="text-sm font-semibold">{{ planLabel }} Plan</div>
                    <div class="text-[11px] text-white/80">Manage account</div>
                </div>
            </Link>

            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
