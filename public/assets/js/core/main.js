/* =====================================
   Active Sidebar Navigation
===================================== */

function initializePage() {
    const currentPage = document.body.dataset.page;

    if (!currentPage) return;

    document.querySelectorAll(".nav-link").forEach((link) => {
        link.classList.remove("active");

        if (link.dataset.page === currentPage) {
            link.classList.add("active");
        }
    });
}

/* =====================================
   Desktop Sidebar Collapse
===================================== */

const HIMS_FLEET_SIDEBAR_COLLAPSED_KEY = "himsFleetSidebarCollapsed";

function isDesktopSidebarViewport() {
    return window.matchMedia("(min-width: 992px)").matches;
}

function getDesktopSidebarCollapsedPreference() {
    try {
        return (
            localStorage.getItem(HIMS_FLEET_SIDEBAR_COLLAPSED_KEY) === "true"
        );
    } catch {
        return false;
    }
}

function setDesktopSidebarCollapsedPreference(collapsed) {
    try {
        localStorage.setItem(
            HIMS_FLEET_SIDEBAR_COLLAPSED_KEY,
            String(collapsed),
        );
    } catch {
        /* Storage may be unavailable; UI state still applies for the session. */
    }
}

/**
 * Apply saved desktop collapse preference early to reduce layout flash.
 * Safe to call before the toggle exists; does not bind listeners.
 */
function applyEarlyDesktopSidebarCollapsedState() {
    if (!document.body) return;

    if (isDesktopSidebarViewport() && getDesktopSidebarCollapsedPreference()) {
        document.body.classList.add("sidebar-collapsed");
    } else {
        document.body.classList.remove("sidebar-collapsed");
    }
}

function updateDesktopSidebarToggleAria(toggle, collapsed) {
    if (!toggle) return;

    const expanded = !collapsed;
    toggle.setAttribute("aria-expanded", String(expanded));
    toggle.setAttribute(
        "aria-label",
        collapsed ? "Expand sidebar" : "Collapse sidebar",
    );

    const icon = toggle.querySelector("i");

    if (icon) {
        icon.className = collapsed
            ? "ph ph-caret-double-right"
            : "ph ph-caret-double-left";
    }
}

/**
 * Sync body class + toggle ARIA for the current viewport.
 * On mobile, visual collapsed state is cleared; localStorage is preserved.
 */
function syncDesktopSidebarCollapseState(toggle) {
    const desktopToggle =
        toggle || document.getElementById("desktopSidebarToggle");
    const preferCollapsed = getDesktopSidebarCollapsedPreference();

    if (!isDesktopSidebarViewport()) {
        document.body.classList.remove("sidebar-collapsed");
        updateDesktopSidebarToggleAria(desktopToggle, false);
        return;
    }

    document.body.classList.toggle("sidebar-collapsed", preferCollapsed);
    updateDesktopSidebarToggleAria(desktopToggle, preferCollapsed);
}

function initDesktopSidebarCollapse() {
    const toggle = document.getElementById("desktopSidebarToggle");

    if (!toggle) return;

    if (toggle.dataset.desktopCollapseInitialized === "true") {
        syncDesktopSidebarCollapseState(toggle);
        return;
    }

    toggle.dataset.desktopCollapseInitialized = "true";
    toggle.type = "button";
    toggle.setAttribute("aria-controls", "sidebar");

    const desktopViewport = window.matchMedia("(min-width: 992px)");

    syncDesktopSidebarCollapseState(toggle);

    toggle.addEventListener("click", () => {
        if (!isDesktopSidebarViewport()) return;

        const nextCollapsed =
            !document.body.classList.contains("sidebar-collapsed");

        setDesktopSidebarCollapsedPreference(nextCollapsed);
        document.body.classList.toggle("sidebar-collapsed", nextCollapsed);
        updateDesktopSidebarToggleAria(toggle, nextCollapsed);

        if (typeof hideSidebarCollapsedTooltip === "function") {
            hideSidebarCollapsedTooltip();
        }
    });

    const onViewportChange = () => {
        syncDesktopSidebarCollapseState(toggle);

        if (typeof hideSidebarCollapsedTooltip === "function") {
            hideSidebarCollapsedTooltip();
        }
    };

    if (typeof desktopViewport.addEventListener === "function") {
        desktopViewport.addEventListener("change", onViewportChange);
    } else {
        desktopViewport.addListener(onViewportChange);
    }
}

