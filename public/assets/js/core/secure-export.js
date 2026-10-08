(function () {
  "use strict";

  const exportTargets = {
    printVehicles: { context: "vehicles", format: "print" },
    exportPDF: { context: "vehicles", format: "pdf" },
    exportVehicles: { context: "vehicles", format: "excel" },
    printDrivers: { context: "drivers", format: "print" },
    exportDriverPDF: { context: "drivers", format: "pdf" },
    exportDrivers: { context: "drivers", format: "excel" },
    printDispatches: { context: "dispatch", format: "print" },
    exportDispatchPDF: { context: "dispatch", format: "pdf" },
    exportDispatches: { context: "dispatch", format: "excel" },
    printReservations: { context: "reservations", format: "print" },
    exportReservationPDF: { context: "reservations", format: "pdf" },
    exportReservations: { context: "reservations", format: "excel" },
    printMaintenance: { context: "maintenance", format: "print" },
    exportMaintenancePDF: { context: "maintenance", format: "pdf" },
    exportMaintenance: { context: "maintenance", format: "excel" },
    printFuel: { context: "fuel", format: "print" },
    exportFuelPDF: { context: "fuel", format: "pdf" },
    exportFuel: { context: "fuel", format: "excel" },
    printRoutes: { context: "route_planning", format: "print" },
    exportRoutesPDF: { context: "route_planning", format: "pdf" },
    exportRoutesExcel: { context: "route_planning", format: "excel" },
    printCostAnalysis: { context: "cost_analysis", format: "print" },
    exportCostAnalysisPDF: { context: "cost_analysis", format: "pdf" },
    exportCostAnalysisExcel: { context: "cost_analysis", format: "excel" },
    printReports: { context: "reports", format: "print" },
    exportReportsPDF: { context: "reports", format: "pdf" },
    exportReportsExcel: { context: "reports", format: "csv" },
  };

  const modal = document.getElementById("secureExportModal");
  const form = document.getElementById("secureExportForm");
  const formatInput = document.getElementById("secureExportFormat");
  const currentPasswordInput = document.getElementById(
    "secureExportCurrentPassword",
  );
  const protectedInput = document.getElementById("secureExportProtectedFile");
  const passwordFields = document.getElementById("secureExportPasswordFields");
  const filePasswordInput = document.getElementById("secureExportFilePassword");
  const filePasswordConfirmationInput = document.getElementById(
    "secureExportFilePasswordConfirmation",
  );
  const currentPasswordError = document.getElementById(
    "secureExportCurrentPasswordError",
  );
  const formError = document.getElementById("secureExportError");
  const submitButton = document.getElementById("confirmSecureExport");
  const protectionHint = document.getElementById("secureExportProtectionHint");
  const bypassOnce = new WeakSet();

  let pendingButton = null;
  let pendingExport = null;
  let confirmedUntil = 0;
  let pendingPdfPassword = null;

  window.consumeSecurePdfEncryption = function () {
    const password = pendingPdfPassword;
    pendingPdfPassword = null;

    return password
      ? { encryption: { userPassword: password } }
      : {};
  };

  if (window.SECURE_EXPORT_ALLOWED !== true) {
    document.querySelectorAll(".export-dropdown").forEach((dropdown) => {
      dropdown.hidden = true;
    });
  }

  function showMessage(message, type) {
    if (typeof window.showToast === "function") {
      window.showToast(message, type);
    }
  }

  function clearPasswords() {
    if (currentPasswordInput) currentPasswordInput.value = "";
    if (filePasswordInput) filePasswordInput.value = "";
    if (filePasswordConfirmationInput) filePasswordConfirmationInput.value = "";
  }

  function clearErrors() {
    if (currentPasswordError) currentPasswordError.textContent = "";
    if (formError) formError.textContent = "";
  }

  function closeModal() {
    modal?.classList.remove("show");
    clearPasswords();
    clearErrors();
    pendingButton = null;
    pendingExport = null;
  }

  function openModal(button, exportDefinition) {
    pendingButton = button;
    pendingExport = exportDefinition;
    clearPasswords();
    clearErrors();
    protectedInput.checked = false;
    passwordFields.hidden = true;
    formatInput.value = exportDefinition.format.toUpperCase();
    if (protectionHint) {
      protectionHint.textContent =
        exportDefinition.format === "pdf"
          ? "PDF protection uses the separate export file password."
          : "Protected CSV/Excel requires encrypted ZIP support, which is unavailable on this server.";
    }
    modal?.classList.add("show");
    window.setTimeout(() => currentPasswordInput?.focus(), 0);
  }

  function valueOrNull(id) {
    const value = document.getElementById(id)?.value;
    return value && value !== "all" ? value : null;
  }

  function firstValue(ids) {
    for (const id of ids) {
      const value = valueOrNull(id);
      if (value !== null) return value;
    }

    return null;
  }

  function collectFilters() {
    const dateRange = firstValue(["reportDateRange", "costDateRange"]);

    return {
      date_range: dateRange,
      start_date:
        dateRange === "custom"
          ? firstValue(["reportStartDate", "costStartDate"])
          : null,
      end_date:
        dateRange === "custom"
          ? firstValue(["reportEndDate", "costEndDate"])
          : null,
      date: firstValue([
        "dispatchDateFilter", "reservationDateFilter", "maintenanceDateFilter",
        "fuelDateFilter", "routeDateFilter",
      ]),
      vehicle_id: firstValue([
        "reportVehicleFilter", "fuelVehicleFilter", "routeVehicleFilter",
        "costVehicleFilter", "chargingVehicleFilter",
      ]),
      vehicle_type: firstValue(["vehicleTypeFilter", "costVehicleTypeFilter"]),
      powertrain: firstValue(["costPowertrainFilter", "chargingPowertrainFilter"]),
      department: firstValue(["reportDepartmentFilter", "routeDepartmentFilter", "costDepartmentFilter"]),
      driver_id: firstValue(["reportDriverFilter", "routeDriverFilter"]),
      status: firstValue([
        "vehicleStatusFilter", "driverStatusFilter", "dispatchStatusFilter",
        "reservationStatusFilter", "maintenanceStatusFilter", "routeStatusFilter",
      ]),
      priority: firstValue(["dispatchPriorityFilter", "reservationPriorityFilter", "routePriorityFilter"]),
    };
  }

  function activeReportType() {
    if (typeof window.getActiveReportOutputModel !== "function") {
      return null;
    }

    return window.getActiveReportOutputModel()?.reportType || "overview";
  }

  async function requestAuthorization(exportDefinition, passwordData = {}) {
    const csrfToken = document
      .querySelector('meta[name="csrf-token"]')
      ?.getAttribute("content");
    const response = await fetch(window.SECURE_EXPORT_AUTHORIZE_URL, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-CSRF-TOKEN": csrfToken,
      },
      body: JSON.stringify({
        context: exportDefinition.context,
        format: exportDefinition.format,
        report_type:
          exportDefinition.context === "reports" ? activeReportType() : null,
        filters: collectFilters(),
        protected_file: passwordData.protectedFile === true,
        current_password: passwordData.currentPassword || null,
        export_password: passwordData.exportPassword || null,
        export_password_confirmation:
          passwordData.exportPasswordConfirmation || null,
      }),
    });
    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
      const error = new Error(payload.message || "Unable to authorize this export.");
      error.status = response.status;
      error.validationErrors = payload.errors || {};
      throw error;
    }

    confirmedUntil = Date.now() + Number(payload.expires_in || 0) * 1000;
    return payload;
  }

  async function requestCsvDownload(exportDefinition, passwordData = {}) {
    const csrfToken = document
      .querySelector('meta[name="csrf-token"]')
      ?.getAttribute("content");
    const response = await fetch(window.SECURE_EXPORT_CSV_URL, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        Accept: "text/csv, application/json",
        "X-CSRF-TOKEN": csrfToken,
      },
      body: JSON.stringify({
        context: exportDefinition.context,
        format: exportDefinition.format,
        report_type: activeReportType(),
        filters: collectFilters(),
        protected_file: passwordData.protectedFile === true,
        current_password: passwordData.currentPassword || null,
        export_password: passwordData.exportPassword || null,
        export_password_confirmation:
          passwordData.exportPasswordConfirmation || null,
      }),
    });

    if (!response.ok) {
      const payload = await response.json().catch(() => ({}));
      const error = new Error(payload.message || "Unable to export this report.");
      error.status = response.status;
      error.validationErrors = payload.errors || {};
      throw error;
    }

    confirmedUntil =
      Date.now() +
      Number(response.headers.get("X-Export-Confirmation-Expires-In") || 0) * 1000;

    const blob = await response.blob();
    const disposition = response.headers.get("Content-Disposition") || "";
    const filenameMatch = disposition.match(/filename="?([^";]+)"?/i);
    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement("a");

    link.href = objectUrl;
    link.download = filenameMatch?.[1] || "fleet_report.csv";
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);
  }

  function continueExport(button) {
    bypassOnce.add(button);
    button.click();
  }

  document.addEventListener(
    "click",
    async (event) => {
      const button = event.target.closest("button[id]");
      const exportDefinition = button ? exportTargets[button.id] : null;

      if (!button || !exportDefinition) return;

      if (bypassOnce.has(button)) {
        bypassOnce.delete(button);
        return;
      }

      event.preventDefault();
      event.stopImmediatePropagation();

      if (Date.now() >= confirmedUntil) {
        openModal(button, exportDefinition);
        return;
      }

      try {
        if (exportDefinition.context === "reports" && exportDefinition.format === "csv") {
          await requestCsvDownload(exportDefinition);
          showMessage("Report exported to CSV successfully.", "success");
        } else {
          await requestAuthorization(exportDefinition);
          continueExport(button);
        }
      } catch (error) {
        confirmedUntil = 0;

        if (error.status === 422) {
          openModal(button, exportDefinition);
          return;
        }

        showMessage(
          error.status === 403
            ? "You are not authorized to export this report."
            : "Unable to authorize this export.",
          "error",
        );
      }
    },
    true,
  );

  protectedInput?.addEventListener("change", () => {
    passwordFields.hidden = !protectedInput.checked;
    filePasswordInput.required = protectedInput.checked;
    filePasswordConfirmationInput.required = protectedInput.checked;
    clearErrors();
  });

  form?.addEventListener("submit", async (event) => {
    event.preventDefault();

    if (!pendingButton || !pendingExport || submitButton.disabled) return;

    clearErrors();
    submitButton.disabled = true;

    try {
      const passwordData = {
        currentPassword: currentPasswordInput.value,
        protectedFile: protectedInput.checked,
        exportPassword: filePasswordInput.value,
        exportPasswordConfirmation: filePasswordConfirmationInput.value,
      };

      if (pendingExport.context === "reports" && pendingExport.format === "csv") {
        await requestCsvDownload(pendingExport, passwordData);
      } else {
        await requestAuthorization(pendingExport, passwordData);
      }

      if (pendingExport.format === "pdf" && passwordData.protectedFile) {
        pendingPdfPassword = passwordData.exportPassword;
        window.setTimeout(() => {
          pendingPdfPassword = null;
        }, 5000);
      }

      const button = pendingButton;
      const wasServerCsv =
        pendingExport.context === "reports" && pendingExport.format === "csv";
      closeModal();
      if (wasServerCsv) {
        showMessage("Report exported to CSV successfully.", "success");
      } else {
        continueExport(button);
      }
    } catch (error) {
      const passwordMessage = error.validationErrors?.current_password?.[0];
      const otherMessage =
        error.validationErrors?.export_password?.[0] ||
        error.validationErrors?.protected_file?.[0] ||
        error.message;

      if (currentPasswordError) {
        currentPasswordError.textContent = passwordMessage || "";
      }
      if (formError) {
        formError.textContent = passwordMessage ? "" : otherMessage;
      }
      clearPasswords();
      currentPasswordInput?.focus();
    } finally {
      submitButton.disabled = false;
    }
  });

  document.getElementById("closeSecureExportModal")?.addEventListener("click", closeModal);
  document.getElementById("cancelSecureExport")?.addEventListener("click", closeModal);
  modal?.addEventListener("click", (event) => {
    if (event.target === modal) closeModal();
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && modal?.classList.contains("show")) closeModal();
  });
})();
