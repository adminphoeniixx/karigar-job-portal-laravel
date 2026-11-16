<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { BellRing, CalendarDays, Clock, Gauge, Info, Loader2, PhoneCall, SlidersHorizontal, Users } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Choice {
    value: string | number;
    label: string;
    description?: string;
}

interface Settings {
    trigger: 'shortlist' | 'apply';
    delay_minutes: number;
    window_start: string | null;
    window_end: string | null;
    days: number[];
    who: 'all' | 'score' | 'manual';
    min_score: number;
    daily_limit: number;
    reminder_call: boolean;
}

const props = defineProps<{
    settings: Settings;
    options: {
        triggers: Choice[];
        delays: Choice[];
        who: Choice[];
        days: Choice[];
        window: { start: string; end: string; timezone: string };
    };
    callsOn: boolean;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'AI Call Settings', href: '/employer/ai-call-settings' }] } });

const form = useForm<Settings>({
    trigger: props.settings.trigger,
    delay_minutes: props.settings.delay_minutes,
    window_start: props.settings.window_start,
    window_end: props.settings.window_end,
    days: [...props.settings.days],
    who: props.settings.who,
    min_score: props.settings.min_score,
    daily_limit: props.settings.daily_limit,
    reminder_call: props.settings.reminder_call,
});

// "Own hours" is a convenience over the two time fields: off means the
// platform's hours, on reveals the pickers pre-filled with them.
const ownHours = computed({
    get: () => form.window_start !== null || form.window_end !== null,
    set: (on: boolean) => {
        form.window_start = on ? (form.window_start ?? props.options.window.start) : null;
        form.window_end = on ? (form.window_end ?? props.options.window.end) : null;
    },
});

function toggleDay(day: number) {
    form.days = form.days.includes(day) ? form.days.filter((d) => d !== day) : [...form.days, day].sort((a, b) => a - b);
}

function save() {
    form.patch('/employer/ai-call-settings', { preserveScroll: true });
}

const windowLabel = computed(() => `${props.options.window.start}–${props.options.window.end} (${props.options.window.timezone === 'Asia/Kolkata' ? 'IST' : props.options.window.timezone})`);
</script>