applyEarlyDesktopSidebarCollapsedState();

/* =====================================
   Collapsed sidebar tooltips
   Floating layer outside .sidebar-nav so labels are not clipped by overflow-y:auto
===================================== */

let sidebarTooltipLayer = null;
let sidebarTooltipActiveTarget = null;

function canShowSidebarCollapsedTooltip() {
    return (
        isDesktopSidebarViewport() &&
        document.body.classList.contains("sidebar-collapsed")
    );
}

function ensureSidebarTooltipLayer() {
    if (sidebarTooltipLayer && document.body.contains(sidebarTooltipLayer)) {
        return sidebarTooltipLayer;
    }

    const sidebar = document.querySelector(".sidebar");

    if (!sidebar) return null;

    let layer = document.getElementById("sidebarTooltipLayer");

    if (!layer) {
        layer = document.createElement("div");
        layer.id = "sidebarTooltipLayer";
        layer.className = "sidebar-tooltip-layer";
        layer.setAttribute("role", "tooltip");
        layer.hidden = true;
        layer.setAttribute("aria-hidden", "true");
        sidebar.appendChild(layer);
    }

    sidebarTooltipLayer = layer;
    return layer;
}

function hideSidebarCollapsedTooltip() {
    sidebarTooltipActiveTarget = null;

    const layer =
        sidebarTooltipLayer || document.getElementById("sidebarTooltipLayer");

    if (!layer) return;

    layer.classList.remove("is-visible");
    layer.hidden = true;
    layer.setAttribute("aria-hidden", "true");
    layer.textContent = "";
}

function showSidebarCollapsedTooltip(target) {
    if (!canShowSidebarCollapsedTooltip() || !target) {
        hideSidebarCollapsedTooltip();
        return;
    }

    const text = target.getAttribute("data-tooltip");

    if (!text) {
        hideSidebarCollapsedTooltip();
        return;
    }

    if (
        target.classList.contains("sidebar-profile") &&
        target.closest(".sidebar-profile-wrap")?.classList.contains("is-open")
    ) {
        hideSidebarCollapsedTooltip();
        return;
    }

    const layer = ensureSidebarTooltipLayer();

    if (!layer) return;

    sidebarTooltipActiveTarget = target;

    const rect = target.getBoundingClientRect();

    layer.textContent = text;
    layer.hidden = false;
    layer.setAttribute("aria-hidden", "false");
    layer.style.top = `${Math.round(rect.top + rect.height / 2)}px`;
    layer.style.left = `${Math.round(rect.right + 12)}px`;

    /* Next frame so the opacity transition runs from the hidden state */
    requestAnimationFrame(() => {
        if (sidebarTooltipActiveTarget === target) {
            layer.classList.add("is-visible");
        }
    });
}

function getSidebarTooltipTarget(node) {
    if (!(node instanceof Element)) return null;

    return node.closest(
        ".nav-link[data-tooltip], .sidebar-profile[data-tooltip]",
    );
}

/**
 * Single delegated tooltip controller for collapsed desktop icons.
 * Does not attach per-link listeners.
 */
