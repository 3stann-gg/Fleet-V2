/* ==========================================
   Fuel Excel + PDF Export
   Processed dataset:
   Search + Filters + Sort
   Ignores Pagination
========================================== */

function getFuelExportRows() {
    const tableBody = document.getElementById("fuelTableBody");

    if (!tableBody) {
        return [];
    }

    const rows =
        typeof getFuelDataRows === "function"
            ? getFuelDataRows(tableBody)
            : Array.from(tableBody.querySelectorAll("tr")).filter((row) => {
                  const isHelper =
                      row.classList.contains("fuel-no-results") ||
                      row.classList.contains("helper-row") ||
                      row.classList.contains("empty-state") ||
                      row.dataset.helperRow === "true";

                  return (
                      !isHelper &&
                      Boolean(
                          row.querySelector(".fuel-number") ||
                          row.dataset.fuelId ||
                          row.dataset.id,
                      )
                  );
              });

    /*
    |--------------------------------------------------------------------------
    | DOM order already reflects the active sort.
    | Search / Filter state is stored on each row.
    | Pagination only controls visibility, so it is ignored here.
    |--------------------------------------------------------------------------
    */
    return rows.filter(
        (row) =>
            row.dataset.fuelMatchesFilter !== "false" &&
            row.dataset.matchesFilter !== "false",
    );
}

function getProcessedFuelRecords(options = {}) {
    const includePagination = options.includePagination === true;

    const tableBody = document.getElementById("fuelTableBody");

    if (!tableBody) {
        return [];
    }

    const rows =
        typeof getFuelDataRows === "function" ? getFuelDataRows(tableBody) : [];

    if (includePagination) {
        return rows.filter(
            (row) =>
                row.dataset.fuelMatchesFilter !== "false" &&
                row.dataset.matchesFilter !== "false" &&
                row.style.display !== "none",
        );
    }

    return getFuelExportRows();
}

function getFuelExportText(row, selector) {
    const element = selector ? row.querySelector(selector) : null;

    return element ? element.textContent.trim() : "";
}

function getFuelExportNumber(row, datasetKey, selector) {
    const raw = row.dataset[datasetKey];

    if (raw != null && String(raw).trim() !== "") {
        const parsed = Number.parseFloat(String(raw).replace(/[^\d.-]/g, ""));

        if (!Number.isNaN(parsed)) {
            return parsed;
        }
    }

    const display = getFuelExportText(row, selector);
    const fromDisplay = Number.parseFloat(display.replace(/[^\d.-]/g, ""));

    return Number.isNaN(fromDisplay) ? display : fromDisplay;
}

function getFuelExportData(row) {
    const vehicleName =
        getFuelExportText(row, ".fuel-vehicle-name") ||
        getFuelExportText(row, ".fuel-vehicle");

    const vehicleType =
        (row.dataset.vehicleType || "").trim() ||
        getFuelExportText(row, ".fuel-vehicle-type");

    return {
        number: getFuelExportText(row, ".fuel-number"),
        date: getFuelExportText(row, ".fuel-date"),
        time: (row.dataset.refuelTime || "").trim(),
        vehicle: vehicleName,
        vehicleType: vehicleType,
        plate: getFuelExportText(row, ".fuel-plate"),
        driver: getFuelExportText(row, ".fuel-driver"),
        fuelType: getFuelExportText(row, ".fuel-type"),
        quantity: getFuelExportNumber(row, "quantity", ".fuel-quantity"),
        costPerLiter: getFuelExportNumber(
            row,
            "costPerLiter",
            ".fuel-cost-per-liter",
        ),
        totalCost: getFuelExportNumber(row, "totalCost", ".fuel-total-cost"),
        odometer: getFuelExportNumber(row, "odometer", ".fuel-odometer"),
        station: getFuelExportText(row, ".fuel-station"),
        receipt: (row.dataset.receipt || "").trim(),
        payment: (row.dataset.payment || "").trim(),
        notes: (row.dataset.notes || "").trim(),
    };
}

/* ==========================================
   Filename
========================================== */

