/* ==========================================
   Vehicle Export Reports
========================================== */

function getVehicleReportRows() {
    const tableBody = document.getElementById("vehicleTableBody");

    if (!tableBody) {
        return [];
    }

    const rows =
        typeof getVehicleDataRows === "function"
            ? getVehicleDataRows(tableBody)
            : Array.from(tableBody.querySelectorAll("tr")).filter((row) => {
                  return Boolean(
                      row.querySelector(".vehicle-name") ||
                      row.querySelector(".vehicle-checkbox"),
                  );
              });

    return rows.filter((row) => row.dataset.vehicleMatchesFilter !== "false");
}


function getVehicleReportFuelLevel(row) {
    const currentFuel = Number(row.dataset.currentFuel);

    const tankCapacity = Number(row.dataset.tankCapacity);

    if (
        Number.isNaN(currentFuel) ||
        Number.isNaN(tankCapacity) ||
        tankCapacity <= 0
    ) {
        return "N/A";
    }

    const percentage = (currentFuel / tankCapacity) * 100;

    const safePercentage = Math.max(0, Math.min(100, percentage));

    return `${safePercentage.toFixed(0)}%`;
}


function formatVehicleReportLiters(value) {
    if (value === null || value === undefined || value === "") {
        return "Not provided";
    }

    const number = Number(value);

    if (Number.isNaN(number)) {
        return "Not provided";
    }

    return `${number.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })} L`;
}

function formatVehicleReportMileage(value) {
    if (value === null || value === undefined || value === "") {
        return "Not provided";
    }

    const number = Number(value);

    if (Number.isNaN(number)) {
        return "Not provided";
    }

    return `${number.toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    })} km`;
}

function formatVehicleReportDate(value) {
    if (!value) {
        return "Not provided";
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return "Not provided";
    }

    return parsed.toLocaleDateString(undefined, {
        year: "numeric",
        month: "short",
        day: "numeric",
    });
}


function getVehicleReportData(row) {
    const brand = row.dataset.brand?.trim() || "";

    const model = row.dataset.model?.trim() || "";

    const vehicleName =
        [brand, model].filter(Boolean).join(" ") || "Not provided";

    const currentFuel = row.dataset.currentFuel ?? "";

    const tankCapacity = row.dataset.tankCapacity ?? "";

    return {
        vehicle: vehicleName,
        plate: row.dataset.plateNumber?.trim() || "Not provided",
        type: row.dataset.vehicleType?.trim() || "Not provided",
        driver: row.dataset.driverName?.trim() || "Not Assigned",
        status: row.dataset.status?.trim() || "Not provided",
        fuelType: row.dataset.fuelType?.trim() || "Not provided",
        tankCapacity: formatVehicleReportLiters(tankCapacity),
        currentFuel: formatVehicleReportLiters(currentFuel),
        fuelLevel: getVehicleReportFuelLevel(row),
        currentMileage: formatVehicleReportMileage(row.dataset.currentOdometer),
        capacity: row.dataset.capacity?.trim() || "Not provided",
        purchaseDate: formatVehicleReportDate(row.dataset.purchaseDate),
        insuranceExpiry: formatVehicleReportDate(row.dataset.insuranceExpiry),
        lastService: row.dataset.lastService?.trim() || "Not provided",
        notes: row.dataset.notes?.trim() || "Not provided",
    };
}

function showVehicleReportToast(message, type) {
    if (typeof window.showToast === "function") {
        window.showToast(message, type);
    }
}

/* ==========================================
   Excel Export - COMPLETE DATA
========================================== */

function initVehicleExport() {
    const exportButton = document.getElementById("exportVehicles");

    if (
        !exportButton ||
        exportButton.dataset.vehicleExportInitialized === "true"
    ) {
        return;
    }

    exportButton.dataset.vehicleExportInitialized = "true";
    exportButton.type = "button";
    exportButton.addEventListener("click", (event) => {
        event.preventDefault();

        const xlsx = window.XLSX;

        if (!xlsx?.utils) {
            showVehicleReportToast("Excel export is unavailable.", "error");
            return;
        }

        const rows = getVehicleReportRows();

        if (rows.length === 0) {
            showVehicleReportToast(
                "There are no vehicles to export.",
                "warning",
            );
            return;
        }

        const headers = [
            "Vehicle",
            "Plate Number",
            "Vehicle Type",
            "Driver",
            "Status",
            "Fuel Type",
            "Tank Capacity",
            "Current Fuel",
            "Fuel Level",
            "Current Mileage",
            "Capacity",
            "Purchase Date",
            "Insurance Expiry",
            "Last Service",
            "Notes",
        ];

        const data = rows.map((row) => {
            const vehicle = getVehicleReportData(row);

            return [
                vehicle.vehicle,
                vehicle.plate,
                vehicle.type,
                vehicle.driver,
                vehicle.status,
                vehicle.fuelType,
                vehicle.tankCapacity,
                vehicle.currentFuel,
                vehicle.fuelLevel,
                vehicle.currentMileage,
                vehicle.capacity,
                vehicle.purchaseDate,
                vehicle.insuranceExpiry,
                vehicle.lastService,
                vehicle.notes,
            ];
        });

        try {
            const workbook = xlsx.utils.book_new();

            const worksheet = xlsx.utils.aoa_to_sheet([headers, ...data]);

            worksheet["!cols"] = [
                { wch: 24 }, // Vehicle
                { wch: 18 }, // Plate
                { wch: 18 }, // Type
                { wch: 22 }, // Driver
                { wch: 16 }, // Status
                { wch: 18 }, // Fuel Type
                { wch: 18 }, // Tank Capacity
                { wch: 18 }, // Current Fuel
                { wch: 14 }, // Fuel Level
                { wch: 20 }, // Mileage
                { wch: 12 }, // Capacity
                { wch: 18 }, // Purchase Date
                { wch: 20 }, // Insurance
                { wch: 18 }, // Last Service
                { wch: 40 }, // Notes
            ];

            xlsx.utils.book_append_sheet(workbook, worksheet, "Fleet Vehicles");

            xlsx.writeFile(workbook, "HIMS_Vehicle_Report.xlsx");

            showVehicleReportToast(
                "Vehicle report exported to Excel successfully.",
                "success",
            );
        } catch (error) {
            console.error("VEHICLE EXCEL EXPORT ERROR:", error);

            showVehicleReportToast(
                "Unable to export vehicles to Excel.",
                "error",
            );
        }
    });
}

