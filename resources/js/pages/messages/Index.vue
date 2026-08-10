<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, BriefcaseBusiness, MessageSquare, Send } from '@lucide/vue';
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

interface Counterpart {
    id: number | null;
    name: string;
    initial: string;
    subtitle: string | null;
}

interface Thread {
    id: number;
    counterpart: Counterpart;
    job: string | null;
    last_message: string | null;
    last_is_mine: boolean;
    last_at: string | null;
    unread: number;
}

interface Message {
    id: number;
    body: string;
    mine: boolean;
    sender: string | null;
    at: string | null;
    read: boolean;
}

interface Active {
    id: number;
    counterpart: Counterpart;
    job: { id: number; title: string } | null;
    messages: Message[];
}

const props = defineProps<{ conversations: Thread[]; active: Active | null }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Messages', href: '/messages' }] } });

const form = useForm({ body: '' });
const scroller = ref<HTMLElement | null>(null);

const scrollToBottom = () => {
    nextTick(() => {
        if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight;
    });
};

const send = () => {
    if (!props.active || !form.body.trim()) return;

    form.post(`/messages/${props.active.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            form.reset('body');
            scrollToBottom();
        },
    });
};

// Enter sends, Shift+Enter makes a new line.
const onKeydown = (e: KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        send();
    }
};

// No websockets yet — poll the open thread and the unread badges.
let poller: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    scrollToBottom();
    poller = setInterval(() => {
        if (form.processing) return;
        // reload() already preserves scroll and component state.
        router.reload({ only: ['conversations', 'active', 'chatUnread'] });
    }, 10000);
});

onBeforeUnmount(() => clearInterval(poller));

watch(() => props.active?.messages.length, scrollToBottom);
watch(() => props.active?.id, scrollToBottom);
</script>

<template>
    <Head title="Messages" />

    <div class="flex h-[calc(100dvh-7rem)] min-h-125 flex-col gap-4 p-4 md:p-6">
        <div class="flex min-h-0 flex-1 overflow-hidden rounded-2xl border bg-card shadow-sm">
            <!-- Thread list -->
            <aside
                class="flex w-full shrink-0 flex-col border-r md:w-80"
                :class="active ? 'hidden md:flex' : 'flex'"
            >
                <div class="border-b px-4 py-3">
                    <h2 class="flex items-center gap-2 font-semibold">
                        <MessageSquare class="size-4 text-orange-600" /> {{ $t('chat.title') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ $t('chat.subtitle') }}</p>
                </div>

                <div v-if="conversations.length" class="min-h-0 flex-1 overflow-y-auto">
                    <Link
                        v-for="c in conversations"
                        :key="c.id"
                        :href="`/messages/${c.id}`"
                        preserve-scroll
                        class="flex w-full items-start gap-3 border-b px-4 py-3 text-left transition hover:bg-muted/60"
                        :class="active?.id === c.id ? 'bg-orange-500/10' : ''"
                    >
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary text-sm font-bold text-white">
                            {{ c.counterpart.initial }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center justify-between gap-2">
                                <span class="truncate text-sm font-semibold">{{ c.counterpart.name }}</span>
                                <span class="shrink-0 text-[10px] text-muted-foreground">{{ c.last_at }}</span>
                            </span>
                            <span v-if="c.job" class="mt-0.5 flex items-center gap-1 truncate text-[11px] text-muted-foreground">
                                <BriefcaseBusiness class="size-3 shrink-0" /> {{ c.job }}
                            </span>
                            <span class="mt-0.5 flex items-center justify-between gap-2">
                                <span class="truncate text-xs text-muted-foreground">
                                    <template v-if="c.last_is_mine">{{ $t('chat.you') }}: </template>{{ c.last_message ?? $t('chat.noMessages') }}
                                </span>
                                <span
                                    v-if="c.unread"
                                    class="inline-flex min-w-5 shrink-0 items-center justify-center rounded-full bg-rose-500 px-1.5 py-0.5 text-[10px] font-bold text-white"
                                >
                                    {{ c.unread > 99 ? '99+' : c.unread }}
                                </span>
                            </span>
                        </span>
                    </Link>
                </div>

                <div v-else class="flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center">
                    <MessageSquare class="size-8 text-muted-foreground/50" />
                    <p class="text-sm font-medium">{{ $t('chat.empty') }}</p>
                    <p class="text-xs text-muted-foreground">{{ $t('chat.emptyHint') }}</p>
                </div>
            </aside>

            <!-- Open thread -->
            <section v-if="active" class="flex min-w-0 flex-1 flex-col">
                <header class="flex items-center gap-3 border-b px-4 py-3">
                    <Link href="/messages" class="rounded-lg p-1.5 transition hover:bg-muted md:hidden">
                        <ArrowLeft class="size-4" />
                    </Link>
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary text-sm font-bold text-white">
                        {{ active.counterpart.initial }}
                    </span>
                    <div class="min-w-0">
                        <h3 class="truncate font-semibold">{{ active.counterpart.name }}</h3>
                        <p class="truncate text-xs text-muted-foreground">
                            <template v-if="active.job">{{ $t('chat.startedFrom') }} {{ active.job.title }}</template>
                            <template v-else>{{ active.counterpart.subtitle ?? '—' }}</template>
                        </p>
                    </div>
                </header>

                <div ref="scroller" class="min-h-0 flex-1 space-y-3 overflow-y-auto bg-muted/30 p-4">
                    <p v-if="!active.messages.length" class="py-8 text-center text-sm text-muted-foreground">
                        {{ $t('chat.noMessages') }}
                    </p>
                    <div
                        v-for="m in active.messages"
                        :key="m.id"
                        class="flex"
                        :class="m.mine ? 'justify-end' : 'justify-start'"
                    >
                        <div
                            class="max-w-[80%] rounded-2xl px-3.5 py-2 text-sm shadow-sm sm:max-w-[65%]"
                            :class="m.mine
                                ? 'rounded-br-sm bg-orange-600 text-white'
                                : 'rounded-bl-sm border bg-card'"
                        >
                            <p class="whitespace-pre-wrap break-words">{{ m.body }}</p>
                            <p class="mt-1 text-right text-[10px]" :class="m.mine ? 'text-white/70' : 'text-muted-foreground'">
                                {{ m.at }}<span v-if="m.mine && m.read"> · {{ $t('chat.read') }}</span>
                            </p>
                        </div>
                    </div>
                </div>

                <form class="flex items-end gap-2 border-t p-3" @submit.prevent="send">
                    <textarea
                        v-model="form.body"
                        rows="1"
                        maxlength="2000"
                        :placeholder="$t('chat.placeholder')"
                        class="max-h-32 min-h-10 flex-1 resize-y rounded-xl border bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-orange-500/40"
                        @keydown="onKeydown"
                    />
                    <button
                        type="submit"
                        :disabled="form.processing || !form.body.trim()"
                        class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-orange-600 text-white transition hover:bg-orange-700 disabled:opacity-40"
                    >
                        <Send class="size-4" />
                    </button>
                </form>
            </section>

            <!-- Nothing selected (desktop only — mobile shows the list instead) -->
            <section v-else class="hidden flex-1 flex-col items-center justify-center gap-2 p-8 text-center md:flex">
                <MessageSquare class="size-10 text-muted-foreground/40" />
                <p class="font-medium">{{ $t('chat.selectThread') }}</p>
                <p class="max-w-xs text-sm text-muted-foreground">{{ $t('chat.selectHint') }}</p>
            </section>
        </div>
    </div>
</template>
