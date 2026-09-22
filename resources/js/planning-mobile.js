const PLANNING_MOBILE_MEDIA =
    "(max-width: 768px), ((max-height: 520px) and (max-width: 1000px))";

export function planningMobileMediaQuery() {
    return PLANNING_MOBILE_MEDIA;
}

export function isPlanningMobileViewport(matchMedia) {
    return Boolean(matchMedia(PLANNING_MOBILE_MEDIA)?.matches);
}

export function setPlanningMobilePanel(page, name) {
    const filtersButton = page.querySelector("#planning-mobile-filters");
    const availabilityButton = page.querySelector(
        "#planning-mobile-availability",
    );
    const filtersOpen =
        name === "filters" && !page.classList.contains("is-filters-open");
    const availabilityOpen =
        name === "availability" &&
        !page.classList.contains("is-availability-open");

    page.classList.toggle("is-filters-open", filtersOpen);
    page.classList.toggle("is-availability-open", availabilityOpen);
    filtersButton?.setAttribute("aria-expanded", filtersOpen ? "true" : "false");
    availabilityButton?.setAttribute(
        "aria-expanded",
        availabilityOpen ? "true" : "false",
    );

    return { filtersOpen, availabilityOpen };
}

export function bindPlanningMobileChrome(root = document) {
    const page = root.querySelector(".planning-page");

    if (!page) {
        return;
    }

    page.querySelector("#planning-mobile-filters")?.addEventListener(
        "click",
        () => {
            setPlanningMobilePanel(page, "filters");
        },
    );
    page.querySelector("#planning-mobile-availability")?.addEventListener(
        "click",
        () => {
            setPlanningMobilePanel(page, "availability");
        },
    );
}
