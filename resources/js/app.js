import './drawing-board';
import './project-upload';

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
