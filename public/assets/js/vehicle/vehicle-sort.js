/* ==========================================
   Sorting
========================================== */

function initVehicleSorting() {
  const tableBody = document.getElementById("vehicleTableBody");

  if (!tableBody || tableBody.dataset.vehicleSortingInitialized === "true") {
    return;
  }

  tableBody.dataset.vehicleSortingInitialized = "true";

  let currentColumn = -1;
  let ascending = true;

  document.querySelectorAll(".sortable").forEach((header) => {
    header.addEventListener("click", () => {
      const column = Number(header.dataset.column);

      if (Number.isNaN(column)) return;

      ascending = currentColumn === column ? !ascending : true;
      currentColumn = column;

      const rows = Array.from(tableBody.querySelectorAll("tr"));

      rows.sort((firstRow, secondRow) => {
        const first = firstRow.children[column]?.textContent.trim() || "";
        const second = secondRow.children[column]?.textContent.trim() || "";

        return ascending
          ? first.localeCompare(second)
          : second.localeCompare(first);
      });

      tableBody.replaceChildren(...rows);

      if (typeof applyVehicleFilters === "function") {
        applyVehicleFilters();
      } else if (typeof refreshVehiclePagination === "function") {
        refreshVehiclePagination();
      }
    });
  });
}

document.addEventListener("DOMContentLoaded", () => {
    initVehicleSorting();
});
