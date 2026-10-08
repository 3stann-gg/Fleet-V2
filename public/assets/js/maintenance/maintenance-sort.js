/* ==========================================
   Maintenance Table Sorting
   Cycles: ascending → descending → default
   Used by refreshMaintenanceTable pipeline
========================================== */

const MAINTENANCE_SORT_CONFIG = {
  1: { type: "text", field: "number" },
  2: { type: "text", field: "vehicle" },
  3: { type: "text", field: "serviceType" },
  4: { type: "text", field: "technician" },
  5: { type: "date", field: "scheduledDate" },
  6: { type: "date", field: "completionDate" },
  7: { type: "number", field: "cost" },
  8: { type: "priority", field: "priority" },
  9: { type: "text", field: "status" },
};

const MAINTENANCE_PRIORITY_RANK = {
  low: 1,
  normal: 2,
  medium: 2,
  high: 3,
  emergency: 4,
  critical: 4,
  urgent: 4,
};

let maintenanceSortState = null;

function getMaintenanceSortTableBody() {
  return document.getElementById("maintenanceTableBody");
}

function parseMaintenanceSortNumber(value) {
  if (value == null || value === "") return Number.NaN;

  const cleaned = String(value).replace(/[^\d.-]/g, "");
  if (!cleaned) return Number.NaN;

  return Number.parseFloat(cleaned);
}

function parseMaintenanceSortDate(value) {
  if (value == null || value === "") return Number.NaN;

  const raw = String(value).trim();
  if (!raw || raw === "—" || raw.toLowerCase() === "not completed") {
    return Number.NaN;
  }

  if (/^\d{4}-\d{2}-\d{2}/.test(raw)) {
    const time = Date.parse(raw);
    return Number.isNaN(time) ? Number.NaN : time;
  }

  const time = Date.parse(raw);
  return Number.isNaN(time) ? Number.NaN : time;
}

function getMaintenanceMetaSortValue(meta, columnIndex) {
  const config = MAINTENANCE_SORT_CONFIG[columnIndex];
  if (!config || !meta) return "";

  return meta[config.field] != null ? meta[config.field] : "";
}

function compareMaintenanceMetaValues(aMeta, bMeta, columnIndex, direction) {
  const config = MAINTENANCE_SORT_CONFIG[columnIndex];
  if (!config) return 0;

  const aRaw = getMaintenanceMetaSortValue(aMeta, columnIndex);
  const bRaw = getMaintenanceMetaSortValue(bMeta, columnIndex);
  const dir = direction === "desc" ? -1 : 1;

  if (config.type === "number") {
    const aNum = parseMaintenanceSortNumber(aRaw);
    const bNum = parseMaintenanceSortNumber(bRaw);
    const aEmpty = Number.isNaN(aNum);
    const bEmpty = Number.isNaN(bNum);

    if (aEmpty && bEmpty) return 0;
    if (aEmpty) return 1;
    if (bEmpty) return -1;
    if (aNum === bNum) return 0;
    return aNum < bNum ? -dir : dir;
  }

  if (config.type === "date") {
    const aTime = parseMaintenanceSortDate(aRaw);
    const bTime = parseMaintenanceSortDate(bRaw);
    const aEmpty = Number.isNaN(aTime);
    const bEmpty = Number.isNaN(bTime);

    if (aEmpty && bEmpty) return 0;
    if (aEmpty) return 1;
    if (bEmpty) return -1;
    if (aTime === bTime) return 0;
    return aTime < bTime ? -dir : dir;
  }

  if (config.type === "priority") {
    const aRank =
      MAINTENANCE_PRIORITY_RANK[String(aRaw).trim().toLowerCase()] || 0;
    const bRank =
      MAINTENANCE_PRIORITY_RANK[String(bRaw).trim().toLowerCase()] || 0;

    if (aRank === bRank) {
      return (
        String(aRaw).localeCompare(String(bRaw), undefined, {
          sensitivity: "base",
          numeric: true,
        }) * dir
      );
    }

    return aRank < bRank ? -dir : dir;
  }

  return (
    String(aRaw).localeCompare(String(bRaw), undefined, {
      sensitivity: "base",
      numeric: true,
    }) * dir
  );
}

function clearMaintenanceSortIndicators(headers) {
  headers.forEach((th) => {
    th.removeAttribute("aria-sort");
    const icon = th.querySelector(".sort-icon");
    if (icon) {
      icon.className = "ph ph-caret-up-down sort-icon";
    }
  });
}

function setMaintenanceSortIndicator(th, direction) {
  if (!th) return;

  if (!direction) {
    th.removeAttribute("aria-sort");
    const icon = th.querySelector(".sort-icon");
    if (icon) {
      icon.className = "ph ph-caret-up-down sort-icon";
    }
    return;
  }

  th.setAttribute(
    "aria-sort",
    direction === "asc" ? "ascending" : "descending",
  );

  const icon = th.querySelector(".sort-icon");
  if (icon) {
    icon.className =
      direction === "asc"
        ? "ph ph-caret-up sort-icon"
        : "ph ph-caret-down sort-icon";
  }
}

