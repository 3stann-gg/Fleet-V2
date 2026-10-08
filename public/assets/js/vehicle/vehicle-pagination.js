/* ==========================================
   Pagination
========================================== */

const vehicleRowsPerPage = 5;
let vehiclePaginationState = null;

function getVehiclePaginationRows() {
  if (!vehiclePaginationState) return [];

  if (typeof getVehicleDataRows === "function") {
    return getVehicleDataRows(vehiclePaginationState.tableBody);
  }

  return Array.from(vehiclePaginationState.tableBody.querySelectorAll("tr"));
}

function createVehiclePaginationButton({
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

function updateVehiclePaginationInfo(info, start, end, total) {
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
    document.createTextNode(" vehicles"),
  );
}

function renderVehiclePagination() {
  if (!vehiclePaginationState) return false;

  const { tableBody, pagination, info } = vehiclePaginationState;
  const dataRows = getVehiclePaginationRows();
  const matchingRows = dataRows.filter(
    (row) => row.dataset.vehicleMatchesFilter !== "false",
  );
  const total = matchingRows.length;
  const totalPages = Math.ceil(total / vehicleRowsPerPage);

  if (totalPages === 0) {
    vehiclePaginationState.currentPage = 1;
  } else {
    vehiclePaginationState.currentPage = Math.min(
      Math.max(vehiclePaginationState.currentPage, 1),
      totalPages,
    );
  }

  const startIndex =
    (vehiclePaginationState.currentPage - 1) * vehicleRowsPerPage;
  const endIndex = startIndex + vehicleRowsPerPage;
  const start = total === 0 ? 0 : startIndex + 1;
  const end = Math.min(endIndex, total);

  dataRows.forEach((row) => {
    row.style.display = "none";
  });

  matchingRows.slice(startIndex, endIndex).forEach((row) => {
    row.style.display = "";
  });

  updateVehiclePaginationInfo(info, start, end, total);
  pagination.replaceChildren();

  const previousButton = createVehiclePaginationButton({
    ariaLabel: "Previous page",
    iconClass: "ph ph-caret-left",
    disabled: vehiclePaginationState.currentPage === 1 || totalPages === 0,
    onClick: () => {
      if (vehiclePaginationState.currentPage > 1) {
        vehiclePaginationState.currentPage -= 1;
        renderVehiclePagination();
      }
    },
  });

  pagination.appendChild(previousButton);

  for (let page = 1; page <= totalPages; page += 1) {
    pagination.appendChild(
      createVehiclePaginationButton({
        label: page,
        ariaLabel: `Page ${page}`,
        active: page === vehiclePaginationState.currentPage,
        onClick: () => {
          vehiclePaginationState.currentPage = page;
          renderVehiclePagination();
        },
      }),
    );
  }

  const nextButton = createVehiclePaginationButton({
    ariaLabel: "Next page",
    iconClass: "ph ph-caret-right",
    disabled:
      totalPages === 0 || vehiclePaginationState.currentPage === totalPages,
    onClick: () => {
      if (vehiclePaginationState.currentPage < totalPages) {
        vehiclePaginationState.currentPage += 1;
        renderVehiclePagination();
      }
    },
  });

  pagination.appendChild(nextButton);

  if (typeof refreshVehicleBulkState === "function") {
    refreshVehicleBulkState();
  }

  return true;
}

function refreshVehiclePagination({ reset = false } = {}) {
  if (!vehiclePaginationState) return false;

  if (reset) {
    vehiclePaginationState.currentPage = 1;
  }

  return renderVehiclePagination();
}

function initVehiclePagination() {

    const tableBody =
        document.getElementById("vehicleTableBody");
    const pagination =
        document.getElementById("vehiclePagination");
    const info =
        document.getElementById("vehiclePaginationInfo");

    if (!tableBody || !pagination) {
        return false;
    }
    if (vehiclePaginationState?.tableBody === tableBody) {
        return refreshVehiclePagination();
    }
    vehiclePaginationState = {
        tableBody,
        pagination,
        info,
        currentPage: 1,
    };
    tableBody.dataset.vehiclePaginationInitialized = "true";
    return renderVehiclePagination();
}

document.addEventListener("DOMContentLoaded", () => {
    initVehiclePagination();
});
