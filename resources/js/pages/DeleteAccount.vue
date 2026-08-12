<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandWordmark from '@/components/BrandWordmark.vue';
import PublicNav from '@/components/PublicNav.vue';

/**
 * How to delete a Super Karigar account. A sibling of the privacy policy in
 * both tone and layout — one readable document, read mostly on a phone, often
 * by someone who is annoyed with us and wants a straight answer.
 *
 * Section content is data so the contents rail and the body cannot drift apart.
 */
defineProps<{
    kycEnabled: boolean;
    updatedAt: string;
}>();

/** Everything below is keyed off this, so a rename happens in one place. */
const sections = computed(() => [
    { id: 'in-the-app', title: 'Delete it in the app' },
    { id: 'on-the-web', title: 'Delete it on the web' },
    { id: 'by-email', title: 'Ask us to delete it' },
    { id: 'what-goes', title: 'What gets deleted' },
    { id: 'what-stays', title: 'What we have to keep' },
    { id: 'before', title: 'Before you delete' },
    { id: 'contact', title: 'Contact' },
]);
</script>

<template>
    <Head title="Delete your account — Super Karigar">
        <meta
            name="description"
            content="How to delete your Super Karigar account and everything attached to it — in the app, on the web, or by writing to us."
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
                    <span class="label-rule">Your account</span>
                </div>
                <h1 class="mt-6 max-w-3xl text-4xl leading-[1.05] font-semibold tracking-tight lg:text-6xl">
                    Delete your account
                </h1>
                <p class="mt-6 max-w-2xl text-lg leading-relaxed text-muted-foreground">
                    You can close your Super Karigar account whenever you like, and you do not have
                    to give us a reason. This page says exactly how to do it, what disappears when
                    you do, and the few things the law makes us hold on to.
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
                class="max-w-2xl text-[15px] leading-relaxed text-foreground/85 [&_h2]:mt-14 [&_h2]:mb-4 [&_h2]:scroll-mt-24 [&_h2]:text-2xl [&_h2]:font-semibold [&_h2]:tracking-tight [&_h2]:text-foreground [&_h3]:mt-8 [&_h3]:mb-2 [&_h3]:font-semibold [&_h3]:text-foreground [&_li]:mb-1.5 [&_ol]:mb-4 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mb-4 [&_ul]:mb-4 [&_ul]:list-disc [&_ul]:pl-5"
            >
                <p class="border-l-2 border-primary pl-4 text-foreground">
                    In short: deleting your account removes your profile, your applications, your
                    messages<span v-if="kycEnabled">, your identity documents</span> and your
                    contact details from Super Karigar. It cannot be undone, and we cannot bring the
                    account back afterwards.
                </p>

                <h2 id="in-the-app">Delete it in the app</h2>
                <p>
                    This is the quickest route, and the only one that needs nothing from us. It
                    works the same way in the worker app and the employer app.
                </p>
                <ol>
                    <li>Open the app and sign in, if you are not already.</li>
                    <li>Go to <strong>Settings</strong>.</li>
                    <li>Tap <strong>Delete account</strong>.</li>
                    <li>
                        Confirm when you are asked to. That confirmation is the last step — nothing
                        is deleted before it.
                    </li>
                </ol>
                <p>
                    You are signed out of every device straight away, and the account is removed
                    immediately.
                </p>

                <h2 id="on-the-web">Delete it on the web</h2>
                <p>
                    Sign in and go to
                    <a href="/settings/profile" class="link-underline text-primary">Settings → Profile</a>,
                    then use <strong>Delete account</strong> at the bottom of the page. The web form
                    asks for your password to confirm it is really you.
                </p>
                <p>
                    If you signed up with your mobile number and a one-time code you never set a
                    password — use the app instead, or write to us and we will delete the account
                    for you.
                </p>

                <h2 id="by-email">Ask us to delete it</h2>
                <p>
                    If you cannot get into your account at all — a lost phone, a number you no
                    longer have — email us and we will do it for you:
                </p>
                <div class="my-6 border border-foreground/15 bg-foreground/[0.03] p-5">
                    <div class="label-rule text-muted-foreground">Deletion requests</div>
                    <p class="mt-3 mb-0">
                        <a
                            href="mailto:privacy@superkarigar.com?subject=Delete%20my%20Super%20Karigar%20account"
                            class="link-underline text-primary"
                        >
                            privacy@superkarigar.com
                        </a>
                    </p>
                    <p class="mt-3 mb-0 text-sm text-muted-foreground">
                        Tell us the mobile number the account uses, and whether it is a worker or an
                        employer account.
                    </p>
                </div>
                <p>
                    We acknowledge every request within 72 hours. Because a deletion cannot be
                    undone, we confirm it is really you before we act on it — usually by contacting
                    you on the number the account is registered with. Once confirmed, the account is
                    deleted within 30 days at the outside, and in practice much sooner.
                </p>

                <h2 id="what-goes">What gets deleted</h2>
                <p>Closing your account permanently removes:</p>
                <ul>
                    <li>
                        <strong>Your profile</strong> — name, photo, mobile number, email, city and
                        language.
                    </li>
                    <li v-if="kycEnabled">
                        <strong>Your identity documents</strong> and everything we stored from
                        verifying them.
                    </li>
                    <li>
                        <strong>Your trade profile and résumé</strong> — skills, experience,
                        expected wage, uploaded files, and the match scores drawn from them.
                    </li>
                    <li>
                        <strong>Your applications and saved jobs</strong>, and for employers, your
                        company profile and the jobs you posted.
                    </li>
                    <li>
                        <strong>Your messages</strong> with other users, and any interviews
                        arranged through us.
                    </li>
                    <li>
                        <strong>Screening call recordings and transcripts</strong> belonging to your
                        applications.
                    </li>
                    <li>
                        <strong>Your notification tokens</strong>, so no device of yours hears from
                        us again.
                    </li>
                </ul>
                <p>
                    We do not keep a hidden copy. There is no dormant account left behind waiting to
                    be reactivated — if you come back later, you start fresh.
                </p>

                <h2 id="what-stays">What we have to keep</h2>
                <p>Two narrow exceptions, both of them things we are not free to erase:</p>
                <ul>
                    <li>
                        <strong>Invoices and payment records.</strong> Tax law sets how long these
                        must be held. They stay for that period and are used for nothing else.
                    </li>
                    <li>
                        <strong>Reviews you left about someone else.</strong> These belong to the
                        person you reviewed as much as to you, so they stay — with your name
                        removed, so they no longer point back at you.
                    </li>
                </ul>
                <p>
                    Backups roll over on their own schedule, so a copy of your data can survive
                    there for a short while after deletion. It is not used for anything, and it goes
                    when that backup expires.
                </p>

                <h2 id="before">Before you delete</h2>
                <ul>
                    <li>
                        <strong>Settle anything open.</strong> An active subscription, unused
                        credits, or money held for a job are not refunded by deleting your account.
                        Sort that out with us first.
                    </li>
                    <li>
                        <strong>Tell the people you were working with.</strong> Employers you have
                        applied to, or workers you have hired, lose the thread with you the moment
                        the account goes.
                    </li>
                    <li>
                        <strong>You may not need to delete at all.</strong> If it is the calls or
                        the alerts that are bothering you, switch AI screening calls off in your
                        profile settings and notifications off on your device, and keep the account.
                    </li>
                </ul>

                <h2 id="contact">Contact</h2>
                <p>
                    Questions about deletion, or about anything else we hold, go to our Grievance
                    Officer at
                    <a href="mailto:privacy@superkarigar.com" class="link-underline text-primary">privacy@superkarigar.com</a>. Our
                    <Link href="/privacy" class="link-underline text-primary">privacy policy</Link>
                    covers what we collect and why.
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
                    <Link href="/privacy" class="transition hover:text-background">Privacy</Link>
                    <Link href="/delete-account" class="text-background">Delete account</Link>
                </div>
            </div>
        </footer>
    </div>
</template>
