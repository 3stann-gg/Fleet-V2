/* ==========================================
   Maintenance Pagination
   Applies page slice after Search/Filter/Sort.
   Delegated controls; rebuild UI only when needed.
========================================== */

const maintenanceRowsPerPage = 5;
let maintenancePaginationState = null;

function updateMaintenancePaginationInfo(info, start, end, total) {
  if (!info) return;

  const range = document.createElement("strong");
  const totalCount = document.createElement("strong");

  range.textContent = `${start}–${end}`;
  totalCount.textContent = String(total);

  info.replaceChildren(
    document.createTextNode("Showing "),
    range,
    document.createTextNode(" of "),
    totalCount,
    document.createTextNode(" maintenance records"),
  );
}

function getMaintenanceVisiblePages(currentPage, totalPages) {
  if (totalPages <= 7) {
    return Array.from({ length: totalPages }, (_, index) => index + 1);
  }

  const pages = new Set([1, totalPages, currentPage]);

  for (let offset = -1; offset <= 1; offset += 1) {
    const page = currentPage + offset;
    if (page > 1 && page < totalPages) {
      pages.add(page);
    }
  }

  return Array.from(pages).sort((a, b) => a - b);
}

function createMaintenancePaginationButton({
  label,
  ariaLabel,
  iconClass,
  disabled = false,
  active = false,
  pageAction,
  pageNumber,
}) {
  const button = document.createElement("button");

  button.type = "button";
  button.setAttribute("aria-label", ariaLabel);
  button.disabled = disabled;

  if (pageAction) {
    button.dataset.maintenancePage = pageAction;
  }

  if (pageNumber != null) {
    button.dataset.pageNumber = String(pageNumber);
  }

  if (active) {
    button.classList.add("active");
    button.setAttribute("aria-current", "page");
  }

  if (iconClass) {
    const icon = document.createElement("i");
    icon.className = iconClass;
    icon.setAttribute("aria-hidden", "true");
    button.appendChild(icon);
  } else {
    button.textContent = String(label);
  }

  return button;
}

function buildMaintenancePaginationControls(totalPages) {
  const { pagination, currentPage } = maintenancePaginationState;

  if (!pagination) return;

  const fragment = document.createDocumentFragment();

  fragment.appendChild(
    createMaintenancePaginationButton({
      ariaLabel: "Previous page",
      iconClass: "ph ph-caret-left",
      disabled: currentPage === 1 || totalPages === 0,
      pageAction: "prev",
    }),
  );

  const visiblePages = getMaintenanceVisiblePages(currentPage, totalPages);
  let previousPage = 0;

  visiblePages.forEach((page) => {
    if (previousPage && page - previousPage > 1) {
      const ellipsis = document.createElement("button");
      ellipsis.type = "button";
      ellipsis.textContent = "…";
      ellipsis.disabled = true;
      ellipsis.setAttribute("aria-hidden", "true");
      ellipsis.setAttribute("tabindex", "-1");
      fragment.appendChild(ellipsis);
    }

    fragment.appendChild(
      createMaintenancePaginationButton({
        label: page,
        ariaLabel: `Page ${page}`,
        active: page === currentPage,
        pageAction: "page",
        pageNumber: page,
      }),
    );

    previousPage = page;
  });

  fragment.appendChild(
    createMaintenancePaginationButton({
      ariaLabel: "Next page",
      iconClass: "ph ph-caret-right",
      disabled: totalPages === 0 || currentPage === totalPages,
      pageAction: "next",
    }),
  );

  pagination.replaceChildren(fragment);
}

/**
 * Apply page visibility + empty state + optional control rebuild.
 * Called from refreshMaintenanceTable with precomputed matching rows.
 */
