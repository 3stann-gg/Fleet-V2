let dispatchPrintInitialized = false;

function getPrintDispatchRows(tableBody) {
  return Array.from(tableBody.querySelectorAll("tr")).filter((row) => {
    if (row.classList.contains("dispatch-no-results")) {
      return false;
    }

    const isReal =
      row.querySelector(".dispatch-number") !== null ||
      row.querySelector(".dispatch-checkbox") !== null;

    if (!isReal) {
      return false;
    }

    return row.dataset.dispatchMatchesFilter !== "false";
  });
}

function readPrintValue(row, selector, fallbackAttr) {
  const el = row.querySelector(selector);
  const value = el ? el.textContent.trim() : "";
  if (value !== "") {
    return value;
  }
  if (fallbackAttr && row.dataset[fallbackAttr]) {
    return row.dataset[fallbackAttr].trim();
  }
  return "Not provided";
}

function buildDispatchPrintHTML() {
  const tableBody = document.getElementById("dispatchTableBody");
  if (!tableBody) {
    return null;
  }

  const rows = getPrintDispatchRows(tableBody);

  const printRows = rows
    .map((row) => {
      const dispatchNumber = readPrintValue(row, ".dispatch-number");
      const reservationNumber = readPrintValue(
          row,
          ".dispatch-reservation-number",
      );
      const patientName = readPrintValue(row, ".dispatch-patient-name");
      const requestType = readPrintValue(
          row,
          ".dispatch-request-type",
          "requestType",
      );
      const vehicle = readPrintValue(row, ".dispatch-vehicle");
      const driver = readPrintValue(row, ".dispatch-driver");
      const pickup = row.dataset.pickup
          ? row.dataset.pickup.trim()
          : "Not provided";
      const destination = row.dataset.destination
          ? row.dataset.destination.trim()
          : "Not provided";
      const schedule = readPrintValue(row, ".dispatch-schedule");
      const priority = readPrintValue(row, ".dispatch-priority", "priority");
      const status = readPrintValue(row, ".status-badge");

      return `<tr>
        <td>${escapeHTML(dispatchNumber)}</td>
        <td>${escapeHTML(reservationNumber)}</td>
        <td>${escapeHTML(patientName)}</td>
        <td>${escapeHTML(requestType)}</td>
        <td>${escapeHTML(vehicle)}</td>
        <td>${escapeHTML(driver)}</td>
        <td>${escapeHTML(pickup)}</td>
        <td>${escapeHTML(destination)}</td>
        <td>${escapeHTML(schedule)}</td>
        <td>${escapeHTML(priority)}</td>
        <td>${escapeHTML(status)}</td>
      </tr>`;
    })
    .join("");

  return `<!DOCTYPE html>
<html>
<head>
  <title>Dispatch Report</title>
  <style>
    @page {
    size: landscape;
    margin: 12mm;
    }
    body {
        font-family: Arial, sans-serif;
        margin: 0;
        color: #000;
    }
    h1 { font-size: 18px; margin: 0 0 4px 0; }
    h2 { font-size: 14px; font-weight: normal; margin: 0 0 4px 0; }
    h3 { font-size: 14px; margin: 0 0 8px 0; }
    .timestamp { font-size: 12px; color: #333; margin-bottom: 16px; }
    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 10px;
        margin-top: 12px;
        table-layout: fixed;
    }
    th,
    td {
        border: 1px solid #999;
        padding: 5px 6px;
        text-align: left;
        vertical-align: top;
        word-break: break-word;
    }

    th {
        background-color: #f2f2f2;
        font-weight: bold;
    }
    tr:nth-child(even) { background-color: #fafafa; }
  </style>
</head>
<body>
  <h1>Hospital Information Management System</h1>
  <h2>Fleet & Transportation Management</h2>
  <h3>Dispatch Report</h3>
  <p class="timestamp">Generated: ${new Date().toLocaleString()}</p>
  <table>
    <thead>
      <tr>
        <th>Dispatch Number</th>
        <th>Reservation Number</th>
        <th>Patient Name</th>
        <th>Request Type</th>
        <th>Vehicle</th>
        <th>Driver</th>
        <th>Pickup</th>
        <th>Destination</th>
        <th>Schedule</th>
        <th>Priority</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      ${printRows || '<tr><td colspan="11">No matching dispatches found.</td></tr>'}
    </tbody>
  </table>
</body>
</html>`;
}

function escapeHTML(str) {
  return str
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function openDispatchPrintWindow() {
  const html = buildDispatchPrintHTML();
  if (!html) {
    if (typeof showToast === "function") {
      showToast("Unable to open the print report.", "error");
    }
    return;
  }

  if (typeof showToast === "function") {
    showToast("Preparing dispatch report for printing.", "success");
  }

  const printWindow = window.open("", "_blank", "width=800,height=600");
  if (!printWindow) {
    if (typeof showToast === "function") {
      showToast("Unable to open the print report.", "error");
    }
    return;
  }

  printWindow.document.open();
  printWindow.document.write(html);
  printWindow.document.close();

  printWindow.onload = function () {
    printWindow.focus();
    printWindow.print();
    setTimeout(() => {
      printWindow.close();
    }, 100);
  };
}

function initDispatchPrint() {
  if (dispatchPrintInitialized) {
    return;
  }
  dispatchPrintInitialized = true;

  const printBtn = document.getElementById("printDispatches");
  if (printBtn) {
    printBtn.addEventListener("click", openDispatchPrintWindow);
  }
}

document.addEventListener("DOMContentLoaded", () => {
    initDispatchPrint();
});