function initSidebarCollapsedTooltips() {
    const sidebar = document.querySelector(".sidebar");

    if (!sidebar) return;

    if (sidebar.dataset.collapsedTooltipsInitialized === "true") {
        ensureSidebarTooltipLayer();
        return;
    }

    sidebar.dataset.collapsedTooltipsInitialized = "true";
    ensureSidebarTooltipLayer();

    /* Accessible names for icon-only collapsed state (nav-label is visibility:hidden) */
    sidebar.querySelectorAll(".nav-link[data-tooltip]").forEach((link) => {
        if (!link.hasAttribute("aria-label")) {
            link.setAttribute(
                "aria-label",
                link.getAttribute("data-tooltip") || "",
            );
        }

        const icon = link.querySelector("i");

        if (icon) {
            icon.setAttribute("aria-hidden", "true");
        }
    });

    sidebar.addEventListener("pointerover", (event) => {
        const target = getSidebarTooltipTarget(event.target);

        if (target) {
            showSidebarCollapsedTooltip(target);
        }
    });

    sidebar.addEventListener("pointerout", (event) => {
        const from = getSidebarTooltipTarget(event.target);
        const to = getSidebarTooltipTarget(event.relatedTarget);

        if (from && from !== to && !to) {
            hideSidebarCollapsedTooltip();
        }
    });

    sidebar.addEventListener("focusin", (event) => {
        const target = getSidebarTooltipTarget(event.target);

        if (target) {
            showSidebarCollapsedTooltip(target);
        }
    });

    sidebar.addEventListener("focusout", (event) => {
        const from = getSidebarTooltipTarget(event.target);
        const to = getSidebarTooltipTarget(event.relatedTarget);

        if (from && !to) {
            hideSidebarCollapsedTooltip();
        }
    });

    const nav = sidebar.querySelector(".sidebar-nav");

    if (nav) {
        nav.addEventListener(
            "scroll",
            () => {
                hideSidebarCollapsedTooltip();
            },
            { passive: true },
        );
    }

    window.addEventListener("resize", () => {
        hideSidebarCollapsedTooltip();
    });
}

/* =====================================
   Global Theme System
   Preference: light | dark | system (himsFleetTheme)
   Resolved data-theme attribute: light | dark
===================================== */

const HIMS_FLEET_THEME_KEY = "himsFleetTheme";
let systemThemeMediaQuery = null;
let systemThemeListenerBound = false;

function getSystemTheme() {
    try {
        if (
            window.matchMedia &&
            window.matchMedia("(prefers-color-scheme: dark)").matches
        ) {
            return "dark";
        }
    } catch {
        /* ignore */
    }
    return "light";
}

/** Saved user preference: light | dark | system */
function getThemePreference() {
    try {
        const value = localStorage.getItem(HIMS_FLEET_THEME_KEY);
        if (value === "dark" || value === "light" || value === "system") {
            return value;
        }
    } catch {
        /* ignore */
    }
    return "light";
}

/** Resolved theme applied to the document (light | dark) */
function getResolvedTheme(preference) {
    const pref = preference || getThemePreference();
    if (pref === "system") return getSystemTheme();
    return pref === "dark" ? "dark" : "light";
}

/**
 * Backward-compatible helper used across modules.
 * Returns resolved light/dark for document styling and Settings form radios.
 */
function getSavedTheme() {
    return getResolvedTheme();
}

function updateAppearanceTriggerLabel() {
    const label = document.getElementById("profileAppearanceCurrent");
    if (!label) return;
    const pref = getThemePreference();
    if (pref === "dark") label.textContent = "Dark";
    else if (pref === "system") label.textContent = "System";
    else label.textContent = "Light";
}

function applyTheme(theme, options = {}) {
    const persist = options.persist !== false;
    let preference = theme;
    if (
        preference !== "light" &&
        preference !== "dark" &&
        preference !== "system"
    ) {
        preference = "light";
    }

    const resolved = getResolvedTheme(preference);
    document.documentElement.setAttribute("data-theme", resolved);

    if (persist) {
        try {
            localStorage.setItem(HIMS_FLEET_THEME_KEY, preference);
        } catch {
            /* Storage may be unavailable */
        }
    }

    syncThemeMenuState();
}

/**
 * Sync profile-dropdown theme radios only.
 * Settings page uses name="settingsTheme" and must not share data-theme-option.
 */
function syncThemeMenuState() {
    const preference = getThemePreference();
    const options = document.querySelectorAll(
        "#sidebarProfileMenu [data-theme-option]",
    );

    options.forEach((option) => {
        const value = option.getAttribute("data-theme-option");
        const isActive = value === preference;

        option.classList.toggle("is-active", isActive);

        if (option.getAttribute("role") === "menuitemradio") {
            option.setAttribute("aria-checked", String(isActive));
        }
    });

    updateAppearanceTriggerLabel();
}

