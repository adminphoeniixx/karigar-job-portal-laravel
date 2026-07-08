<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, Mail, Send, X } from '@lucide/vue';
import { reactive, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

interface Template {
    id: number;
    key: string;
    name: string;
    description: string | null;
    subject: string;
    body_html: string;
    placeholders: string[] | null;
    is_active: boolean;
}

const props = defineProps<{ templates: Template[] }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Email Templates', href: '/admin/email-templates' }] } });

// A local editable copy per template, keyed by id.
const drafts = reactive<Record<number, { subject: string; body_html: string; is_active: boolean }>>(
    Object.fromEntries(
        props.templates.map((t) => [t.id, { subject: t.subject, body_html: t.body_html, is_active: t.is_active }]),
    ),
);

const saving = ref<number | null>(null);
const testEmail = reactive<Record<number, string>>({});

const save = (t: Template) => {
    saving.value = t.id;
    router.patch(`/admin/email-templates/${t.id}`, drafts[t.id], {
        preserveScroll: true,
        onFinish: () => (saving.value = null),
    });
};

const sendTest = (t: Template) => {
    if (!testEmail[t.id]) return;
    router.post(`/admin/email-templates/${t.id}/test`, { email: testEmail[t.id] }, { preserveScroll: true });
};

const token = (ph: string) => `{{ ${ph} }}`;

const insertPlaceholder = (t: Template, ph: string) => {
    drafts[t.id].body_html += token(ph);
};
</script>

<template>
    <Head title="Email Templates" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :icon="Mail"
            title="Email Templates"
            description="Edit the wording of automated emails. Placeholder tokens get replaced with real values when the email is sent."
        />

        <div v-for="t in templates" :key="t.id" class="rounded-2xl border bg-card shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-semibold">{{ t.name }}</span>
                        <span
                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset"
                            :class="drafts[t.id].is_active ? 'bg-orange-500/10 text-orange-600 ring-orange-500/20 dark:text-orange-300' : 'bg-muted text-muted-foreground ring-border'"
                        >
                            {{ drafts[t.id].is_active ? 'Active' : 'Paused' }}
                        </span>
                    </div>
                    <p v-if="t.description" class="mt-0.5 text-xs text-muted-foreground">{{ t.description }}</p>
                    <code class="text-[11px] text-muted-foreground">{{ t.key }}</code>
                </div>
                <button
                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition"
                    :class="drafts[t.id].is_active ? 'text-muted-foreground hover:bg-muted' : 'text-orange-600 hover:bg-orange-500/10'"
                    @click="drafts[t.id].is_active = !drafts[t.id].is_active"
                >
                    <component :is="drafts[t.id].is_active ? X : Check" class="size-3.5" />
                    {{ drafts[t.id].is_active ? 'Pause' : 'Activate' }}
                </button>
            </div>

            <div class="flex flex-col gap-4 p-5">
                <!-- Subject -->
                <div>
                    <label class="mb-1 block text-sm font-medium">Subject</label>
                    <input
                        v-model="drafts[t.id].subject"
                        class="w-full rounded-xl border bg-background px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40"
                    />
                </div>

                <!-- Body -->
                <div>
                    <label class="mb-1 block text-sm font-medium">Body (HTML)</label>
                    <textarea
                        v-model="drafts[t.id].body_html"
                        rows="10"
                        class="w-full rounded-xl border bg-background px-4 py-2.5 font-mono text-xs leading-relaxed focus:outline-none focus:ring-2 focus:ring-orange-500/40"
                    ></textarea>
                </div>

                <!-- Placeholders -->
                <div v-if="t.placeholders?.length">
                    <p class="mb-1.5 text-xs font-medium text-muted-foreground">Available placeholders (click to insert):</p>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="ph in t.placeholders"
                            :key="ph"
                            type="button"
                            class="rounded-full bg-muted px-2.5 py-1 font-mono text-[11px] text-foreground transition hover:bg-orange-500/10 hover:text-orange-600"
                            @click="insertPlaceholder(t, ph)"
                        >
                            {{ token(ph) }}
                        </button>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                    <div class="flex items-center gap-2">
                        <input
                            v-model="testEmail[t.id]"
                            type="email"
                            placeholder="you@example.com"
                            class="w-48 rounded-lg border bg-background px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-orange-500/40"
                        />
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground"
                            @click="sendTest(t)"
                        >
                            <Send class="size-3.5" /> Send test
                        </button>
                    </div>
                    <button
                        type="button"
                        :disabled="saving === t.id"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-rose-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95 disabled:opacity-60"
                        @click="save(t)"
                    >
                        <Check class="size-4" /> Save changes
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
