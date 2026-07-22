<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

type StepState = 'done' | 'current' | 'upcoming' | 'rejected' | 'skipped';

interface Step {
    key: string;
    state: StepState;
    at: string | null;
    result: string | null;
}

const props = defineProps<{
    steps: Step[];
    /** Compact mode drops the header + date lines (for list rows). */
    compact?: boolean;
}>();

const { t } = useI18n();

function labelFor(step: Step): string {
    if (step.key === 'decision') {
        if (step.result === 'accepted') return t('tracker.selected');
        if (step.result === 'rejected') return t('tracker.notSelected');
        if (step.result === 'withdrawn') return t('tracker.withdrawn');
        return t('tracker.decision');
    }
    return t(`tracker.${step.key}`);
}

function subFor(step: Step): string {
    if (step.at) {
        return new Intl.DateTimeFormat(undefined, {
            day: 'numeric',
            month: 'short',
            hour: 'numeric',
            minute: '2-digit',
        }).format(new Date(step.at));
    }
    if (step.state === 'current') return t('tracker.inProgress');
    if (step.state === 'skipped') return t('tracker.skipped');
    if (step.state === 'upcoming') return t('tracker.pending');
    return '';
}

// Colour + icon per state.
function dotClass(state: StepState): string {
    switch (state) {
        case 'done':
            return 'bg-emerald-500 border-emerald-500 text-white';
        case 'current':
            return 'bg-orange-500 border-orange-500 text-white ring-4 ring-orange-500/20 animate-pulse';
        case 'rejected':
            return 'bg-red-500 border-red-500 text-white';
        case 'skipped':
            return 'bg-muted border-border text-muted-foreground';
        default:
            return 'bg-background border-border text-transparent';
    }
}

function lineClass(state: StepState): string {
    return state === 'done' ? 'bg-emerald-500' : state === 'rejected' ? 'bg-red-500' : 'bg-border';
}

const isTerminalRejected = computed(() => props.steps.some((s) => s.state === 'rejected'));
</script>

<template>
    <div>
        <h3 v-if="!compact" class="mb-3 text-sm font-semibold text-foreground">{{ $t('tracker.title') }}</h3>
        <ol class="relative">
            <li v-for="(step, i) in steps" :key="step.key" class="flex gap-3" :class="compact ? 'pb-4 last:pb-0' : 'pb-5 last:pb-0'">
                <!-- Icon + connector column -->
                <div class="relative flex flex-col items-center">
                    <span
                        class="z-10 grid size-6 place-items-center rounded-full border-2 text-[11px] font-bold transition-colors"
                        :class="dotClass(step.state)"
                    >
                        <template v-if="step.state === 'done'">✓</template>
                        <template v-else-if="step.state === 'rejected'">✕</template>
                        <template v-else-if="step.state === 'current'">●</template>
                    </span>
                    <span
                        v-if="i < steps.length - 1"
                        class="absolute top-6 h-full w-0.5"
                        :class="lineClass(step.state)"
                    />
                </div>

                <!-- Label column -->
                <div class="min-w-0 flex-1 pt-0.5">
                    <p
                        class="text-sm font-medium leading-tight"
                        :class="{
                            'text-emerald-600 dark:text-emerald-400': step.state === 'done' && step.result === 'accepted',
                            'text-red-600 dark:text-red-400': step.state === 'rejected',
                            'text-foreground': step.state === 'current' || (step.state === 'done' && step.result !== 'accepted'),
                            'text-muted-foreground': step.state === 'upcoming' || step.state === 'skipped',
                        }"
                    >
                        {{ labelFor(step) }}
                    </p>
                    <p v-if="!compact && subFor(step)" class="mt-0.5 text-xs text-muted-foreground">{{ subFor(step) }}</p>
                </div>
            </li>
        </ol>
        <p v-if="isTerminalRejected && !compact" class="mt-1 text-xs text-muted-foreground">
            {{ $t('tracker.rejectedHint') }}
        </p>
    </div>
</template>
