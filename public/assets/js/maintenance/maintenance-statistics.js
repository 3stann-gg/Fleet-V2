/* ==========================================
   Maintenance Statistics
   Counts ALL maintenance records (ignores search/filter visibility).
========================================== */

function normalizeMaintenanceStatus(value) {
  return String(value || "")
    .trim()
    .toLowerCase()
    .replace(/[\s_-]+/g, " ")
    .trim();
}

function isMaintenanceStatisticsDataRow(row) {
  if (!row || row.nodeType !== Node.ELEMENT_NODE) return false;

  const isHelperRow =
    row.classList.contains("maintenance-no-results") ||
    row.classList.contains("helper-row") ||
    row.classList.contains("empty-state") ||
    row.classList.contains("temporary-row") ||
    row.dataset.helperRow === "true" ||
    row.dataset.temporary === "true";

  if (isHelperRow) return false;

  return Boolean(
    row.querySelector(".maintenance-number") ||
      row.querySelector(".maintenance-checkbox") ||
      row.querySelector(".maintenance-vehicle"),
  );
}

function getMaintenanceStatisticsRows(tableBody) {
  if (!tableBody) return [];

  return Array.from(tableBody.querySelectorAll("tr")).filter(
    isMaintenanceStatisticsDataRow,
  );
}

function getMaintenanceRowStatusForStatistics(row) {
  const badge = row.querySelector(".status-badge");
  return normalizeMaintenanceStatus(badge ? badge.textContent : "");
}

/**
 * Recalculate summary cards from every real maintenance row.
 * Does not use style.display — search/filters do not affect counts.
 */
function updateMaintenanceStatistics() {
  const totalEl = document.getElementById("totalMaintenance");
  const scheduledEl = document.getElementById("scheduledMaintenance");
  const inProgressEl = document.getElementById("inProgressMaintenance");
  const completedEl = document.getElementById("completedMaintenance");
  const tableBody = document.getElementById("maintenanceTableBody");

  if (
    !totalEl ||
    !scheduledEl ||
    !inProgressEl ||
    !completedEl ||
    !tableBody
  ) {
    return;
  }

  let total = 0;
  let scheduled = 0;
  let inProgress = 0;
  let completed = 0;

  getMaintenanceStatisticsRows(tableBody).forEach((row) => {
    total += 1;

    const status = getMaintenanceRowStatusForStatistics(row);

    if (status === "scheduled") {
      scheduled += 1;
    } else if (status === "in progress") {
      inProgress += 1;
    } else if (status === "completed") {
      completed += 1;
    }
    /* Cancelled and unknown statuses count toward total only */
  });

  totalEl.textContent = String(total);
  scheduledEl.textContent = String(scheduled);
  inProgressEl.textContent = String(inProgress);
  completedEl.textContent = String(completed);
}

function refreshMaintenanceStatistics() {
  updateMaintenanceStatistics();
}

/**
 * Mark statistics ready. Prefer a single updateMaintenanceStatistics()
 * call after the initial refreshMaintenanceTable() from include.js.
 */
function initMaintenanceStatistics() {
  const tableBody = document.getElementById("maintenanceTableBody");
  const totalEl = document.getElementById("totalMaintenance");

  if (!tableBody || !totalEl) return;

  if (tableBody.dataset.maintenanceStatisticsInitialized === "true") {
    return;
  }

  tableBody.dataset.maintenanceStatisticsInitialized = "true";
}
