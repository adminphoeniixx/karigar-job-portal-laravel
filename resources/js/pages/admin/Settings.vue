<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, Settings as SettingsIcon } from '@lucide/vue';
import { reactive } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

type SettingKey = 'first_post_free_enabled' | 'kyc_verification_enabled';

const props = defineProps<{
    settings: Record<SettingKey, boolean>;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Settings', href: '/admin/settings' }] } });

const form = reactive({
    first_post_free_enabled: props.settings.first_post_free_enabled,
    kyc_verification_enabled: props.settings.kyc_verification_enabled,
});

const toggles: { key: SettingKey; title: string; description: string }[] = [
    {
        key: 'first_post_free_enabled',
        title: 'First job post free',
        description:
            'When on, every employer can post their first job for free — even without a ' +
            'subscription. After that, an active plan is required to post more jobs. ' +
            'When off, posting jobs always requires an active plan.',
    },
    {
        key: 'kyc_verification_enabled',
        title: 'KYC verification',
        description:
            'When on, workers and employers can submit PAN/Aadhaar/GST for verification and ' +
            'approved ones show a verified badge. When off, the feature disappears everywhere — ' +
            'the apps hide their KYC screens, the badge stops showing, and the KYC endpoints ' +
            'return 404. Submitted documents are kept, so turning it back on restores them.',
    },
];

const save = () => {
    router.patch('/admin/settings', form, { preserveScroll: true });
};
</script>

<template>
    <Head title="Settings" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :icon="SettingsIcon"
            title="Settings"
            description="App-wide feature toggles."
        />

        <div class="rounded-2xl border bg-card p-5 shadow-sm">
            <div
                v-for="(toggle, index) in toggles"
                :key="toggle.key"
                class="flex items-start justify-between gap-4"
                :class="index > 0 ? 'mt-5 border-t pt-5' : ''"
            >
                <div>
                    <h3 class="font-semibold">{{ toggle.title }}</h3>
                    <p class="mt-1 text-sm text-muted-foreground">{{ toggle.description }}</p>
                </div>

                <button
                    type="button"
                    role="switch"
                    :aria-checked="form[toggle.key]"
                    :aria-label="toggle.title"
                    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition"
                    :class="form[toggle.key] ? 'bg-emerald-500' : 'bg-muted'"
                    @click="form[toggle.key] = !form[toggle.key]"
                >
                    <span
                        class="inline-block size-5 transform rounded-full bg-white shadow transition"
                        :class="form[toggle.key] ? 'translate-x-5' : 'translate-x-0.5'"
                    />
                </button>
            </div>

            <div class="mt-5 flex justify-end border-t pt-4">
                <button
                    class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-rose-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95"
                    @click="save"
                >
                    <Check class="size-4" /> Save
                </button>
            </div>
        </div>
    </div>
</template>
