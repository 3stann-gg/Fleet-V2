/* ==========================================
   Maintenance Export
========================================== */

function getMaintenanceExportRows() {
    const tableBody = document.getElementById("maintenanceTableBody");

    if (!tableBody) {
        return [];
    }

    const rows =
        typeof getMaintenanceDataRows === "function"
            ? getMaintenanceDataRows(tableBody)
            : Array.from(tableBody.querySelectorAll("tr")).filter((row) => {
                  const isHelper =
                      row.classList.contains("maintenance-no-results") ||
                      row.classList.contains("helper-row") ||
                      row.classList.contains("empty-state") ||
                      row.dataset.helperRow === "true";

                  return (
                      !isHelper &&
                      Boolean(
                          row.querySelector(".maintenance-number") ||
                          row.querySelector(".maintenance-checkbox") ||
                          row.querySelector(".maintenance-vehicle"),
                      )
                  );
              });

    /*
    |--------------------------------------------------------------------------
    | Export only rows matching current Search + Filters.
    | Pagination is ignored.
    |--------------------------------------------------------------------------
    */
    return rows.filter(
        (row) =>
            row.dataset.matchesFilter !== "false" &&
            row.dataset.maintenanceMatchesFilter !== "false",
    );
}

function getMaintenanceExportText(row, selector) {
    const element = selector ? row.querySelector(selector) : null;
    const value = element?.textContent?.trim() || "";

    return value;
}

function getMaintenanceExportCost(row) {
    const raw = row.dataset.cost;

    if (raw !== undefined && String(raw).trim() !== "") {
        const parsed = Number.parseFloat(String(raw).replace(/[^\d.-]/g, ""));

        if (!Number.isNaN(parsed)) {
            return parsed;
        }
    }

    const display = getMaintenanceExportText(row, ".maintenance-cost");
    const fromDisplay = Number.parseFloat(display.replace(/[^\d.-]/g, ""));

    return Number.isNaN(fromDisplay) ? display : fromDisplay;
}


function getMaintenanceExportData(row) {
    return {
        number: getMaintenanceExportText(row, ".maintenance-number"),
        vehicle: getMaintenanceExportText(row, ".maintenance-vehicle"),
        serviceType: getMaintenanceExportText(row, ".maintenance-service-type"),
        technician: getMaintenanceExportText(row, ".maintenance-technician"),
        scheduledDate: getMaintenanceExportText(
            row,
            ".maintenance-scheduled-date",
        ),
        completionDate: getMaintenanceExportText(
            row,
            ".maintenance-completion-date",
        ),
        cost: getMaintenanceExportCost(row),
        priority: getMaintenanceExportText(row, ".maintenance-priority"),
        status: getMaintenanceExportText(row, ".status-badge"),
        odometer: (row.dataset.odometer || "").trim(),
        description: (row.dataset.description || "").trim(),
        partsUsed: (row.dataset.partsUsed || "").trim(),
        notes: (row.dataset.notes || "").trim(),
    };
}


