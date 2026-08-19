<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandWordmark from '@/components/BrandWordmark.vue';
import PublicNav from '@/components/PublicNav.vue';

/**
 * The privacy policy. Written as one readable document rather than a wall of
 * clauses — most of the people it describes are reading it on a phone, in a
 * second language, deciding whether to trust us with a KYC document.
 *
 * Section content is data so the contents rail and the body cannot drift apart.
 */
const props = defineProps<{
    kycEnabled: boolean;
    updatedAt: string;
}>();

/** Everything below is keyed off this, so a rename happens in one place. */
const sections = computed(() => [
    { id: 'what-we-collect', title: 'What we collect' },
    { id: 'why', title: 'Why we use it' },
    { id: 'ai', title: 'AI and automated calls' },
    { id: 'sharing', title: 'Who else sees it' },
    { id: 'keeping', title: 'How long we keep it' },
    { id: 'rights', title: 'Your rights and choices' },
    { id: 'security', title: 'How we protect it' },
    { id: 'contact', title: 'Contact and grievances' },
]);
</script>

<template>
    <Head title="Privacy policy — Super Karigar">
        <meta
            name="description"
            content="What Super Karigar collects from karigars and employers, why, who else sees it, and how to have it deleted."
        />
    </Head>

    <div class="theme-paper bg-noise min-h-screen bg-background text-foreground antialiased">
        <PublicNav>
            <Link href="/jobs" class="link-underline">{{ $t('nav.browseJobs') }}</Link>
            <Link href="/" class="link-underline">Home</Link>
        </PublicNav>

        <!-- Masthead -->
        <section class="border-b border-foreground/10">
            <div class="mx-auto max-w-[88rem] px-6 py-14 lg:px-10 lg:py-20">
                <div class="flex items-center gap-3 text-primary">
                    <span class="h-px w-10 bg-primary"></span>
                    <span class="label-rule">Legal</span>
                </div>
                <h1 class="mt-6 max-w-3xl text-4xl leading-[1.05] font-semibold tracking-tight lg:text-6xl">
                    Privacy policy
                </h1>
                <p class="mt-6 max-w-2xl text-lg leading-relaxed text-muted-foreground">
                    Super Karigar connects skilled karigars with people who need them. Doing that
                    means holding some genuinely personal things — your phone number, where you
                    work, sometimes an identity document. This page says exactly what we hold,
                    why, and how to make us delete it.
                </p>
                <p class="mt-6 text-sm text-muted-foreground">Last updated {{ updatedAt }}</p>
            </div>
        </section>

        <div class="mx-auto grid max-w-[88rem] gap-12 px-6 py-14 lg:grid-cols-[16rem_1fr] lg:gap-16 lg:px-10 lg:py-20">
            <!-- Contents rail -->
            <aside class="lg:sticky lg:top-24 lg:self-start">
                <div class="label-rule text-muted-foreground">Contents</div>
                <nav class="mt-4 flex flex-col gap-2.5 text-sm">
                    <a
                        v-for="section in sections"
                        :key="section.id"
                        :href="`#${section.id}`"
                        class="text-muted-foreground transition hover:text-primary"
                    >
                        {{ section.title }}
                    </a>
                </nav>
            </aside>

            <!-- Body. Prose sizing is set here once rather than per element. -->
            <article
                class="max-w-2xl text-[15px] leading-relaxed text-foreground/85 [&_h2]:mt-14 [&_h2]:mb-4 [&_h2]:scroll-mt-24 [&_h2]:text-2xl [&_h2]:font-semibold [&_h2]:tracking-tight [&_h2]:text-foreground [&_h3]:mt-8 [&_h3]:mb-2 [&_h3]:font-semibold [&_h3]:text-foreground [&_li]:mb-1.5 [&_p]:mb-4 [&_ul]:mb-4 [&_ul]:list-disc [&_ul]:pl-5"
            >
                <p class="border-l-2 border-primary pl-4 text-foreground">
                    In short: we collect what a job match actually needs, we never sell your data,
                    and an employer only sees your contact details once you have applied to them or
                    they have paid to unlock your profile. You can ask us to delete everything at
                    any time.
                </p>

                <h2 id="what-we-collect">What we collect</h2>

                <h3>Everyone</h3>
                <ul>
                    <li>
                        <strong>Your mobile number.</strong> This is how you sign in — we send a
                        one-time code to it. It is the only thing we insist on.
                    </li>
                    <li>
                        <strong>Your name</strong>, and your <strong>email address</strong> if you
                        choose to add one.
                    </li>
                    <li>
                        <strong>Your language.</strong> So we speak to you in the one you picked.
                    </li>
                    <li>
                        <strong>Usage and device information</strong> — pages you open, your IP
                        address, and a notification token if you use our app and allow alerts.
                    </li>
                </ul>

                <h3>If you are a karigar</h3>
                <ul>
                    <li>
                        <strong>Your trade profile</strong> — skills, years of experience,
                        education, languages you speak, a short bio, and the wage you expect.
                    </li>
                    <li>
                        <strong>Where you work</strong> — your city and state, and if you allow it,
                        your location and how far you are willing to travel. This is what puts you
                        in front of jobs near you instead of jobs across the country.
                    </li>
                    <li>
                        <strong>Your résumé</strong>, if you upload one. We read the text out of it
                        so it can be matched against jobs.
                    </li>
                    <li><strong>A profile photo</strong>, if you add one.</li>
                    <li>
                        <strong>Payout details</strong> such as a UPI ID, only when you are being
                        paid through the platform.
                    </li>
                </ul>

                <h3>If you are an employer</h3>
                <ul>
                    <li>Your company name, city, and business details including GST where relevant.</li>
                    <li>The jobs you post, and billing records for anything you pay for.</li>
                </ul>

                <h3 v-if="props.kycEnabled">If you get verified</h3>
                <ul v-if="props.kycEnabled">
                    <li>
                        <strong>Identity documents</strong> — PAN, Aadhaar or GST, whichever you
                        submit. Verification is optional; you can use Super Karigar without it. We
                        keep these on private storage, never on a public address, and they are
                        never shown to other users. Other people only ever see whether you are
                        verified, never the document itself.
                    </li>
                </ul>

                <h3>What you create by using the platform</h3>
                <ul>
                    <li>Applications you send, and their status.</li>
                    <li>Messages between karigars and employers.</li>
                    <li>Reviews you leave or receive.</li>
                    <li>Recordings and transcripts of screening calls — see below.</li>
                </ul>

                <h2 id="why">Why we use it</h2>
                <p>We use your information to do the things you came here for, and little else:</p>
                <ul>
                    <li>To show you jobs worth your time, and to show employers karigars worth theirs.</li>
                    <li>To let you sign in, apply, message, and get paid.</li>
                    <li>To tell you when something happens — an application moves, a message arrives, an interview is set.</li>
                    <li>To keep the platform honest: stopping fraud, spam, fake listings and abuse.</li>
                    <li>To meet legal obligations, including tax and telecom rules.</li>
                </ul>
                <p>
                    <strong>We do not sell your personal data, and we do not run advertising on it.</strong>
                </p>

                <h2 id="ai">AI and automated calls</h2>
                <p>
                    Two parts of Super Karigar make decisions with software rather than a person.
                    You should know about both.
                </p>

                <h3>Matching and shortlisting</h3>
                <p>
                    When you apply for a job, an AI model reads your profile and résumé against
                    that job and scores how well they fit. Employers see applicants ranked by that
                    score. Where an employer has switched it on, a high score can shortlist you
                    automatically, and a very low score can reject an application automatically.
                </p>
                <p>
                    A score is never the last word on you. An employer can always look past it, and
                    if you think a decision about you was wrong, write to us and a person will look
                    at it.
                </p>

                <h3>Screening calls</h3>
                <p>
                    If you are shortlisted, we may ring you with an automated voice agent that asks
                    whether you are still interested and when you could attend an interview. It
                    tells you at the start that it is automated and who it is calling for.
                </p>
                <ul>
                    <li>The call is recorded and transcribed so the employer can see what you said.</li>
                    <li>
                        The agent only <em>proposes</em> an interview time. Nothing is booked until
                        the employer confirms it.
                    </li>
                    <li>We only call during daytime hours, and only a limited number of times.</li>
                    <li>
                        <strong>You can switch these calls off entirely</strong> in your profile
                        settings, or by telling the agent. That choice sticks — it applies to every
                        job you apply for afterwards.
                    </li>
                </ul>

                <h2 id="sharing">Who else sees it</h2>

                <h3>Other users</h3>
                <p>
                    Your public profile — trade, skills, experience, city, photo — is visible to
                    employers searching for workers. <strong>Your phone number is not.</strong> An
                    employer only gets your contact details when you apply to their job, or when
                    they spend credits to unlock your profile. Karigars see an employer's company
                    details and the jobs they post.
                </p>

                <h3>Companies that help us run the service</h3>
                <p>
                    They may only use what we give them to do their job for us, never for
                    themselves:
                </p>
                <ul>
                    <li><strong>Hosting and databases</strong> — running the platform itself.</li>
                    <li><strong>Payments</strong> — card and UPI processing for subscriptions and payouts. We never see or store your full card details.</li>
                    <li><strong>Telecom and voice providers</strong> — sending one-time codes and placing screening calls.</li>
                    <li><strong>AI providers</strong> — scoring applications and holding screening calls.</li>
                    <li><strong>Notifications and email</strong> — delivering alerts and messages.</li>
                    <li><strong>Image delivery and search</strong> — serving photos and making listings searchable.</li>
                </ul>

                <h3>When the law requires it</h3>
                <p>
                    We will share information where we are legally required to, or where it is
                    genuinely necessary to protect someone's safety or to investigate fraud.
                </p>

                <h2 id="keeping">How long we keep it</h2>
                <ul>
                    <li><strong>Your account</strong> — for as long as it is open.</li>
                    <li>
                        <strong>Identity documents</strong> — kept while your account is open so
                        you do not have to submit them twice, and deleted when you close it.
                    </li>
                    <li>
                        <strong>Call recordings and transcripts</strong> — kept while the
                        application they belong to is live.
                    </li>
                    <li>
                        <strong>Invoices and payment records</strong> — kept as long as tax law
                        requires, even after you leave.
                    </li>
                </ul>
                <p>
                    When you delete your account we remove your personal information. Some things
                    survive in a form that no longer identifies you: reviews you left about someone
                    else, and records we are legally obliged to keep.
                </p>

                <h2 id="rights">Your rights and choices</h2>
                <p>Under India's data protection law you can ask us to:</p>
                <ul>
                    <li><strong>Show you</strong> what we hold about you.</li>
                    <li><strong>Correct</strong> anything wrong or out of date.</li>
                    <li><strong>Delete</strong> your account and the data attached to it.</li>
                    <li><strong>Withdraw consent</strong> you previously gave — for location, for screening calls, for notifications.</li>
                    <li><strong>Nominate someone</strong> to exercise these rights if you cannot.</li>
                </ul>
                <p>You can do much of this yourself, without asking us:</p>
                <ul>
                    <li>Edit or clear your profile, résumé and location from your profile page.</li>
                    <li>Turn screening calls off in your profile settings.</li>
                    <li>Turn notifications off on your device.</li>
                    <li>
                        Close your account from your account settings —
                        <Link href="/delete-account" class="link-underline text-primary">how to delete your account</Link>
                        walks through it step by step.
                    </li>
                </ul>
                <p>
                    For anything else, write to us at the address below. We will respond within the
                    time the law allows. We may need to confirm it is really you before we act on a
                    request to delete or hand over data.
                </p>

                <h2 id="security">How we protect it</h2>
                <p>
                    Traffic to Super Karigar is encrypted. Identity documents live on private
                    storage that is not reachable from the public internet, and are served only
                    through short-lived links to people entitled to see them. Access to production
                    data is limited to the people who need it to keep the service running.
                </p>
                <p>
                    No system is perfect. If we ever discover a breach that puts you at risk, we
                    will tell you and the Data Protection Board as the law requires.
                </p>

                <h3>Children</h3>
                <p>
                    Super Karigar is for people aged 18 and over. We do not knowingly collect
                    information about children. If you believe a child has an account, tell us and
                    we will remove it.
                </p>

                <h3>Changes to this policy</h3>
                <p>
                    When we change this page we update the date at the top. If a change materially
                    affects your rights, we will tell you in the app or by message rather than
                    quietly editing it.
                </p>

                <h2 id="contact">Contact and grievances</h2>
                <p>
                    For anything about your privacy — a request, a complaint, or a question about
                    this page — reach our Grievance Officer:
                </p>
                <div class="my-6 border border-foreground/15 bg-foreground/[0.03] p-5">
                    <div class="label-rule text-muted-foreground">Grievance Officer</div>
                    <p class="mt-3 mb-0">
                        <a href="mailto:privacy@superkarigar.com" class="link-underline text-primary">
                            privacy@superkarigar.com
                        </a>
                    </p>
                    <p class="mt-3 mb-0 text-sm text-muted-foreground">
                        We acknowledge every request within 72 hours and resolve it within the
                        period the law allows.
                    </p>
                </div>
                <p>
                    If you are not satisfied with how we have handled a complaint, you may escalate
                    it to the Data Protection Board of India.
                </p>
            </article>
        </div>

        <footer class="bg-foreground text-background/70">
            <div class="mx-auto flex max-w-[88rem] flex-wrap items-center justify-between gap-4 px-6 py-10 text-sm lg:px-10">
                <div class="text-[18px] text-background">
                    <BrandWordmark tone="plain" />
                </div>
                <div class="flex flex-wrap gap-6">
                    <Link href="/" class="transition hover:text-background">Home</Link>
                    <Link href="/jobs" class="transition hover:text-background">{{ $t('nav.browseJobs') }}</Link>
                    <Link href="/privacy" class="text-background">Privacy</Link>
                </div>
            </div>
        </footer>
    </div>
</template>
