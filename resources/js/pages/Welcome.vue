<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AirVent,
    ArrowRight,
    ArrowUpRight,
    BadgeCheck,
    Briefcase,
    Brush,
    Car,
    ChefHat,
    Cog,
    Flame,
    Hammer,
    HardHat,
    Languages,
    MapPin,
    Scissors,
    Search,
    ShieldCheck,
    Sparkles,
    Sprout,
    Star,
    Users,
    Wand2,
    Wrench,
    Zap,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import BrandWordmark from '@/components/BrandWordmark.vue';
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';

interface Job {
    id: number;
    title: string;
    category: string | null;
    city: string | null;
    state: string | null;
    wage_min: string | null;
    wage_max: string | null;
    wage_type: string | null;
    employer: { id: number; name: string };
}

const props = defineProps<{
    stats: { jobs: number; workers: number; employers: number; cities: number };
    latestJobs: Job[];
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);

const query = ref('');
const search = () => router.get('/jobs', query.value ? { q: query.value } : {});

const counters = ref({ jobs: 0, workers: 0, employers: 0, cities: 0 });
function animate(key: keyof typeof counters.value, to: number) {
    const start = performance.now();
    const step = (now: number) => {
        const p = Math.min((now - start) / 1400, 1);
        counters.value[key] = Math.floor((1 - Math.pow(1 - p, 3)) * to);
        if (p < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
}
// The landing page is a light-only design. A full page load already gets that
// from HomeController, but an Inertia visit from a dark app page keeps the
// class on <html> — drop it while this page is mounted, then hand it back.
let restoreDark = false;

onMounted(() => {
    restoreDark = document.documentElement.classList.contains('dark');
    document.documentElement.classList.remove('dark');

    animate('jobs', props.stats.jobs);
    animate('workers', props.stats.workers);
    animate('employers', props.stats.employers);
    animate('cities', props.stats.cities);
});

onUnmounted(() => {
    if (restoreDark) document.documentElement.classList.add('dark');
});

// Categories come from the admin (shared by HandleInertiaRequests), so the
// landing list always matches what employers can actually pick. Icons are
// matched by name; anything the admin adds later falls back to a briefcase.
const categoryIcons: Record<string, typeof Wrench> = {
    Plumbing: Wrench,
    Electrician: Zap,
    Carpenter: Hammer,
    Painter: Brush,
    Welder: Flame,
    Mason: HardHat,
    Mechanic: Cog,
    Cleaning: Sparkles,
    Gardening: Sprout,
    Cooking: ChefHat,
    Driver: Car,
    'Security Guard': ShieldCheck,
    'AC & Appliance Repair': AirVent,
    Tailoring: Scissors,
    Beautician: Wand2,
    'Labour / Helper': Users,
};

const categories = computed(() =>
    ((page.props.categories as string[] | undefined) ?? Object.keys(categoryIcons)).map((name) => ({
        name,
        icon: categoryIcons[name] ?? Briefcase,
    })),
);

// The links under the search field are the trades people actually search for —
// the full list is alphabetical, which would open with "AC & Appliance Repair".
const popularOrder = ['Plumbing', 'Electrician', 'Carpenter', 'Painter', 'Mechanic'];
const popularCategories = computed(() => {
    const names = categories.value.map((c) => c.name);
    const picked = popularOrder.filter((n) => names.includes(n));

    return (picked.length ? picked : names).slice(0, 5);
});

const features = [
    { icon: MapPin, title: 'Hyperlocal matching', desc: 'Find jobs and workers within your radius using precise geo-search.' },
    { icon: ShieldCheck, title: 'KYC verified', desc: 'PAN + Aadhaar verification builds trust on both sides.' },
    { icon: Zap, title: 'Instant search', desc: 'Typesense-powered results in milliseconds.' },
    { icon: Languages, title: 'Your language', desc: 'Hindi, English & Hinglish — switch anytime.' },
];

const steps = [
    { n: '01', title: 'Create your profile', desc: 'Sign up as a worker or employer and complete KYC for trust.' },
    { n: '02', title: 'Post or find work', desc: 'Employers post jobs; workers search nearby openings by skill & location.' },
    { n: '03', title: 'Connect & get hired', desc: 'Match with the right people and start working — simple and fast.' },
];

const testimonials = [
    { name: 'Ramesh K.', role: 'Electrician · Jaipur', quote: 'Got 3 jobs right near my home. Creating a profile was so easy.' },
    { name: 'Sunita Builders', role: 'Employer · Pune', quote: 'Hiring verified workers is effortless now. Brilliant platform.' },
    { name: 'Amit S.', role: 'Painter · Delhi', quote: 'The location filter only shows nearby work — saves me so much time.' },
];

const wage = (j: Job) => {
    if (!j.wage_min && !j.wage_max) return 'Negotiable';
    const range = [j.wage_min, j.wage_max].filter(Boolean).join('–');
    return `₹${range}${j.wage_type ? ' / ' + j.wage_type : ''}`;
};
</script>

<template>
    <Head title="Super Karigar — Skilled work, simplified" />

    <!--
      Landing photos (public/images/landing/) — Wikimedia Commons:
      electrician.jpg  "Male labour working at Building construction site" (CC BY-SA 4.0)
      welder.jpg       "Skilled Carpenter Working on Wood in a Workshop" (CC BY-SA 4.0)
      painter.jpg      "Fort Kochi - Wall Painters on ropes" (CC BY-SA 4.0)
      plumber.jpg      "Masons plastering the brick walk" (CC BY-SA 4.0)
      employer.jpg     "Uravu Bamboo Workshop - Workers - 2" by Ingo Mehling (CC BY-SA 4.0)
      weaver.jpg       "Chendamangalam-Weaving factory-WUS-09972" by Rainer Halama (CC BY-SA 4.0)
    -->

    <div class="theme-paper bg-noise min-h-screen bg-background text-foreground antialiased">
        <!-- Nav: a rule underneath, no floating pill, no shadow -->
        <header class="sticky top-0 z-40 border-b border-foreground/10 bg-background/85 backdrop-blur">
            <div class="mx-auto flex max-w-[88rem] items-center justify-between px-6 py-4 lg:px-10">
                <Link href="/" class="text-[18px]">
                    <BrandWordmark />
                </Link>
                <nav class="hidden items-center gap-8 text-[13px] font-medium text-muted-foreground lg:flex">
                    <a href="#trades" class="link-underline">Trades</a>
                    <a href="#jobs" class="link-underline">Jobs</a>
                    <a href="#how" class="link-underline">How it works</a>
                    <a href="#why" class="link-underline">Why us</a>
                </nav>
                <div class="flex items-center gap-3">
                    <LanguageSwitcher />
                    <Link
                        v-if="user"
                        href="/dashboard"
                        class="rounded-sm bg-foreground px-4 py-2 text-[13px] font-semibold text-background transition hover:bg-primary"
                    >
                        {{ $t('nav.dashboard') }}
                    </Link>
                    <Link v-else href="/employer/login" class="rounded-sm bg-foreground px-4 py-2 text-[13px] font-semibold text-background transition hover:bg-primary">
                        {{ $t('common.login') }}
                    </Link>
                </div>
            </div>
        </header>

        <!-- Hero: type on the left, image bleeding off the right edge. The left
             column keeps the 88rem measure while the image runs to the viewport. -->
        <section class="relative border-b border-foreground/10">
            <div class="grid gap-12 lg:grid-cols-[1.06fr_1fr] lg:gap-0">
                <div class="flex flex-col justify-center px-6 pt-14 lg:py-24 lg:pl-[max(2.5rem,calc((100vw-88rem)/2+2.5rem))] lg:pr-16">
                    <div class="flex items-center gap-3 text-primary">
                        <span class="h-px w-10 bg-primary"></span>
                        <span class="label-rule">{{ $t('landing.badge') }}</span>
                    </div>

                    <h1 class="display-xl mt-8">
                        {{ $t('landing.heroTitle') }}<br />
                        <span class="italic text-primary">{{ $t('landing.heroAccent') }}</span>
                    </h1>

                    <p class="mt-8 max-w-md text-[17px] leading-relaxed text-muted-foreground">
                        {{ $t('landing.heroSubtitle') }}
                    </p>

                    <!-- Search: a ruled field, not a boxed pill -->
                    <form class="mt-10 max-w-lg" @submit.prevent="search">
                        <div class="flex items-center gap-3 border-b-2 border-foreground/85 pb-3 transition focus-within:border-primary">
                            <Search class="size-5 shrink-0 text-foreground/50" />
                            <input
                                v-model="query"
                                type="text"
                                :placeholder="$t('landing.searchPlaceholder')"
                                class="w-full bg-transparent text-base outline-none placeholder:text-muted-foreground/70"
                            />
                            <button type="submit" class="shrink-0 rounded-sm bg-primary px-5 py-2 text-[13px] font-bold uppercase tracking-wider text-primary-foreground transition hover:bg-foreground">
                                {{ $t('common.search') }}
                            </button>
                        </div>
                        <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-[13px] text-muted-foreground">
                            <Link v-for="name in popularCategories" :key="name" :href="`/jobs?category=${name}`" class="link-underline">
                                {{ name }}
                            </Link>
                        </div>
                    </form>

                    <div class="mt-12 flex flex-wrap items-center gap-x-8 gap-y-3 text-[13px] text-muted-foreground">
                        <span class="inline-flex items-center gap-2"><Star class="size-4 fill-primary text-primary" /> <b class="font-semibold text-foreground">4.8</b> {{ $t('landing.rating') }}</span>
                        <span class="inline-flex items-center gap-2"><BadgeCheck class="size-4 text-primary" /> {{ $t('landing.kycVerified') }}</span>
                        <span class="inline-flex items-center gap-2"><MapPin class="size-4 text-primary" /> {{ stats.cities }}+ {{ $t('landing.citiesPlus') }}</span>
                    </div>
                </div>

                <!-- Full-bleed portrait, cropped by the viewport edge -->
                <div class="relative">
                    <img
                        src="/images/landing/weaver.jpg"
                        alt="Indian handloom weaver at her loom in a Kerala weaving factory"
                        class="h-72 w-full object-cover sm:h-96 lg:h-full lg:min-h-[40rem]"
                    />
                    <div class="absolute bottom-6 left-6 bg-background/95 px-5 py-4 backdrop-blur">
                        <div class="text-[13px] font-semibold">{{ $t('landing.trustKycTitle') }}</div>
                        <div class="mt-1 text-[13px] text-muted-foreground">{{ stats.workers }}+ {{ $t('landing.trustWorkers') }}</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Stats: bare numerals on a ruled row -->
        <section class="border-b border-foreground/10">
            <div class="mx-auto grid max-w-[88rem] grid-cols-2 divide-x divide-y divide-foreground/10 px-6 sm:grid-cols-4 sm:divide-y-0 lg:px-10">
                <div
                    v-for="(s, key) in { [$t('landing.activeJobs')]: counters.jobs, [$t('landing.workers')]: counters.workers, [$t('landing.employers')]: counters.employers, [$t('landing.cities')]: counters.cities }"
                    :key="key"
                    class="px-6 py-9 first:pl-0 sm:py-11"
                >
                    <div class="text-[2.75rem] font-bold leading-none tracking-tight sm:text-5xl">{{ s }}<span class="text-primary">+</span></div>
                    <div class="label-rule mt-3 text-muted-foreground">{{ key }}</div>
                </div>
            </div>
        </section>

        <!-- Two doors: full-bleed panels, text over image, no cards -->
        <section class="grid border-b border-foreground/10 md:grid-cols-2">
            <Link href="/worker/register" class="group relative flex min-h-[24rem] items-end overflow-hidden border-b border-foreground/10 md:border-b-0 md:border-r">
                <img src="/images/landing/welder.jpg" alt="Indian carpenter working in a workshop" loading="lazy" class="absolute inset-0 h-full w-full object-cover transition duration-[900ms] group-hover:scale-[1.05]" />
                <div class="absolute inset-0 bg-gradient-to-t from-[#17110c]/90 via-[#17110c]/45 to-transparent"></div>
                <div class="relative w-full p-8 text-background lg:p-12">
                    <div class="label-rule text-background/70">01 / {{ $t('landing.forWorkers') }}</div>
                    <h2 class="display-lg mt-4 max-w-sm text-background">{{ $t('landing.joinFree') }}</h2>
                    <p class="mt-4 max-w-sm text-sm leading-relaxed text-background/80">{{ $t('landing.forWorkersDesc') }}</p>
                    <span class="mt-7 inline-flex items-center gap-2 border-b border-background/50 pb-1 text-sm font-semibold">
                        {{ $t('landing.joinFree') }} <ArrowRight class="size-4 transition group-hover:translate-x-1" />
                    </span>
                </div>
            </Link>
            <Link href="/employer/register" class="group relative flex min-h-[24rem] items-end overflow-hidden">
                <img src="/images/landing/employer.jpg" alt="Karigar at work in a bamboo workshop in Kerala" loading="lazy" class="absolute inset-0 h-full w-full object-cover transition duration-[900ms] group-hover:scale-[1.05]" />
                <div class="absolute inset-0 bg-gradient-to-t from-[#17110c]/90 via-[#17110c]/45 to-transparent"></div>
                <div class="relative w-full p-8 text-background lg:p-12">
                    <div class="label-rule text-background/70">02 / {{ $t('landing.forEmployers') }}</div>
                    <h2 class="display-lg mt-4 max-w-sm text-background">{{ $t('landing.getStarted') }}</h2>
                    <p class="mt-4 max-w-sm text-sm leading-relaxed text-background/80">{{ $t('landing.forEmployersDesc') }}</p>
                    <span class="mt-7 inline-flex items-center gap-2 border-b border-background/50 pb-1 text-sm font-semibold">
                        {{ $t('landing.getStarted') }} <ArrowRight class="size-4 transition group-hover:translate-x-1" />
                    </span>
                </div>
            </Link>
        </section>

        <!-- Trades: an index, not a tile grid -->
        <section id="trades" class="border-b border-foreground/10">
            <div class="mx-auto max-w-[88rem] px-6 py-20 lg:px-10">
                <div class="flex flex-wrap items-end justify-between gap-6 border-b border-foreground/10 pb-8">
                    <div>
                        <div class="label-rule text-primary">03 / {{ $t('landing.popularCategories') }}</div>
                        <h2 class="display-lg mt-4 max-w-md">{{ categories.length }} trades, one app.</h2>
                    </div>
                    <Link href="/jobs" class="link-underline inline-flex items-center gap-2 pb-1 text-sm font-semibold">
                        {{ $t('common.viewAll') }} <ArrowRight class="size-4" />
                    </Link>
                </div>
                <div class="grid sm:grid-cols-2 lg:grid-cols-4">
                    <Link
                        v-for="c in categories"
                        :key="c.name"
                        :href="`/jobs?category=${c.name}`"
                        class="group flex items-center gap-4 border-b border-foreground/10 py-5 pr-4 transition hover:bg-foreground/[0.03] lg:border-r lg:pl-5 lg:[&:nth-child(4n)]:border-r-0"
                    >
                        <component :is="c.icon" class="size-5 shrink-0 text-primary" />
                        <span class="truncate text-[15px] font-medium">{{ c.name }}</span>
                        <ArrowUpRight class="ml-auto size-4 shrink-0 -translate-x-1 text-muted-foreground opacity-0 transition group-hover:translate-x-0 group-hover:opacity-100" />
                    </Link>
                </div>
            </div>
        </section>

        <!-- Latest jobs: a listings index with hairline rows -->
        <section id="jobs" class="border-b border-foreground/10">
            <div class="mx-auto max-w-[88rem] px-6 py-20 lg:px-10">
                <div class="flex flex-wrap items-end justify-between gap-6 border-b border-foreground/10 pb-8">
                    <div>
                        <div class="label-rule text-primary">04 / {{ $t('landing.latestJobs') }}</div>
                        <h2 class="display-lg mt-4">Open right now.</h2>
                    </div>
                    <Link href="/jobs" class="link-underline inline-flex items-center gap-2 pb-1 text-sm font-semibold">
                        {{ $t('common.viewAll') }} <ArrowRight class="size-4" />
                    </Link>
                </div>

                <div v-if="latestJobs.length">
                    <Link
                        v-for="job in latestJobs"
                        :key="job.id"
                        :href="`/jobs/${job.id}`"
                        class="group grid grid-cols-1 items-baseline gap-x-6 gap-y-2 border-b border-foreground/10 py-7 transition hover:bg-foreground/[0.03] md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto]"
                    >
                        <div>
                            <h3 class="text-xl font-bold tracking-tight">
                                <span class="link-underline">{{ job.title }}</span>
                            </h3>
                            <p class="mt-1.5 text-[13px] text-muted-foreground">{{ job.employer.name }}</p>
                        </div>
                        <div class="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
                            <MapPin class="size-3.5" /> {{ [job.city, job.state].filter(Boolean).join(', ') || $t('jobs.locationNA') }}
                        </div>
                        <div class="text-sm font-semibold">{{ wage(job) }}</div>
                        <div class="flex items-center gap-4">
                            <span v-if="job.category" class="label-rule hidden text-muted-foreground lg:block">{{ job.category }}</span>
                            <ArrowUpRight class="size-5 text-muted-foreground transition group-hover:-translate-y-0.5 group-hover:translate-x-0.5 group-hover:text-primary" />
                        </div>
                    </Link>
                </div>

                <div v-else class="border-b border-foreground/10 py-16 text-center text-muted-foreground">
                    {{ $t('landing.noActiveJobs') }} <Link href="/employer/register" class="font-semibold text-primary underline">{{ $t('landing.postOne') }}</Link>.
                </div>
            </div>
        </section>

        <!-- How it works: oversized numerals, no boxes -->
        <section id="how" class="border-b border-foreground/10">
            <div class="mx-auto max-w-[88rem] px-6 py-20 lg:px-10">
                <div class="label-rule text-primary">05 / {{ $t('landing.simple') }}</div>
                <h2 class="display-lg mt-4 max-w-lg">{{ $t('landing.howItWorks') }}</h2>
                <div class="mt-14 grid gap-y-12 md:grid-cols-3 md:gap-x-12">
                    <div v-for="step in steps" :key="step.n" class="border-t border-foreground/15 pt-6">
                        <div class="text-5xl font-bold leading-none tracking-tight text-primary/25">{{ step.n }}</div>
                        <h3 class="mt-5 text-xl font-bold tracking-tight">{{ step.title }}</h3>
                        <p class="mt-3 max-w-xs text-sm leading-relaxed text-muted-foreground">{{ step.desc }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Why us: image band on the left, ruled feature list on the right -->
        <section id="why" class="border-b border-foreground/10">
            <div class="mx-auto grid max-w-[88rem] items-stretch lg:grid-cols-2">
                <div class="relative min-h-[22rem] lg:min-h-full">
                    <img src="/images/landing/painter.jpg" alt="Indian painters at work on a building in Kochi" loading="lazy" class="h-full w-full object-cover" />
                </div>
                <div class="px-6 py-16 lg:px-14 lg:py-20">
                    <div class="label-rule text-primary">06 / {{ $t('landing.whyKarigar') }}</div>
                    <h2 class="display-lg mt-4 max-w-md">{{ $t('landing.builtForTrust') }}</h2>
                    <dl class="mt-10">
                        <div v-for="f in features" :key="f.title" class="flex gap-5 border-t border-foreground/10 py-6 last:border-b">
                            <component :is="f.icon" class="mt-0.5 size-5 shrink-0 text-primary" />
                            <div>
                                <dt class="text-base font-bold tracking-tight">{{ f.title }}</dt>
                                <dd class="mt-1.5 text-sm leading-relaxed text-muted-foreground">{{ f.desc }}</dd>
                            </div>
                        </div>
                    </dl>
                </div>
            </div>
        </section>

        <!-- Voices: one ruled row of quotes -->
        <section class="border-b border-foreground/10">
            <div class="mx-auto max-w-[88rem] px-6 py-20 lg:px-10">
                <h2 class="display-lg max-w-2xl">{{ $t('landing.lovedBy') }}</h2>
                <div class="mt-14 grid gap-x-12 gap-y-10 md:grid-cols-3">
                    <figure v-for="t in testimonials" :key="t.name" class="border-t border-foreground/15 pt-6">
                        <div class="flex gap-0.5 text-primary">
                            <Star v-for="n in 5" :key="n" class="size-3.5 fill-primary" />
                        </div>
                        <blockquote class="mt-5 text-lg font-medium leading-snug tracking-tight">“{{ t.quote }}”</blockquote>
                        <figcaption class="mt-6 text-[13px]">
                            <span class="font-semibold">{{ t.name }}</span>
                            <span class="text-muted-foreground"> — {{ t.role }}</span>
                        </figcaption>
                    </figure>
                </div>
            </div>
        </section>

        <!-- Closing: flat ink block, no gradient -->
        <section class="bg-foreground text-background">
            <div class="mx-auto max-w-[88rem] px-6 py-24 lg:px-10">
                <div class="grid gap-10 lg:grid-cols-[1.4fr_1fr] lg:items-end">
                    <h2 class="display-xl max-w-3xl">{{ $t('landing.readyTitle') }}</h2>
                    <div>
                        <p class="max-w-sm text-background/70">{{ $t('landing.readySubtitle') }}</p>
                        <div class="mt-8 flex flex-wrap gap-3">
                            <Link href="/worker/register" class="inline-flex items-center gap-2 rounded-sm bg-primary px-6 py-3.5 text-sm font-bold text-primary-foreground transition hover:bg-background hover:text-foreground">
                                {{ $t('landing.joinAsWorker') }} <ArrowRight class="size-4" />
                            </Link>
                            <Link href="/employer/register" class="rounded-sm border border-background/35 px-6 py-3.5 text-sm font-bold transition hover:bg-background hover:text-foreground">
                                {{ $t('landing.hireWorkers') }}
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="bg-foreground text-background/70">
            <div class="mx-auto grid max-w-[88rem] gap-10 border-t border-background/15 px-6 py-14 sm:grid-cols-2 lg:grid-cols-5 lg:px-10">
                <div class="lg:col-span-2">
                    <div class="text-[18px] text-background">
                        <BrandWordmark tone="plain" />
                    </div>
                    <p class="mt-4 max-w-xs text-sm leading-relaxed">
                        India's skilled-work marketplace — KYC-verified karigars, hyperlocal jobs, in your language.
                    </p>
                </div>
                <div>
                    <div class="label-rule text-background/50">Product</div>
                    <div class="mt-4 space-y-2.5 text-sm">
                        <Link href="/jobs" class="block transition hover:text-background">Browse jobs</Link>
                        <Link href="/employer/register" class="block transition hover:text-background">Post a job</Link>
                        <a href="#trades" class="block transition hover:text-background">Trades</a>
                    </div>
                </div>
                <div>
                    <div class="label-rule text-background/50">Join</div>
                    <div class="mt-4 space-y-2.5 text-sm">
                        <Link href="/worker/register" class="block transition hover:text-background">As a worker</Link>
                        <Link href="/employer/register" class="block transition hover:text-background">As an employer</Link>
                    </div>
                </div>
                <div>
                    <div class="label-rule text-background/50">Company</div>
                    <div class="mt-4 space-y-2.5 text-sm">
                        <a href="#how" class="block transition hover:text-background">How it works</a>
                        <Link href="/privacy" class="block transition hover:text-background">Privacy policy</Link>
                        <Link href="/delete-account" class="block transition hover:text-background">Delete your account</Link>
                        <Link href="/admin/login" class="block transition hover:text-background">Admin</Link>
                    </div>
                </div>
            </div>
            <div class="mx-auto flex max-w-[88rem] flex-wrap items-center justify-between gap-3 border-t border-background/15 px-6 py-6 text-xs lg:px-10">
                <span>© {{ new Date().getFullYear() }} Super Karigar. All rights reserved.</span>
                <Link href="/privacy" class="transition hover:text-background">Privacy</Link>
                <span class="inline-flex items-center gap-2"><Languages class="size-3.5" /> 8 languages</span>
            </div>
        </footer>
    </div>
</template>
