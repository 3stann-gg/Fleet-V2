/* ==========================================
   Fuel Search and Filters
========================================== */

let fuelSearchState = null;

function getFuelSearchText(row, columnIndex, selector) {
    const selectedElement = selector ? row.querySelector(selector) : null;
    const cell = row.children && row.children[columnIndex];
    const value = selectedElement
        ? selectedElement.textContent
        : cell?.textContent;

    return value ? value.trim() : "";
}

function getFuelFilterLabel(select) {
    if (!select || select.value === "all") {
        return "";
    }

    const option = select.options[select.selectedIndex];
    const value = option ? option.textContent : select.value;

    return value.trim().toLowerCase();
}

function getFuelDataRows(tableBody) {
    if (!tableBody) {
        return [];
    }

    return Array.from(tableBody.querySelectorAll("tr")).filter((row) => {
        const isHelperRow =
            row.classList.contains("fuel-no-results") ||
            row.classList.contains("helper-row") ||
            row.classList.contains("empty-state") ||
            row.dataset.helperRow === "true";

        return (
            !isHelperRow &&
            Boolean(
                row.querySelector(".fuel-number") ||
                row.querySelector(".fuel-checkbox"),
            )
        );
    });
}

function updateFuelNoResultsRow(tableBody, shouldShow) {
    if (!tableBody) {
        return;
    }

    const existingRow = tableBody.querySelector(".fuel-no-results");

    if (!shouldShow) {
        existingRow?.remove();
        return;
    }

    if (existingRow) {
        return;
    }

    const row = document.createElement("tr");
    const cell = document.createElement("td");

    row.className = "fuel-no-results";
    row.dataset.helperRow = "true";
    cell.colSpan = 13;
    cell.className = "text-center";
    cell.textContent = "No fuel records found.";

    row.appendChild(cell);

    tableBody.appendChild(row);
}

function renderFuelFilterRows(matchingRows) {
    if (!fuelSearchState) {
        return;
    }

    const matchingRowSet = new Set(matchingRows);

    getFuelDataRows(fuelSearchState.tableBody).forEach((row) => {
        row.style.display = matchingRowSet.has(row) ? "" : "none";
    });

    updateFuelNoResultsRow(
        fuelSearchState.tableBody,
        matchingRows.length === 0,
    );
}

function populateFuelVehicleFilter() {
    const filter = document.getElementById("fuelVehicleFilter");

    const tableBody = document.getElementById("fuelTableBody");

    if (!filter || !tableBody) {
        return;
    }

    const currentValue = filter.value || "all";

    const vehicles = new Set();

    getFuelDataRows(tableBody).forEach((row) => {
        const vehicle = row.querySelector(".fuel-vehicle")?.textContent?.trim();

        if (vehicle) {
            vehicles.add(vehicle);
        }
    });

    filter.innerHTML = '<option value="all">All Vehicles</option>';

    Array.from(vehicles)
        .sort((a, b) =>
            a.localeCompare(b, undefined, {
                sensitivity: "base",
                numeric: true,
            }),
        )
        .forEach((vehicle) => {
            const option = document.createElement("option");

            option.value = vehicle;

            option.textContent = vehicle;

            filter.appendChild(option);
        });

    const exists = Array.from(filter.options).some(
        (option) => option.value === currentValue,
    );

    filter.value = exists ? currentValue : "all";
}

