/* ==========================================
Reservation Pagination
========================================== */

const reservationRowsPerPage = 10;
let reservationPaginationState = null;

function getReservationPaginationRows() {
    if (!reservationPaginationState || typeof getReservationDataRows !== "function") {
        return [];
    }

    return getReservationDataRows(reservationPaginationState.tableBody);
}

function createReservationPaginationButton({label, ariaLabel, iconClass, disabled = false, active = false, onClick,}) {
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

function updateReservationPaginationInfo(info, start, end, total) {
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
        document.createTextNode(" reservations")
    );
}

function renderReservationPagination() {
    if (!reservationPaginationState) {
        return false;
    }

    const {tableBody, pagination, info,} = reservationPaginationState;
    const dataRows = getReservationPaginationRows();
    const matchingRows = dataRows.filter((row) => row.dataset.reservationMatchesFilter !== "false");
    const total = matchingRows.length;
    const totalPages = Math.ceil(total / reservationRowsPerPage);

    if (totalPages === 0) {
        reservationPaginationState.currentPage = 1;
    } else {
        reservationPaginationState.currentPage =
            Math.min(
                Math.max(
                    reservationPaginationState.currentPage,
                    1
                ),
                totalPages
            );
    }

    const startIndex = (reservationPaginationState.currentPage - 1) * reservationRowsPerPage;
    const endIndex = startIndex + reservationRowsPerPage;
    const start = total === 0
      ? 0
      : startIndex + 1;
    const end = Math.min(endIndex, total);

    dataRows.forEach((row) => {
        row.style.display = "none";
    });

    matchingRows.slice(startIndex, endIndex)
      .forEach((row) => {
          row.style.display = "";
      });

    if (typeof updateReservationNoResultsRow === "function") {
      updateReservationNoResultsRow(tableBody, total === 0);
    }

    updateReservationPaginationInfo(info, start, end, total);

    pagination.replaceChildren();

    const previousButton = createReservationPaginationButton({
      ariaLabel: "Previous page",
      iconClass: "ph ph-caret-left",
      disabled: reservationPaginationState.currentPage === 1 || totalPages === 0,
        onClick: () => {
           if (reservationPaginationState.currentPage > 1) {
            reservationPaginationState.currentPage -= 1;
              renderReservationPagination();
          }
        },
    });

    pagination.appendChild(previousButton);
    for (let page = 1; page <= totalPages; page += 1) {
      pagination.appendChild(
        createReservationPaginationButton({
          label: page,
          ariaLabel: `Page ${page}`,
          active: page === reservationPaginationState.currentPage,
            onClick: () => {
              reservationPaginationState.currentPage = page;
                renderReservationPagination();
            },
        })
      );
    }
    
    const nextButton = createReservationPaginationButton({
      ariaLabel: "Next page",
      iconClass: "ph ph-caret-right",
      disabled: totalPages === 0 || reservationPaginationState.currentPage === totalPages,
        onClick: () => {
          if (reservationPaginationState.currentPage < totalPages) {
            reservationPaginationState.currentPage += 1;
              renderReservationPagination();
          }
        },
    });

    pagination.appendChild(nextButton);

    if (typeof refreshReservationBulkState === "function") {
      refreshReservationBulkState();
    }

    return true;
}

function updateReservationPagination({reset = false,} = {}) {
    if (!reservationPaginationState) {
        return false;
    }
    if (reset) {
        reservationPaginationState.currentPage = 1;
    }

    return renderReservationPagination();
}

function resetReservationPagination() {return updateReservationPagination({reset: true,});}

function initReservationPagination() {
    const tableBody = document.getElementById(
      "reservationTableBody"
    );

    const pagination = document.getElementById(
      "reservationPagination"
    );

    const info = document.getElementById(
      "reservationPaginationInfo"
    );

    if (!tableBody || !pagination) {
        return false;
    }

    if (reservationPaginationState?.tableBody === tableBody) {
        return updateReservationPagination();
    }

    reservationPaginationState = {tableBody, pagination, info, currentPage: 1,};
    tableBody.dataset.reservationPaginationInitialized = "true";

    return renderReservationPagination();
}

document.addEventListener("DOMContentLoaded", () => {
    initReservationPagination();
  }
);