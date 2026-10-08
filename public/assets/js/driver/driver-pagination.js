/* ==========================================
   Driver Pagination
========================================== */

const driverRowsPerPage = 5;
let driverPaginationState = null;

function getDriverPaginationRows() {
  if (!driverPaginationState || typeof getDriverDataRows !== "function") {
    return [];
  }

  return getDriverDataRows(driverPaginationState.tableBody);
}

function createDriverPaginationButton({
  label,
  ariaLabel,
  iconClass,
  disabled = false,
  active = false,
  onClick,
}) {
  const button = document.createElement("button");

  button.type = "button";
  button.setAttribute("aria-label", ariaLabel);
  button.disabled = disabled;

  if (active) {
    button.classList.add("active");
  }

  if (iconClass) {
    const icon = document.createElement("i");

    icon.className = iconClass;
    button.appendChild(icon);
  } else {
    button.textContent = label;
  }

  button.addEventListener("click", onClick);

  return button;
}

function updateDriverPaginationInfo(info, start, end, total) {
  if (!info) return;

  const range = document.createElement("strong");
  const totalCount = document.createElement("strong");

  range.textContent = `${start}–${end}`;
  totalCount.textContent = total;
  info.replaceChildren(
    document.createTextNode("Showing "),
    range,
    document.createTextNode(" of "),
    totalCount,
    document.createTextNode(" drivers"),
  );
}

function renderDriverPagination() {
  if (!driverPaginationState) return false;

  const { tableBody, pagination, info } = driverPaginationState;
  const dataRows = getDriverPaginationRows();
  const matchingRows = dataRows.filter(
    (row) => row.dataset.driverMatchesFilter !== "false",
  );
  const total = matchingRows.length;
  const totalPages = Math.ceil(total / driverRowsPerPage);

  if (totalPages === 0) {
    driverPaginationState.currentPage = 1;
  } else {
    driverPaginationState.currentPage = Math.min(
      Math.max(driverPaginationState.currentPage, 1),
      totalPages,
    );
  }

  const startIndex = (driverPaginationState.currentPage - 1) * driverRowsPerPage;
  const endIndex = startIndex + driverRowsPerPage;
  const start = total === 0 ? 0 : startIndex + 1;
  const end = Math.min(endIndex, total);

  dataRows.forEach((row) => {
    row.style.display = "none";
  });

  matchingRows.slice(startIndex, endIndex).forEach((row) => {
    row.style.display = "";
  });

  if (typeof updateDriverNoResultsRow === "function") {
    updateDriverNoResultsRow(tableBody, total === 0);
  }

  updateDriverPaginationInfo(info, start, end, total);
  pagination.replaceChildren();

  const previousButton = createDriverPaginationButton({
    ariaLabel: "Previous page",
    iconClass: "ph ph-caret-left",
    disabled: driverPaginationState.currentPage === 1 || totalPages === 0,
    onClick: () => {
      if (driverPaginationState.currentPage > 1) {
        driverPaginationState.currentPage -= 1;
        renderDriverPagination();
      }
    },
  });

  pagination.appendChild(previousButton);

  for (let page = 1; page <= totalPages; page += 1) {
    pagination.appendChild(
      createDriverPaginationButton({
        label: page,
        ariaLabel: `Page ${page}`,
        active: page === driverPaginationState.currentPage,
        onClick: () => {
          driverPaginationState.currentPage = page;
          renderDriverPagination();
        },
      }),
    );
  }

  const nextButton = createDriverPaginationButton({
    ariaLabel: "Next page",
    iconClass: "ph ph-caret-right",
    disabled:
      totalPages === 0 || driverPaginationState.currentPage === totalPages,
    onClick: () => {
      if (driverPaginationState.currentPage < totalPages) {
        driverPaginationState.currentPage += 1;
        renderDriverPagination();
      }
    },
  });

  pagination.appendChild(nextButton);

  if (typeof refreshDriverBulkState === "function") {
    refreshDriverBulkState();
  }

  return true;
}

function refreshDriverPagination({ reset = false } = {}) {
  if (!driverPaginationState) return false;

  if (reset) {
    driverPaginationState.currentPage = 1;
  }

  return renderDriverPagination();
}

function resetDriverPagination() {
  return refreshDriverPagination({ reset: true });
}

function initDriverPagination() {
  const tableBody = document.getElementById("driverTableBody");
  const pagination = document.getElementById("driverPagination");
  const info = document.getElementById("driverPaginationInfo");

  if (!tableBody || !pagination) return false;

  if (driverPaginationState?.tableBody === tableBody) {
    return refreshDriverPagination();
  }

  driverPaginationState = {
    tableBody,
    pagination,
    info,
    currentPage: 1,
  };
  tableBody.dataset.driverPaginationInitialized = "true";

  return renderDriverPagination();
}

document.addEventListener("DOMContentLoaded", () => {
    initDriverPagination();
});