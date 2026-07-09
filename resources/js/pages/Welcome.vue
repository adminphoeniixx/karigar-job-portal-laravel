<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    ArrowUpRight,
    BadgeCheck,
    Brush,
    Building2,
    Cog,
    Flame,
    Hammer,
    HardHat,
    Languages,
    Lightbulb,
    MapPin,
    Search,
    ShieldCheck,
    Sparkles,
    Star,
    Wrench,
    Zap,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';

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
onMounted(() => {
    animate('jobs', props.stats.jobs);
    animate('workers', props.stats.workers);
    animate('employers', props.stats.employers);
    animate('cities', props.stats.cities);
});

const categories = [
    { name: 'Plumbing', icon: Wrench },
    { name: 'Electrician', icon: Lightbulb },
    { name: 'Carpenter', icon: Hammer },
    { name: 'Painter', icon: Brush },
    { name: 'Welder', icon: Flame },
    { name: 'Mason', icon: HardHat },
    { name: 'Mechanic', icon: Cog },
    { name: 'Cleaning', icon: Sparkles },
];

const features = [
    { icon: MapPin, title: 'Hyperlocal matching', desc: 'Find jobs and workers within your radius using precise geo-search.', span: 'lg:col-span-2', img: '/images/landing/painter.jpg', imgAlt: 'Indian painters at work on a building in Kochi' },
    { icon: ShieldCheck, title: 'KYC verified', desc: 'PAN + Aadhaar verification builds trust on both sides.', span: '' },
    { icon: Zap, title: 'Instant search', desc: 'Typesense-powered results in milliseconds.', span: '' },
    { icon: Languages, title: 'Your language', desc: 'Hindi, English & Hinglish — switch anytime.', span: 'lg:col-span-2', img: '/images/landing/plumber.jpg', imgAlt: 'Indian masons plastering a wall' },
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
    <Head title="Karigar — Skilled work, simplified" />

    <!--
      Landing photos (public/images/landing/) — Wikimedia Commons:
      electrician.jpg  "Male labour working at Building construction site" (CC BY-SA 4.0)
      welder.jpg       "Skilled Carpenter Working on Wood in a Workshop" (CC BY-SA 4.0)
      painter.jpg      "Fort Kochi - Wall Painters on ropes" (CC BY-SA 4.0)
      plumber.jpg      "Masons plastering the brick walk" (CC BY-SA 4.0)
    -->

    <div class="relative min-h-screen overflow-hidden bg-background text-foreground antialiased">
        <!-- Ambient tints -->
        <div class="pointer-events-none absolute inset-0 -z-10">
            <div class="absolute left-1/2 top-[-10%] h-[520px] w-[760px] -translate-x-1/2 rounded-full bg-orange-500/10 blur-[140px]"></div>
            <div class="absolute right-[-5%] top-[20%] h-[360px] w-[360px] rounded-full bg-rose-400/10 blur-[120px]"></div>
        </div>
        <div class="pointer-events-none absolute inset-0 -z-10 bg-grid opacity-[0.5] [mask-image:radial-gradient(ellipse_at_top,black,transparent_70%)]"></div>

        <!-- Nav -->
        <header class="sticky top-0 z-30 border-b bg-card/80 backdrop-blur-xl">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-5 py-3.5">
                <Link href="/" class="flex items-center gap-2.5 text-lg font-bold">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-rose-600 text-white shadow-lg shadow-orange-500/30">K</span>
                    Karigar
                </Link>
                <nav class="hidden items-center gap-1 text-sm font-medium text-muted-foreground md:flex">
                    <a href="#features" class="rounded-lg px-3 py-1.5 transition hover:text-foreground">Features</a>
                    <a href="#jobs" class="rounded-lg px-3 py-1.5 transition hover:text-foreground">Jobs</a>
                    <a href="#how" class="rounded-lg px-3 py-1.5 transition hover:text-foreground">How it works</a>
                </nav>
                <Link
                    v-if="user"
                    href="/dashboard"
                    class="rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow transition hover:opacity-90"
                >
                    Dashboard
                </Link>
                <Link v-else href="/employer/login" class="rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow transition hover:opacity-90">
                    Login
                </Link>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative mx-auto grid max-w-6xl items-center gap-12 px-5 pt-16 pb-16 lg:grid-cols-2 lg:pt-24">
            <div class="animate-in fade-in slide-in-from-bottom-4 text-center duration-700 lg:text-left">
                <span class="inline-flex items-center gap-2 rounded-full border bg-card px-4 py-1.5 text-xs font-semibold text-muted-foreground shadow-sm">
                    <Sparkles class="size-3.5 text-primary" />
                    India's skilled-work marketplace
                </span>
                <h1 class="mt-7 text-5xl font-extrabold leading-[1.04] tracking-tight sm:text-6xl">
                    Hire skilled workers,
                    <span class="bg-gradient-to-r from-orange-600 via-rose-500 to-rose-400 bg-clip-text text-transparent">in minutes.</span>
                </h1>
                <p class="mx-auto mt-6 max-w-xl text-lg text-muted-foreground lg:mx-0">
                    Plumbers, electricians, carpenters & more — trusted, KYC-verified, and right near you.
                </p>

                <form class="mx-auto mt-9 flex max-w-lg items-center gap-2 rounded-2xl border bg-card p-2 shadow-premium lg:mx-0" @submit.prevent="search">
                    <div class="flex flex-1 items-center gap-2 rounded-xl bg-accent px-3">
                        <Search class="size-5 text-accent-foreground/70" />
                        <input
                            v-model="query"
                            type="text"
                            placeholder="Search — e.g. plumber, painter…"
                            class="flex-1 bg-transparent py-2.5 text-sm outline-none placeholder:text-muted-foreground"
                        />
                    </div>
                    <button type="submit" class="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground shadow-lg shadow-orange-600/25 transition hover:opacity-90 active:scale-95">
                        Search
                    </button>
                </form>

                <div class="mt-7 flex flex-wrap items-center justify-center gap-x-7 gap-y-2 text-sm text-muted-foreground lg:justify-start">
                    <span class="inline-flex items-center gap-1.5"><Star class="size-4 fill-amber-400 text-amber-400" /> <b class="text-foreground">4.8</b> rating</span>
                    <span class="inline-flex items-center gap-1.5"><BadgeCheck class="size-4 text-primary" /> KYC verified</span>
                    <span class="inline-flex items-center gap-1.5"><MapPin class="size-4 text-rose-500" /> {{ stats.cities }}+ cities</span>
                </div>
            </div>

            <!-- Hero image -->
            <div class="relative animate-in fade-in slide-in-from-bottom-8 duration-1000">
                <div class="absolute -inset-6 rounded-[2.5rem] bg-gradient-to-br from-orange-500/25 via-rose-400/15 to-transparent blur-2xl"></div>
                <img
                    src="/images/landing/electrician.jpg"
                    alt="Skilled Indian worker on a construction site"
                    class="relative aspect-[4/3] w-full rounded-[2rem] border-4 border-card object-cover shadow-premium"
                />
                <!-- Floating chips -->
                <div class="absolute -left-4 top-6 flex items-center gap-2 rounded-2xl border bg-card px-4 py-2.5 shadow-premium">
                    <span class="flex size-9 items-center justify-center rounded-xl bg-accent text-primary"><BadgeCheck class="size-5" /></span>
                    <div>
                        <div class="text-sm font-bold">KYC Verified</div>
                        <div class="text-[11px] text-muted-foreground">Trusted workers</div>
                    </div>
                </div>
                <div class="absolute -bottom-5 right-6 flex items-center gap-2 rounded-2xl border bg-card px-4 py-2.5 shadow-premium">
                    <span class="flex size-9 items-center justify-center rounded-xl bg-amber-400/15 text-amber-500"><Star class="size-5 fill-amber-400 text-amber-400" /></span>
                    <div>
                        <div class="text-sm font-bold">4.8 rating</div>
                        <div class="text-[11px] text-muted-foreground">{{ stats.workers }}+ workers</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Stats -->
        <section class="border-y bg-card">
            <div class="mx-auto grid max-w-6xl grid-cols-2 gap-px sm:grid-cols-4">
                <div v-for="(s, key) in { 'Active jobs': counters.jobs, Workers: counters.workers, Employers: counters.employers, Cities: counters.cities }" :key="key" class="px-4 py-10 text-center">
                    <div class="text-4xl font-extrabold tracking-tight">{{ s }}<span class="text-primary">+</span></div>
                    <div class="mt-1 text-sm font-medium text-muted-foreground">{{ key }}</div>
                </div>
            </div>
        </section>

        <!-- Role entry -->
        <section class="mx-auto max-w-6xl px-5 py-20">
            <div class="grid gap-6 md:grid-cols-2">
                <div class="group relative overflow-hidden rounded-3xl border bg-card shadow-sm transition duration-300 hover:-translate-y-1.5 hover:shadow-premium">
                    <div class="relative h-44 overflow-hidden">
                        <img src="/images/landing/welder.jpg" alt="Indian carpenter working in a workshop" loading="lazy" class="h-full w-full object-cover transition duration-500 group-hover:scale-105" />
                        <div class="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent"></div>
                        <div class="absolute bottom-4 left-6 flex size-12 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-rose-600 text-white shadow-lg shadow-orange-500/30">
                            <HardHat class="size-6" />
                        </div>
                    </div>
                    <div class="p-8 pt-5">
                    <h3 class="relative text-2xl font-bold">For Workers</h3>
                    <p class="relative mt-2 text-sm text-muted-foreground">Find jobs near you, build a verified profile, and get hired faster.</p>
                    <div class="relative mt-7 flex gap-3">
                        <Link href="/worker/register" class="flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-primary px-4 py-2.5 text-sm font-bold text-primary-foreground transition hover:opacity-90 active:scale-95">Join free <ArrowRight class="size-4" /></Link>
                        <Link href="/worker/login" class="flex-1 rounded-xl border bg-card px-4 py-2.5 text-center text-sm font-semibold transition hover:bg-secondary">Login</Link>
                    </div>
                    </div>
                </div>
                <div class="group relative overflow-hidden rounded-3xl border bg-card shadow-sm transition duration-300 hover:-translate-y-1.5 hover:shadow-premium">
                    <div class="relative h-44 overflow-hidden">
                        <img src="/images/landing/meeting.jpg" alt="Employer team hiring" loading="lazy" class="h-full w-full object-cover transition duration-500 group-hover:scale-105" />
                        <div class="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent"></div>
                        <div class="absolute bottom-4 left-6 flex size-12 items-center justify-center rounded-2xl bg-gradient-to-br from-rose-500 to-orange-600 text-white shadow-lg shadow-rose-500/30">
                            <Building2 class="size-6" />
                        </div>
                    </div>
                    <div class="p-8 pt-5">
                    <h3 class="relative text-2xl font-bold">For Employers</h3>
                    <p class="relative mt-2 text-sm text-muted-foreground">Post jobs, filter by location & skill, and hire trusted workers.</p>
                    <div class="relative mt-7 flex gap-3">
                        <Link href="/employer/register" class="flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-primary px-4 py-2.5 text-sm font-bold text-primary-foreground transition hover:opacity-90 active:scale-95">Get started <ArrowRight class="size-4" /></Link>
                        <Link href="/employer/login" class="flex-1 rounded-xl border bg-card px-4 py-2.5 text-center text-sm font-semibold transition hover:bg-secondary">Login</Link>
                    </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Bento features -->
        <section id="features" class="mx-auto max-w-6xl px-5 py-12">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-primary">Why Karigar</p>
            <h2 class="mt-3 text-3xl font-bold sm:text-4xl">Built for trust & speed</h2>
            <div class="mt-10 grid gap-4 lg:grid-cols-3">
                <div
                    v-for="f in features"
                    :key="f.title"
                    class="group flex items-center gap-6 rounded-3xl border bg-card p-7 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                    :class="f.span"
                >
                    <div class="flex-1">
                        <div class="flex size-12 items-center justify-center rounded-2xl bg-accent text-primary transition group-hover:bg-orange-500/15">
                            <component :is="f.icon" class="size-6" />
                        </div>
                        <h3 class="mt-5 text-lg font-bold">{{ f.title }}</h3>
                        <p class="mt-2 text-sm text-muted-foreground">{{ f.desc }}</p>
                    </div>
                    <img
                        v-if="f.img"
                        :src="f.img"
                        :alt="f.imgAlt"
                        loading="lazy"
                        class="hidden h-36 w-48 shrink-0 rounded-2xl object-cover shadow-md sm:block"
                    />
                </div>
            </div>
        </section>

        <!-- Categories -->
        <section class="mx-auto max-w-6xl px-5 py-12">
            <h2 class="text-2xl font-bold">Popular categories</h2>
            <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Link
                    v-for="c in categories"
                    :key="c.name"
                    :href="`/jobs?category=${c.name}`"
                    class="group flex items-center gap-3 rounded-2xl border bg-card p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-orange-300 hover:shadow-md"
                >
                    <span class="flex size-11 items-center justify-center rounded-xl bg-accent text-primary transition group-hover:bg-orange-500/15">
                        <component :is="c.icon" class="size-5" />
                    </span>
                    <span class="font-semibold">{{ c.name }}</span>
                </Link>
            </div>
        </section>

        <!-- Latest jobs -->
        <section id="jobs" class="mx-auto max-w-6xl px-5 py-12">
            <div class="flex items-end justify-between">
                <h2 class="text-2xl font-bold sm:text-3xl">Latest jobs</h2>
                <Link href="/jobs" class="inline-flex items-center gap-1 text-sm font-semibold text-primary transition hover:gap-2">View all <ArrowRight class="size-4" /></Link>
            </div>

            <div v-if="latestJobs.length" class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="job in latestJobs"
                    :key="job.id"
                    :href="`/jobs/${job.id}`"
                    class="group relative overflow-hidden rounded-3xl border bg-card p-5 shadow-sm transition duration-300 hover:-translate-y-1.5 hover:border-orange-300 hover:shadow-md"
                >
                    <div class="flex items-center justify-between">
                        <span v-if="job.category" class="rounded-full bg-accent px-2.5 py-0.5 text-xs font-semibold text-accent-foreground">{{ job.category }}</span>
                        <ArrowUpRight class="size-4 text-muted-foreground transition group-hover:text-primary" />
                    </div>
                    <h3 class="mt-3 text-lg font-bold leading-snug">{{ job.title }}</h3>
                    <p class="mt-1 inline-flex items-center gap-1 text-xs text-muted-foreground"><MapPin class="size-3.5" /> {{ [job.city, job.state].filter(Boolean).join(', ') || 'N/A' }}</p>
                    <div class="mt-4 flex items-center justify-between border-t pt-3 text-sm">
                        <span class="font-bold text-primary">{{ wage(job) }}</span>
                        <span class="text-xs text-muted-foreground">{{ job.employer.name }}</span>
                    </div>
                </Link>
            </div>

            <div v-else class="mt-6 rounded-3xl border border-dashed p-12 text-center text-muted-foreground">
                No active jobs yet — be the first to <Link href="/employer/register" class="font-semibold text-primary underline">post one</Link>.
            </div>
        </section>

        <!-- How it works -->
        <section id="how" class="mx-auto max-w-6xl px-5 py-20">
            <p class="text-center text-sm font-semibold uppercase tracking-[0.2em] text-primary">Simple</p>
            <h2 class="mt-3 text-center text-3xl font-bold sm:text-4xl">How it works</h2>
            <div class="mt-12 grid gap-6 md:grid-cols-3">
                <div v-for="(step, i) in steps" :key="step.n" class="relative rounded-3xl border bg-card p-7 shadow-sm">
                    <div class="flex size-12 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-rose-600 text-lg font-extrabold text-white shadow-lg shadow-orange-500/30">{{ step.n }}</div>
                    <h3 class="mt-4 text-lg font-bold">{{ step.title }}</h3>
                    <p class="mt-2 text-sm text-muted-foreground">{{ step.desc }}</p>
                    <ArrowRight v-if="i < steps.length - 1" class="absolute right-6 top-9 hidden size-5 text-border md:block" />
                </div>
            </div>
        </section>

        <!-- Testimonials -->
        <section class="mx-auto max-w-6xl px-5 py-12">
            <h2 class="text-center text-3xl font-bold sm:text-4xl">Loved by workers & employers</h2>
            <div class="mt-12 grid gap-6 md:grid-cols-3">
                <div v-for="t in testimonials" :key="t.name" class="rounded-3xl border bg-card p-7 shadow-sm">
                    <div class="flex gap-0.5 text-amber-400">
                        <Star v-for="n in 5" :key="n" class="size-4 fill-amber-400" />
                    </div>
                    <p class="mt-4 text-sm leading-relaxed text-foreground/80">“{{ t.quote }}”</p>
                    <div class="mt-6 flex items-center gap-3 border-t pt-4">
                        <span class="flex size-10 items-center justify-center rounded-full bg-gradient-to-br from-orange-500 to-rose-600 text-sm font-bold text-white">{{ t.name.charAt(0) }}</span>
                        <div>
                            <div class="text-sm font-bold">{{ t.name }}</div>
                            <div class="text-xs text-muted-foreground">{{ t.role }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="mx-auto max-w-6xl px-5 pb-20 pt-8">
            <div class="relative overflow-hidden rounded-[2rem] bg-gradient-to-br from-orange-500 to-rose-600 p-12 text-center shadow-premium">
                <div class="absolute inset-0 bg-grid opacity-20"></div>
                <div class="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-white/15 blur-3xl"></div>
                <div class="relative">
                    <h2 class="text-3xl font-extrabold text-white sm:text-4xl">Ready to get started?</h2>
                    <p class="mx-auto mt-3 max-w-md text-orange-50/90">Join thousands building India's workforce on Karigar.</p>
                    <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                        <Link href="/worker/register" class="inline-flex items-center gap-1.5 rounded-xl bg-white px-7 py-3.5 font-bold text-orange-600 shadow-lg transition hover:bg-orange-50 active:scale-95">Join as worker <ArrowRight class="size-4" /></Link>
                        <Link href="/employer/register" class="rounded-xl border border-white/40 bg-white/10 px-7 py-3.5 font-bold text-white backdrop-blur transition hover:bg-white/20">Hire workers</Link>
                    </div>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="bg-[#151b36] text-slate-300">
            <div class="mx-auto grid max-w-6xl gap-8 px-5 py-12 sm:grid-cols-4">
                <div>
                    <div class="flex items-center gap-2 text-lg font-bold text-white">
                        <span class="flex size-8 items-center justify-center rounded-lg bg-gradient-to-br from-orange-500 to-rose-600 text-white">K</span>
                        Karigar
                    </div>
                    <p class="mt-3 text-sm text-slate-400">Skilled work, simplified.</p>
                </div>
                <div>
                    <div class="text-sm font-semibold text-white">Product</div>
                    <div class="mt-3 space-y-2 text-sm text-slate-400">
                        <Link href="/jobs" class="block transition hover:text-white">Browse jobs</Link>
                        <Link href="/employer/register" class="block transition hover:text-white">Post a job</Link>
                    </div>
                </div>
                <div>
                    <div class="text-sm font-semibold text-white">Join</div>
                    <div class="mt-3 space-y-2 text-sm text-slate-400">
                        <Link href="/worker/register" class="block transition hover:text-white">As a worker</Link>
                        <Link href="/employer/register" class="block transition hover:text-white">As an employer</Link>
                    </div>
                </div>
                <div>
                    <div class="text-sm font-semibold text-white">Company</div>
                    <div class="mt-3 space-y-2 text-sm text-slate-400">
                        <Link href="/admin/login" class="block transition hover:text-white">Admin</Link>
                    </div>
                </div>
            </div>
            <div class="border-t border-white/10 py-5 text-center text-xs text-slate-500">
                © {{ new Date().getFullYear() }} Karigar. All rights reserved.
            </div>
        </footer>
    </div>
</template>
