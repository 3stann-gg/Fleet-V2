/* ==========================================
   Print
========================================== */

let vehiclePrintInProgress = false;

function initVehiclePrint() {
  const printButton = document.getElementById("printVehicles");

  if (
    !printButton ||
    printButton.dataset.vehiclePrintInitialized === "true"
  ) {
    return;
  }

  printButton.dataset.vehiclePrintInitialized = "true";

  printButton.addEventListener("click", () => {
    const tableBody = document.getElementById("vehicleTableBody");

    if (vehiclePrintInProgress || !tableBody) return;

    const dataRows =
      typeof getVehicleDataRows === "function"
        ? getVehicleDataRows(tableBody)
        : Array.from(tableBody.querySelectorAll("tr"));
    const matchingRows =
      typeof getVehicleReportRows === "function"
        ? getVehicleReportRows()
        : dataRows;
    const matchingRowSet = new Set(matchingRows);
    const originalDisplays = new Map(
      dataRows.map((row) => [row, row.style.display]),
    );
    let restored = false;

    dataRows.forEach((row) => {
      row.style.display = matchingRowSet.has(row) ? "" : "none";
    });
    vehiclePrintInProgress = true;

    const restorePrintState = () => {
      if (restored) return;

      restored = true;
      dataRows.forEach((row) => {
        row.style.display = originalDisplays.get(row) || "";
      });
      vehiclePrintInProgress = false;
      window.removeEventListener?.("afterprint", restorePrintState);
    };

    if (typeof window.addEventListener === "function") {
      window.addEventListener("afterprint", restorePrintState, { once: true });
    }

    if (typeof window.showToast === "function") {
      window.showToast("Preparing document...", "success");
    }
    
    try {
      window.print();

      if (typeof window.addEventListener !== "function") {
        restorePrintState();
      }
    } catch {
      restorePrintState();

      if (typeof window.showToast === "function") {
        window.showToast("Unable to print the Vehicle report.", "error");
      }
    }
  });
}

document.addEventListener("DOMContentLoaded", initVehiclePrint);