import type { PDFDocumentLoadingTask } from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/legacy/build/pdf.worker.min.mjs?url';

/**
 * Open the PDF with pdf.js, which loads with the first one: its legacy build, whose polyfills (such as
 * `Map.prototype.getOrInsertComputed`) let it run in the desktop app's Chromium and in older browsers.
 */
export async function loadPdf(blob: Blob): Promise<PDFDocumentLoadingTask> {
    const pdfjs = await import('pdfjs-dist/legacy/build/pdf.mjs');
    pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;

    return pdfjs.getDocument({ data: new Uint8Array(await blob.arrayBuffer()) });
}
