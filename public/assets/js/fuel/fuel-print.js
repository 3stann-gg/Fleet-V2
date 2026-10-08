/* ==========================================
   Fuel Print Report
   Uses getFuelExportRows() — Search + Filters + Sort, no pagination
========================================== */

function escapeFuelPrintHtml(value) {
  return String(value == null ? "" : value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function showFuelPrintToast(message, type) {
  if (typeof showToast === "function") {
    showToast(message, type);
  } else if (
    typeof window !== "undefined" &&
    typeof window.showToast === "function"
  ) {
    window.showToast(message, type);
  }
}

function getFuelPrintRows() {
  if (typeof getFuelExportRows === "function") {
    return getFuelExportRows();
  }
  return [];
}

function buildFuelPrintTableBody(rows) {
    return rows
        .map((row) => {
            const record =
                typeof getFuelExportData === "function"
                    ? getFuelExportData(row)
                    : {};
            const vehicleDisplay = [record.vehicle, record.vehicleType]
                .filter(Boolean)
                .join(" - ");
            const quantity =
                typeof formatFuelExportQuantity === "function"
                    ? formatFuelExportQuantity(record.quantity)
                    : record.quantity;
            const costPerLiter =
                typeof formatFuelExportPdfCurrency === "function"
                    ? formatFuelExportPdfCurrency(record.costPerLiter)
                    : record.costPerLiter;
            const totalCost =
                typeof formatFuelExportPdfCurrency === "function"
                    ? formatFuelExportPdfCurrency(record.totalCost)
                    : record.totalCost;
            const odometer =
                typeof formatFuelExportOdometer === "function"
                    ? formatFuelExportOdometer(record.odometer)
                    : record.odometer || "";

            return `<tr>
                <td>${escapeFuelPrintHtml(record.number)}</td>
                <td>${escapeFuelPrintHtml(record.date)}</td>
                <td>${escapeFuelPrintHtml(vehicleDisplay)}</td>
                <td>${escapeFuelPrintHtml(record.plate)}</td>
                <td>${escapeFuelPrintHtml(record.driver)}</td>
                <td>${escapeFuelPrintHtml(record.fuelType)}</td>
                <td>${escapeFuelPrintHtml(quantity)}</td>
                <td>${escapeFuelPrintHtml(costPerLiter)}</td>
                <td>${escapeFuelPrintHtml(totalCost)}</td>
                <td>${escapeFuelPrintHtml(odometer)}</td>
                <td>${escapeFuelPrintHtml(record.station)}</td>
                <td>${escapeFuelPrintHtml(record.receipt || "—")}</td>
            </tr>`;
        })
        .join("");
}

function buildFuelPrintHtml(rows) {
  const generatedAt = new Date();
  const generatedDate = generatedAt.toLocaleDateString();
  const generatedTime = generatedAt.toLocaleTimeString();
  const tableBodyHtml = buildFuelPrintTableBody(rows);
  const summary =
    typeof getFuelExportSummary === "function"
      ? getFuelExportSummary(rows)
      : { totalLiters: 0, totalCost: 0, averagePerLiter: 0 };

  const totalLiters =
    typeof formatFuelExportQuantity === "function"
      ? formatFuelExportQuantity(summary.totalLiters)
      : summary.totalLiters;
  const totalCost =
      typeof formatFuelExportPdfCurrency === "function"
          ? formatFuelExportPdfCurrency(summary.totalCost)
          : summary.totalCost;
  const avgCost =
      typeof formatFuelExportPdfCurrency === "function"
          ? formatFuelExportPdfCurrency(summary.averagePerLiter) + "/L"
          : summary.averagePerLiter;

  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Fuel Records Report</title>
  <style>
    @page {
      size: A4 landscape;
      margin: 12mm;
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      padding: 0;
      font-family: Arial, Helvetica, sans-serif;
      color: #000;
      background: #fff;
      font-size: 10px;
      line-height: 1.35;
    }

    .report-header { margin-bottom: 12px; }
    .report-header h1 {
      margin: 0 0 2px;
      font-size: 16px;
      color: #00a86b;
    }
    .report-header h2 {
      margin: 0 0 6px;
      font-size: 13px;
      font-weight: 600;
    }
    .report-header h3 {
      margin: 0 0 8px;
      font-size: 14px;
    }
    .meta { margin: 2px 0; color: #333; }
    .summary {
      margin: 8px 0 12px;
      padding: 8px 10px;
      border: 1px solid #ddd;
      background: #f8fafc;
    }
    .summary span { margin-right: 18px; white-space: nowrap; }

    table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    }
    th:nth-child(1),
    td:nth-child(1) {
        width: 9%;
    }
    th:nth-child(2),
    td:nth-child(2) {
        width: 7%;
    }
    th:nth-child(3),
    td:nth-child(3) {
        width: 14%;
    }
    th:nth-child(4),
    td:nth-child(4) {
        width: 8%;
    }
    th:nth-child(5),
    td:nth-child(5) {
        width: 11%;
    }
    th:nth-child(6),
    td:nth-child(6) {
        width: 9%;
    }
    th:nth-child(7),
    td:nth-child(7) {
        width: 7%;
    }
    th:nth-child(8),
    td:nth-child(8) {
        width: 8%;
    }
    th:nth-child(9),
    td:nth-child(9) {
        width: 9%;
    }
    th:nth-child(10),
    td:nth-child(10) {
        width: 8%;
    }
    th:nth-child(11),
    td:nth-child(11) {
        width: 10%;
    }
    th:nth-child(12),
    td:nth-child(12) {
        width: 10%;
    }

    thead { display: table-header-group; }

    th, td {
      border: 1px solid #333;
      padding: 4px 5px;
      text-align: left;
      vertical-align: top;
      word-wrap: break-word;
    }

    th {
      background: #e8f8f2;
      font-weight: 700;
      font-size: 9px;
    }

    tr { page-break-inside: avoid; }

    @media print {
      body {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }
    }
  </style>
</head>
<body>
  <div class="report-header">
    <h1>Fleet &amp; Transportation Management</h1>
    <h2>Hospital Information Management System</h2>
    <h3>Fuel Records Report</h3>
    <p class="meta">Generated Date: ${escapeFuelPrintHtml(generatedDate)}</p>
    <p class="meta">Generated Time: ${escapeFuelPrintHtml(generatedTime)}</p>
    <p class="meta">Total Printed Records: ${rows.length}</p>
  </div>

  <div class="summary">
    <span><strong>Total Fuel Consumed:</strong> ${escapeFuelPrintHtml(totalLiters)}</span>
    <span><strong>Total Fuel Cost:</strong> ${escapeFuelPrintHtml(totalCost)}</span>
    <span><strong>Average Cost per Liter:</strong> ${escapeFuelPrintHtml(avgCost)}</span>
  </div>

  <table>
    <thead>
      <tr>
        <th>Fuel Record No.</th>
        <th>Date</th>
        <th>Vehicle</th>
        <th>Plate No.</th>
        <th>Driver</th>
        <th>Fuel Type</th>
        <th>Quantity</th>
        <th>Cost per Liter</th>
        <th>Total Cost</th>
        <th>Odometer</th>
        <th>Fuel Station</th>
        <th>Receipt / Reference No.</th>
      </tr>
    </thead>
    <tbody>
      ${tableBodyHtml}
    </tbody>
  </table>
</body>
</html>`;
}

function printFuelRecords() {
    if (typeof getFuelExportRows !== "function") {
        showFuelPrintToast("Unable to prepare the print report.", "error");
        return;
    }

    const rows = getFuelPrintRows();

    if (rows.length === 0) {
        showFuelPrintToast("No fuel records available to print.", "warning");
        return;
    }

    let printWindow = null;

    try {
        printWindow = window.open(
            "",
            "_blank",
            "width=1100,height=760,resizable=yes,scrollbars=yes",
        );

        if (!printWindow) {
            showFuelPrintToast(
                "Unable to open the print report. Please allow pop-ups for this site.",
                "error",
            );
            return;
        }

        const html = buildFuelPrintHtml(rows);

        printWindow.document.open();
        printWindow.document.write(html);
        printWindow.document.close();

        const runPrint = () => {
            setTimeout(() => {
                try {
                    printWindow.focus();
                    printWindow.print();
                } catch (error) {
                    console.error("Fuel print dialog failed:", error);

                    showFuelPrintToast("Unable to print fuel report.", "error");
                }
            }, 150);
        };

        if (printWindow.document.readyState === "complete") {
            runPrint();
        } else {
            printWindow.onload = runPrint;
        }

        const closeWindow = () => {
            try {
                if (printWindow && !printWindow.closed) {
                    printWindow.close();
                }
            } catch {
                /* ignore */
            }
        };

        if (typeof printWindow.addEventListener === "function") {
            printWindow.addEventListener("afterprint", closeWindow, {
                once: true,
            });
        }
        
        setTimeout(closeWindow, 10000);
    } catch (error) {
        console.error("Fuel print failed:", error);

        showFuelPrintToast("Unable to print fuel report.", "error");

        try {
            if (printWindow && !printWindow.closed) {
                printWindow.close();
            }
        } catch {
            /* ignore */
        }
    }
}

function initFuelPrint() {
  const printButton = document.getElementById("printFuel");

  if (!printButton || printButton.dataset.fuelPrintInitialized === "true") {
    return;
  }

  printButton.dataset.fuelPrintInitialized = "true";
  printButton.type = "button";

  printButton.addEventListener("click", (event) => {
    event.preventDefault();
    printFuelRecords();
  });
}

document.addEventListener("DOMContentLoaded", () => {
    initFuelPrint();
});