function getMaintenanceExportDateStamp() {
    const now = new Date();

    const year = now.getFullYear();

    const month = String(now.getMonth() + 1).padStart(2, "0");

    const day = String(now.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}`;
}

function getMaintenanceExportFilename() {
    return `Maintenance_Records_${getMaintenanceExportDateStamp()}.xlsx`;
}

function getMaintenancePdfFilename() {
    return `Maintenance_Records_${getMaintenanceExportDateStamp()}.pdf`;
}


function formatMaintenancePdfCost(cost) {
    if (typeof cost === "number" && !Number.isNaN(cost)) {
        return `PHP ${cost.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })}`;
    }

    if (cost == null || cost === "") {
        return "PHP 0.00";
    }

    return String(cost);
}


function showMaintenanceExportToast(message, type) {
    if (typeof showToast === "function") {
        showToast(message, type);
    } else if (
        typeof window !== "undefined" &&
        typeof window.showToast === "function"
    ) {
        window.showToast(message, type);
    }
}


function buildMaintenanceExportSheetData(rows) {
    const headers = [
        "Maintenance No.",
        "Vehicle",
        "Service Type",
        "Technician / Workshop",
        "Scheduled Date",
        "Completion Date",
        "Cost",
        "Priority",
        "Status",
        "Odometer",
        "Description",
        "Parts Used",
        "Notes",
    ];

    const body = rows.map((row) => {
        const record = getMaintenanceExportData(row);

        return [
            record.number,
            record.vehicle,
            record.serviceType,
            record.technician,
            record.scheduledDate,
            record.completionDate,
            formatMaintenanceExportCostDisplay(record.cost),
            record.priority,
            record.status,
            record.notes || record.description || "",
        ];
    });

    return [headers, ...body];
}

function exportMaintenanceToExcel() {
    const xlsx = window.XLSX;

    if (!xlsx?.utils) {
        showMaintenanceExportToast("Excel export is unavailable.", "error");
        return;
    }

    const rows = getMaintenanceExportRows();

    if (rows.length === 0) {
        showMaintenanceExportToast(
            "No maintenance records available to export.",
            "warning",
        );
        return;
    }

    try {
        const data = buildMaintenanceExportSheetData(rows);

        const workbook = xlsx.utils.book_new();

        const worksheet = xlsx.utils.aoa_to_sheet(data);

        /*
        |--------------------------------------------------------------------------
        | Excel column widths
        |--------------------------------------------------------------------------
        */
        worksheet["!cols"] = [
            { wch: 18 }, // Maintenance No.
            { wch: 28 }, // Vehicle
            { wch: 24 }, // Service Type
            { wch: 30 }, // Technician / Workshop
            { wch: 18 }, // Scheduled Date
            { wch: 18 }, // Completion Date
            { wch: 15 }, // Cost
            { wch: 14 }, // Priority
            { wch: 16 }, // Status
            { wch: 14 }, // Odometer
            { wch: 45 }, // Description
            { wch: 35 }, // Parts Used
            { wch: 40 }, // Notes
        ];

        xlsx.utils.book_append_sheet(
            workbook,
            worksheet,
            "Maintenance Records",
        );

        xlsx.writeFile(workbook, getMaintenanceExportFilename());

        showMaintenanceExportToast(
            "Maintenance records exported successfully.",
            "success",
        );
    } catch (error) {
        console.error("Maintenance Excel export failed:", error);

        showMaintenanceExportToast(
            "Unable to export maintenance records.",
            "error",
        );
    }
}

function initMaintenanceExcelExport() {
    const exportButton = document.getElementById("exportMaintenance");

    if (
        !exportButton ||
        exportButton.dataset.maintenanceExcelExportInitialized === "true"
    ) {
        return;
    }

    exportButton.dataset.maintenanceExcelExportInitialized = "true";
    exportButton.type = "button";
    exportButton.addEventListener("click", (event) => {
        event.preventDefault();

        exportMaintenanceToExcel();
    });
}

function initMaintenanceExport() {
    initMaintenanceExcelExport();
}

function exportMaintenanceToPdf() {
    const jsPDF = window.jspdf?.jsPDF;

    if (!jsPDF) {
        showMaintenanceExportToast("PDF export is unavailable.", "error");
        return;
    }

    const rows = getMaintenanceExportRows();

    if (rows.length === 0) {
        showMaintenanceExportToast(
            "No maintenance records available to export.",
            "warning",
        );
        return;
    }

    try {
        const pdfDocument = new jsPDF({
            orientation: "landscape",
            unit: "mm",
            format: "a4",
            ...(window.consumeSecurePdfEncryption?.() || {}),
        });

        if (typeof pdfDocument.autoTable !== "function") {
            showMaintenanceExportToast("PDF export is unavailable.", "error");
            return;
        }

        const generatedAt = new Date();

        pdfDocument.setFontSize(16);
        pdfDocument.setTextColor(0, 168, 107);
        pdfDocument.text("Hospital Information Management System", 14, 14);
        pdfDocument.setFontSize(11);
        pdfDocument.setTextColor(0, 0, 0);
        pdfDocument.text("Fleet & Transportation Management", 14, 21);
        pdfDocument.setFontSize(14);
        pdfDocument.text("Maintenance Records Report", 14, 30);
        pdfDocument.setFontSize(9);
        pdfDocument.text(`Generated: ${generatedAt.toLocaleString()}`, 14, 38);
        pdfDocument.text(`Total Exported Records: ${rows.length}`, 14, 44);

        const body = rows.map((row) => {
            const record = getMaintenanceExportData(row);

            return [
                record.number,
                record.vehicle,
                record.serviceType,
                record.technician,
                record.scheduledDate,
                record.completionDate,
                formatMaintenancePdfCost(record.cost),
                record.priority,
                record.status,
                record.notes || record.description || "",
            ];
        });

        pdfDocument.autoTable({
            head: [
                [
                    "Maintenance No.",
                    "Vehicle",
                    "Service Type",
                    "Technician / Workshop",
                    "Scheduled Date",
                    "Completion Date",
                    "Cost",
                    "Priority",
                    "Status",
                    "Notes",
                ],
            ],

            body,
            startY: 50,
            margin: {
                top: 14,
                right: 8,
                bottom: 14,
                left: 8,
            },
            tableWidth: "auto",
            styles: {
                fontSize: 6.5,
                cellPadding: 1.8,
                overflow: "linebreak",
                valign: "top",
                lineWidth: 0.2,
            },
            headStyles: {
                fillColor: [0, 168, 107],
                textColor: 255,
                fontStyle: "bold",
                fontSize: 6.5,
                cellPadding: 2,
            },
            columnStyles: {
                0: { cellWidth: 23 }, // Maintenance No.
                1: { cellWidth: 32 }, // Vehicle
                2: { cellWidth: 25 }, // Service Type
                3: { cellWidth: 34 }, // Technician
                4: { cellWidth: 23 }, // Scheduled
                5: { cellWidth: 23 }, // Completion
                6: {
                    cellWidth: 22,
                    halign: "right",
                }, // Cost
                7: { cellWidth: 17 }, // Priority
                8: { cellWidth: 18 }, // Status
                9: { cellWidth: "auto" }, // Notes
            },
            alternateRowStyles: {
                fillColor: [245, 247, 250],
            },
            showHead: "everyPage",

            didDrawPage: () => {
                const pageSize = pdfDocument.internal.pageSize;
                const pageWidth = pageSize.width || pageSize.getWidth();
                const pageHeight = pageSize.height || pageSize.getHeight();
                const pageNumber = pdfDocument.internal.getNumberOfPages();

                pdfDocument.setFontSize(8);
                pdfDocument.setTextColor(100);

                pdfDocument.text(
                    `Page ${pageNumber}`,
                    pageWidth - 8,
                    pageHeight - 6,
                    {
                        align: "right",
                    },
                );
            },
        });

        pdfDocument.save(getMaintenancePdfFilename());

        showMaintenanceExportToast(
            "Maintenance PDF exported successfully.",
            "success",
        );
    } catch (error) {
        console.error("Maintenance PDF export failed:", error);

        showMaintenanceExportToast(
            "Unable to export maintenance report.",
            "error",
        );
    }
}

function initMaintenancePDFExport() {
    const exportButton = document.getElementById("exportMaintenancePDF");

    if (
        !exportButton ||
        exportButton.dataset.maintenancePdfExportInitialized === "true"
    ) {
        return;
    }

    exportButton.dataset.maintenancePdfExportInitialized = "true";
    exportButton.type = "button";
    exportButton.addEventListener("click", (event) => {
        event.preventDefault();

        exportMaintenanceToPdf();
    });
}

document.addEventListener("DOMContentLoaded", () => {
    initMaintenanceExcelExport();
    initMaintenancePDFExport();
});
