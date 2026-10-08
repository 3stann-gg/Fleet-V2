/* ==========================================
   Fuel Pagination
========================================== */

const fuelRowsPerPage = 5;
let fuelPaginationState = null;

function getFuelPaginationRows() {
    if (!fuelPaginationState || typeof getFuelDataRows !== "function") {
        return [];
    }

    return getFuelDataRows(fuelPaginationState.tableBody);
}

function createFuelPaginationButton({
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

function updateFuelPaginationInfo(info, start, end, total) {
    if (!info) {
        return;
    }

    const range = document.createElement("strong");
    const totalCount = document.createElement("strong");

    range.textContent = `${start}–${end}`;

    totalCount.textContent = total;

    info.replaceChildren(
        document.createTextNode("Showing "),
        range,
        document.createTextNode(" of "),
        totalCount,
        document.createTextNode(" fuel records"),
    );
}

function renderFuelPagination() {
    if (!fuelPaginationState) {
        return false;
    }

    const { tableBody, pagination, info } = fuelPaginationState;
    const dataRows = getFuelPaginationRows();
    const matchingRows = dataRows.filter(
        (row) => row.dataset.fuelMatchesFilter !== "false",
    );
    const total = matchingRows.length;
    const totalPages = Math.ceil(total / fuelRowsPerPage);

    if (totalPages === 0) {
        fuelPaginationState.currentPage = 1;
    } else {
        fuelPaginationState.currentPage = Math.min(
            Math.max(fuelPaginationState.currentPage, 1),
            totalPages,
        );
    }

    const startIndex = (fuelPaginationState.currentPage - 1) * fuelRowsPerPage;
    const endIndex = startIndex + fuelRowsPerPage;
    const start = total === 0 ? 0 : startIndex + 1;
    const end = Math.min(endIndex, total);

    dataRows.forEach((row) => {
        row.style.display = "none";
    });

    matchingRows.slice(startIndex, endIndex).forEach((row) => {
        row.style.display = "";
    });

    if (typeof updateFuelNoResultsRow === "function") {
        updateFuelNoResultsRow(tableBody, total === 0);
    }

    updateFuelPaginationInfo(info, start, end, total);

    pagination.replaceChildren();

    const previousButton = createFuelPaginationButton({
        ariaLabel: "Previous page",

        iconClass: "ph ph-caret-left",

        disabled: fuelPaginationState.currentPage === 1 || totalPages === 0,

        onClick: () => {
            if (fuelPaginationState.currentPage > 1) {
                fuelPaginationState.currentPage -= 1;

                renderFuelPagination();
            }
        },
    });

    pagination.appendChild(previousButton);

    for (let page = 1; page <= totalPages; page += 1) {
        pagination.appendChild(
            createFuelPaginationButton({
                label: page,
                ariaLabel: `Page ${page}`,

                active: page === fuelPaginationState.currentPage,

                onClick: () => {
                    fuelPaginationState.currentPage = page;

                    renderFuelPagination();
                },
            }),
        );
    }

    const nextButton = createFuelPaginationButton({
        ariaLabel: "Next page",
        iconClass: "ph ph-caret-right",
        disabled:
            totalPages === 0 || fuelPaginationState.currentPage === totalPages,

        onClick: () => {
            if (fuelPaginationState.currentPage < totalPages) {
                fuelPaginationState.currentPage += 1;

                renderFuelPagination();
            }
        },
    });

    pagination.appendChild(nextButton);

    if (typeof refreshFuelBulkState === "function") {
        refreshFuelBulkState();
    }

    return true;
}

function refreshFuelPagination({ reset = false } = {}) {
    if (!fuelPaginationState) {
        return false;
    }

    if (reset) {
        fuelPaginationState.currentPage = 1;
    }

    return renderFuelPagination();
}

function resetFuelPagination() {
    return refreshFuelPagination({
        reset: true,
    });
}

function initFuelPagination() {
    const tableBody = document.getElementById("fuelTableBody");
    const pagination = document.getElementById("fuelPagination");
    const info = document.getElementById("fuelPaginationInfo");

    if (!tableBody || !pagination) {
        return false;
    }


    if (fuelPaginationState?.tableBody === tableBody) {
        return refreshFuelPagination();
    }

    fuelPaginationState = {
        tableBody,
        pagination,
        info,
        currentPage: 1,
    };

    tableBody.dataset.fuelPaginationInitialized = "true";

    return renderFuelPagination();
}

document.addEventListener("DOMContentLoaded", () => {
    initFuelPagination();
});
