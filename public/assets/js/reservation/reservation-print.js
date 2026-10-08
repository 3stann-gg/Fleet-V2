let reservationPrintInitialized = false;

function initReservationPrint() {
  if (reservationPrintInitialized) return;

  const printButton = document.getElementById("printReservations");
  if (!printButton) return;

  reservationPrintInitialized = true;

  printButton.addEventListener("click", () => {
    try {
      if (typeof showToast === "function") {
        showToast("Preparing reservation report for printing.", "success");
      }

      const tableBody = document.getElementById("reservationTableBody");
      if (!tableBody) return;

      const rows = Array.from(tableBody.querySelectorAll("tr"));

      const tableRows = [];

      rows.forEach((row) => {
        if (
          row.id === "reservation-no-results" ||
          row.classList.contains("reservation-no-results")
        ) {
          return;
        }

        const isReal =
          row.querySelector(".reservation-number") ||
          row.querySelector(".reservation-checkbox");

        if (!isReal) {
          return;
        }

        if (row.dataset.reservationMatchesFilter === "false") {
          return;
        }

        const getText = (selector) => {
          const el = row.querySelector(selector);
          return el ? el.textContent.trim() : "";
        };

        const getDataset = (key) => {
          const value = row.dataset[key];
          return value ? value.trim() : "Not provided";
        };

        const schedule = getText(".reservation-schedule");
        const status = getText(".status-badge");

        tableRows.push(
          "<tr>" +
            "<td>" + escapeHtml(getText(".reservation-number")) + "</td>" +
            "<td>" + escapeHtml(getText(".patient-name")) + "</td>" +
            "<td>" + escapeHtml(getDataset("requestType")) + "</td>" +
            "<td>" + escapeHtml(getText(".reservation-vehicle")) + "</td>" +
            "<td>" + escapeHtml(getText(".reservation-driver")) + "</td>" +
            "<td>" + escapeHtml(getText(".reservation-pickup")) + "</td>" +
            "<td>" + escapeHtml(getText(".reservation-destination")) + "</td>" +
            "<td>" + escapeHtml(schedule || "Not provided") + "</td>" +
            "<td>" + escapeHtml(getDataset("priority")) + "</td>" +
            "<td>" + escapeHtml(status || "Not provided") + "</td>" +
          "</tr>"
        );
      });

      const generatedDate = new Date().toLocaleString();
      const html =
        "<!doctype html>" +
        "<html>" +
        "<head>" +
        "<title>Reservation Report</title>" +
        "<style>" +
        "body { font-family: Arial, sans-serif; margin: 20px; color: #111; }" +
        "h1 { font-size: 18px; margin: 0 0 4px; }" +
        "h2 { font-size: 14px; font-weight: normal; margin: 0 0 4px; color: #555; }" +
        ".generated { font-size: 12px; color: #777; margin-bottom: 16px; }" +
        "table { width: 100%; border-collapse: collapse; font-size: 12px; }" +
        "th { background: #f3f4f6; text-align: left; padding: 8px; border: 1px solid #d1d5db; }" +
        "td { padding: 8px; border: 1px solid #e5e7eb; }" +
        "tr:nth-child(even) { background: #fafafa; }" +
        "@media print {" +
        "body { margin: 0; }" +
        "}" +
        "</style>" +
        "</head>" +
        "<body>" +
        "<h1>Hospital Information Management System</h1>" +
        "<h2>Fleet & Transportation Management</h2>" +
        "<h2>Reservation Report</h2>" +
        "<div class=\"generated\">Generated: " + escapeHtml(generatedDate) + "</div>" +
        "<table>" +
        "<thead>" +
        "<tr>" +
        "<th>Reservation No.</th>" +
        "<th>Patient</th>" +
        "<th>Request Type</th>" +
        "<th>Vehicle</th>" +
        "<th>Driver</th>" +
        "<th>Pickup</th>" +
        "<th>Destination</th>" +
        "<th>Schedule</th>" +
        "<th>Priority</th>" +
        "<th>Status</th>" +
        "</tr>" +
        "</thead>" +
        "<tbody>" +
        tableRows.join("") +
        "</tbody>" +
        "</table>" +
        "</body>" +
        "</html>";

      const printWindow = window.open("", "_blank");
      if (!printWindow) {
        if (typeof showToast === "function") {
          showToast("Unable to open the print report.", "error");
        }
        return;
      }

      printWindow.document.open();
      printWindow.document.write(html);
      printWindow.document.close();

      printWindow.onload = () => {
          printWindow.focus();
          printWindow.print();
      };

      printWindow.onafterprint = () => {
          printWindow.close();
      };

      printWindow.print();
    } catch (error) {
      if (typeof showToast === "function") {
        showToast("Unable to open the print report.", "error");
      }
    }
  });
}

function escapeHtml(text) {
  const div = document.createElement("div");
  div.textContent = text;
  return div.innerHTML;
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initReservationPrint);
} else {
  initReservationPrint();
}
