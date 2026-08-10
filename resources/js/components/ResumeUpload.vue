<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { FileText, Trash2, Upload } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';

export interface Resume {
    name: string | null;
    uploaded_ago: string | null;
    characters: number;
    max_characters: number;
}

/**
 * The worker's resume, wherever it needs to be offered: the profile page, and
 * the apply panel on a job (that is the moment they care). Uploads on pick —
 * there is nothing else in this form to wait for.
 *
 * `compact` drops the character count and the removal button, for the narrow
 * apply column.
 */
withDefaults(defineProps<{ resume: Resume | null; compact?: boolean }>(), { compact: false });

const { t } = useI18n();

const form = useForm<{ resume: File | null }>({ resume: null });

const onPick = (e: Event) => {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    if (!file) return;
    form.resume = file;
    form.post('/worker/resume', {
        preserveScroll: true,
        // Let the same file be picked again after a failed upload.
        onFinish: () => (input.value = ''),
    });
};

const remove = () => {
    if (window.confirm(t('resume.confirmRemove'))) {
        router.delete('/worker/resume', { preserveScroll: true });
    }
};
</script>

<template>
    <div>
        <!-- Uploaded -->
        <div v-if="resume" class="flex flex-wrap items-center gap-3 rounded-xl border border-orange-500/20 bg-orange-500/5 p-3">
            <FileText class="size-5 shrink-0 text-orange-600" />
            <div class="min-w-0 flex-1">
                <a href="/worker/resume" target="_blank" class="block truncate text-sm font-medium underline-offset-2 hover:underline">{{ resume.name }}</a>
                <p class="text-xs text-muted-foreground">
                    <span v-if="resume.uploaded_ago">{{ $t('resume.uploadedAgo', { when: resume.uploaded_ago }) }}</span>
                    <span v-if="!compact">
                        <span v-if="resume.uploaded_ago"> · </span>{{ $t('resume.parsed', { count: resume.characters }) }}
                    </span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <label
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition hover:bg-muted"
                    :class="{ 'pointer-events-none opacity-50': form.processing }"
                >
                    <Upload class="size-3.5" />
                    {{ form.processing ? $t('resume.uploading') : $t('resume.replace') }}
                    <input type="file" accept="application/pdf,.pdf" class="hidden" @change="onPick" />
                </label>
                <button
                    v-if="!compact"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-500/30 dark:hover:bg-rose-500/10"
                    @click="remove"
                >
                    <Trash2 class="size-3.5" /> {{ $t('common.remove') }}
                </button>
            </div>
        </div>

        <!-- Nothing uploaded yet -->
        <label
            v-else
            class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border border-dashed bg-muted/30 px-4 text-center transition hover:border-orange-500/50 hover:bg-muted/50"
            :class="[compact ? 'py-4' : 'py-6', { 'pointer-events-none opacity-50': form.processing }]"
        >
            <Upload class="size-5 text-orange-500" />
            <span class="text-xs font-medium">{{ form.processing ? $t('resume.uploading') : $t('resume.upload') }}</span>
            <input type="file" accept="application/pdf,.pdf" class="hidden" @change="onPick" />
        </label>

        <p class="mt-2 text-xs text-muted-foreground">{{ compact ? $t('resume.applyHint') : $t('resume.hint') }}</p>
        <InputError :message="form.errors.resume" />
    </div>
</template>
