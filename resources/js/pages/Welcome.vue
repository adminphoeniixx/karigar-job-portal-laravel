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
    { icon: MapPin, title: 'Hyperlocal matching', desc: 'Find jobs and workers within your radius using precise geo-search.', span: 'lg:col-span-2' },
    { icon: ShieldCheck, title: 'KYC verified', desc: 'PAN + Aadhaar verification builds trust on both sides.', span: '' },
    { icon: Zap, title: 'Instant search', desc: 'Typesense-powered results in milliseconds.', span: '' },
    { icon: Languages, title: 'Your language', desc: 'Hindi, English & Hinglish — switch anytime.', span: 'lg:col-span-2' },
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

    <div class="dark relative min-h-screen overflow-hidden bg-[#04100d] text-slate-200 antialiased">
        <!-- Ambient glows -->
        <div class="pointer-events-none absolute inset-0 -z-10">
            <div class="absolute left-1/2 top-[-10%] h-[520px] w-[760px] -translate-x-1/2 rounded-full bg-teal-600/25 blur-[140px]"></div>
            <div class="absolute right-[-5%] top-[20%] h-[360px] w-[360px] rounded-full bg-cyan-600/20 blur-[120px]"></div>
            <div class="absolute left-[-5%] top-[45%] h-[340px] w-[340px] rounded-full bg-rose-500/10 blur-[120px]"></div>
        </div>
        <div class="pointer-events-none absolute inset-0 -z-10 bg-grid opacity-[0.4] [mask-image:radial-gradient(ellipse_at_top,black,transparent_70%)]"></div>

        <!-- Nav -->
        <header class="sticky top-0 z-30 border-b border-white/5 bg-[#04100d]/70 backdrop-blur-xl">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-5 py-3.5">
                <Link href="/" class="flex items-center gap-2.5 text-lg font-bold text-white">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-cyan-600 text-white shadow-lg shadow-teal-500/40">K</span>
                    Karigar
                </Link>
                <nav class="hidden items-center gap-1 text-sm font-medium text-slate-400 md:flex">
                    <a href="#features" class="rounded-lg px-3 py-1.5 transition hover:text-white">Features</a>
                    <a href="#jobs" class="rounded-lg px-3 py-1.5 transition hover:text-white">Jobs</a>
                    <a href="#how" class="rounded-lg px-3 py-1.5 transition hover:text-white">How it works</a>
                </nav>
                <Link
                    v-if="user"
                    href="/dashboard"
                    class="rounded-xl bg-white px-4 py-2 text-sm font-semibold text-slate-900 transition hover:bg-slate-200"
                >
                    Dashboard
                </Link>
                <Link v-else href="/employer/login" class="rounded-xl border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white backdrop-blur transition hover:bg-white/10">
                    Login
                </Link>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative mx-auto max-w-6xl px-5 pt-20 pb-16 text-center lg:pt-28">
            <div class="animate-in fade-in slide-in-from-bottom-4 duration-700">
                <span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-1.5 text-xs font-semibold text-slate-300 backdrop-blur">
                    <Sparkles class="size-3.5 text-rose-400" />
                    India's skilled-work marketplace
                </span>
                <h1 class="mx-auto mt-7 max-w-4xl text-5xl font-extrabold leading-[1.04] tracking-tight text-white sm:text-7xl">
                    Hire skilled workers,
                    <span class="bg-gradient-to-r from-teal-400 via-cyan-400 to-rose-300 bg-clip-text text-transparent">in minutes.</span>
                </h1>
                <p class="mx-auto mt-6 max-w-xl text-lg text-slate-400">
                    Plumbers, electricians, carpenters & more — trusted, KYC-verified, and right near you.
                </p>

                <form class="mx-auto mt-9 flex max-w-lg items-center gap-2 rounded-2xl border border-white/10 bg-white/[0.06] p-2 shadow-2xl shadow-teal-950/50 backdrop-blur-xl" @submit.prevent="search">
                    <Search class="ml-2 size-5 text-slate-400" />
                    <input
                        v-model="query"
                        type="text"
                        placeholder="Search — e.g. plumber, painter…"
                        class="flex-1 bg-transparent px-1 py-2 text-sm text-white placeholder:text-slate-500 outline-none"
                    />
                    <button type="submit" class="rounded-xl bg-gradient-to-r from-teal-500 to-cyan-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-teal-600/30 transition hover:opacity-90 active:scale-95">
                        Search
                    </button>
                </form>

                <div class="mt-7 flex flex-wrap items-center justify-center gap-x-7 gap-y-2 text-sm text-slate-400">
                    <span class="inline-flex items-center gap-1.5"><Star class="size-4 fill-rose-400 text-rose-400" /> <b class="text-white">4.8</b> rating</span>
                    <span class="inline-flex items-center gap-1.5"><BadgeCheck class="size-4 text-teal-400" /> KYC verified</span>
                    <span class="inline-flex items-center gap-1.5"><MapPin class="size-4 text-cyan-400" /> {{ stats.cities }}+ cities</span>
                </div>
            </div>

            <!-- product preview mockup -->
            <div class="relative mx-auto mt-16 max-w-3xl animate-in fade-in slide-in-from-bottom-8 duration-1000">
                <div class="absolute -inset-px rounded-3xl bg-gradient-to-r from-teal-500/40 via-cyan-500/30 to-rose-400/30 blur-xl"></div>
                <div class="relative rounded-3xl border border-white/10 bg-white/[0.04] p-2 backdrop-blur-xl">
                    <div class="rounded-2xl border border-white/10 bg-[#08120f]">
                        <div class="flex items-center gap-1.5 border-b border-white/5 px-4 py-3">
                            <span class="h-2.5 w-2.5 rounded-full bg-red-400/70"></span>
                            <span class="h-2.5 w-2.5 rounded-full bg-rose-400/70"></span>
                            <span class="h-2.5 w-2.5 rounded-full bg-green-400/70"></span>
                            <div class="ml-3 h-6 flex-1 rounded-md border border-white/5 bg-white/5"></div>
                        </div>
                        <div class="space-y-3 p-4 text-left">
                            <div class="flex items-center justify-between">
                                <div class="text-sm font-semibold text-white">Jobs near you</div>
                                <div class="rounded-lg bg-gradient-to-r from-teal-500 to-cyan-600 px-3 py-1 text-xs font-semibold text-white">Post a job</div>
                            </div>
                            <div
                                v-for="(c, i) in categories.slice(0, 3)"
                                :key="i"
                                class="flex items-center gap-3 rounded-xl border border-white/10 bg-white/[0.03] p-3 transition hover:border-teal-400/40"
                            >
                                <div class="flex size-10 items-center justify-center rounded-lg bg-teal-500/15 text-teal-300">
                                    <component :is="c.icon" class="size-5" />
                                </div>
                                <div class="flex-1">
                                    <div class="text-sm font-medium text-white">{{ ['Plumber needed', 'Electrician — wiring', 'Carpenter for furniture'][i] }}</div>
                                    <div class="text-xs text-slate-500">Jaipur · ₹500–800 / day</div>
                                </div>
                                <div class="rounded-full bg-rose-400/15 px-2.5 py-1 text-xs font-semibold text-rose-300">Apply</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Stats -->
        <section class="border-y border-white/5 bg-white/[0.02]">
            <div class="mx-auto grid max-w-6xl grid-cols-2 gap-px sm:grid-cols-4">
                <div v-for="(s, key) in { 'Active jobs': counters.jobs, Workers: counters.workers, Employers: counters.employers, Cities: counters.cities }" :key="key" class="px-4 py-10 text-center">
                    <div class="bg-gradient-to-b from-white to-slate-400 bg-clip-text text-4xl font-extrabold tracking-tight text-transparent">{{ s }}<span class="text-teal-400">+</span></div>
                    <div class="mt-1 text-sm font-medium text-slate-500">{{ key }}</div>
                </div>
            </div>
        </section>

        <!-- Role entry -->
        <section class="mx-auto max-w-6xl px-5 py-20">
            <div class="grid gap-6 md:grid-cols-2">
                <div class="group relative overflow-hidden rounded-3xl border border-white/10 bg-white/[0.03] p-8 backdrop-blur transition duration-300 hover:-translate-y-1.5 hover:border-teal-400/30">
                    <div class="absolute -right-12 -top-12 h-48 w-48 rounded-full bg-teal-500/15 blur-3xl transition group-hover:bg-teal-500/30"></div>
                    <div class="relative flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-500 to-cyan-600 text-white shadow-lg shadow-teal-500/30">
                        <HardHat class="size-7" />
                    </div>
                    <h3 class="relative mt-5 text-2xl font-bold text-white">For Workers</h3>
                    <p class="relative mt-2 text-sm text-slate-400">Find jobs near you, build a verified profile, and get hired faster.</p>
                    <div class="relative mt-7 flex gap-3">
                        <Link href="/worker/register" class="flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-rose-400 px-4 py-2.5 text-sm font-bold text-rose-950 transition hover:bg-rose-300 active:scale-95">Join free <ArrowRight class="size-4" /></Link>
                        <Link href="/worker/login" class="flex-1 rounded-xl border border-white/15 bg-white/5 px-4 py-2.5 text-center text-sm font-semibold text-white transition hover:bg-white/10">Login</Link>
                    </div>
                </div>
                <div class="group relative overflow-hidden rounded-3xl border border-white/10 bg-white/[0.03] p-8 backdrop-blur transition duration-300 hover:-translate-y-1.5 hover:border-cyan-400/30">
                    <div class="absolute -right-12 -top-12 h-48 w-48 rounded-full bg-cyan-500/15 blur-3xl transition group-hover:bg-cyan-500/30"></div>
                    <div class="relative flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br from-cyan-500 to-teal-600 text-white shadow-lg shadow-cyan-500/30">
                        <Building2 class="size-7" />
                    </div>
                    <h3 class="relative mt-5 text-2xl font-bold text-white">For Employers</h3>
                    <p class="relative mt-2 text-sm text-slate-400">Post jobs, filter by location & skill, and hire trusted workers.</p>
                    <div class="relative mt-7 flex gap-3">
                        <Link href="/employer/register" class="flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-rose-400 px-4 py-2.5 text-sm font-bold text-rose-950 transition hover:bg-rose-300 active:scale-95">Get started <ArrowRight class="size-4" /></Link>
                        <Link href="/employer/login" class="flex-1 rounded-xl border border-white/15 bg-white/5 px-4 py-2.5 text-center text-sm font-semibold text-white transition hover:bg-white/10">Login</Link>
                    </div>
                </div>
            </div>
        </section>

        <!-- Bento features -->
        <section id="features" class="mx-auto max-w-6xl px-5 py-12">
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-teal-400">Why Karigar</p>
            <h2 class="mt-3 text-3xl font-bold text-white sm:text-4xl">Built for trust & speed</h2>
            <div class="mt-10 grid gap-4 lg:grid-cols-3">
                <div
                    v-for="f in features"
                    :key="f.title"
                    class="group rounded-3xl border border-white/10 bg-white/[0.03] p-7 backdrop-blur transition hover:border-white/20 hover:bg-white/[0.05]"
                    :class="f.span"
                >
                    <div class="flex size-12 items-center justify-center rounded-2xl bg-teal-500/15 text-teal-300 transition group-hover:bg-teal-500/25">
                        <component :is="f.icon" class="size-6" />
                    </div>
                    <h3 class="mt-5 text-lg font-bold text-white">{{ f.title }}</h3>
                    <p class="mt-2 text-sm text-slate-400">{{ f.desc }}</p>
                </div>
            </div>
        </section>

        <!-- Categories -->
        <section class="mx-auto max-w-6xl px-5 py-12">
            <h2 class="text-2xl font-bold text-white">Popular categories</h2>
            <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Link
                    v-for="c in categories"
                    :key="c.name"
                    :href="`/jobs?category=${c.name}`"
                    class="group flex items-center gap-3 rounded-2xl border border-white/10 bg-white/[0.03] p-4 transition hover:-translate-y-0.5 hover:border-teal-400/40 hover:bg-white/[0.06]"
                >
                    <span class="flex size-11 items-center justify-center rounded-xl bg-white/5 text-teal-300 transition group-hover:bg-teal-500/20">
                        <component :is="c.icon" class="size-5" />
                    </span>
                    <span class="font-semibold text-white">{{ c.name }}</span>
                </Link>
            </div>
        </section>

        <!-- Latest jobs -->
        <section id="jobs" class="mx-auto max-w-6xl px-5 py-12">
            <div class="flex items-end justify-between">
                <h2 class="text-2xl font-bold text-white sm:text-3xl">Latest jobs</h2>
                <Link href="/jobs" class="inline-flex items-center gap-1 text-sm font-semibold text-teal-400 transition hover:gap-2 hover:text-teal-300">View all <ArrowRight class="size-4" /></Link>
            </div>

            <div v-if="latestJobs.length" class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="job in latestJobs"
                    :key="job.id"
                    :href="`/jobs/${job.id}`"
                    class="group relative overflow-hidden rounded-3xl border border-white/10 bg-white/[0.03] p-5 transition duration-300 hover:-translate-y-1.5 hover:border-teal-400/40 hover:bg-white/[0.05]"
                >
                    <div class="flex items-center justify-between">
                        <span v-if="job.category" class="rounded-full border border-teal-400/20 bg-teal-500/10 px-2.5 py-0.5 text-xs font-semibold text-teal-300">{{ job.category }}</span>
                        <ArrowUpRight class="size-4 text-slate-600 transition group-hover:text-teal-400" />
                    </div>
                    <h3 class="mt-3 text-lg font-bold leading-snug text-white">{{ job.title }}</h3>
                    <p class="mt-1 inline-flex items-center gap-1 text-xs text-slate-500"><MapPin class="size-3.5" /> {{ [job.city, job.state].filter(Boolean).join(', ') || 'N/A' }}</p>
                    <div class="mt-4 flex items-center justify-between border-t border-white/5 pt-3 text-sm">
                        <span class="font-bold text-rose-300">{{ wage(job) }}</span>
                        <span class="text-xs text-slate-500">{{ job.employer.name }}</span>
                    </div>
                </Link>
            </div>

            <div v-else class="mt-6 rounded-3xl border border-dashed border-white/10 p-12 text-center text-slate-500">
                No active jobs yet — be the first to <Link href="/employer/register" class="font-semibold text-teal-400 underline">post one</Link>.
            </div>
        </section>

        <!-- How it works -->
        <section id="how" class="mx-auto max-w-6xl px-5 py-20">
            <p class="text-center text-sm font-semibold uppercase tracking-[0.2em] text-teal-400">Simple</p>
            <h2 class="mt-3 text-center text-3xl font-bold text-white sm:text-4xl">How it works</h2>
            <div class="mt-12 grid gap-6 md:grid-cols-3">
                <div v-for="(step, i) in steps" :key="step.n" class="relative rounded-3xl border border-white/10 bg-white/[0.03] p-7 backdrop-blur">
                    <div class="flex size-12 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-500 to-cyan-600 text-lg font-extrabold text-white shadow-lg shadow-teal-500/30">{{ step.n }}</div>
                    <h3 class="mt-4 text-lg font-bold text-white">{{ step.title }}</h3>
                    <p class="mt-2 text-sm text-slate-400">{{ step.desc }}</p>
                    <ArrowRight v-if="i < steps.length - 1" class="absolute right-6 top-9 hidden size-5 text-slate-700 md:block" />
                </div>
            </div>
        </section>

        <!-- Testimonials -->
        <section class="mx-auto max-w-6xl px-5 py-12">
            <h2 class="text-center text-3xl font-bold text-white sm:text-4xl">Loved by workers & employers</h2>
            <div class="mt-12 grid gap-6 md:grid-cols-3">
                <div v-for="t in testimonials" :key="t.name" class="rounded-3xl border border-white/10 bg-white/[0.03] p-7 backdrop-blur">
                    <div class="flex gap-0.5 text-rose-400">
                        <Star v-for="n in 5" :key="n" class="size-4 fill-rose-400" />
                    </div>
                    <p class="mt-4 text-sm leading-relaxed text-slate-300">“{{ t.quote }}”</p>
                    <div class="mt-6 flex items-center gap-3 border-t border-white/5 pt-4">
                        <span class="flex size-10 items-center justify-center rounded-full bg-gradient-to-br from-teal-500 to-cyan-600 text-sm font-bold text-white">{{ t.name.charAt(0) }}</span>
                        <div>
                            <div class="text-sm font-bold text-white">{{ t.name }}</div>
                            <div class="text-xs text-slate-500">{{ t.role }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="mx-auto max-w-6xl px-5 pb-20 pt-8">
            <div class="relative overflow-hidden rounded-[2rem] border border-white/10 bg-gradient-to-br from-teal-600/90 to-cyan-700/90 p-12 text-center shadow-2xl shadow-teal-950/50">
                <div class="absolute inset-0 bg-grid opacity-20"></div>
                <div class="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-rose-400/30 blur-3xl"></div>
                <div class="relative">
                    <h2 class="text-3xl font-extrabold text-white sm:text-4xl">Ready to get started?</h2>
                    <p class="mx-auto mt-3 max-w-md text-teal-100/80">Join thousands building India's workforce on Karigar.</p>
                    <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                        <Link href="/worker/register" class="inline-flex items-center gap-1.5 rounded-xl bg-rose-400 px-7 py-3.5 font-bold text-rose-950 shadow-lg transition hover:bg-rose-300 active:scale-95">Join as worker <ArrowRight class="size-4" /></Link>
                        <Link href="/employer/register" class="rounded-xl border border-white/25 bg-white/10 px-7 py-3.5 font-bold text-white backdrop-blur transition hover:bg-white/20">Hire workers</Link>
                    </div>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="border-t border-white/5">
            <div class="mx-auto grid max-w-6xl gap-8 px-5 py-12 sm:grid-cols-4">
                <div>
                    <div class="flex items-center gap-2 text-lg font-bold text-white">
                        <span class="flex size-8 items-center justify-center rounded-lg bg-gradient-to-br from-teal-500 to-cyan-600 text-white">K</span>
                        Karigar
                    </div>
                    <p class="mt-3 text-sm text-slate-500">Skilled work, simplified.</p>
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
            <div class="border-t border-white/5 py-5 text-center text-xs text-slate-600">
                © {{ new Date().getFullYear() }} Karigar. All rights reserved.
            </div>
        </footer>
    </div>
</template>
