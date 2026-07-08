import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Poppins', {
                    weights: [400, 500, 600, 700, 800],
                }),
            ],
        }),
        // This app is a client-rendered SPA (app.ts mounts with createApp and
        // several pages touch `window` during setup, e.g. auth/Register.vue).
        // @inertiajs/vite v3 turns SSR on by default, which crashes on those —
        // so SSR is explicitly disabled.
        inertia({ ssr: false }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        // Wayfinder runs `php artisan` to generate route/action helpers. During a
        // Node-only Docker build there is no PHP, so it is skipped (the committed,
        // pre-generated files under resources/js are used instead).
        ...(process.env.DISABLE_WAYFINDER ? [] : [wayfinder({ formVariants: true })]),
    ],
});