function initSystemThemeListener() {
    if (systemThemeListenerBound) return;
    if (!window.matchMedia) return;

    systemThemeMediaQuery = window.matchMedia("(prefers-color-scheme: dark)");
    const onChange = () => {
        if (getThemePreference() !== "system") return;
        document.documentElement.setAttribute("data-theme", getSystemTheme());
        syncThemeMenuState();
    };

    if (typeof systemThemeMediaQuery.addEventListener === "function") {
        systemThemeMediaQuery.addEventListener("change", onChange);
    } else if (typeof systemThemeMediaQuery.addListener === "function") {
        systemThemeMediaQuery.addListener(onChange);
    }

    systemThemeListenerBound = true;
}

function applyEarlyTheme() {
    applyTheme(getThemePreference(), { persist: false });
}

function initThemeControls() {
    const menu = document.getElementById("sidebarProfileMenu");

    if (!menu) return;

    initSystemThemeListener();

    if (menu.dataset.themeControlsInitialized === "true") {
        syncThemeMenuState();
        return;
    }

    menu.dataset.themeControlsInitialized = "true";

    menu.addEventListener("click", (event) => {
        const option = event.target?.closest?.("[data-theme-option]");

        if (
            !option ||
            option.disabled ||
            option.getAttribute("aria-disabled") === "true"
        ) {
            return;
        }

        const theme = option.getAttribute("data-theme-option");

        if (theme !== "light" && theme !== "dark" && theme !== "system") {
            event.preventDefault();
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        applyTheme(theme);
    });

    syncThemeMenuState();
}

applyEarlyTheme();

/* =====================================
   Sidebar Profile Dropdown
===================================== */

function initSidebarProfileDropdown() {
    const wrap = document.querySelector(".sidebar-profile-wrap");
    const toggle = document.getElementById("sidebarProfileToggle");
    const menu = document.getElementById("sidebarProfileMenu");
    const appearanceTrigger = document.getElementById(
        "profileAppearanceTrigger",
    );
    const appearanceSubmenu = document.getElementById(
        "profileAppearanceSubmenu",
    );

    if (!wrap || !toggle || !menu) return;

    if (wrap.dataset.profileDropdownInitialized === "true") {
        return;
    }

    wrap.dataset.profileDropdownInitialized = "true";

    const isAppearanceOpen = () =>
        appearanceSubmenu &&
        !appearanceSubmenu.hidden &&
        appearanceTrigger?.getAttribute("aria-expanded") === "true";

    const setAppearanceOpen = (open) => {
        if (!appearanceTrigger || !appearanceSubmenu) return;
        appearanceTrigger.setAttribute("aria-expanded", String(open));
        appearanceTrigger.classList.toggle("is-submenu-open", open);
        if (open) {
            appearanceSubmenu.hidden = false;
            appearanceSubmenu.classList.add("is-open");
        } else {
            appearanceSubmenu.hidden = true;
            appearanceSubmenu.classList.remove("is-open");
        }
    };

    const closeAppearanceSubmenu = () => {
        if (!isAppearanceOpen()) return;
        setAppearanceOpen(false);
    };

    const openAppearanceSubmenu = () => {
        setAppearanceOpen(true);
        if (typeof syncThemeMenuState === "function") {
            syncThemeMenuState();
        }
    };

    const toggleAppearanceSubmenu = () => {
        if (isAppearanceOpen()) closeAppearanceSubmenu();
        else openAppearanceSubmenu();
    };

    const setOpen = (open) => {
        wrap.classList.toggle("is-open", open);
        toggle.setAttribute("aria-expanded", String(open));
        menu.setAttribute("aria-hidden", String(!open));

        if (open) {
            menu.removeAttribute("hidden");

            if (typeof hideSidebarCollapsedTooltip === "function") {
                hideSidebarCollapsedTooltip();
            }
            if (typeof syncThemeMenuState === "function") {
                syncThemeMenuState();
            }
        } else {
            menu.setAttribute("hidden", "");
            closeAppearanceSubmenu();
        }
    };

    const isOpen = () => wrap.classList.contains("is-open");

    const closeMenu = () => {
        if (!isOpen()) return;
        setOpen(false);
    };

    const openMenu = () => {
        setOpen(true);
    };

    const toggleMenu = () => {
        if (isOpen()) {
            closeMenu();
        } else {
            openMenu();
        }
    };

    toggle.addEventListener("click", (event) => {
        event.preventDefault();
        event.stopPropagation();
        toggleMenu();
    });

    toggle.addEventListener("keydown", (event) => {
        if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            toggleMenu();
        }
    });

    document.addEventListener("click", (event) => {
        if (!isOpen()) return;

        const target = event.target;

        if (!(target instanceof Node) || !wrap.contains(target)) {
            closeMenu();
        }
    });

    document.addEventListener("keydown", (event) => {
        if (event.key !== "Escape" || !isOpen()) return;

        if (isAppearanceOpen()) {
            event.preventDefault();
            closeAppearanceSubmenu();
            appearanceTrigger?.focus();
            return;
        }

        closeMenu();
        toggle.focus();
    });

    appearanceTrigger?.addEventListener("click", (event) => {
        event.preventDefault();
        event.stopPropagation();
        toggleAppearanceSubmenu();
    });

    appearanceTrigger?.addEventListener("keydown", (event) => {
        if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            event.stopPropagation();
            toggleAppearanceSubmenu();
            return;
        }
        if (event.key === "ArrowRight") {
            event.preventDefault();
            event.stopPropagation();
            openAppearanceSubmenu();
            appearanceSubmenu
                ?.querySelector(
                    "[data-theme-option].is-active, [data-theme-option]",
                )
                ?.focus();
            return;
        }
        if (event.key === "ArrowLeft") {
            event.preventDefault();
            event.stopPropagation();
            closeAppearanceSubmenu();
        }
    });

    appearanceSubmenu?.addEventListener("keydown", (event) => {
        if (event.key === "ArrowLeft") {
            event.preventDefault();
            closeAppearanceSubmenu();
            appearanceTrigger?.focus();
        }
    });

    menu.addEventListener("click", (event) => {
        if (event.target?.closest?.("[data-theme-option]")) {
            return;
        }

        if (event.target?.closest?.("#profileAppearanceTrigger")) {
            return;
        }

        const item = event.target?.closest?.(
            '[role="menuitem"], [role="menuitemradio"]',
        );

        if (!item) return;

        /* Close submenu when choosing other profile actions */
        closeAppearanceSubmenu();

        const action = item.getAttribute("data-profile-action");
        const label = (item.textContent || "").replace(/\s+/g, " ").trim();

        if (action === "account-settings" || /account settings/i.test(label)) {
            /* Allow natural navigation for real href */
            if (
                item.tagName === "A" &&
                item.getAttribute("href")?.includes("settings")
            ) {
                closeMenu();
                return;
            }
            event.preventDefault();
            closeMenu();
            window.location.href = "/settings";
            return;
        }

        if (action === "profile" || /^profile$/i.test(label)) {
            if (
                item.tagName === "A" &&
                item.getAttribute("href")?.includes("profile")
            ) {
                closeMenu();
                return;
            }
            event.preventDefault();
            closeMenu();
            window.location.href = "/profile";
            return;
        }

        event.preventDefault();
        closeMenu();

        if (action === "help" || /help/i.test(label)) {
            if (typeof showToast === "function") {
                showToast(
                    "Help Center is not connected in this frontend build.",
                    "info",
                );
            }
            return;
        }

        if (action === "logout" || /logout/i.test(label)) {
            if (typeof performFleetLogout === "function") {
                performFleetLogout();
            } else if (typeof logout === "function") {
                if (
                    window.confirm(
                        "Sign out of HIMS Fleet?\n\nYou will need to sign in again to continue.\nTheme, profile, and fleet settings will be kept.",
                    )
                ) {
                    logout();
                    window.location.replace("/login");
                }
            } else if (typeof showToast === "function") {
                showToast(
                    "Unable to sign out. Auth utility is unavailable.",
                    "error",
                );
            }
        }
    });
}

