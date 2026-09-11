// Shell-only entry: load PDF.js, OCR and other feature libraries from page-specific Vite files.
const topbar = document.querySelector('.nicon-topbar');
if (topbar instanceof HTMLElement) {
    const syncTopbarHeight = () => {
        document.documentElement.style.setProperty(
            '--nicon-topbar-height',
            `${Math.round(topbar.getBoundingClientRect().height)}px`,
        );
    };

    syncTopbarHeight();
    new ResizeObserver(syncTopbarHeight).observe(topbar);
}
