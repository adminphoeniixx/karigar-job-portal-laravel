<script setup lang="ts">
import { Bot, X } from '@lucide/vue';
import { ref, watch } from 'vue';

// "View conversation": what the AI agent and the karigar said on one
// screening call, as a chat. Fetched when it opens, so the pages that list
// calls do not carry every transcript.

interface Turn {
    speaker: 'agent' | 'karigar';
    text: string;
}

interface Conversation {
    worker_name: string | null;
    outcome_label: string | null;
    summary: string | null;
    called_label: string | null;
    conversation: Turn[];
    // Signed link to the call's audio; null until the agent has uploaded it.
    recording_url: string | null;
}

const props = defineProps<{ callId: number | null }>();
const emit = defineEmits<{ close: [] }>();

const data = ref<Conversation | null>(null);
const loading = ref(false);
const failed = ref(false);

watch(
    () => props.callId,
    async (id) => {
        data.value = null;
        failed.value = false;
        if (id === null) return;

        loading.value = true;
        try {
            const res = await fetch(`/employer/screening-calls/${id}/conversation`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error(String(res.status));
            // The popup may have been closed or switched to another call meanwhile.
            if (props.callId === id) data.value = await res.json();
        } catch {
            if (props.callId === id) failed.value = true;
        } finally {
            if (props.callId === id) loading.value = false;
        }
    },
    { immediate: true },
);
</script>

<template>
    <div v-if="callId !== null" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="emit('close')">
        <div class="flex max-h-[85vh] w-full max-w-lg flex-col rounded-2xl border bg-card shadow-xl">
            <header class="flex items-start justify-between gap-3 border-b p-5">
                <div class="min-w-0">
                    <h3 class="text-lg font-semibold">
                        {{ data?.worker_name ? $t('applicants.screening.conversationWith', { name: data.worker_name }) : $t('applicants.screening.conversation') }}
                    </h3>
                    <p v-if="data?.called_label" class="mt-0.5 text-xs text-muted-foreground">
                        {{ data.called_label }}<template v-if="data.outcome_label"> · {{ data.outcome_label }}</template>
                    </p>
                </div>
                <button type="button" class="rounded-lg p-1 text-muted-foreground transition hover:bg-muted hover:text-foreground" :aria-label="$t('common.cancel')" @click="emit('close')">
                    <X class="size-5" />
                </button>
            </header>

            <div class="overflow-y-auto p-5">
                <p v-if="loading" class="text-sm text-muted-foreground">{{ $t('common.loading') }}</p>
                <p v-else-if="failed" class="text-sm text-rose-600">{{ $t('applicants.screening.conversationFailed') }}</p>
                <template v-else-if="data">
                    <div v-if="data.recording_url" class="mb-4">
                        <p class="mb-1.5 text-xs font-semibold text-muted-foreground">{{ $t('applicants.screening.recording') }}</p>
                        <audio :src="data.recording_url" controls preload="metadata" class="w-full" />
                    </div>

                    <p v-if="data.summary" class="mb-4 rounded-xl bg-muted/50 px-3 py-2 text-sm text-muted-foreground">{{ data.summary }}</p>

                    <p v-if="!data.conversation.length" class="text-sm text-muted-foreground">{{ $t('applicants.screening.conversationEmpty') }}</p>
                    <ol v-else class="flex flex-col gap-2.5">
                        <li v-for="(turn, i) in data.conversation" :key="i" class="flex" :class="turn.speaker === 'karigar' ? 'justify-end' : 'justify-start'">
                            <div
                                class="max-w-[85%] rounded-2xl px-3.5 py-2 text-sm"
                                :class="turn.speaker === 'karigar' ? 'rounded-br-md bg-orange-600 text-white' : 'rounded-bl-md bg-muted'"
                            >
                                <span class="mb-0.5 flex items-center gap-1 text-[11px] font-semibold opacity-75">
                                    <Bot v-if="turn.speaker === 'agent'" class="size-3" />
                                    {{ turn.speaker === 'agent' ? $t('applicants.screening.agent') : (data.worker_name ?? $t('applicants.screening.karigar')) }}
                                </span>
                                {{ turn.text }}
                            </div>
                        </li>
                    </ol>
                </template>
            </div>
        </div>
    </div>
</template>
