<script setup lang="ts">
/**
 * The brand lockup: "SUPER कारीगर" with the gold motif. It used to be live
 * text (Outfit + Mukta); it is now the supplied artwork, exported trimmed and
 * transparent to public/images/brand/.
 *
 * `tone` picks the variant, and maps onto the same two backgrounds the text
 * version served:
 *   brand — ink "SUPER", for paper backgrounds (default: nav, auth)
 *   plain — paper "SUPER", for the ink footers and the dark sidebar
 * The terracotta कारीगर and the gold motif are identical in both.
 *
 * Height is set in `em`, so the existing call sites keep sizing the lockup by
 * their own font-size (text-[18px] in the navs, text-[15px] in the sidebar)
 * exactly as they did when this was type.
 */
withDefaults(defineProps<{ tone?: 'brand' | 'plain' }>(), { tone: 'brand' });
</script>

<template>
    <img
        :src="tone === 'plain' ? '/images/brand/wordmark-light.png' : '/images/brand/wordmark.png'"
        alt="Super Karigar"
        width="834"
        height="320"
        class="h-[2.4em] w-auto max-w-full select-none"
    />
</template>
