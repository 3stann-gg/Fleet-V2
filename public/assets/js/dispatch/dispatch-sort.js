/* ==========================================
   Dispatch Sorting
========================================== */

let dispatchSortingInitialized = false;

const DISPATCH_SORT_CLASS_MAP = {
    1: ".dispatch-number",
    2: ".dispatch-reservation-number",
    3: ".dispatch-patient-name",
    4: ".dispatch-vehicle",
    5: ".dispatch-driver",
    6: ".dispatch-schedule",
    7: ".dispatch-priority",
    8: ".status-badge",
};

function getDispatchSortableRows(tableBody) {
    if (!tableBody) {
        return [];
    }

    return Array.from(tableBody.querySelectorAll("tr")).filter((row) => {
        if (row.classList.contains("dispatch-no-results")) {
            return false;
        }

        return (
            row.querySelector(".dispatch-number") !== null ||
            row.querySelector(".dispatch-checkbox") !== null
        );
    });
}

function getDispatchSortValue(row, columnIndex) {
    const selector = DISPATCH_SORT_CLASS_MAP[columnIndex];

    if (!selector) {
        return "";
    }

    return row.querySelector(selector)?.textContent?.trim() || "";
}

function clearDispatchSortIndicators() {
    document.querySelectorAll("#dispatchTable th.sortable").forEach((th) => {
        th.removeAttribute("aria-sort");

        const icon = th.querySelector(".sort-icon");

        if (icon) {
            icon.className = "ph ph-caret-up-down sort-icon";
        }
    });
}

function setDispatchSortIcon(th, direction) {
    const icon = th.querySelector(".sort-icon");

    if (!icon) {
        return;
    }

    icon.className =
        direction === "asc"
            ? "ph ph-caret-up sort-icon"
            : "ph ph-caret-down sort-icon";
}

function applyDispatchSort(columnIndex, direction) {
    const tableBody = document.getElementById("dispatchTableBody");

    if (!tableBody) {
        return;
    }

    const rows = getDispatchSortableRows(tableBody).filter(
        (row) => row.dataset.dispatchMatchesFilter !== "false",
    );

    const sorted = rows.slice().sort((a, b) => {
        const aValue = getDispatchSortValue(a, columnIndex).toLowerCase();

        const bValue = getDispatchSortValue(b, columnIndex).toLowerCase();

        if (aValue < bValue) {
            return direction === "asc" ? -1 : 1;
        }

        if (aValue > bValue) {
            return direction === "asc" ? 1 : -1;
        }

        return 0;
    });

    sorted.forEach((row) => {
        tableBody.appendChild(row);
    });

    if (typeof updateDispatchPagination === "function") {
        updateDispatchPagination();
    }
}

function initDispatchSorting() {
    if (dispatchSortingInitialized) {
        return;
    }

    const table = document.getElementById("dispatchTable");

    if (!table) {
        return;
    }

    dispatchSortingInitialized = true;

    let activeColumn = null;
    let activeDirection = "asc";

    table.addEventListener("click", (event) => {
        const th = event.target.closest("th.sortable");

        if (!th) {
            return;
        }

        const columnIndex = Number(th.dataset.column);

        if (!DISPATCH_SORT_CLASS_MAP[columnIndex]) {
            return;
        }

        if (activeColumn === columnIndex) {
            activeDirection = activeDirection === "asc" ? "desc" : "asc";
        } else {
           
            activeColumn = columnIndex;
            activeDirection = "asc";
        }

        clearDispatchSortIndicators();

        th.setAttribute(
            "aria-sort",
            activeDirection === "asc" ? "ascending" : "descending",
        );

        setDispatchSortIcon(th, activeDirection);

        applyDispatchSort(activeColumn, activeDirection);
    });
}

document.addEventListener("DOMContentLoaded", () => {
    initDispatchSorting();
});
