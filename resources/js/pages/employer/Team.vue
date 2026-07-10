<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Phone, Plus, ShieldCheck, Trash2, Users } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '@/components/PageHeader.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Member {
    id: number;
    name: string;
    phone: string | null;
    role: 'manager' | 'recruiter';
    added: string;
}

defineProps<{ members: Member[] }>();

const { t } = useI18n();

defineOptions({ layout: { breadcrumbs: [{ title: 'Team', href: '/employer/team' }] } });

const form = useForm({
    name: '',
    phone: '',
    role: 'recruiter' as 'manager' | 'recruiter',
});

const roleInfo: Record<string, { label: string; desc: string; pill: string }> = {
    manager: {
        label: 'team.manager',
        desc: 'team.managerDesc',
        pill: 'bg-orange-500/10 text-orange-600 ring-orange-500/20 dark:text-orange-300',
    },
    recruiter: {
        label: 'team.recruiter',
        desc: 'team.recruiterDesc',
        pill: 'bg-blue-500/10 text-blue-600 ring-blue-500/20 dark:text-blue-300',
    },
};

const add = () => {
    form.post('/employer/team', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};

const changeRole = (member: Member, event: Event) => {
    const role = (event.target as HTMLSelectElement).value;
    router.patch(`/employer/team/${member.id}`, { role }, { preserveScroll: true });
};

const remove = (member: Member) => {
    if (window.confirm(t('team.removeConfirm', { name: member.name }))) {
        router.delete(`/employer/team/${member.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Team" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="Users" :title="$t('team.title')" :description="$t('team.subtitle')" />

        <!-- Add member -->
        <form class="rounded-2xl border bg-card p-5 shadow-sm md:p-6" @submit.prevent="add">
            <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                <Plus class="size-4 text-orange-500" /> {{ $t('team.addMember') }}
            </h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="member_name">{{ $t('common.name') }} <span class="font-normal text-muted-foreground">({{ $t('common.optional') }})</span></Label>
                    <Input id="member_name" v-model="form.name" placeholder="e.g. Rakesh" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="member_phone">{{ $t('team.mobileNumber') }}</Label>
                    <div class="flex items-center gap-2">
                        <span class="flex h-9 items-center rounded-md border bg-muted px-2.5 text-sm font-semibold text-muted-foreground">+91</span>
                        <Input id="member_phone" v-model="form.phone" type="tel" inputmode="numeric" maxlength="10" placeholder="9876543210" />
                    </div>
                    <InputError :message="form.errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="member_role">{{ $t('team.role') }}</Label>
                    <select
                        id="member_role"
                        v-model="form.role"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20"
                    >
                        <option value="manager">{{ $t('team.manager') }}</option>
                        <option value="recruiter">{{ $t('team.recruiter') }}</option>
                    </select>
                    <InputError :message="form.errors.role" />
                </div>
            </div>
            <p class="mt-3 text-xs text-muted-foreground">{{ $t(roleInfo[form.role].desc) }}. {{ $t('team.otpHint') }}</p>
            <div class="mt-4 flex justify-end">
                <button
                    type="submit"
                    :disabled="form.processing || form.phone.length !== 10"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-rose-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:opacity-90 active:scale-95 disabled:opacity-50"
                >
                    <Plus class="size-4" /> {{ $t('team.addBtn') }}
                </button>
            </div>
        </form>

        <!-- Members list -->
        <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div class="border-b px-6 py-4">
                <h2 class="flex items-center gap-2 text-sm font-semibold"><ShieldCheck class="size-4 text-orange-500" /> {{ $t('team.yourTeam') }}</h2>
            </div>
            <div v-if="members.length" class="divide-y">
                <div v-for="m in members" :key="m.id" class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-rose-500 text-sm font-bold text-white">
                            {{ m.name.charAt(0).toUpperCase() }}
                        </span>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="truncate font-semibold">{{ m.name }}</span>
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset" :class="roleInfo[m.role].pill">
                                    {{ $t(roleInfo[m.role].label) }}
                                </span>
                            </div>
                            <div class="flex items-center gap-3 text-xs text-muted-foreground">
                                <span v-if="m.phone" class="inline-flex items-center gap-1"><Phone class="size-3" /> +91 {{ m.phone }}</span>
                                <span>{{ $t('team.added') }} {{ m.added }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <select
                            :value="m.role"
                            class="h-8 rounded-lg border bg-background px-2 text-xs font-medium focus:outline-none focus:ring-2 focus:ring-orange-500/30"
                            @change="changeRole(m, $event)"
                        >
                            <option value="manager">{{ $t('team.manager') }}</option>
                            <option value="recruiter">{{ $t('team.recruiter') }}</option>
                        </select>
                        <button
                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-500/10 dark:text-rose-400"
                            @click="remove(m)"
                        >
                            <Trash2 class="size-3.5" /> {{ $t('common.remove') }}
                        </button>
                    </div>
                </div>
            </div>
            <div v-else class="px-6 py-14 text-center">
                <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground"><Users class="size-7" /></div>
                <p class="mt-4 font-medium">{{ $t('team.empty') }}</p>
                <p class="mt-1 text-sm text-muted-foreground">{{ $t('team.emptyHint') }}</p>
            </div>
        </div>
    </div>
</template>
