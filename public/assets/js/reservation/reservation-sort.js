/* ==========================================
Reservation Sorting :)
========================================== */

let reservationSortInitialized = false;

function initReservationSorting() {
    if (reservationSortInitialized) return;

    const tableBody = document.getElementById("reservationTableBody");

    if (!tableBody) return;

    reservationSortInitialized = true;

    const sortState = new Map();

    const sortConfig = {
        1: ".reservation-number",
        2: ".patient-name",
        3: ".reservation-vehicle",
        4: ".reservation-driver",
        5: ".reservation-pickup",
        6: ".reservation-destination",
        7: ".reservation-schedule",
        8: ".reservation-priority",
        9: ".status-badge",
    };

    const sortableHeaders = Array.from(
        document.querySelectorAll("th.sortable[data-column]")
    );

    const getRealReservationRows = () => {
        return Array.from(
            tableBody.querySelectorAll("tr")
        ).filter((row) => {

            if (
                row.id === "reservation-no-results" ||
                row.classList.contains("reservation-no-results")
            ) {
                return false;
            }

            return Boolean(
                row.querySelector(".reservation-number") ||
                row.querySelector(".reservation-checkbox")
            );
        });
    };

    sortableHeaders.forEach((th) => {
        const column = th.dataset.column;

        if (!sortConfig[column]) {
            return;
        }

        th.addEventListener("click", () => {
            const current = sortState.get(column) || "asc";
            const next = current === "asc"
                ? "desc"
                : "asc";

            sortState.set(column, next);

            const selector = sortConfig[column];
            const rows = getRealReservationRows();

            rows.sort((a, b) => {

                const aText = a.querySelector(selector)
                    ?.textContent
                    ?.trim() || "";

                const bText = b.querySelector(selector)
                    ?.textContent
                    ?.trim() || "";

                const comparison = aText.localeCompare(bText, undefined,
                    {
                        numeric: true,
                        sensitivity: "base",
                    }
                );

                return next === "asc"
                    ? comparison
                    : -comparison;
            });

            const fragment = document.createDocumentFragment();

            rows.forEach((row) => {
                fragment.appendChild(row);
            });

            tableBody.appendChild(fragment);

            sortableHeaders.forEach((header) => {
                const icon = header.querySelector(".sort-icon");
                if (
                    header.dataset.column === column
                ) {
                    header.setAttribute("aria-sort", next === "asc"
                            ? "ascending"
                            : "descending"
                    );
                    if (icon) {icon.className = next === "asc"
                        ? "ph ph-caret-up sort-icon"
                        : "ph ph-caret-down sort-icon";
                    }

                } else {
                    header.removeAttribute("aria-sort");
                    if (icon) {icon.className = "ph ph-caret-up-down sort-icon";}
                }
            });

            if (typeof updateReservationPagination === "function") {
                updateReservationPagination();
            }

            if (typeof refreshReservationBulkState === "function") {
                refreshReservationBulkState();
            }
        });
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded",
        initReservationSorting
    );
} else {
    initReservationSorting();
}
