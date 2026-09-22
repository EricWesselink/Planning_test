import * as pdfjsLib from "pdfjs-dist";
import pdfWorker from "pdfjs-dist/build/pdf.worker.min.mjs?url";

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorker;

const root = document.querySelector("[data-vakman-drawing]");
const url = root?.getAttribute("data-vakman-drawing");

if (root instanceof HTMLElement && url) {
    mountDrawing(root, url);
}

async function mountDrawing(root, url) {
    const host = root.querySelector("[data-vakman-drawing-pages]");
    const status = root.querySelector("[data-vakman-drawing-status]");
    if (!(host instanceof HTMLElement)) {
        return;
    }

    let pdf;
    try {
        pdf = await pdfjsLib.getDocument({ url, withCredentials: true }).promise;
    } catch {
        if (status) {
            status.textContent = "Tekening kon niet worden geladen.";
        }
        return;
    }

    let scale = 1;

    const draw = async () => {
        host.replaceChildren();
        const width = Math.max(host.clientWidth, 280);
        const outputScale = window.devicePixelRatio || 1;

        for (let number = 1; number <= pdf.numPages; number += 1) {
            const page = await pdf.getPage(number);
            const base = page.getViewport({ scale: 1 });
            const cssWidth = Math.floor(width * scale);
            const viewport = page.getViewport({
                scale: (cssWidth / Math.max(base.width, 1)) * outputScale,
            });
            const canvas = document.createElement("canvas");
            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);
            canvas.style.width = `${Math.floor(viewport.width / outputScale)}px`;
            canvas.style.height = `${Math.floor(viewport.height / outputScale)}px`;
            host.append(canvas);
            const context = canvas.getContext("2d");
            if (!context) {
                continue;
            }
            await page.render({ canvasContext: context, viewport }).promise;
        }
    };

    await draw();

    root.querySelector('[data-drawing-zoom="in"]')?.addEventListener(
        "click",
        () => {
            scale = Math.min(4, Math.round((scale + 0.5) * 10) / 10);
            draw();
        },
    );
    root.querySelector('[data-drawing-zoom="out"]')?.addEventListener(
        "click",
        () => {
            scale = Math.max(1, Math.round((scale - 0.5) * 10) / 10);
            draw();
        },
    );
}