function applyFuelFilters({ resetPage = false } = {}) {
    if (!fuelSearchState) {
        return [];
    }

    const { tableBody, searchInput, vehicleFilter, typeFilter, dateFilter } =
        fuelSearchState;
    const searchQuery = (searchInput?.value || "").trim().toLowerCase();
    const vehicleValue = getFuelFilterLabel(vehicleFilter);
    const typeValue = getFuelFilterLabel(typeFilter);
    const dateValue = (dateFilter?.value || "").trim();
    const matchingRows = [];

    getFuelDataRows(tableBody).forEach((row) => {
        const number = getFuelSearchText(row, 1, ".fuel-number");
        const date = getFuelSearchText(row, 2, ".fuel-date");
        const vehicle = getFuelSearchText(row, 3, ".fuel-vehicle");
        const plate = getFuelSearchText(row, 4, ".fuel-plate");
        const driver = getFuelSearchText(row, 5, ".fuel-driver");
        const fuelType = getFuelSearchText(row, 6, ".fuel-type");
        const quantity = getFuelSearchText(row, 7, ".fuel-quantity");
        const costPerLiter = getFuelSearchText(row, 8, ".fuel-cost-per-liter");
        const totalCost = getFuelSearchText(row, 9, ".fuel-total-cost");
        const odometer = getFuelSearchText(row, 10, ".fuel-odometer");
        const station = getFuelSearchText(row, 11, ".fuel-station");
        const receipt = (row.dataset.receipt || "").trim();
        const payment = (row.dataset.payment || "").trim();
        const notes = (row.dataset.notes || "").trim();
        const refuelDate = (row.dataset.refuelDate || "").trim();

        const searchableText = [
            number,
            date,
            refuelDate,
            vehicle,
            plate,
            driver,
            fuelType,
            quantity,
            costPerLiter,
            totalCost,
            odometer,
            station,
            receipt,
            payment,
            notes,
        ]
            .join(" ")
            .toLowerCase();


        const matchesSearch =
            !searchQuery || searchableText.includes(searchQuery);
        const matchesVehicle =
            !vehicleValue || vehicle.toLowerCase() === vehicleValue;
        const matchesType = !typeValue || fuelType.toLowerCase() === typeValue;
        const matchesDate = !dateValue || refuelDate === dateValue;
        const isMatch =
            matchesSearch && matchesVehicle && matchesType && matchesDate;
        row.dataset.fuelMatchesFilter = String(isMatch);
        row.dataset.matchesFilter = String(isMatch);

        if (isMatch) {
            matchingRows.push(row);
        }
    });

    if (typeof sortFuelRows === "function") {
        sortFuelRows(matchingRows);
    }

    updateFuelNoResultsRow(tableBody, matchingRows.length === 0);

    if (typeof refreshFuelPagination === "function") {
        const handled = refreshFuelPagination({
            reset: resetPage,
            matchingRows,
        });

        if (handled) {
            return matchingRows;
        }
    }

    if (typeof applyFuelPagination === "function") {
        const handled = applyFuelPagination({
            matchingRows,
            allRows: getFuelDataRows(tableBody),
            resetPage,
        });

        if (handled) {
            return matchingRows;
        }
    }

    renderFuelFilterRows(matchingRows);

    return matchingRows;
}

function refreshFuelTable(options = {}) {
    return applyFuelFilters(options);
}
function applyFuelSearch(options) {
    return applyFuelFilters(options);
}
function refreshFuelSearch(options) {
    return applyFuelFilters(options);
}
function resetFuelFilters() {
    if (!fuelSearchState) {
        return [];
    }

    const { searchInput, vehicleFilter, typeFilter, dateFilter } =
        fuelSearchState;

    if (searchInput) {
        searchInput.value = "";
    }
    if (vehicleFilter) {
        vehicleFilter.value = "all";
    }
    if (typeFilter) {
        typeFilter.value = "all";
    }
    if (dateFilter) {
        dateFilter.value = "";
    }

    return applyFuelFilters({
        resetPage: true,
    });
}

function initFuelSearch() {
    const tableBody = document.getElementById("fuelTableBody");
    const searchInput = document.getElementById("fuelSearch");
    const vehicleFilter = document.getElementById("fuelVehicleFilter");
    const typeFilter = document.getElementById("fuelTypeFilter");
    const dateFilter = document.getElementById("fuelDateFilter");
    const refreshButton = document.getElementById("refreshFuel");

    if (!tableBody || tableBody.dataset.fuelSearchInitialized === "true") {
        return;
    }

    tableBody.dataset.fuelSearchInitialized = "true";

    fuelSearchState = {
        tableBody,
        searchInput,
        vehicleFilter,
        typeFilter,
        dateFilter,
    };

    searchInput?.addEventListener("input", () => {
        applyFuelFilters({
            resetPage: true,
        });
    });
    vehicleFilter?.addEventListener("change", () => {
        applyFuelFilters({
            resetPage: true,
        });
    });
    typeFilter?.addEventListener("change", () => {
        applyFuelFilters({
            resetPage: true,
        });
    });
    dateFilter?.addEventListener("change", () => {
        applyFuelFilters({
            resetPage: true,
        });
    });
    refreshButton?.addEventListener("click", () => {
        resetFuelFilters();
    });

    applyFuelFilters();
}

function initFuelFilters() {
    initFuelSearch();
}

document.addEventListener("DOMContentLoaded", () => {
    initFuelSearch();
});
