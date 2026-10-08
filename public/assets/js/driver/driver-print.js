/* ==========================================
   Driver Print Report
========================================== */

let driverPrintInProgress = false;

function createDriverPrintHeader() {
  const header = document.createElement("div");
  const title = document.createElement("h1");
  const subtitle = document.createElement("p");
  const reportTitle = document.createElement("h2");
  const generatedDate = document.createElement("p");

  header.className = "driver-print-header";
  title.textContent = "Hospital Information Management System";
  subtitle.textContent = "Fleet & Transportation Management";
  reportTitle.textContent = "Driver Report";
  generatedDate.textContent = `Generated: ${new Date().toLocaleString()}`;
  header.append(title, subtitle, reportTitle, generatedDate);

  return header;
}

function initDriverPrint() {
  const printButton = document.getElementById("printDrivers");

  if (!printButton || printButton.dataset.driverPrintInitialized === "true") {
    return;
  }

  printButton.dataset.driverPrintInitialized = "true";

  printButton.addEventListener("click", () => {
    const tableBody = document.getElementById("driverTableBody");

    if (
      driverPrintInProgress ||
      !tableBody ||
      typeof getDriverDataRows !== "function" ||
      typeof getDriverReportRows !== "function"
    ) {
      return;
    }

    const dataRows = getDriverDataRows(tableBody);
    const matchingRows = getDriverReportRows();
    const matchingRowSet = new Set(matchingRows);
    const originalDisplays = new Map(
      dataRows.map((row) => [row, row.style.display]),
    );
    const table = tableBody.closest("table");
    const printHeader = createDriverPrintHeader();
    let restored = false;

    dataRows.forEach((row) => {
      row.style.display = matchingRowSet.has(row) ? "" : "none";
    });

    table?.parentElement?.insertBefore(printHeader, table);
    driverPrintInProgress = true;

    const restorePrintState = () => {
      if (restored) return;

      restored = true;
      dataRows.forEach((row) => {
        row.style.display = originalDisplays.get(row) || "";
      });
      printHeader.remove();
      driverPrintInProgress = false;
      window.removeEventListener?.("afterprint", restorePrintState);
    };

    if (typeof window.addEventListener === "function") {
      window.addEventListener("afterprint", restorePrintState, { once: true });
    }

    showDriverReportToast("Preparing driver report for printing.", "success");

    try {
      window.print();

      if (typeof window.addEventListener !== "function") {
        restorePrintState();
      }
    } catch {
      restorePrintState();
      showDriverReportToast("Unable to print the Driver report.", "error");
    }
  });
}
