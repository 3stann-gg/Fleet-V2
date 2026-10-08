let driverSortingInitialized = false;

const DRIVER_SORT_CLASS_MAP = [
  null,
  ".driver-name",
  null,
  ".driver-license",
  null,
  ".driver-assignment",
  ".status-badge",
  null,
];

const DRIVER_SORT_CELL_INDEX = {
  2: 2,
  4: 4,
  7: 7,
};

function getDriverSortableRows(tableBody) {
  return Array.from(tableBody.querySelectorAll("tr")).filter((row) => {
    if (row.classList.contains("driver-no-results")) {
      return false;
    }
    return row.querySelector(".driver-name") !== null ||
      row.querySelector(".driver-checkbox") !== null;
  });
}

function getDriverSortValue(row, columnIndex) {
  const selector = DRIVER_SORT_CLASS_MAP[columnIndex];
  if (selector) {
    return (row.querySelector(selector)?.textContent || "").trim();
  }
  if (DRIVER_SORT_CELL_INDEX[columnIndex]) {
    const cell = row.cells[DRIVER_SORT_CELL_INDEX[columnIndex]];
    if (cell) {
      return cell.textContent.trim();
    }
  }
  return "";
}

function clearDriverSortIndicators() {
  document.querySelectorAll("th.sortable").forEach((th) => {
    th.removeAttribute("aria-sort");
    const icon = th.querySelector(".sort-icon");
    if (icon) {
      icon.className = "ph ph-caret-up-down sort-icon";
    }
  });
}

function setDriverSortIcon(th, direction) {
  const icon = th.querySelector(".sort-icon");
  if (icon) {
    icon.className =
      direction === "asc"
        ? "ph ph-caret-up sort-icon"
        : "ph ph-caret-down sort-icon";
  }
}

function applyDriverSort(columnIndex, direction) {
  const tableBody = document.getElementById("driverTableBody");
  if (!tableBody) {
    return;
  }

  const rows = getDriverSortableRows(tableBody).filter((row) => {
    return row.dataset.driverMatchesFilter !== "false";
  });

  const sorted = rows.slice().sort((a, b) => {
    const aVal = getDriverSortValue(a, columnIndex).toLowerCase();
    const bVal = getDriverSortValue(b, columnIndex).toLowerCase();
    if (aVal < bVal) {
      return direction === "asc" ? -1 : 1;
    }
    if (aVal > bVal) {
      return direction === "asc" ? 1 : -1;
    }
    return 0;
  });

  sorted.forEach((row) => {
    tableBody.appendChild(row);
  });

  if (typeof refreshDriverPagination === "function") {
      refreshDriverPagination();
  }
}

function initDriverSorting() {
  if (driverSortingInitialized) {
    return;
  }
  driverSortingInitialized = true;

  let activeColumn = null;
  let activeDirection = "asc";

  const table = document.querySelector(".fleet-table");
  if (!table) {
    return;
  }

  table.addEventListener("click", (event) => {
    const th = event.target.closest("th.sortable");
    if (!th) {
      return;
    }

    const columnIndex = Number(th.dataset.column);
    if (
      !DRIVER_SORT_CLASS_MAP[columnIndex] &&
      !DRIVER_SORT_CELL_INDEX[columnIndex]
    ) {
      return;
    }

    if (activeColumn === columnIndex) {
      activeDirection = activeDirection === "asc" ? "desc" : "asc";
    } else {
      activeColumn = columnIndex;
      activeDirection = "asc";
    }

    clearDriverSortIndicators();
    th.setAttribute(
      "aria-sort",
      activeDirection === "asc" ? "ascending" : "descending",
    );
    setDriverSortIcon(th, activeDirection);

    applyDriverSort(activeColumn, activeDirection);
  });
}

document.addEventListener("DOMContentLoaded", () => {
    initDriverSorting();
});