function applyMaintenancePagination({
  matchingRows = [],
  allRows = [],
  resetPage = false,
} = {}) {
  if (!maintenancePaginationState) {
    allRows.forEach((row) => {
      row.style.display =
        row.dataset.matchesFilter !== "false" ? "" : "none";
    });

    if (typeof updateMaintenanceNoResultsRow === "function") {
      updateMaintenanceNoResultsRow(
        document.getElementById("maintenanceTableBody"),
        matchingRows.length === 0,
      );
    }

    return false;
  }

  const { tableBody, info } = maintenancePaginationState;
  const total = matchingRows.length;
  const totalPages = Math.ceil(total / maintenanceRowsPerPage) || 0;

  if (resetPage) {
    maintenancePaginationState.currentPage = 1;
  }

  if (totalPages === 0) {
    maintenancePaginationState.currentPage = 1;
  } else {
    maintenancePaginationState.currentPage = Math.min(
      Math.max(maintenancePaginationState.currentPage, 1),
      totalPages,
    );
  }

  const currentPage = maintenancePaginationState.currentPage;
  const startIndex = (currentPage - 1) * maintenanceRowsPerPage;
  const endIndex = startIndex + maintenanceRowsPerPage;
  const start = total === 0 ? 0 : startIndex + 1;
  const end = Math.min(endIndex, total);

  allRows.forEach((row) => {
    row.style.display = "none";
  });

  matchingRows.slice(startIndex, endIndex).forEach((row) => {
    row.style.display = "";
  });

  if (typeof updateMaintenanceNoResultsRow === "function") {
    updateMaintenanceNoResultsRow(tableBody, total === 0);
  }

  updateMaintenancePaginationInfo(info, start, end, total);

  const last = maintenancePaginationState.lastRendered || {};
  const controlsChanged =
    last.currentPage !== currentPage ||
    last.totalPages !== totalPages ||
    last.rowsPerPage !== maintenanceRowsPerPage;

  if (controlsChanged) {
    buildMaintenancePaginationControls(totalPages);
    maintenancePaginationState.lastRendered = {
      currentPage,
      totalPages,
      rowsPerPage: maintenanceRowsPerPage,
    };
  }

  if (typeof syncMaintenanceSelectionUI === "function") {
    syncMaintenanceSelectionUI();
  } else if (typeof refreshMaintenanceBulkState === "function") {
    refreshMaintenanceBulkState();
  }

  return true;
}

function renderMaintenancePagination() {
  if (!maintenancePaginationState) return false;

  const tableBody = maintenancePaginationState.tableBody;
  const allRows =
    typeof getMaintenanceDataRows === "function"
      ? getMaintenanceDataRows(tableBody)
      : [];
  const matchingRows = allRows.filter(
    (row) => row.dataset.matchesFilter !== "false",
  );

  return applyMaintenancePagination({
    matchingRows,
    allRows,
    resetPage: false,
  });
}

function refreshMaintenancePagination({ reset = false } = {}) {
  if (!maintenancePaginationState) return false;

  if (reset) {
    maintenancePaginationState.currentPage = 1;
  }

  return renderMaintenancePagination();
}

function updateMaintenancePagination() {
  return refreshMaintenancePagination();
}

function resetMaintenancePagination() {
  return refreshMaintenancePagination({ reset: true });
}

/**
 * State + one delegated listener only. No initial pipeline render.
 */
function initMaintenancePagination() {
  const tableBody = document.getElementById("maintenanceTableBody");
  const pagination = document.getElementById("maintenancePagination");
  const info = document.getElementById("maintenancePaginationInfo");

  if (!tableBody || !pagination) return false;

  if (tableBody.dataset.maintenancePaginationInitialized === "true") {
    return true;
  }

  tableBody.dataset.maintenancePaginationInitialized = "true";

  maintenancePaginationState = {
    tableBody,
    pagination,
    info,
    currentPage: 1,
    rowsPerPage: maintenanceRowsPerPage,
    lastRendered: null,
  };

  pagination.addEventListener("click", (event) => {
    const button = event.target.closest("button[data-maintenance-page]");

    if (!button || button.disabled || !maintenancePaginationState) {
      return;
    }

    const action = button.dataset.maintenancePage;
    const totalPages = maintenancePaginationState.lastRendered?.totalPages || 0;

    if (action === "prev") {
      if (maintenancePaginationState.currentPage <= 1) return;
      maintenancePaginationState.currentPage -= 1;
    } else if (action === "next") {
      if (
        totalPages === 0 ||
        maintenancePaginationState.currentPage >= totalPages
      ) {
        return;
      }
      maintenancePaginationState.currentPage += 1;
    } else if (action === "page") {
      const page = Number(button.dataset.pageNumber);
      if (!page || page === maintenancePaginationState.currentPage) return;
      maintenancePaginationState.currentPage = page;
    } else {
      return;
    }

    /* Page navigation only needs visibility slice, not full rematch */
    if (typeof isMaintenanceTableRefreshing === "function" && isMaintenanceTableRefreshing()) {
      return;
    }

    renderMaintenancePagination();
  });

  return true;
}
