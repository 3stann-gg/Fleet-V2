/* ==========================================
Reservation Export
========================================== */

let reservationExportInitialized = false;

function getReservationExportRows() {
  const tableBody = document.getElementById("reservationTableBody");
    if (!tableBody) {
        return [];
    }

    return Array.from(
        tableBody.querySelectorAll("tr")
    ).filter((row) => {
      const isHelperRow =
        row.classList.contains("reservation-no-results") ||
        row.classList.contains("helper-row") ||
        row.classList.contains("empty-state") ||
        row.dataset.helperRow === "true";

      if (isHelperRow) {
        return false;
      }

      const isRealRow =
        row.querySelector(".reservation-number") ||
        row.querySelector(".reservation-checkbox");

      if (!isRealRow) {
            return false;
      }


      if (
        row.dataset.reservationMatchesFilter === "false"
      ) {
        return false;
      }

        return true;
    });
}

function getReservationExportText(row, selector) {
  const element = row.querySelector(selector);

  return element
    ? element.textContent.trim()
    : "";
}

function getReservationExportData(row, key) {
  const value = row.dataset[key];

  return value && value.trim()
    ? value.trim()
    : "Not provided";
}

function initReservationExport() {
  if (reservationExportInitialized) {
    return;
  }

  const exportButton = document.getElementById("exportReservations");

  if (!exportButton) {
    return;
  }

  reservationExportInitialized = true;
  exportButton.addEventListener("click", () => {
    try {
      if (typeof XLSX === "undefined" || !XLSX.utils) {
        if (typeof window.showToast === "function") {
          window.showToast(
            "Excel export library is unavailable.",
            "error"
          );
        }

        return;
      }

      const headers = [
        "Reservation Number",
        "Patient Name",
        "Request Type",
        "Vehicle",
        "Driver",
        "Pickup Location",
        "Destination",
        "Schedule",
        "Priority",
        "Status",
        "Contact Number",
        "Notes",
      ];

      const data = [];
      const rows = getReservationExportRows();

      rows.forEach((row) => {
        data.push([
          getReservationExportText(row, ".reservation-number"),
          getReservationExportText(row, ".patient-name"),
          getReservationExportData(row, "requestType"),
          getReservationExportText(row, ".reservation-vehicle"),
          getReservationExportText(row, ".reservation-driver"),
          getReservationExportText(row, ".reservation-pickup"),
          getReservationExportText(row, ".reservation-destination"),
          getReservationExportText(row, ".reservation-schedule") || "Not provided",
          getReservationExportData(row, "priority"),
          getReservationExportText(row, ".status-badge") || "Not provided",
          getReservationExportData(row, "contactNumber"),
          getReservationExportData(row, "notes"),
        ]);
      });

      if (data.length === 0) {
        if (typeof window.showToast === "function") {
          window.showToast(
            "There are no reservations to export.",
            "error"
          );
        }

        return;
      }

      const worksheet = XLSX.utils.aoa_to_sheet([
        headers,
        ...data
      ]);

      worksheet["!cols"] = [
          { wch: 20 }, // Reservation Number
          { wch: 24 }, // Patient Name
          { wch: 18 }, // Request Type
          { wch: 25 }, // Vehicle
          { wch: 22 }, // Driver
          { wch: 28 }, // Pickup Location
          { wch: 28 }, // Destination
          { wch: 24 }, // Schedule
          { wch: 14 }, // Priority
          { wch: 14 }, // Status
          { wch: 18 }, // Contact Number
          { wch: 30 }, // Notes
      ];

      const workbook = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(
        workbook,
        worksheet,
        "Reservations"
      );

      XLSX.writeFile(
        workbook,
        "HIMS_Reservation_Report.xlsx"
      );

      if (typeof window.showToast === "function") {
        window.showToast(
          "Reservation report exported to Excel successfully.",
          "success"
        );
       }

      } catch (error) {
        console.error(
          "RESERVATION EXCEL EXPORT ERROR:",
          error
        );
        
        if (typeof window.showToast === "function") {
        window.showToast(
          "Failed to export reservation report.",
          "error"
          );
        }
      }
    });
}

let reservationPDFExportInitialized = false;

function initReservationPDFExport() {
  if (reservationPDFExportInitialized) {
      return;
  }

  const pdfButton = document.getElementById("exportReservationPDF");
    if (!pdfButton) {
        return;
    }

  reservationPDFExportInitialized = true;
  pdfButton.addEventListener("click", () => {

    try {
      if (
        typeof window.jspdf === "undefined" ||
        typeof window.jspdf.jsPDF === "undefined"
      ) {
        if (typeof window.showToast === "function") {
          window.showToast(
             "PDF export library is unavailable.",
              "error"
           );
        }

        return;
      }

      const { jsPDF } = window.jspdf;
      const doc = new jsPDF({
        orientation: "landscape",
        ...(window.consumeSecurePdfEncryption?.() || {}),
      });

      if (typeof doc.autoTable !== "function") {
        if (typeof window.showToast === "function") {
          window.showToast(
            "PDF table export library is unavailable.",
            "error"
          );
        }

        return;
      }

      const headers = [
        "Reservation No.",
        "Patient",
        "Request Type",
        "Vehicle",
        "Driver",
        "Pickup",
        "Destination",
        "Schedule",
        "Priority",
        "Status",
      ];

      const data = [];
      const rows = getReservationExportRows();

      rows.forEach((row) => {
        data.push([
          getReservationExportText(row, ".reservation-number"),
          getReservationExportText(row, ".patient-name"),
          getReservationExportData(row, "requestType"),
          getReservationExportText(row, ".reservation-vehicle"),
          getReservationExportText(row, ".reservation-driver"),
          getReservationExportText(row, ".reservation-pickup"),
          getReservationExportText(row, ".reservation-destination"),
          getReservationExportText(row, ".reservation-schedule") || "Not provided",
          getReservationExportData(row, "priority"),
          getReservationExportText(row, ".status-badge") || "Not provided",
        ]);
      });

      if (data.length === 0) {
        if (typeof window.showToast === "function") {
          window.showToast(
            "There are no reservations to export.",
            "error"
          );
        }

        return;
       }

      const generatedDate = new Date().toLocaleString();
        doc.setFontSize(16);
        doc.text("Hospital Information Management System",
          14,
          15
        );
        doc.setFontSize(12);
        doc.text("Fleet & Transportation Management",
          14,
          22
        );
        doc.text("Reservation Report",
          14,
          29
        );
        doc.setFontSize(9);
        doc.text("Generated: " + generatedDate,
          14,
          36
        );
        doc.autoTable({
          head: [headers],
          body: data,
          startY: 42,
          theme: "grid",
          styles: {fontSize: 7,},
          headStyles: {fillColor: [41, 128, 185],},
        });

        doc.save(
          "HIMS_Reservation_Report.pdf"
        );

        if (typeof window.showToast === "function") {
          window.showToast(
            "Reservation report exported to PDF successfully.",
            "success"
          );
        }

        } catch (error) {
            console.error(
              "RESERVATION PDF EXPORT ERROR:", error
            );

            if (typeof window.showToast === "function") {
                window.showToast(
                  "Failed to export reservation report.", "error"
                );
            }
        }
    });
}


if (document.readyState === "loading") {
    document.addEventListener(
        "DOMContentLoaded",
        initReservationExport
    );
    document.addEventListener(
        "DOMContentLoaded",
        initReservationPDFExport
    );

} else {
    initReservationExport();
    initReservationPDFExport();
}
