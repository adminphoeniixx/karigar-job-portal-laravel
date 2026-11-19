<script setup lang="ts">
// The job details added on 2026-11-19, as the karigar reads them on a job
// page: job type, education, English, age, languages, what to bring, where
// karigars may come from, working days, the interview's days, hours and
// place, bonus and anything else asked. Rows the job does not fill are left
// out. `ruled` matches the public job page; `card` the karigar's own.
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ageRange, assetKey, daysLabel, educationKey, englishKey, hoursLabel, jobTypeKey } from '@/lib/jobDetails';
import { rupees } from '@/lib/utils';

export interface JobRequirementFields {
    job_type?: string | null;
    education?: string | null;
    english?: string | null;
    age_min?: number | null;
    age_max?: number | null;
    languages?: string[] | null;
    assets?: string[] | null;
    reach?: string | null;
    relocation_allowed?: boolean | null;
    work_days?: string[] | null;
    interview_mode?: string | null;
    interview_days?: string[] | null;
    interview_start?: string | null;
    interview_end?: string | null;
    interview_address?: string | null;
    incentive_max?: string | null;
    incentive_type?: string | null;
    other_requirements?: string | null;
    city?: string | null;
}

const props = withDefaults(defineProps<{ job: JobRequirementFields; variant?: 'ruled' | 'card' }>(), { variant: 'card' });

const { t } = useI18n();

const rows = computed(() => {
    const j = props.job;
    const age = ageRange(j.age_min, j.age_max);
    const interview = [daysLabel(j.interview_days, t), hoursLabel(j.interview_start, j.interview_end)].filter(Boolean).join(' · ');
    const interviewPlace = j.interview_mode !== 'remote' && j.interview_address ? t('jobDetail.interviewAt', { address: j.interview_address }) : null;
    const incentive = j.incentive_max && Number(j.incentive_max) > 0 ? [t('jobDetail.incentiveValue', { amount: `₹${rupees(j.incentive_max)}` }), j.incentive_type].filter(Boolean).join(' · ') : null;

    return [
        { label: t('jobDetail.jobTypeLabel'), value: j.job_type ? t(jobTypeKey(j.job_type)) : null },
        { label: t('jobDetail.educationLabel'), value: j.education ? t(educationKey(j.education)) : null },
        { label: t('jobDetail.englishLabel'), value: j.english ? t(englishKey(j.english)) : null },
        { label: t('jobDetail.ageLabel'), value: age ? t('jobDetail.ageValue', { range: age }) : null },
        { label: t('jobDetail.languagesLabel'), value: j.languages?.length ? j.languages.join(', ') : null },
        { label: t('jobDetail.assetsLabel'), value: j.assets?.length ? j.assets.map((a) => t(assetKey(a))).join(', ') : null },
        {
            label: t('jobDetail.reachLabel'),
            value: j.reach === 'nearby' ? t('jobDetail.reachNearby', { km: 10 }) : j.reach === 'city' ? t('jobDetail.reachCity', { city: j.city || t('jobForm.theCity') }) : null,
        },
        { label: t('jobDetail.workDaysLabel'), value: daysLabel(j.work_days, t) },
        { label: t('jobDetail.interviewLabel'), value: [interview, interviewPlace].filter(Boolean).join(' · ') || null },
        { label: t('jobDetail.incentiveLabel'), value: incentive },
        { label: t('jobDetail.otherLabel'), value: j.other_requirements || null },
    ].filter((row): row is { label: string; value: string } => !!row.value);
});
</script>

<template>
    <div v-if="rows.length || job.relocation_allowed">
        <dl v-if="variant === 'ruled'" class="divide-y divide-foreground/10 border-b border-foreground/10">
            <div v-for="row in rows" :key="row.label" class="grid gap-1 py-4 text-sm sm:grid-cols-[12rem_1fr]">
                <dt class="label-rule text-muted-foreground">{{ row.label }}</dt>
                <dd class="font-semibold break-words whitespace-pre-line">{{ row.value }}</dd>
            </div>
            <p v-if="job.relocation_allowed" class="py-4 text-sm font-medium text-primary">{{ $t('jobDetail.relocation') }}</p>
        </dl>
        <template v-else>
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[10rem_1fr]">
                <template v-for="row in rows" :key="row.label">
                    <dt class="text-muted-foreground">{{ row.label }}</dt>
                    <dd class="font-medium break-words whitespace-pre-line">{{ row.value }}</dd>
                </template>
            </dl>
            <p v-if="job.relocation_allowed" class="mt-3 text-xs font-medium text-emerald-700 dark:text-emerald-300">{{ $t('jobDetail.relocation') }}</p>
        </template>
    </div>
</template>
