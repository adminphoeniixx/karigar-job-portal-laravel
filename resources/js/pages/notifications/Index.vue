<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Bell, CheckCheck } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';

interface Notification {
    id: string;
    data: { message?: string; url?: string; type?: string };
    read_at: string | null;
    created_at: string;
}

defineProps<{
    notifications: { data: Notification[]; links: { url: string | null; label: string; active: boolean }[] };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Notifications', href: '/notifications' }] } });

const open = (n: Notification) => {
    router.post(`/notifications/${n.id}/read`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            if (n.data.url) router.visit(n.data.url);
        },
    });
};

const markAll = () => router.post('/notifications/read-all', {}, { preserveScroll: true });
</script>

<template>
    <Head title="Notifications" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="Bell" title="Notifications" description="Your activity updates">
            <template #action>
                <button class="inline-flex items-center gap-1.5 rounded-xl border px-4 py-2 text-sm font-semibold transition hover:bg-muted" @click="markAll">
                    <CheckCheck class="size-4" /> Mark all read
                </button>
            </template>
        </PageHeader>

        <div v-if="notifications.data.length" class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <button
                v-for="n in notifications.data"
                :key="n.id"
                class="flex w-full items-start gap-3 border-b px-5 py-4 text-left transition last:border-0 hover:bg-muted/40"
                :class="!n.read_at && 'bg-teal-500/[0.04]'"
                @click="open(n)"
            >
                <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="n.read_at ? 'bg-transparent' : 'bg-teal-500'"></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm" :class="!n.read_at && 'font-medium'">{{ n.data.message ?? 'Notification' }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ n.created_at }}</p>
                </div>
            </button>
        </div>

        <div v-else class="rounded-2xl border bg-card px-5 py-16 text-center shadow-sm">
            <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground"><Bell class="size-7" /></div>
            <p class="mt-4 font-medium">No notifications</p>
            <p class="mt-1 text-sm text-muted-foreground">You're all caught up.</p>
        </div>

        <Link v-if="false" href="#">pagination placeholder</Link>
    </div>
</template>