function getFuelExportDateStamp() {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, "0");
    const day = String(now.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}`;
}

function getFuelExportFilename() {
    return `fuel-records-${getFuelExportDateStamp()}.xlsx`;
}

function getFuelPdfFilename() {
    return `fuel-records-${getFuelExportDateStamp()}.pdf`;
}

function formatFuelExportPdfCurrency(value) {
    const number = Number.parseFloat(value);

    if (Number.isNaN(number)) {
        return value == null ? "" : String(value);
    }

    return `PHP ${number.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

function formatFuelExportQuantity(value) {
    const number = Number.parseFloat(value);

    if (Number.isNaN(number)) {
        return value == null ? "" : String(value);
    }

    return `${number.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })} L`;
}

function formatFuelExportOdometer(value) {
    const number = Number.parseFloat(value);

    if (Number.isNaN(number)) {
        return value == null ? "" : String(value);
    }

    return `${number.toLocaleString(undefined, {
        maximumFractionDigits: 2,
    })} km`;
}

function getFuelExportSummary(rows) {
    let liters = 0;
    let totalCost = 0;

    rows.forEach((row) => {
        const record = getFuelExportData(row);

        const quantity = Number.parseFloat(record.quantity);

        const cost = Number.parseFloat(record.totalCost);

        if (!Number.isNaN(quantity)) {
            liters += quantity;
        }

        if (!Number.isNaN(cost)) {
            totalCost += cost;
        }
    });

    const averagePerLiter = liters > 0 ? totalCost / liters : 0;

    return {
        totalRecords: rows.length,

        totalLiters: liters,

        totalCost: totalCost,

        averagePerLiter: averagePerLiter,
    };
}
function showFuelExportToast(message, type) {
    if (typeof showToast === "function") {
        showToast(message, type);
    } else if (
        typeof window !== "undefined" &&
        typeof window.showToast === "function"
    ) {
        window.showToast(message, type);
    }
}
/* ==========================================
   Excel Sheet
========================================== */
function buildFuelExportSheetData(rows) {
    const headers = [
        "Fuel Record No.",
        "Date",
        "Time",
        "Vehicle",
        "Vehicle Type",
        "Plate No.",
        "Driver",
        "Fuel Type",
        "Quantity (L)",
        "Cost per Liter",
        "Total Cost",
        "Odometer",
        "Fuel Station",
        "Receipt / Reference No.",
        "Payment Method",
        "Notes",
    ];

    const body = rows.map((row) => {
        const record = getFuelExportData(row);

        return [
            record.number,
            record.date,
            record.time,
            record.vehicle,
            record.vehicleType,
            record.plate,
            record.driver,
            record.fuelType,
            typeof record.quantity === "number"
                ? record.quantity
                : record.quantity,
            typeof record.costPerLiter === "number"
                ? record.costPerLiter
                : record.costPerLiter,
            typeof record.totalCost === "number"
                ? record.totalCost
                : record.totalCost,
            typeof record.odometer === "number"
                ? record.odometer
                : record.odometer,
            record.station,
            record.receipt,
            record.payment,
            record.notes,
        ];
    });

    return [headers, ...body];
}

function exportFuelToExcel() {
    const xlsx = window.XLSX;

    if (!xlsx?.utils) {
        showFuelExportToast("Excel export is unavailable.", "error");

        return;
    }

    const rows = getFuelExportRows();

    if (rows.length === 0) {
        showFuelExportToast("No fuel records available to export.", "warning");

        return;
    }

    try {
        const data = buildFuelExportSheetData(rows);
        const workbook = xlsx.utils.book_new();
        const worksheet = xlsx.utils.aoa_to_sheet(data);
        /*
        |--------------------------------------------------------------------------
        | Excel column widths
        |--------------------------------------------------------------------------
        */
        worksheet["!cols"] = [
            { wch: 20 }, // Record No.
            { wch: 15 }, // Date
            { wch: 10 }, // Time
            { wch: 24 }, // Vehicle
            { wch: 18 }, // Vehicle Type
            { wch: 16 }, // Plate
            { wch: 22 }, // Driver
            { wch: 20 }, // Fuel Type
            { wch: 15 }, // Quantity
            { wch: 18 }, // Cost/L
            { wch: 18 }, // Total Cost
            { wch: 16 }, // Odometer
            { wch: 28 }, // Station
            { wch: 24 }, // Receipt
            { wch: 20 }, // Payment
            { wch: 40 }, // Notes
        ];

        xlsx.utils.book_append_sheet(workbook, worksheet, "Fuel Records");

        xlsx.writeFile(workbook, getFuelExportFilename());

        showFuelExportToast("Fuel records exported successfully.", "success");
    } catch (error) {
        console.error("Fuel Excel export failed:", error);

        showFuelExportToast("Unable to export fuel records.", "error");
    }
}

function exportFuelToPdf() {
    const jsPDF = window.jspdf?.jsPDF;

    if (!jsPDF) {
        showFuelExportToast("PDF export is unavailable.", "error");

        return;
    }

    const rows = getFuelExportRows();

    if (rows.length === 0) {
        showFuelExportToast("No fuel records available to export.", "warning");

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
            showFuelExportToast("PDF export is unavailable.", "error");

            return;
        }

        const generatedAt = new Date();
        const generatedDate = generatedAt.toLocaleDateString();
        const generatedTime = generatedAt.toLocaleTimeString();
        const summary = getFuelExportSummary(rows);

        pdfDocument.setFontSize(16);
        pdfDocument.setTextColor(0, 168, 107);
        pdfDocument.text("Hospital Information Management System", 12, 13);
        pdfDocument.setFontSize(11);
        pdfDocument.setTextColor(0, 0, 0);
        pdfDocument.text("Fleet & Transportation Management", 12, 20);
        pdfDocument.setFontSize(14);
        pdfDocument.text("Fuel Records Report", 12, 28);
        pdfDocument.setFontSize(8);
        pdfDocument.text(`Generated Date: ${generatedDate}`, 12, 35);
        pdfDocument.text(`Generated Time: ${generatedTime}`, 12, 40);
        pdfDocument.text(`Total Records: ${summary.totalRecords}`, 12, 45);
        pdfDocument.text(
            `Total Fuel Consumed: ${formatFuelExportQuantity(summary.totalLiters)}`,
            70,
            45,
        );
        pdfDocument.text(
            `Total Fuel Cost: ${formatFuelExportPdfCurrency(summary.totalCost)}`,
            150,
            45,
        );

        pdfDocument.text(
            `Average Cost/L: ${formatFuelExportPdfCurrency(summary.averagePerLiter)}`,
            235,
            45,
        );

        const body = rows.map((row) => {
            const record = getFuelExportData(row);

            const vehicleDisplay = [record.vehicle, record.vehicleType]
                .filter(Boolean)
                .join(" - ");

            return [
                record.number,
                record.date,
                vehicleDisplay,
                record.plate,
                record.driver,
                record.fuelType,
                formatFuelExportQuantity(record.quantity),
                formatFuelExportPdfCurrency(record.costPerLiter),
                formatFuelExportPdfCurrency(record.totalCost),
                formatFuelExportOdometer(record.odometer),
                record.station,
                record.receipt || "—",
            ];
        });

        pdfDocument.autoTable({
            head: [
                [
                    "Fuel Record No.",
                    "Date",
                    "Vehicle",
                    "Plate No.",
                    "Driver",
                    "Fuel Type",
                    "Quantity",
                    "Cost / L",
                    "Total Cost",
                    "Odometer",
                    "Fuel Station",
                    "Receipt / Ref.",
                ],
            ],
            body,
            startY: 52,
            theme: "grid",
            styles: {
                fontSize: 6.5,
                cellPadding: 1.5,
                overflow: "linebreak",
                valign: "top",
            },
            headStyles: {
                fillColor: [0, 168, 107],

                textColor: 255,

                fontStyle: "bold",

                fontSize: 6.5,
            },
            alternateRowStyles: {
                fillColor: [245, 247, 250],
            },
            columnStyles: {
                0: { cellWidth: 24 }, // Fuel Record No.
                1: { cellWidth: 17 }, // Date
                2: { cellWidth: 38 }, // Vehicle
                3: { cellWidth: 19 }, // Plate
                4: { cellWidth: 28 }, // Driver
                5: { cellWidth: 23 }, // Fuel Type
                6: { cellWidth: 17 }, // Quantity
                7: { cellWidth: 19 }, // Cost / L
                8: { cellWidth: 21 }, // Total Cost
                9: { cellWidth: 20 }, // Odometer
                10: { cellWidth: 30 }, // Fuel Station
                11: { cellWidth: 25 }, // Receipt / Ref.
            },
            margin: {
                top: 12,
                right: 8,
                bottom: 14,
                left: 8,
            },

            showHead: "everyPage",

            didDrawPage: () => {
                const pageSize = pdfDocument.internal.pageSize;
                const pageHeight = pageSize.height
                    ? pageSize.height
                    : pageSize.getHeight();
                const pageWidth = pageSize.width
                    ? pageSize.width
                    : pageSize.getWidth();
                const pageNumber = pdfDocument.internal.getNumberOfPages();
                pdfDocument.setFontSize(7);
                pdfDocument.setTextColor(100);
                pdfDocument.text(
                    `Page ${pageNumber}`,
                    pageWidth - 10,
                    pageHeight - 7,
                    {
                        align: "right",
                    },
                );
            },
        });

        pdfDocument.save(getFuelPdfFilename());

        showFuelExportToast("Fuel PDF exported successfully.", "success");
    } catch (error) {
        console.error("Fuel PDF export failed:", error);

        showFuelExportToast("Unable to export fuel report.", "error");
    }
}

function initFuelExcelExport() {
    const exportButton = document.getElementById("exportFuel");

    if (
        !exportButton ||
        exportButton.dataset.fuelExcelExportInitialized === "true"
    ) {
        return;
    }

    exportButton.dataset.fuelExcelExportInitialized = "true";
    exportButton.type = "button";
    exportButton.addEventListener("click", (event) => {
        event.preventDefault();
        exportFuelToExcel();
    });
}

function initFuelPDFExport() {
    const exportButton = document.getElementById("exportFuelPDF");

    if (
        !exportButton ||
        exportButton.dataset.fuelPdfExportInitialized === "true"
    ) {
        return;
    }

    exportButton.dataset.fuelPdfExportInitialized = "true";
    exportButton.type = "button";
    exportButton.addEventListener("click", (event) => {
        event.preventDefault();
        exportFuelToPdf();
    });
}

function initFuelExport() {
    initFuelExcelExport();
    initFuelPDFExport();
}

document.addEventListener("DOMContentLoaded", () => {
    initFuelExport();
});
