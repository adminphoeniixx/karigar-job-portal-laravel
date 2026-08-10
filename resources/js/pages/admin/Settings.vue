<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, Settings as SettingsIcon } from '@lucide/vue';
import { computed, reactive } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

type SettingKey =
    | 'first_post_free_enabled'
    | 'kyc_verification_enabled'
    | 'ai_auto_shortlist_enabled'
    | 'ai_auto_reject_enabled';

const props = defineProps<{
    settings: Record<SettingKey, boolean> & {
        ai_auto_shortlist_threshold: number;
        ai_auto_reject_below: number;
    };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Settings', href: '/admin/settings' }] } });

const form = reactive({
    first_post_free_enabled: props.settings.first_post_free_enabled,
    kyc_verification_enabled: props.settings.kyc_verification_enabled,
    ai_auto_shortlist_enabled: props.settings.ai_auto_shortlist_enabled,
    ai_auto_shortlist_threshold: props.settings.ai_auto_shortlist_threshold,
    ai_auto_reject_enabled: props.settings.ai_auto_reject_enabled,
    ai_auto_reject_below: props.settings.ai_auto_reject_below,
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
    {
        key: 'ai_auto_shortlist_enabled',
        title: 'AI auto-shortlist',
        description:
            'Every applicant is always scored by the AI and ranked best-match-first — that never ' +
            'changes. This only controls whether a high scorer is shortlisted automatically. ' +
            'When off, shortlisting stays a manual employer action. When on, any applicant ' +
            'scoring at or above the threshold below is shortlisted and the worker is notified.',
    },
    {
        key: 'ai_auto_reject_enabled',
        title: 'AI auto-reject',
        description:
            'When on, an applicant scoring below the floor below is rejected automatically and told so. ' +
            'Only untouched applications are affected — never one the employer has already shortlisted, ' +
            'interviewed or decided. Leave this off unless you trust the scores: a wrong auto-reject ' +
            'costs a real worker a real job.',
    },
];

// Mirrors AiMatcher's recommendation buckets, so the admin can see how wide a
// net the chosen threshold casts before saving it.
const thresholdHint = computed(() => {
    const n = form.ai_auto_shortlist_threshold;
    const bucket = n >= 80 ? 'only "strong match" applicants' : n >= 60 ? '"good match" and above' : '"maybe" and above — a wide net';

    return `Shortlists ${bucket}. Each auto-shortlist notifies the worker, so a lower number means more notifications.`;
});

// Warn when the two bands are set close together — the gap between them is the
// range the employer still decides for themselves.
const rejectHint = computed(() => {
    const floor = form.ai_auto_reject_below;
    const top = form.ai_auto_shortlist_enabled ? form.ai_auto_shortlist_threshold : 100;
    const band = `Applicants scoring under ${floor}% are rejected and notified.`;

    return floor >= top
        ? `${band} This overlaps your shortlist threshold — widen the gap.`
        : `${band} ${floor}–${top}% is left for the employer to decide.`;
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
            <div
                v-for="(toggle, index) in toggles"
                :key="toggle.key"
                :class="index > 0 ? 'mt-5 border-t pt-5' : ''"
            >
                <div class="flex items-start justify-between gap-4">
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

                <!-- Threshold only matters while auto-shortlisting is on. -->
                <div
                    v-if="toggle.key === 'ai_auto_shortlist_enabled' && form.ai_auto_shortlist_enabled"
                    class="mt-4 rounded-xl bg-muted/50 p-4"
                >
                    <label class="font-medium text-sm" for="ai-threshold">
                        Auto-shortlist at score
                    </label>
                    <div class="mt-2 flex items-center gap-3">
                        <input
                            id="ai-threshold"
                            v-model.number="form.ai_auto_shortlist_threshold"
                            type="range"
                            min="40"
                            max="100"
                            step="5"
                            class="h-2 w-full max-w-xs accent-orange-500"
                        />
                        <span class="w-14 shrink-0 text-right font-semibold tabular-nums">
                            {{ form.ai_auto_shortlist_threshold }}%
                        </span>
                    </div>
                    <p class="mt-2 text-xs text-muted-foreground">
                        {{ thresholdHint }}
                    </p>
                </div>

                <!-- Reject floor only matters while auto-reject is on. -->
                <div
                    v-if="toggle.key === 'ai_auto_reject_enabled' && form.ai_auto_reject_enabled"
                    class="mt-4 rounded-xl border border-rose-200 bg-rose-50/60 p-4 dark:border-rose-900 dark:bg-rose-950/30"
                >
                    <label class="font-medium text-sm" for="ai-reject-below">
                        Auto-reject below score
                    </label>
                    <div class="mt-2 flex items-center gap-3">
                        <input
                            id="ai-reject-below"
                            v-model.number="form.ai_auto_reject_below"
                            type="range"
                            min="5"
                            max="40"
                            step="5"
                            class="h-2 w-full max-w-xs accent-rose-500"
                        />
                        <span class="w-14 shrink-0 text-right font-semibold tabular-nums">
                            {{ form.ai_auto_reject_below }}%
                        </span>
                    </div>
                    <p class="mt-2 text-xs text-muted-foreground">{{ rejectHint }}</p>
                </div>
            </div>

            <div class="mt-5 flex justify-end border-t pt-4">
                <button
                    class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95"
                    @click="save"
                >
                    <Check class="size-4" /> Save
                </button>
            </div>
        </div>
    </div>
</template>
