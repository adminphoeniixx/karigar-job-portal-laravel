<script setup lang="ts">
// "Karigars matching this job" on the review step, as WorkIndia shows
// "Candidates matching your job post" before posting: a count and the top
// five, from GET /employer/jobs/matching-karigars. No phone numbers: the
// Worker Database is where an employer unlocks those.
import { BadgeCheck, MapPin, Users } from '@lucide/vue';
import { ref, watch } from 'vue';

export interface MatchParams {
    category: string;
    skills: string[];
    latitude: string | number;
    longitude: string | number;
    city: string;
    reach: string;
    gender: string;
    education: string;
    experience_level: string;
    experience_min: string | number;
}

interface Karigar {
    id: number;
    name: string;
    avatar_url: string | null;
    gender: string | null;
    experience_years: number | null;
    education: string | null;
    languages: string[];
    skills: string[];
    verified: boolean;
    distance_label: string | null;
    location_label: string | null;
}

const props = defineProps<{ params: MatchParams }>();

const loading = ref(false);
const failed = ref(false);
const result = ref<{ count: number; radius_km: number; karigars: Karigar[] } | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;
let seq = 0;

const load = () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        const mine = ++seq;
        loading.value = true;
        failed.value = false;
        try {
            const query = new URLSearchParams();
            for (const [key, value] of Object.entries(props.params)) {
                if (Array.isArray(value)) value.forEach((v) => query.append(`${key}[]`, v));
                else if (value !== '' && value !== null && value !== undefined) query.set(key, String(value));
            }
            const res = await fetch(`/employer/jobs/matching-karigars?${query}`, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(String(res.status));
            const data = await res.json();
            if (mine === seq) result.value = data;
        } catch {
            if (mine === seq) failed.value = true;
        } finally {
            if (mine === seq) loading.value = false;
        }
    }, 300);
};

watch(() => JSON.stringify(props.params), load, { immediate: true });

const initials = (name: string) =>
    name
        .split(/\s+/)
        .map((p) => p[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
</script>

<template>
    <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
        <h2 class="flex items-center gap-2 text-sm font-semibold text-muted-foreground">
            <Users class="size-4 text-orange-500" /> {{ $t('jobForm.matchingTitle') }}
        </h2>

        <p v-if="loading && !result" class="mt-4 text-sm text-muted-foreground">{{ $t('jobForm.matchingLoading') }}</p>
        <p v-else-if="failed" class="mt-4 text-sm text-muted-foreground">{{ $t('jobForm.matchingFailed') }}</p>

        <template v-else-if="result">
            <div class="mt-3 flex flex-wrap items-baseline gap-x-3 gap-y-1" :class="loading ? 'opacity-60' : ''">
                <span class="text-4xl font-bold tracking-tight text-orange-600 dark:text-orange-400">{{ result.count }}</span>
                <span class="text-sm font-medium">{{ $t('jobForm.matchingCount', { count: result.count }, result.count) }}</span>
                <span class="text-xs text-muted-foreground">{{ $t('jobForm.matchingWithin', { km: result.radius_km }) }}</span>
            </div>

            <p v-if="result.count === 0" class="mt-3 text-sm text-muted-foreground">{{ $t('jobForm.matchingNone') }}</p>

            <ul v-else class="mt-4 grid gap-2">
                <li v-for="k in result.karigars" :key="k.id" class="flex items-start gap-3 rounded-xl border p-3">
                    <img v-if="k.avatar_url" :src="k.avatar_url" alt="" class="size-10 shrink-0 rounded-full object-cover" />
                    <span v-else class="flex size-10 shrink-0 items-center justify-center rounded-full bg-orange-500/10 text-xs font-bold text-orange-700 dark:text-orange-300">{{ initials(k.name) }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-1.5 text-sm font-semibold">
                            {{ k.name }}
                            <BadgeCheck v-if="k.verified" class="size-3.5 text-emerald-600" />
                            <span class="text-xs font-normal text-muted-foreground">
                                · {{ k.experience_years ? $t('jobForm.yrsExp', { n: k.experience_years }) : $t('jobForm.fresher') }}
                            </span>
                        </p>
                        <p v-if="k.location_label || k.distance_label" class="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                            <MapPin class="size-3 shrink-0" />
                            {{ [k.location_label, k.distance_label].filter(Boolean).join(' · ') }}
                        </p>
                        <p v-if="k.skills.length || k.languages.length || k.education" class="mt-1 text-xs text-muted-foreground">
                            {{ [k.skills.slice(0, 3).join(', '), k.education, k.languages.join(', ')].filter(Boolean).join(' · ') }}
                        </p>
                    </div>
                </li>
            </ul>

            <p class="mt-3 text-xs text-muted-foreground">{{ $t('jobForm.matchingFoot') }}</p>
        </template>
    </section>
</template>
