/* ==========================================
   Fuel Table Sorting
========================================== */

let fuelSortingInitialized = false;

const FUEL_SORT_CLASS_MAP = [
    null,
    ".fuel-number", // 1 - Fuel Record No.
    ".fuel-date", // 2 - Date
    ".fuel-vehicle", // 3 - Vehicle
    null, // 4 - Plate No. (not sortable)
    null, // 5 - Driver (not sortable)
    ".fuel-type", // 6 - Fuel Type
    ".fuel-quantity", // 7 - Quantity
    null, // 8 - Cost / L (not sortable)
    ".fuel-total-cost", // 9 - Total Cost
    ".fuel-odometer", // 10 - Odometer
    null, // 11 - Fuel Station (not sortable)
    null, // 12 - Actions
];

function getFuelSortableRows(tableBody) {
    if (!tableBody) {
        return [];
    }

    return Array.from(tableBody.querySelectorAll("tr")).filter((row) => {
        if (row.classList.contains("fuel-no-results")) {
            return false;
        }

        return (
            row.querySelector(".fuel-number") !== null ||
            row.querySelector(".fuel-checkbox") !== null
        );
    });
}

function getFuelSortValue(row, columnIndex) {
    const selector = FUEL_SORT_CLASS_MAP[columnIndex];

    if (!selector) {
        return "";
    }

    return (row.querySelector(selector)?.textContent || "").trim();
}

function parseFuelSortNumber(value) {
    const cleaned = String(value || "").replace(/[^\d.-]/g, "");

    const number = Number.parseFloat(cleaned);

    return Number.isNaN(number) ? null : number;
}

function parseFuelSortDate(row) {

    const raw = row.dataset.refuelDate || "";

    if (raw) {
        const timestamp = Date.parse(raw);

        if (!Number.isNaN(timestamp)) {
            return timestamp;
        }
    }

    const display = getFuelSortValue(row, 2);

    const timestamp = Date.parse(display);

    return Number.isNaN(timestamp) ? null : timestamp;
}

function compareFuelSortValues(rowA, rowB, columnIndex, direction) {
    let result = 0;

    /* Date */

    if (columnIndex === 2) {
        const aDate = parseFuelSortDate(rowA);
        const bDate = parseFuelSortDate(rowB);

        if (aDate === null && bDate === null) {
            return 0;
        }
        if (aDate === null) {
            return 1;
        }
        if (bDate === null) {
            return -1;
        }
        if (aDate < bDate) {
            result = -1;
        } else if (aDate > bDate) {
            result = 1;
        } else {
            result = 0;
        }
    } else if (columnIndex === 7 || columnIndex === 9 || columnIndex === 10) {

    /* Numeric */
        const aNumber = parseFuelSortNumber(
            getFuelSortValue(rowA, columnIndex),
        );
        const bNumber = parseFuelSortNumber(
            getFuelSortValue(rowB, columnIndex),
        );
        if (aNumber === null && bNumber === null) {
            return 0;
        }
        if (aNumber === null) {
            return 1;
        }
        if (bNumber === null) {
            return -1;
        }
        if (aNumber < bNumber) {
            result = -1;
        } else if (aNumber > bNumber) {
            result = 1;
        } else {
            result = 0;
        }
    } else {

    /* Text */
        const aValue = getFuelSortValue(rowA, columnIndex).toLowerCase();
        const bValue = getFuelSortValue(rowB, columnIndex).toLowerCase();

        if (aValue < bValue) {
            result = -1;
        } else if (aValue > bValue) {
            result = 1;
        } else {
            result = 0;
        }
    }

    return direction === "asc" ? result : -result;
}

function clearFuelSortIndicators() {
    document.querySelectorAll("th.sortable").forEach((th) => {
        th.removeAttribute("aria-sort");

        const icon = th.querySelector(".sort-icon");

        if (icon) {
            icon.className = "ph ph-caret-up-down sort-icon";
        }
    });
}

function setFuelSortIcon(th, direction) {
    const icon = th.querySelector(".sort-icon");

    if (!icon) {
        return;
    }

    icon.className =
        direction === "asc"
            ? "ph ph-caret-up sort-icon"
            : "ph ph-caret-down sort-icon";
}

function applyFuelSort(columnIndex, direction) {
    const tableBody = document.getElementById("fuelTableBody");

    if (!tableBody) {
        return;
    }

    const rows = getFuelSortableRows(tableBody).filter(
        (row) => row.dataset.fuelMatchesFilter !== "false",
    );

    const sorted = rows
        .slice()
        .sort((a, b) => compareFuelSortValues(a, b, columnIndex, direction));

    sorted.forEach((row) => {
        tableBody.appendChild(row);
    });

    if (typeof refreshFuelPagination === "function") {
        refreshFuelPagination();
    } else if (typeof applyFuelPagination === "function") {
        applyFuelPagination();
    }
}

function initFuelSorting() {
    if (fuelSortingInitialized) {
        return;
    }

    fuelSortingInitialized = true;

    const table =
        document.querySelector(".fuel-page .fleet-table") ||
        document.querySelector(".fuel-table") ||
        document.querySelector(".fleet-table");

    if (!table) {
        return;
    }

    let activeColumn = null;
    let activeDirection = "asc";

    table.addEventListener("click", (event) => {
        const th = event.target.closest("th.sortable");

        if (!th) {
            return;
        }

        const columnIndex = Number(th.dataset.column);

        if (!FUEL_SORT_CLASS_MAP[columnIndex]) {
            return;
        }

        if (activeColumn === columnIndex) {
            activeDirection = activeDirection === "asc" ? "desc" : "asc";
        } else {
            activeColumn = columnIndex;

            activeDirection = "asc";
        }

        clearFuelSortIndicators();

        th.setAttribute(
            "aria-sort",
            activeDirection === "asc" ? "ascending" : "descending",
        );

        setFuelSortIcon(th, activeDirection);

        applyFuelSort(activeColumn, activeDirection);
    });

    table.addEventListener("keydown", (event) => {
        const th = event.target.closest("th.sortable");

        if (!th) {
            return;
        }

        if (event.key !== "Enter" && event.key !== " ") {
            return;
        }

        event.preventDefault();

        th.click();
    });
}

document.addEventListener("DOMContentLoaded", () => {
    initFuelSorting();
});
