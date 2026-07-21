<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, Settings as SettingsIcon } from '@lucide/vue';
import { reactive } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

const props = defineProps<{
    settings: {
        first_post_free_enabled: boolean;
    };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Settings', href: '/admin/settings' }] } });

const form = reactive({
    first_post_free_enabled: props.settings.first_post_free_enabled,
});

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
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="font-semibold">First job post free</h3>
                    <p class="mt-1 text-sm text-muted-foreground">
                        When on, every employer can post their first job for free — even without a
                        subscription. After that, an active plan is required to post more jobs.
                        When off, posting jobs always requires an active plan.
                    </p>
                </div>

                <button
                    type="button"
                    role="switch"
                    :aria-checked="form.first_post_free_enabled"
                    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition"
                    :class="form.first_post_free_enabled ? 'bg-emerald-500' : 'bg-muted'"
                    @click="form.first_post_free_enabled = !form.first_post_free_enabled"
                >
                    <span
                        class="inline-block size-5 transform rounded-full bg-white shadow transition"
                        :class="form.first_post_free_enabled ? 'translate-x-5' : 'translate-x-0.5'"
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
