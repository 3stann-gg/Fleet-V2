/* ==========================================
   Maintenance Search + Filters + Table Pipeline
========================================== */

const MAINTENANCE_TABLE_COLUMN_COUNT = 11;

let maintenanceSearchState = null;
let isRefreshingMaintenanceTable = false;
let maintenanceNextOriginalOrder = 0;

function isMaintenanceTableRefreshing() {
    return isRefreshingMaintenanceTable;
}

function getMaintenanceDataRows(tableBody) {
    if (!tableBody) {
        return [];
    }

    return Array.from(tableBody.querySelectorAll("tr")).filter((row) => {
        const isHelperRow =
            row.classList.contains("maintenance-no-results") ||
            row.classList.contains("helper-row") ||
            row.classList.contains("empty-state") ||
            row.dataset.helperRow === "true";

        if (isHelperRow) {
            return false;
        }

        return Boolean(
            row.querySelector(".maintenance-number") ||
            row.querySelector(".maintenance-checkbox") ||
            row.querySelector(".maintenance-vehicle"),
        );
    });
}

function getMaintenanceCellText(row, selector) {
    const element = row.querySelector(selector);

    return element ? element.textContent.trim() : "";
}

function ensureMaintenanceOriginalOrder(row) {
    if (
        row.dataset.maintenanceSortIndex == null ||
        row.dataset.maintenanceSortIndex === ""
    ) {
        row.dataset.maintenanceSortIndex = String(maintenanceNextOriginalOrder);

        maintenanceNextOriginalOrder += 1;
    }

    return Number(row.dataset.maintenanceSortIndex) || 0;
}

function buildMaintenanceRowMeta(row) {
    const number = getMaintenanceCellText(row, ".maintenance-number");
    const vehicle = getMaintenanceCellText(row, ".maintenance-vehicle");
    const serviceType = getMaintenanceCellText(
        row,
        ".maintenance-service-type",
    );
    const technician = getMaintenanceCellText(row, ".maintenance-technician");
    const scheduledDisplay = getMaintenanceCellText(
        row,
        ".maintenance-scheduled-date",
    );
    const completionDisplay = getMaintenanceCellText(
        row,
        ".maintenance-completion-date",
    );

    const cost = getMaintenanceCellText(row, ".maintenance-cost");
    const priority = getMaintenanceCellText(row, ".maintenance-priority");
    const status = getMaintenanceCellText(row, ".status-badge");
    const scheduledDate = (row.dataset.scheduledDate || "").trim();
    const completionDate = (row.dataset.completionDate || "").trim();
    const datasetText = [
        row.dataset.description,
        row.dataset.notes,
        row.dataset.partsUsed,
        row.dataset.odometer,
        row.dataset.priority,
        scheduledDate,
        completionDate,
    ]
        .map((value) => (value ? String(value).trim() : ""))
        .join(" ");

    const searchableText = [
        number,
        vehicle,
        serviceType,
        technician,
        scheduledDisplay,
        completionDisplay,
        cost,
        priority,
        status,
        datasetText,
    ]
        .join(" ")
        .toLowerCase()
        .replace(/\s+/g, " ")
        .trim();

    return {
        row,
        originalOrder: ensureMaintenanceOriginalOrder(row),
        searchableText,
        status: status.toLowerCase(),
        serviceType: serviceType.toLowerCase(),
        scheduledDate,
        number,
        vehicle,
        technician,
        cost,
        priority,
        completionDate,
    };
}

function getMaintenanceFilterSelectValue(select) {
    if (!select) {
        return "";
    }

    if (!select.value || select.value === "all") {
        return "";
    }

    const option = select.options[select.selectedIndex];
    const value = option?.textContent || select.value || "";

    return value.trim().toLowerCase();
}


function updateMaintenanceNoResultsRow(tableBody, shouldShow) {
    if (!tableBody) {
        return;
    }

    const existingRow = tableBody.querySelector(".maintenance-no-results");

    if (!shouldShow) {
        existingRow?.remove();
        return;
    }
    if (existingRow) {
        return;
    }

    const row = document.createElement("tr");
    const cell = document.createElement("td");

    row.className = "maintenance-no-results";
    row.dataset.helperRow = "true";
    cell.colSpan = MAINTENANCE_TABLE_COLUMN_COUNT;
    cell.className = "text-center";
    cell.textContent = "No maintenance records found.";

    row.appendChild(cell);
    tableBody.appendChild(row);
}


function ensureMaintenanceSearchState() {
    const tableBody = document.getElementById("maintenanceTableBody");

    if (!tableBody) {
        return null;
    }
    if (maintenanceSearchState?.tableBody === tableBody) {
        return maintenanceSearchState;
    }

    maintenanceSearchState = {
        tableBody,

        searchInput: document.getElementById("maintenanceSearch"),
        statusFilter: document.getElementById("maintenanceStatusFilter"),
        typeFilter: document.getElementById("maintenanceTypeFilter"),
        dateFilter: document.getElementById("maintenanceDateFilter"),
    };

    return maintenanceSearchState;
}

