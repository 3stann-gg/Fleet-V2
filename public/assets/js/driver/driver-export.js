/* ==========================================
   Driver Export Reports
========================================== */

function getDriverReportRows() {
  const tableBody = document.getElementById("driverTableBody");

  if (!tableBody) return [];

  const rows =
    typeof getDriverDataRows === "function"
      ? getDriverDataRows(tableBody)
      : Array.from(tableBody.querySelectorAll("tr")).filter((row) => {
          const isHelperRow =
            row.classList.contains("driver-no-results") ||
            row.classList.contains("helper-row") ||
            row.classList.contains("empty-state") ||
            row.dataset.helperRow === "true";

          return (
            !isHelperRow &&
            Boolean(
              row.querySelector(".driver-name") ||
                row.querySelector(".driver-checkbox"),
            )
          );
        });

  return rows.filter((row) => row.dataset.driverMatchesFilter !== "false");
}

function getDriverReportText(row, columnIndex, selector) {
  const selectedElement = selector ? row.querySelector(selector) : null;
  const cell = row.children[columnIndex];
  const value = selectedElement ? selectedElement.textContent : cell?.textContent;

  return value ? value.trim() : "";
}

function getDriverReportOptionalValue(row, key) {
  const value = row.dataset[key];

  return value && value.trim() ? value.trim() : "Not provided";
}

function getDriverReportData(row) {
    return {
        name: `${row.dataset.firstName} ${row.dataset.lastName}`.trim(),
        employeeId: "DRV-" + String(row.dataset.id).padStart(3, "0"),
        licenseNumber: row.dataset.licenseNumber,
        licenseClass: row.dataset.licenseClass,
        assignedVehicle: row.dataset.vehicle?.trim() || "Not provided",
        status: row.dataset.status,
        contact: row.dataset.contactNumber,
        licenseExpiry: getDriverReportOptionalValue(row, "licenseExpiry"),
        email: getDriverReportOptionalValue(row, "email"),
        experience: getDriverReportOptionalValue(row, "experience"),
        address: getDriverReportOptionalValue(row, "address"),
        emergencyContact: getDriverReportOptionalValue(row, "emergencyContact"),
        notes: getDriverReportOptionalValue(row, "notes"),
    };
}

function showDriverReportToast(message, type) {
  if (
    typeof window !== "undefined" &&
    typeof window.showToast === "function"
  ) {
    window.showToast(message, type);
  }
}

function initDriverExport() {
  const exportButton = document.getElementById("exportDrivers");

  if (!exportButton || exportButton.dataset.driverExportInitialized === "true") {
    return;
  }

  exportButton.dataset.driverExportInitialized = "true";

  exportButton.addEventListener("click", () => {
    const xlsx = window.XLSX;

    if (!xlsx?.utils) {
      showDriverReportToast("Excel export is unavailable.", "error");
      return;
    }

    const data = [
      [
        "Driver Name",
        "Employee ID",
        "License Number",
        "License Class",
        "Assigned Vehicle",
        "Status",
        "Contact",
        "License Expiry",
        "Email",
        "Experience",
        "Address",
        "Emergency Contact",
        "Notes",
      ],
      ...getDriverReportRows().map((row) => {
        const driver = getDriverReportData(row);

        return [
          driver.name,
          driver.employeeId,
          driver.licenseNumber,
          driver.licenseClass,
          driver.assignedVehicle,
          driver.status,
          driver.contact,
          driver.licenseExpiry,
          driver.email,
          driver.experience,
          driver.address,
          driver.emergencyContact,
          driver.notes,
        ];
      }),
    ];

    try {
      const workbook = xlsx.utils.book_new();
      const worksheet = xlsx.utils.aoa_to_sheet(data);

      worksheet["!cols"] = [
          { wch: 22 }, // Driver Name
          { wch: 14 }, // Employee ID
          { wch: 18 }, // License Number
          { wch: 15 }, // License Class
          { wch: 25 }, // Assigned Vehicle
          { wch: 14 }, // Status
          { wch: 16 }, // Contact
          { wch: 16 }, // License Expiry
          { wch: 28 }, // Email
          { wch: 14 }, // Experience
          { wch: 30 }, // Address
          { wch: 24 }, // Emergency Contact
          { wch: 30 }, // Notes
      ];

      xlsx.utils.book_append_sheet(workbook, worksheet, "Drivers");
      xlsx.writeFile(workbook, "HIMS_Driver_Report.xlsx");
      showDriverReportToast(
        "Driver report exported to Excel successfully.",
        "success",
      );
    } catch {
      showDriverReportToast(
        "Unable to export the Driver report to Excel.",
        "error",
      );
    }
  });
}

function initDriverPDFExport() {
  const exportButton = document.getElementById("exportDriverPDF");

  if (
    !exportButton ||
    exportButton.dataset.driverPdfExportInitialized === "true"
  ) {
    return;
  }

  exportButton.dataset.driverPdfExportInitialized = "true";

  exportButton.addEventListener("click", () => {
    const jsPDF = window.jspdf?.jsPDF;

    if (!jsPDF) {
      showDriverReportToast("PDF export is unavailable.", "error");
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
        showDriverReportToast("PDF export is unavailable.", "error");
        return;
      }

      pdfDocument.setFontSize(16);
      pdfDocument.setTextColor(0, 168, 107);
      pdfDocument.text("Hospital Information Management System", 14, 16);
      pdfDocument.setFontSize(11);
      pdfDocument.setTextColor(0, 0, 0);
      pdfDocument.text("Fleet & Transportation Management", 14, 23);
      pdfDocument.setFontSize(14);
      pdfDocument.text("Driver Report", 14, 31);
      pdfDocument.setFontSize(9);
      pdfDocument.text(`Generated: ${new Date().toLocaleString()}`, 14, 38);

      const rows = getDriverReportRows().map((row) => {
        const driver = getDriverReportData(row);

        return [
          driver.name,
          driver.employeeId,
          driver.licenseNumber,
          driver.licenseClass,
          driver.assignedVehicle,
          driver.status,
          driver.contact,
        ];
      });

      pdfDocument.autoTable({
        head: [
          [
            "Driver",
            "Employee ID",
            "License No.",
            "License Class",
            "Assigned Vehicle",
            "Status",
            "Contact",
          ],
        ],
        body: rows,
        startY: 44,
        headStyles: {
          fillColor: [0, 168, 107],
          textColor: 255,
        },
        styles: {
          fontSize: 8,
        },
      });

      pdfDocument.save("HIMS_Driver_Report.pdf");
      showDriverReportToast(
        "Driver report exported to PDF successfully.",
        "success",
      );
    } catch {
      showDriverReportToast(
        "Unable to export the Driver report to PDF.",
        "error",
      );
    }
  });
}

document.addEventListener("DOMContentLoaded", () => {
    initDriverExport();
    initDriverPDFExport();
    initDriverPrint();
});
