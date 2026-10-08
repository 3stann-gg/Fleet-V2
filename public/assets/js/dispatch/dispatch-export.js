let dispatchExportInitialized = false;

const DISPATCH_EXPORT_COLUMNS = [
    "Dispatch Number",
    "Reservation Number",
    "Patient Name",
    "Request Type",
    "Vehicle",
    "Driver",
    "Pickup",
    "Destination",
    "Schedule",
    "Priority",
    "Status",
    "Contact Number",
    "Notes",
];

function getExportDispatchRows(tableBody) {
    if (!tableBody) {
        return [];
    }

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

        /*
         * Export only rows matching the current filters.
         *
         * If the filter system has not yet initialized,
         * still allow the row to be exported.
         */
        return row.dataset.dispatchMatchesFilter !== "false";
    });
}

function readDispatchExportValue(row, selector) {
    const element = row.querySelector(selector);

    return element?.textContent?.trim() || "Not provided";
}

function readDispatchExportDataset(row, attribute) {
    const value = row.dataset[attribute];

    return value && value.trim() !== "" ? value.trim() : "Not provided";
}

function buildDispatchExportData(rows) {
    return rows.map((row) => {
        const dispatchNumber = readDispatchExportValue(row, ".dispatch-number");
        const reservationNumber = readDispatchExportValue(
            row,
            ".dispatch-reservation-number",
        );
        const patientName = readDispatchExportValue(
            row,
            ".dispatch-patient-name",
        );
        const requestType = readDispatchExportValue(
            row,
            ".dispatch-request-type",
        );
        const vehicle = readDispatchExportValue(row, ".dispatch-vehicle");
        const driver = readDispatchExportValue(row, ".dispatch-driver");
        const pickup = readDispatchExportDataset(row, "pickup");
        const destination = readDispatchExportDataset(row, "destination");
        const schedule =
            row.querySelector(".dispatch-schedule")?.textContent?.trim() ||
            "Not provided";
        const priority =
            row.querySelector(".dispatch-priority")?.textContent?.trim() ||
            readDispatchExportDataset(row, "priority");
        const status =
            row.querySelector(".status-badge")?.textContent?.trim() ||
            readDispatchExportDataset(row, "status");
        const contact = readDispatchExportDataset(row, "contact");
        const notes = readDispatchExportDataset(row, "notes");

        return {
            "Dispatch Number": dispatchNumber,
            "Reservation Number": reservationNumber,
            "Patient Name": patientName,
            "Request Type": requestType,
            Vehicle: vehicle,
            Driver: driver,
            Pickup: pickup,
            Destination: destination,
            Schedule: schedule,
            Priority: priority,
            Status: status,
            "Contact Number": contact,
            Notes: notes,
        };
    });
}

function exportDispatchesToExcel() {
    const tableBody = document.getElementById("dispatchTableBody");

    if (!tableBody) {
        return;
    }

    if (typeof XLSX === "undefined") {
        if (typeof showToast === "function") {
            showToast("Excel export library is unavailable.", "error");
        }

        return;
    }

    const rows = getExportDispatchRows(tableBody);

    /*
     * Prevent exporting an empty report.
     */
    if (rows.length === 0) {
        if (typeof showToast === "function") {
            showToast("There are no dispatch records to export.", "error");
        }

        return;
    }

    const data = buildDispatchExportData(rows);

    const worksheet = XLSX.utils.json_to_sheet(data, {
        header: DISPATCH_EXPORT_COLUMNS,
    });

    /*
     * Improve Excel column widths.
     */
    worksheet["!cols"] = [
        { wch: 18 }, // Dispatch Number
        { wch: 20 }, // Reservation Number
        { wch: 22 }, // Patient Name
        { wch: 18 }, // Request Type
        { wch: 25 }, // Vehicle
        { wch: 22 }, // Driver
        { wch: 25 }, // Pickup
        { wch: 25 }, // Destination
        { wch: 28 }, // Schedule
        { wch: 12 }, // Priority
        { wch: 15 }, // Status
        { wch: 18 }, // Contact Number
        { wch: 35 }, // Notes
    ];

    const workbook = XLSX.utils.book_new();

    XLSX.utils.book_append_sheet(workbook, worksheet, "Dispatches");

    XLSX.writeFile(workbook, "HIMS_Dispatch_Report.xlsx");

    if (typeof showToast === "function") {
        showToast("Dispatch report exported to Excel successfully.", "success");
    }
}

function exportDispatchesToPDF() {
    if (
        typeof window.jspdf === "undefined" ||
        typeof window.jspdf.jsPDF === "undefined"
    ) {
        if (typeof showToast === "function") {
            showToast("PDF export library is unavailable.", "error");
        }
        return;
    }

    const { jsPDF } = window.jspdf;

    const tableBody = document.getElementById("dispatchTableBody");

    if (!tableBody) {
        return;
    }

    const rows = getExportDispatchRows(tableBody);
    const data = buildDispatchExportData(rows);

    /*
     * PDF columns
     *
     * Notes is intentionally excluded from PDF.
     * Excel still uses DISPATCH_EXPORT_COLUMNS,
     * which includes Notes.
     */
    const PDF_COLUMNS = DISPATCH_EXPORT_COLUMNS.filter(
        (column) => column !== "Notes",
    );

    const doc = new jsPDF({
        orientation: "landscape",
        unit: "pt",
        format: "a4",
        ...(window.consumeSecurePdfEncryption?.() || {}),
    });

    doc.setFontSize(16);
    doc.text("Hospital Fleet Dispatch Report", 30, 40);

    doc.setFontSize(9);

    const generated = new Date().toLocaleString();
    doc.text("Generated: " + generated, 30, 58);

    doc.autoTable({
        startY: 75,

        head: [PDF_COLUMNS],

        body: data.map((row) => PDF_COLUMNS.map((column) => row[column])),

        margin: {
            left: 30,
            right: 30,
        },

        tableWidth: "auto",

        styles: {
            fontSize: 6.5,
            cellPadding: 2.5,
            overflow: "linebreak",
            valign: "middle",
        },

        headStyles: {
            fontSize: 6.5,
            fontStyle: "bold",
        },

        bodyStyles: {
            fontSize: 6.5,
        },

        theme: "grid",
    });

    doc.save("HIMS_Dispatch_Report.pdf");

    if (typeof showToast === "function") {
        showToast("Dispatch report exported to PDF successfully.", "success");
    }
}

let dispatchPDFInitialized = false;

function initDispatchPDFExport() {
    if (dispatchPDFInitialized) {
        return;
    }

    const pdfBtn = document.getElementById("exportDispatchPDF");

    if (!pdfBtn) {
        return;
    }

    dispatchPDFInitialized = true;

    pdfBtn.addEventListener("click", exportDispatchesToPDF);
}

function initDispatchExport() {
    if (dispatchExportInitialized) {
        return;
    }

    const exportBtn = document.getElementById("exportDispatches");

    if (exportBtn) {
        dispatchExportInitialized = true;

        exportBtn.addEventListener("click", exportDispatchesToExcel);
    }

    initDispatchPDFExport();
}

document.addEventListener("DOMContentLoaded", () => {
    initDispatchExport();
});
