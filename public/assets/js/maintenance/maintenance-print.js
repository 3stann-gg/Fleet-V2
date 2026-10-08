/* ==========================================
   Maintenance Print Report
   Uses Search + Filters + Sort
   Ignores Pagination
========================================== */

function escapeMaintenancePrintHtml(value) {
    return String(value == null ? "" : value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}


function showMaintenancePrintToast(message, type) {
    if (typeof showToast === "function") {
        showToast(message, type);
    } else if (
        typeof window !== "undefined" &&
        typeof window.showToast === "function"
    ) {
        window.showToast(message, type);
    }
}


function formatMaintenancePrintCost(cost) {
    if (typeof cost === "number" && !Number.isNaN(cost)) {
        return `₱${cost.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })}`;
    }

    if (cost == null || cost === "") {
        return "₱0.00";
    }

    return String(cost);
}


function getMaintenancePrintRows() {
    if (typeof getMaintenanceExportRows === "function") {
        return getMaintenanceExportRows();
    }

    const tableBody = document.getElementById("maintenanceTableBody");

    if (!tableBody || typeof getMaintenanceDataRows !== "function") {
        return [];
    }

    return getMaintenanceDataRows(tableBody).filter(
        (row) => row.dataset.matchesFilter !== "false",
    );
}

function buildMaintenancePrintTableBody(rows) {
    return rows
        .map((row) => {
            const record =
                typeof getMaintenanceExportData === "function"
                    ? getMaintenanceExportData(row)
                    : {};

            const notes = record.notes || record.description || "";

            return `
                <tr>
                    <td>${escapeMaintenancePrintHtml(record.number)}</td>

                    <td>${escapeMaintenancePrintHtml(record.vehicle)}</td>

                    <td>${escapeMaintenancePrintHtml(record.serviceType)}</td>

                    <td>${escapeMaintenancePrintHtml(record.technician)}</td>

                    <td>${escapeMaintenancePrintHtml(record.scheduledDate)}</td>

                    <td>${escapeMaintenancePrintHtml(
                        record.completionDate,
                    )}</td>

                    <td class="cost-cell">
                        ${escapeMaintenancePrintHtml(
                            formatMaintenancePrintCost(record.cost),
                        )}
                    </td>

                    <td>${escapeMaintenancePrintHtml(record.priority)}</td>

                    <td>${escapeMaintenancePrintHtml(record.status)}</td>

                    <td>${escapeMaintenancePrintHtml(notes)}</td>
                </tr>
            `;
        })
        .join("");
}

function buildMaintenancePrintHtml(rows) {
    const generatedAt = new Date();
    const generatedDate = generatedAt.toLocaleDateString();
    const generatedTime = generatedAt.toLocaleTimeString();
    const tableBodyHtml = buildMaintenancePrintTableBody(rows);

    return `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>
        Maintenance Records Report
    </title>

    <style>
        @page {
            size: A4 landscape;
            margin: 10mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: Arial, Helvetica, sans-serif;
            color: #000;
            background: #fff;
            font-size: 10px;
            line-height: 1.25;
        }

        .report-header {
            margin-bottom: 10px;
        }

        .report-header h1 {
            margin: 0 0 3px;
            font-size: 18px;
            font-weight: 700;
        }

        .report-header h2 {
            margin: 0 0 5px;
            font-size: 12px;
            font-weight: 600;
        }

        .report-header h3 {
            margin: 0 0 7px;
            font-size: 14px;
            font-weight: 700;
        }

        .meta {
            margin: 0 0 2px;
            font-size: 9px;
            color: #222;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-top: 8px;
        }

        thead {
            display: table-header-group;
        }

        tr {
            page-break-inside: avoid;
            break-inside: avoid;
        }

        th,
        td {
            border: 1px solid #666;
            padding: 3px 4px;
            text-align: left;
            vertical-align: top;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        th {
            background: #f0f0f0;
            font-weight: 700;
            font-size: 8.5px;
        }

        td {
            font-size: 8.5px;
        }

        .cost-cell {
            text-align: right;
            white-space: nowrap;
        }

        tbody tr:nth-child(even) {
            background: #fafafa;
        }

        /* Fixed column widths */
        th:nth-child(1),
        td:nth-child(1) {
            width: 10%;
        }

        th:nth-child(2),
        td:nth-child(2) {
            width: 13%;
        }

        th:nth-child(3),
        td:nth-child(3) {
            width: 10%;
        }

        th:nth-child(4),
        td:nth-child(4) {
            width: 13%;
        }

        th:nth-child(5),
        td:nth-child(5) {
            width: 9%;
        }

        th:nth-child(6),
        td:nth-child(6) {
            width: 9%;
        }

        th:nth-child(7),
        td:nth-child(7) {
            width: 8%;
        }

        th:nth-child(8),
        td:nth-child(8) {
            width: 7%;
        }

        th:nth-child(9),
        td:nth-child(9) {
            width: 8%;
        }

        th:nth-child(10),
        td:nth-child(10) {
            width: 13%;
        }

        @media print {
            body {
                background: #fff;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            th {
                background: #f0f0f0 !important;
            }
        }
    </style>
</head>

<body>

    <div class="report-header">

        <h1>
            Hospital Information Management System
        </h1>

        <h2>
            Fleet &amp; Transportation Management
        </h2>

        <h3>
            Maintenance Records Report
        </h3>

        <p class="meta">
            Generated Date:
            ${escapeMaintenancePrintHtml(generatedDate)}
        </p>

        <p class="meta">
            Generated Time:
            ${escapeMaintenancePrintHtml(generatedTime)}
        </p>

        <p class="meta">
            Total Printed Records:
            ${rows.length}
        </p>

    </div>

    <table>

        <thead>
            <tr>
                <th>Maintenance No.</th>
                <th>Vehicle</th>
                <th>Service Type</th>
                <th>Technician / Workshop</th>
                <th>Scheduled Date</th>
                <th>Completion Date</th>
                <th>Cost</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Notes</th>
            </tr>
        </thead>

        <tbody>
            ${
                tableBodyHtml ||
                `
                <tr>
                    <td colspan="10">
                        No matching maintenance records found.
                    </td>
                </tr>
                `
            }
        </tbody>

    </table>

</body>
</html>`;
}

function printMaintenanceRecords() {
    const rows = getMaintenancePrintRows();

    if (!rows.length) {
        showMaintenancePrintToast(
            "No maintenance records available to print.",
            "warning",
        );
        return;
    }

    let printWindow = null;

    try {
        printWindow = window.open("", "_blank", "width=1100,height=760");
    } catch (error) {
        console.error("Maintenance print window failed:", error);

        showMaintenancePrintToast("Unable to open the print report.", "error");

        return;
    }

    if (!printWindow) {
        showMaintenancePrintToast("Unable to open the print report.", "error");

        return;
    }

    try {
        const html = buildMaintenancePrintHtml(rows);

        printWindow.document.open();
        printWindow.document.write(html);
        printWindow.document.close();

        const runPrint = () => {
            try {
                printWindow.focus();
                printWindow.print();
            } catch (error) {
                console.error("Maintenance print failed:", error);

                showMaintenancePrintToast(
                    "Unable to print maintenance report.",
                    "error",
                );
            }

            const closeWindow = () => {
                try {
                    printWindow.close();
                } catch {
                    /* ignore */
                }
            };

            if (typeof printWindow.addEventListener === "function") {
                printWindow.addEventListener("afterprint", closeWindow, {
                    once: true,
                });
            }

            setTimeout(closeWindow, 700);
        };

        if (printWindow.document.readyState === "complete") {
            runPrint();
        } else {
            printWindow.onload = runPrint;
        }
    } catch (error) {
        console.error("Maintenance print failed:", error);

        showMaintenancePrintToast(
            "Unable to print maintenance report.",
            "error",
        );

        try {
            printWindow.close();
        } catch {
            /* ignore */
        }
    }
}

function initMaintenancePrint() {
    const printButton = document.getElementById("printMaintenance");

    if (
        !printButton ||
        printButton.dataset.maintenancePrintInitialized === "true"
    ) {
        return;
    }

    printButton.dataset.maintenancePrintInitialized = "true";
    printButton.type = "button";
    printButton.addEventListener("click", (event) => {
        event.preventDefault();

        printMaintenanceRecords();
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initMaintenancePrint);
} else {
    initMaintenancePrint();
}