function updateMaintenanceSortIndicators() {
  if (!maintenanceSortState) return;

  const { activeColumn, activeDirection, headers } = maintenanceSortState;

  clearMaintenanceSortIndicators(headers);

  if (activeColumn && activeDirection) {
    const activeHeader = headers.find(
      (th) => Number(th.dataset.column) === activeColumn,
    );
    setMaintenanceSortIndicator(activeHeader, activeDirection);
  }
}

/**
 * Sort meta arrays in place. Uses active sort or original insertion order.
 */
function sortMaintenanceRowMetas(matchingMetas, nonMatchingMetas) {
  const byOriginal = (a, b) => a.originalOrder - b.originalOrder;

  if (
    !maintenanceSortState?.activeColumn ||
    !maintenanceSortState?.activeDirection
  ) {
    matchingMetas.sort(byOriginal);
    nonMatchingMetas.sort(byOriginal);
    return;
  }

  const { activeColumn, activeDirection } = maintenanceSortState;

  const byActive = (a, b) =>
    compareMaintenanceMetaValues(a, b, activeColumn, activeDirection);

  matchingMetas.sort(byActive);
  nonMatchingMetas.sort(byActive);
}

/**
 * Reorder tbody only when desired order differs from current order.
 * @returns {boolean} whether DOM was modified
 */
function applyMaintenanceRowOrder(tableBody, matchingMetas, nonMatchingMetas) {
  if (!tableBody) return false;

  const desired = [...matchingMetas, ...nonMatchingMetas].map(
    (meta) => meta.row,
  );
  const current =
    typeof getMaintenanceDataRows === "function"
      ? getMaintenanceDataRows(tableBody)
      : Array.from(tableBody.children).filter((row) => row.tagName === "TR");

  let sameOrder =
    desired.length === current.length &&
    desired.every((row, index) => row === current[index]);

  if (sameOrder) {
    updateMaintenanceSortIndicators();
    return false;
  }

  const fragment = document.createDocumentFragment();
  desired.forEach((row) => fragment.appendChild(row));

  const emptyRow = tableBody.querySelector(".maintenance-no-results");
  if (emptyRow) {
    fragment.appendChild(emptyRow);
  }

  tableBody.appendChild(fragment);
  updateMaintenanceSortIndicators();
  return true;
}

function cycleMaintenanceSortDirection(current) {
  if (current === "asc") return "desc";
  if (current === "desc") return null;
  return "asc";
}

/**
 * Listeners only — no pipeline run.
 */
function initMaintenanceSorting() {
  const tableBody = getMaintenanceSortTableBody();
  if (!tableBody) return;

  if (tableBody.dataset.maintenanceSortingInitialized === "true") {
    return;
  }

  tableBody.dataset.maintenanceSortingInitialized = "true";

  const table = tableBody.closest("table");
  if (!table) return;

  const headers = Array.from(
    table.querySelectorAll("th.sortable[data-column]"),
  ).filter((th) => MAINTENANCE_SORT_CONFIG[Number(th.dataset.column)]);

  if (headers.length === 0) return;

  maintenanceSortState = {
    activeColumn: null,
    activeDirection: null,
    headers,
  };

  headers.forEach((th) => {
    if (!th.hasAttribute("tabindex")) {
      th.setAttribute("tabindex", "0");
    }

    if (!th.hasAttribute("role")) {
      th.setAttribute("role", "columnheader");
    }

    const activate = () => {
      const columnIndex = Number(th.dataset.column);
      if (!MAINTENANCE_SORT_CONFIG[columnIndex]) return;

      if (maintenanceSortState.activeColumn === columnIndex) {
        maintenanceSortState.activeDirection = cycleMaintenanceSortDirection(
          maintenanceSortState.activeDirection,
        );

        if (!maintenanceSortState.activeDirection) {
          maintenanceSortState.activeColumn = null;
        }
      } else {
        maintenanceSortState.activeColumn = columnIndex;
        maintenanceSortState.activeDirection = "asc";
      }

      if (typeof refreshMaintenanceTable === "function") {
        refreshMaintenanceTable({ resetPage: true });
      }
    };

    th.addEventListener("click", (event) => {
      event.preventDefault();
      activate();
    });

    th.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        activate();
      }
    });
  });

  /* No MutationObserver — pipeline assigns order and refreshes after CRUD */
}

/** @deprecated use refreshMaintenanceTable */
function reapplyMaintenanceSort() {
  if (typeof refreshMaintenanceTable === "function") {
    refreshMaintenanceTable();
  }
}

function applyMaintenanceSort() {
  if (typeof refreshMaintenanceTable === "function") {
    refreshMaintenanceTable();
  }
}

document.addEventListener("DOMContentLoaded", () => {
    initMaintenanceSorting();
});