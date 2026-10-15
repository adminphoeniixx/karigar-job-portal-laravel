<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * Draws a PDF onto canvases with pdf.js, one per page. Used instead of an
 * <iframe> because phones will not show a PDF inline, and Chrome's built-in
 * viewer flickers to black each time the document is swapped.
 *
 * pdf.js is loaded on first use, so it stays out of every other page's bundle.
 */
const props = defineProps<{ src: Blob | null }>();

const host = ref<HTMLDivElement | null>(null);
let renderSeq = 0;
let worker: Worker | null = null;

const render = async (blob: Blob) => {
    const seq = ++renderSeq;
    const [pdfjs, { default: PdfWorker }] = await Promise.all([import('pdfjs-dist'), import('pdfjs-dist/build/pdf.worker.min.mjs?worker')]);
    // Vite bundles the worker as a plain .js file: some servers send .mjs with
    // a MIME type browsers refuse for a worker script.
    worker ??= new PdfWorker();
    pdfjs.GlobalWorkerOptions.workerPort = worker;

    const doc = await pdfjs.getDocument({ data: new Uint8Array(await blob.arrayBuffer()) }).promise;
    const width = host.value?.clientWidth || 600;
    const ratio = window.devicePixelRatio || 1;

    // Draw every page off-screen first, then swap them in together, so the
    // old preview stays up until the new one is ready (no flash).
    const canvases: HTMLCanvasElement[] = [];
    for (let n = 1; n <= doc.numPages; n++) {
        const page = await doc.getPage(n);
        const base = page.getViewport({ scale: 1 });
        const viewport = page.getViewport({ scale: (width / base.width) * ratio });
        const canvas = document.createElement('canvas');
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        canvas.style.width = '100%';
        canvas.className = 'rounded-lg border bg-white shadow-md';
        await page.render({ canvasContext: canvas.getContext('2d')!, viewport }).promise;
        canvases.push(canvas);
    }
    await doc.destroy();

    if (seq !== renderSeq || !host.value) return;
    host.value.replaceChildren(...canvases);
};

watch(
    () => props.src,
    (blob) => blob && render(blob),
    { immediate: true, flush: 'post' },
);

onBeforeUnmount(() => {
    renderSeq++;
    worker?.terminate();
});
</script>

<template>
    <div ref="host" class="flex flex-col gap-3" />
</template>