function initVehiclePDFExport() {
    const exportButton = document.getElementById("exportPDF");

    if (
        !exportButton ||
        exportButton.dataset.vehiclePdfExportInitialized === "true"
    ) {
        return;
    }

    exportButton.dataset.vehiclePdfExportInitialized = "true";
    exportButton.type = "button";
    exportButton.addEventListener("click", (event) => {
        event.preventDefault();

        const jsPDF = window.jspdf?.jsPDF;

        if (!jsPDF) {
            showVehicleReportToast("PDF export is unavailable.", "error");
            return;
        }

        const rows = getVehicleReportRows();

        if (rows.length === 0) {
            showVehicleReportToast(
                "There are no vehicles to export.",
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
                showVehicleReportToast("PDF export is unavailable.", "error");
                return;
            }

            pdfDocument.setFontSize(16);
            pdfDocument.setTextColor(0, 168, 107);
            pdfDocument.text("Hospital Information Management System", 14, 15);
            pdfDocument.setFontSize(11);
            pdfDocument.setTextColor(0, 0, 0);
            pdfDocument.text("Fleet & Transportation Management", 14, 22);
            pdfDocument.setFontSize(14);
            pdfDocument.text("Vehicle Report", 14, 29);
            pdfDocument.setFontSize(9);
            pdfDocument.text(
                `Generated: ${new Date().toLocaleString()}`,
                14,
                36,
            );

            const body = rows.map((row) => {
                const vehicle = getVehicleReportData(row);

                return [
                    vehicle.vehicle,
                    vehicle.plate,
                    vehicle.type,
                    vehicle.driver,
                    vehicle.status,
                    vehicle.fuelType,
                    vehicle.tankCapacity,
                    vehicle.fuelLevel,
                    vehicle.lastService,
                    vehicle.notes,
                ];
            });

            pdfDocument.autoTable({
                head: [
                    [
                        "Vehicle",
                        "Plate Number",
                        "Vehicle Type",
                        "Driver",
                        "Status",
                        "Fuel Type",
                        "Tank Capacity",
                        "Fuel Level",
                        "Last Service",
                        "Notes",
                    ],
                ],

                body,

                startY: 42,

                theme: "grid",

                styles: {
                    fontSize: 6.5,
                    cellPadding: 2,
                    overflow: "linebreak",
                    valign: "top",
                },

                headStyles: {
                    fillColor: [41, 128, 185],
                    textColor: 255,
                    fontStyle: "bold",
                    fontSize: 6.5,
                },

                columnStyles: {
                    0: { cellWidth: 30 }, // Vehicle
                    1: { cellWidth: 23 }, // Plate
                    2: { cellWidth: 24 }, // Type
                    3: { cellWidth: 29 }, // Driver
                    4: { cellWidth: 19 }, // Status
                    5: { cellWidth: 23 }, // Fuel Type
                    6: { cellWidth: 22 }, // Tank
                    7: {
                        cellWidth: 18,
                        halign: "center",
                    }, // Fuel %
                    8: { cellWidth: 24 }, // Service
                    9: { cellWidth: 50 }, // Notes
                },

                margin: {
                    left: 10,
                    right: 10,
                },

                showHead: "everyPage",
            });

            pdfDocument.save("HIMS_Vehicle_Report.pdf");

            showVehicleReportToast(
                "Vehicle report exported to PDF successfully.",
                "success",
            );
        } catch (error) {
            console.error("VEHICLE PDF EXPORT ERROR:", error);

            showVehicleReportToast(
                "Unable to export the Vehicle report to PDF.",
                "error",
            );
        }
    });
}

document.addEventListener("DOMContentLoaded", () => {
    initVehicleExport();
    initVehiclePDFExport();
});