function refreshMaintenanceTable(options = {}) {
    if (isRefreshingMaintenanceTable) {
        return [];
    }

    const resetPage = options.resetPage === true;
    const refreshStatistics = options.refreshStatistics === true;

    isRefreshingMaintenanceTable = true;

    try {
        const state = ensureMaintenanceSearchState();

        if (!state?.tableBody) {
            return [];
        }

        const { tableBody, searchInput, statusFilter, typeFilter, dateFilter } =
            state;
        const searchQuery = (searchInput?.value || "").trim().toLowerCase();
        const statusValue = getMaintenanceFilterSelectValue(statusFilter);
        const typeValue = getMaintenanceFilterSelectValue(typeFilter);
        const dateValue = (dateFilter?.value || "").trim();
        const allRows = getMaintenanceDataRows(tableBody);
        const metas = allRows.map(buildMaintenanceRowMeta);
        const matchingMetas = [];
        const nonMatchingMetas = [];

        metas.forEach((meta) => {
            const matchesSearch =
                !searchQuery || meta.searchableText.includes(searchQuery);

            const matchesStatus = !statusValue || meta.status === statusValue;
            const matchesType = !typeValue || meta.serviceType === typeValue;
            const matchesDate = !dateValue || meta.scheduledDate === dateValue;
            const isMatch =
                matchesSearch && matchesStatus && matchesType && matchesDate;

            meta.row.dataset.matchesFilter = String(isMatch);
            meta.row.dataset.maintenanceMatchesFilter = String(isMatch);

            if (isMatch) {
                matchingMetas.push(meta);
            } else {
                nonMatchingMetas.push(meta);
            }
        });

        if (typeof sortMaintenanceRowMetas === "function") {
            sortMaintenanceRowMetas(matchingMetas, nonMatchingMetas);
        } else {
            const byOriginal = (a, b) => a.originalOrder - b.originalOrder;

            matchingMetas.sort(byOriginal);

            nonMatchingMetas.sort(byOriginal);
        }

        if (typeof applyMaintenanceRowOrder === "function") {
            applyMaintenanceRowOrder(
                tableBody,
                matchingMetas,
                nonMatchingMetas,
            );
        }

        const matchingRows = matchingMetas.map((meta) => meta.row);

        updateMaintenanceNoResultsRow(tableBody, matchingRows.length === 0);

        if (typeof applyMaintenancePagination === "function") {
            applyMaintenancePagination({
                matchingRows,
                allRows: getMaintenanceDataRows(tableBody),
                resetPage,
            });
        } else {
            allRows.forEach((row) => {
                row.style.display =
                    row.dataset.matchesFilter !== "false" ? "" : "none";
            });
        }

        /*
         * Make sure no-results remains visible
         * after pagination updates.
         */
        updateMaintenanceNoResultsRow(tableBody, matchingRows.length === 0);

        if (
            refreshStatistics &&
            typeof updateMaintenanceStatistics === "function"
        ) {
            updateMaintenanceStatistics();
        }

        if (typeof syncMaintenanceSelectionUI === "function") {
            syncMaintenanceSelectionUI();
        }

        return matchingRows;
    } catch (error) {
        console.error("refreshMaintenanceTable failed:", error);

        return [];
    } finally {
        queueMicrotask(() => {
            isRefreshingMaintenanceTable = false;
        });
    }
}


function applyMaintenanceFilters(options) {
    return refreshMaintenanceTable(options);
}

function applyMaintenanceSearch(options) {
    return refreshMaintenanceTable(options);
}

function refreshMaintenanceSearch(options) {
    return refreshMaintenanceTable(options);
}

function resetMaintenanceFilters() {
    const state = ensureMaintenanceSearchState();

    if (!state) {
        return [];
    }
    if (state.searchInput) {
        state.searchInput.value = "";
    }
    if (state.statusFilter) {
        state.statusFilter.value = "all";
    }
    if (state.typeFilter) {
        state.typeFilter.value = "all";
    }
    if (state.dateFilter) {
        state.dateFilter.value = "";
    }

    return refreshMaintenanceTable({
        resetPage: true,
        refreshStatistics: true,
        reason: "refresh",
    });
}


function initMaintenanceSearch() {
    const tableBody = document.getElementById("maintenanceTableBody");

    if (!tableBody) {
        return;
    }
    if (tableBody.dataset.maintenanceSearchInitialized === "true") {
        return;
    }

    tableBody.dataset.maintenanceSearchInitialized = "true";

    ensureMaintenanceSearchState();

    getMaintenanceDataRows(tableBody).forEach((row) => {
        ensureMaintenanceOriginalOrder(row);
    });

    const { searchInput, statusFilter, typeFilter, dateFilter } =
        maintenanceSearchState;
    const refreshButton = document.getElementById("refreshMaintenance");

    searchInput?.addEventListener("input", () => {
        refreshMaintenanceTable({
            resetPage: true,
        });
    });
    statusFilter?.addEventListener("change", () => {
        refreshMaintenanceTable({
            resetPage: true,
        });
    });
    typeFilter?.addEventListener("change", () => {
        refreshMaintenanceTable({
            resetPage: true,
        });
    });
    dateFilter?.addEventListener("change", () => {
        refreshMaintenanceTable({
            resetPage: true,
        });
    });
    refreshButton?.addEventListener("click", (event) => {
        event.preventDefault();

        resetMaintenanceFilters();
    });

    refreshMaintenanceTable({
        resetPage: true,
    });
}

function initMaintenanceFilters() {
    initMaintenanceSearch();
}

document.addEventListener("DOMContentLoaded", () => {
    initMaintenanceSearch();
});