<template>
    <Head title="AI Call Settings" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :icon="SlidersHorizontal"
            title="AI Call Settings"
            description="When the AI rings your applicants, in which hours, who it calls, and the reminder on interview day. These apply to every job on your account."
        />

        <div v-if="!callsOn" class="flex items-start gap-3 rounded-2xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm text-amber-800 dark:text-amber-200">
            <Info class="mt-0.5 size-4 shrink-0" />
            AI calls are switched off on the platform right now. Your settings are saved and take effect as soon as calls are on.
        </div>

        <form class="grid gap-6 lg:grid-cols-2" @submit.prevent="save">
            <!-- When -->
            <section class="rounded-2xl border bg-card p-6 shadow-sm">
                <h2 class="flex items-center gap-2 text-base font-semibold"><PhoneCall class="size-4 text-orange-500" /> When is an applicant called?</h2>
                <p class="mt-1 text-sm text-muted-foreground">The AI call asks whether they still want the job, their experience, pay, and when they could interview.</p>

                <div class="mt-4 grid gap-3">
                    <label
                        v-for="t in options.triggers"
                        :key="t.value"
                        class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition"
                        :class="form.trigger === t.value ? 'border-orange-500 bg-orange-500/5 ring-2 ring-orange-500/20' : 'hover:border-orange-300'"
                    >
                        <input v-model="form.trigger" type="radio" name="trigger" :value="t.value" class="mt-1 accent-orange-600" />
                        <span>
                            <span class="block text-sm font-semibold">{{ t.label }}</span>
                            <span class="block text-xs text-muted-foreground">{{ t.description }}</span>
                        </span>
                    </label>
                </div>
                <InputError class="mt-2" :message="form.errors.trigger" />

                <Label class="mt-5 mb-2 block">How soon after that?</Label>
                <select v-model.number="form.delay_minutes" class="h-10 w-full rounded-lg border bg-background px-3 text-sm">
                    <option v-for="d in options.delays" :key="d.value" :value="d.value">{{ d.label }}</option>
                </select>
                <InputError class="mt-2" :message="form.errors.delay_minutes" />
                <p class="mt-2 text-xs text-muted-foreground">A call that falls outside your hours waits for them to open. Pressing <strong>Call</strong> on an applicant always rings right away.</p>
            </section>

            <!-- Hours & days -->
            <section class="rounded-2xl border bg-card p-6 shadow-sm">
                <h2 class="flex items-center gap-2 text-base font-semibold"><Clock class="size-4 text-orange-500" /> Calling hours</h2>
                <p class="mt-1 text-sm text-muted-foreground">The platform calls between {{ windowLabel }}. You can narrow that, not widen it.</p>

                <label class="mt-4 flex cursor-pointer items-center gap-3">
                    <input v-model="ownHours" type="checkbox" class="size-4 accent-orange-600" />
                    <span class="text-sm font-medium">Use my own hours</span>
                </label>

                <div v-if="ownHours" class="mt-3 grid grid-cols-2 gap-3">
                    <div>
                        <Label for="window_start" class="mb-1 block text-xs">From</Label>
                        <input id="window_start" v-model="form.window_start" type="time" :min="options.window.start" :max="options.window.end" step="900" class="h-10 w-full rounded-lg border bg-background px-3 text-sm" />
                        <InputError class="mt-1" :message="form.errors.window_start" />
                    </div>
                    <div>
                        <Label for="window_end" class="mb-1 block text-xs">To</Label>
                        <input id="window_end" v-model="form.window_end" type="time" :min="options.window.start" :max="options.window.end" step="900" class="h-10 w-full rounded-lg border bg-background px-3 text-sm" />
                        <InputError class="mt-1" :message="form.errors.window_end" />
                    </div>
                </div>

                <Label class="mt-5 mb-2 flex items-center gap-2"><CalendarDays class="size-4 text-orange-500" /> Days</Label>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="d in options.days"
                        :key="d.value"
                        type="button"
                        class="rounded-full border px-3 py-1.5 text-sm font-medium transition"
                        :class="form.days.includes(Number(d.value)) ? 'border-orange-500 bg-orange-500 text-white' : 'hover:border-orange-300'"
                        @click="toggleDay(Number(d.value))"
                    >
                        {{ d.label }}
                    </button>
                </div>
                <InputError class="mt-2" :message="form.errors.days" />
                <p class="mt-2 text-xs text-muted-foreground">A call due on a day that is off moves to the next day that is on.</p>
            </section>

            <!-- Who -->
            <section class="rounded-2xl border bg-card p-6 shadow-sm">
                <h2 class="flex items-center gap-2 text-base font-semibold"><Users class="size-4 text-orange-500" /> Who gets an automatic call?</h2>

                <div class="mt-4 grid gap-3">
                    <label
                        v-for="w in options.who"
                        :key="w.value"
                        class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition"
                        :class="form.who === w.value ? 'border-orange-500 bg-orange-500/5 ring-2 ring-orange-500/20' : 'hover:border-orange-300'"
                    >
                        <input v-model="form.who" type="radio" name="who" :value="w.value" class="mt-1 accent-orange-600" />
                        <span>
                            <span class="block text-sm font-semibold">{{ w.label }}</span>
                            <span class="block text-xs text-muted-foreground">{{ w.description }}</span>
                        </span>
                    </label>
                </div>
                <InputError class="mt-2" :message="form.errors.who" />

                <div v-if="form.who === 'score'" class="mt-4">
                    <Label for="min_score" class="mb-1 block">Minimum AI match score</Label>
                    <div class="flex items-center gap-3">
                        <input id="min_score" v-model.number="form.min_score" type="range" min="0" max="100" step="5" class="w-full accent-orange-600" />
                        <span class="w-12 text-right text-sm font-semibold tabular-nums">{{ form.min_score }}</span>
                    </div>
                    <InputError class="mt-1" :message="form.errors.min_score" />
                </div>
            </section>

            <!-- Limits & reminder -->
            <section class="rounded-2xl border bg-card p-6 shadow-sm">
                <h2 class="flex items-center gap-2 text-base font-semibold"><Gauge class="size-4 text-orange-500" /> Daily limit per job</h2>
                <p class="mt-1 text-sm text-muted-foreground">At most this many automatic calls a day for one job. Extra applicants are booked for the next day. 0 means no limit.</p>
                <div class="mt-3 flex items-center gap-3">
                    <Input id="daily_limit" v-model.number="form.daily_limit" type="number" min="0" max="500" class="w-28" />
                    <span class="text-sm text-muted-foreground">calls / job / day</span>
                </div>
                <InputError class="mt-1" :message="form.errors.daily_limit" />

                <h2 class="mt-6 flex items-center gap-2 text-base font-semibold"><BellRing class="size-4 text-orange-500" /> Interview day</h2>
                <p class="mt-1 text-sm text-muted-foreground">On the morning of a booked interview, you and the karigar both get a reminder. The AI can also ring the karigar to ask whether they are coming.</p>
                <label class="mt-3 flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition" :class="form.reminder_call ? 'border-orange-500 bg-orange-500/5' : ''">
                    <input v-model="form.reminder_call" type="checkbox" class="mt-1 size-4 accent-orange-600" />
                    <span>
                        <span class="block text-sm font-semibold">AI reminder call on interview day</span>
                        <span class="block text-xs text-muted-foreground">A short call, under a minute when they say yes. Uses AI call credits like any other call. If they cannot make it, the AI asks which time suits and tells you.</span>
                    </span>
                </label>
                <InputError class="mt-1" :message="form.errors.reminder_call" />
            </section>

            <div class="flex items-center gap-3 lg:col-span-2">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-2 rounded-xl bg-orange-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-orange-700 disabled:opacity-60"
                >
                    <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                    Save settings
                </button>
                <span v-if="form.recentlySuccessful" class="text-sm text-emerald-700 dark:text-emerald-300">Saved.</span>
            </div>
        </form>
    </div>
</template>
