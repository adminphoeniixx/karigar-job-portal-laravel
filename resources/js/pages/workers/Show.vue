<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, BadgeCheck, Briefcase, Mail, MapPin, Phone, Star, UserRound } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';

interface Review {
    rating: number;
    comment: string | null;
    reviewer: string;
    created_at: string;
}

defineProps<{
    worker: {
        id: number;
        user_id: number;
        name: string | null;
        avatar_url: string | null;
        bio: string | null;
        skills: string[];
        city: string | null;
        state: string | null;
        experience_years: number | null;
        expected_wage: string | null;
        wage_type: string | null;
        available: boolean;
        phone: string | null;
        email: string | null;
        contact_unlocked: boolean;
    };
    reviews: { average: number; count: number; items: Review[] } | null;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Find Workers', href: '/employer/workers' }, { title: 'Profile', href: '#' }] } });
</script>

<template>
    <Head :title="worker.name ?? 'Worker'" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Link href="/employer/workers" class="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground transition hover:text-foreground">
            <ArrowLeft class="size-4" /> Back to directory
        </Link>

        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Main -->
            <div class="lg:col-span-2">
                <div class="rounded-2xl border bg-card p-6 shadow-sm">
                    <div class="flex items-center gap-4">
                        <img v-if="worker.avatar_url" :src="worker.avatar_url" alt="" class="size-16 rounded-2xl object-cover" />
                        <div v-else class="flex size-16 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-rose-600 text-white"><UserRound class="size-8" /></div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h1 class="text-2xl font-bold tracking-tight">{{ worker.name }}</h1>
                                <span v-if="worker.available" class="inline-flex items-center gap-1 rounded-full bg-orange-500/10 px-2 py-0.5 text-xs font-semibold text-orange-600 dark:text-orange-300"><BadgeCheck class="size-3" /> Available</span>
                            </div>
                            <div class="mt-1 flex items-center gap-3 text-sm text-muted-foreground">
                                <span class="inline-flex items-center gap-1"><MapPin class="size-3.5" /> {{ [worker.city, worker.state].filter(Boolean).join(', ') || '—' }}</span>
                                <span v-if="reviews && reviews.count" class="inline-flex items-center gap-0.5 text-amber-500"><Star class="size-3.5" fill="currentColor" /> {{ reviews.average }} ({{ reviews.count }})</span>
                            </div>
                        </div>
                    </div>

                    <p v-if="worker.bio" class="mt-5 whitespace-pre-line text-sm leading-relaxed text-muted-foreground">{{ worker.bio }}</p>

                    <div v-if="worker.skills.length" class="mt-5">
                        <h2 class="mb-2 flex items-center gap-2 text-sm font-semibold"><Briefcase class="size-4 text-orange-600" /> Skills</h2>
                        <div class="flex flex-wrap gap-1.5">
                            <span v-for="s in worker.skills" :key="s" class="rounded-full bg-muted px-3 py-1 text-xs font-medium text-muted-foreground">{{ s }}</span>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-xl border p-3">
                            <div class="text-xs text-muted-foreground">Experience</div>
                            <div class="font-semibold">{{ worker.experience_years != null ? worker.experience_years + ' years' : '—' }}</div>
                        </div>
                        <div class="rounded-xl border p-3">
                            <div class="text-xs text-muted-foreground">Expected wage</div>
                            <div class="font-semibold">{{ worker.expected_wage ? '₹' + worker.expected_wage + (worker.wage_type ? ' / ' + worker.wage_type : '') : '—' }}</div>
                        </div>
                    </div>
                </div>

                <!-- Reviews -->
                <div v-if="reviews && reviews.items.length" class="mt-6 rounded-2xl border bg-card p-6 shadow-sm">
                    <h2 class="mb-4 text-lg font-semibold">Reviews</h2>
                    <div class="space-y-4">
                        <div v-for="(r, i) in reviews.items" :key="i" class="border-b pb-4 last:border-0 last:pb-0">
                            <div class="flex items-center justify-between">
                                <span class="font-medium">{{ r.reviewer }}</span>
                                <span class="inline-flex items-center gap-0.5 text-amber-500"><Star class="size-3.5" fill="currentColor" /> {{ r.rating }}</span>
                            </div>
                            <p v-if="r.comment" class="mt-1 text-sm text-muted-foreground">{{ r.comment }}</p>
                            <p class="mt-1 text-xs text-muted-foreground/70">{{ r.created_at }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact card -->
            <div>
                <div class="rounded-2xl border bg-card p-6 shadow-sm">
                    <h2 class="text-sm font-semibold">Contact</h2>
                    <div v-if="worker.contact_unlocked" class="mt-3 space-y-2 text-sm">
                        <p class="inline-flex items-center gap-2"><Mail class="size-4 text-orange-600" /> {{ worker.email }}</p>
                        <p v-if="worker.phone" class="inline-flex items-center gap-2"><Phone class="size-4 text-orange-600" /> {{ worker.phone }}</p>
                    </div>
                    <div v-else class="mt-3">
                        <p class="text-sm text-muted-foreground">Contact details unlock when you unlock this worker from one of your job's applicants.</p>
                        <Link href="/employer/jobs" class="mt-3 inline-flex rounded-xl border px-4 py-2 text-sm font-semibold transition hover:bg-muted">Go to my jobs</Link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
