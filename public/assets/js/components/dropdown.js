/* =====================================
   Shared export / action dropdown menus
   Used in list card headers (Print / PDF / Excel)
===================================== */

function closeExportDropdown(dropdown) {
  if (!dropdown) return;

  dropdown.classList.remove("is-open");

  const toggle = dropdown.querySelector(".export-menu-toggle");
  const menu = dropdown.querySelector(".export-menu");

  if (toggle) {
    toggle.setAttribute("aria-expanded", "false");
  }

  if (menu) {
    menu.hidden = true;
  }
}

function closeAllExportDropdowns(except) {
  document.querySelectorAll(".export-dropdown.is-open").forEach((dropdown) => {
    if (except && dropdown === except) return;
    closeExportDropdown(dropdown);
  });
}

function openExportDropdown(dropdown) {
  if (!dropdown) return;

  closeAllExportDropdowns(dropdown);

  const toggle = dropdown.querySelector(".export-menu-toggle");
  const menu = dropdown.querySelector(".export-menu");

  dropdown.classList.add("is-open");

  if (toggle) {
    toggle.setAttribute("aria-expanded", "true");
  }

  if (menu) {
    menu.hidden = false;
  }
}

function toggleExportDropdown(dropdown) {
  if (!dropdown) return;

  if (dropdown.classList.contains("is-open")) {
    closeExportDropdown(dropdown);
  } else {
    openExportDropdown(dropdown);
  }
}

/**
 * One-time delegated init for all .export-dropdown menus on the page.
 * Safe to call after dynamic injection; does not attach duplicate listeners.
 */
function initExportDropdowns() {
  if (document.body.dataset.exportDropdownsInitialized === "true") {
    return;
  }

  document.body.dataset.exportDropdownsInitialized = "true";

  document.addEventListener("click", (event) => {
    const target = event.target;

    if (!(target instanceof Element)) {
      return;
    }

    const toggle = target.closest(".export-menu-toggle");

    if (toggle) {
      const dropdown = toggle.closest(".export-dropdown");

      if (!dropdown) {
        return;
      }

      event.preventDefault();
      toggleExportDropdown(dropdown);
      return;
    }

    const menuItem = target.closest(".export-menu-item");

    if (menuItem) {
      const dropdown = menuItem.closest(".export-dropdown");

      /* Allow the item's existing feature click handlers to run, then close */
      if (dropdown) {
        requestAnimationFrame(() => {
          closeExportDropdown(dropdown);
        });
      }

      return;
    }

    if (!target.closest(".export-dropdown")) {
      closeAllExportDropdowns();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape") {
      return;
    }

    const openDropdown = document.querySelector(".export-dropdown.is-open");

    if (!openDropdown) {
      return;
    }

    const toggle = openDropdown.querySelector(".export-menu-toggle");

    closeExportDropdown(openDropdown);

    if (toggle && typeof toggle.focus === "function") {
      toggle.focus();
    }
  });
}

document.addEventListener("DOMContentLoaded", () => {
    initExportDropdowns();
});