/* =====================================
   Responsive Sidebar Navigation
===================================== */
function initResponsiveNavigation() {
    const sidebar =
        document.getElementById("sidebar") ||
        document.querySelector(".sidebar");
    const menuButton =
        document.querySelector(".navbar-custom .menu-toggle") ||
        document.querySelector(".menu-toggle");
    if (!sidebar || !menuButton) {
        return;
    }
    const compactViewport = window.matchMedia("(max-width: 991px)");
    let backdrop = document.querySelector(".sidebar-backdrop");
    if (!backdrop) {
        backdrop = document.createElement("div");
        backdrop.className = "sidebar-backdrop";
        backdrop.setAttribute("aria-hidden", "true");
        document.body.appendChild(backdrop);
    }
    menuButton.type = "button";
    menuButton.setAttribute("aria-controls", "sidebar");
    const closeSidebar = () => {
        document.body.classList.remove("sidebar-open");
        menuButton.setAttribute("aria-expanded", "false");
        backdrop.setAttribute("aria-hidden", "true");
    };
    const openSidebar = () => {
        if (!compactViewport.matches) {
            return;
        }
        document.body.classList.add("sidebar-open");
        menuButton.setAttribute("aria-expanded", "true");
        backdrop.setAttribute("aria-hidden", "false");
    };
    const syncSidebarState = () => {
        if (!compactViewport.matches) {
            closeSidebar();
            return;
        }
        menuButton.setAttribute(
            "aria-expanded",
            String(document.body.classList.contains("sidebar-open")),
        );
    };
    /*
  |--------------------------------------------------------------------------
  | Prevent duplicate listeners
  |--------------------------------------------------------------------------
  */
    if (menuButton.dataset.responsiveNavigationInitialized === "true") {
        syncSidebarState();
        return;
    }
    menuButton.dataset.responsiveNavigationInitialized = "true";
    menuButton.setAttribute("aria-label", "Toggle navigation menu");
    menuButton.setAttribute("aria-expanded", "false");
    menuButton.addEventListener("click", (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (!compactViewport.matches) {
            return;
        }
        const open = document.body.classList.contains("sidebar-open");
        if (open) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });

    backdrop.addEventListener("click", closeSidebar);
    sidebar.addEventListener("click", (event) => {
        const navigationLink = event.target?.closest?.(".nav-link");
        if (!compactViewport.matches || !navigationLink) {
            return;
        }
        closeSidebar();
    });

    document.addEventListener("keydown", (event) => {
        if (
            event.key === "Escape" &&
            compactViewport.matches &&
            document.body.classList.contains("sidebar-open")
        ) {
            closeSidebar();
        }
    });

    const onViewportChange = () => {
        syncSidebarState();
    };

    if (typeof compactViewport.addEventListener === "function") {
        compactViewport.addEventListener("change", onViewportChange);
    } else {
        compactViewport.addListener(onViewportChange);
    }
    syncSidebarState();
}

/* =====================================
   Initialize Fleet UI
===================================== */

function initializeFleetUI() {
  initializePage();

  initDesktopSidebarCollapse();
  initSidebarCollapsedTooltips();

  initThemeControls();
  initSidebarProfileDropdown();

  initResponsiveNavigation();
}

function bootFleetUI() {
  initializeFleetUI();

  setTimeout(() => {
    initDesktopSidebarCollapse();
    initSidebarCollapsedTooltips();
    initThemeControls();
    initSidebarProfileDropdown();
    initResponsiveNavigation();
  }, 150);

  setTimeout(() => {
    initDesktopSidebarCollapse();
    initSidebarCollapsedTooltips();
    initThemeControls();
    initSidebarProfileDropdown();
    initResponsiveNavigation();
  }, 500);
}

if (document.readyState === "loading") {
  document.addEventListener(
    "DOMContentLoaded",
    bootFleetUI
  );
} else {
  bootFleetUI();
}