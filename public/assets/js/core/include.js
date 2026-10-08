async function loadComponent(id, file) {
  const target = document.getElementById(id);

  if (!target) {
    return;
  }

  try {
    const response = await fetch(file);

    if (!response.ok) {
      console.error(`Cannot load ${file}`);
      return;
    }

    target.innerHTML = await response.text();
  } catch {
    console.error(`Cannot load ${file}`);
  }
}

function loadSharedScriptOnce(src) {
  return new Promise((resolve) => {
    if (document.querySelector('script[data-shared-src="' + src + '"]')) {
      resolve();
      return;
    }
    const existing = Array.from(document.scripts).some((s) =>
      (s.getAttribute("src") || "").includes(src.replace("../", "")),
    );
    if (existing) {
      resolve();
      return;
    }
    const script = document.createElement("script");
    script.src = src;
    script.async = false;
    script.dataset.sharedSrc = src;
    script.onload = () => resolve();
    script.onerror = () => resolve();
    document.head.appendChild(script);
  });
}

document.addEventListener("DOMContentLoaded", async () => {
  /* Shared UX scripts for all pages (idempotent) */
  await loadSharedScriptOnce("../assets/js/core/auth.js");
  if (typeof requireAuth === "function" && !requireAuth()) {
    return;
  }

  await loadSharedScriptOnce("../assets/js/core/pending-action.js");
  await loadSharedScriptOnce("../assets/js/core/user-profile.js");
  await loadSharedScriptOnce("../assets/js/components/navbar.js");

  await loadComponent("sidebar", "../components/shared/sidebar.html");

  if (typeof initializePage === "function") {
    initializePage();
  }

  if (typeof syncUserProfileUI === "function") {
    syncUserProfileUI();
  }

  if (typeof initSidebarProfileDropdown === "function") {
    initSidebarProfileDropdown();
  }

  if (typeof initThemeControls === "function") {
    initThemeControls();
  }

  /* Desktop edge toggle lives in the sidebar component */
  if (typeof initDesktopSidebarCollapse === "function") {
    initDesktopSidebarCollapse();
  }

  /* Collapsed-icon tooltips (floating layer; not clipped by nav overflow) */
  if (typeof initSidebarCollapsedTooltips === "function") {
    initSidebarCollapsedTooltips();
  }

  /* List-card Export dropdown (Print / PDF / Excel) */
  if (typeof initExportDropdowns === "function") {
    initExportDropdowns();
  }

  await loadComponent("navbar", "../components/shared/navbar.html");

  if (typeof initResponsiveNavigation === "function") {
    initResponsiveNavigation();
  }

  if (typeof initNavbarInteractions === "function") {
    initNavbarInteractions();
  }

  if (typeof initDashboardPage === "function") {
    initDashboardPage();
  }

  if (document.getElementById("vehicle-modal")) {
    await loadComponent("vehicle-modal", "../components/vehicle/add-vehicle-modal.html");
  }

  if (document.getElementById("view-vehicle-modal")) {
    await loadComponent(
      "view-vehicle-modal",
      "../components/vehicle/view-vehicle-modal.html",
    );
  }

  if (document.getElementById("edit-vehicle-modal")) {
    await loadComponent(
      "edit-vehicle-modal",
      "../components/vehicle/edit-vehicle-modal.html",
    );
  }

  if (document.getElementById("delete-vehicle-modal")) {
    await loadComponent(
      "delete-vehicle-modal",
      "../components/vehicle/delete-vehicle-modal.html",
    );
  }

  if (document.getElementById("add-driver-modal")) {
    await loadComponent(
      "add-driver-modal",
      "../components/driver/add-driver-modal.html",
    );
  }

  if (document.getElementById("view-driver-modal")) {
    await loadComponent(
      "view-driver-modal",
      "../components/driver/view-driver-modal.html",
    );
  }

  if (document.getElementById("edit-driver-modal")) {
    await loadComponent(
      "edit-driver-modal",
      "../components/driver/edit-driver-modal.html",
    );
  }

  if (document.getElementById("delete-driver-modal")) {
    await loadComponent(
      "delete-driver-modal",
      "../components/driver/delete-driver-modal.html",
    );
  }

  if (document.getElementById("add-reservation-modal")) {
    await loadComponent(
      "add-reservation-modal",
      "../components/reservation/add-reservation-modal.html",
    );
  }

  if (document.getElementById("view-reservation-modal")) {
    await loadComponent(
      "view-reservation-modal",
      "../components/reservation/view-reservation-modal.html",
    );
  }

  if (document.getElementById("edit-reservation-modal")) {
    await loadComponent(
      "edit-reservation-modal",
      "../components/reservation/edit-reservation-modal.html",
    );
  }

  if (document.getElementById("delete-reservation-modal")) {
    await loadComponent(
      "delete-reservation-modal",
      "../components/reservation/delete-reservation-modal.html",
    );
  }

  if (document.getElementById("add-dispatch-modal")) {
    await loadComponent(
      "add-dispatch-modal",
      "../components/dispatch/add-dispatch-modal.html",
    );
  }

  if (document.getElementById("view-dispatch-modal")) {
    await loadComponent(
      "view-dispatch-modal",
      "../components/dispatch/view-dispatch-modal.html",
    );
  }

  if (document.getElementById("edit-dispatch-modal")) {
    await loadComponent(
      "edit-dispatch-modal",
      "../components/dispatch/edit-dispatch-modal.html",
    );
  }

  if (document.getElementById("delete-dispatch-modal")) {
    await loadComponent(
      "delete-dispatch-modal",
      "../components/dispatch/delete-dispatch-modal.html",
    );
  }

  if (document.getElementById("add-maintenance-modal")) {
    await loadComponent(
      "add-maintenance-modal",
      "../components/maintenance/add-maintenance-modal.html",
    );
  }

  if (document.getElementById("view-maintenance-modal")) {
    await loadComponent(
      "view-maintenance-modal",
      "../components/maintenance/view-maintenance-modal.html",
    );
  }

  if (document.getElementById("edit-maintenance-modal")) {
    await loadComponent(
      "edit-maintenance-modal",
      "../components/maintenance/edit-maintenance-modal.html",
    );
  }

  if (document.getElementById("delete-maintenance-modal")) {
    await loadComponent(
      "delete-maintenance-modal",
      "../components/maintenance/delete-maintenance-modal.html",
    );
  }

  if (document.getElementById("add-fuel-modal")) {
    await loadComponent(
      "add-fuel-modal",
      "../components/fuel/add-fuel-modal.html",
    );
  }

  if (document.getElementById("view-fuel-modal")) {
    await loadComponent(
      "view-fuel-modal",
      "../components/fuel/view-fuel-modal.html",
    );
  }

  if (document.getElementById("edit-fuel-modal")) {
    await loadComponent(
      "edit-fuel-modal",
      "../components/fuel/edit-fuel-modal.html",
    );
  }

  if (document.getElementById("delete-fuel-modal")) {
    await loadComponent(
      "delete-fuel-modal",
      "../components/fuel/delete-fuel-modal.html",
    );
  }

  /* Toast Component */
  if (document.getElementById("toast")) {
    await loadComponent("toast", "../components/shared/toast.html");
  }

  /* Initialize Modules */

  if (typeof initVehicleModal === "function") {
    initVehicleModal();
  }
  if (typeof initVehicleForm === "function") {
    initVehicleForm();
  }
  if (typeof initViewVehicleModal === "function") {
    initViewVehicleModal();
  }

  if (typeof initEditVehicleModal === "function") {
    initEditVehicleModal();
  }

  if (typeof initDeleteVehicleModal === "function") {
    initDeleteVehicleModal();
  }

  if (typeof initVehicleFilters === "function") {
    initVehicleFilters();
  }

  if (typeof initVehiclePagination === "function") {
    initVehiclePagination();
  }
  /* Initialize Toast */

  if (typeof initToast === "function") {
    initToast();
  }
  if (typeof initBulkActions === "function") {
    initBulkActions();
  }
  if (typeof initVehicleExport === "function") {
    initVehicleExport();
  }
  if (typeof initVehiclePrint === "function") {
    initVehiclePrint();
  }
  if (typeof initVehicleSorting === "function") {
    initVehicleSorting();
  }
  if (typeof initVehiclePDFExport === "function") {
    initVehiclePDFExport();
  }
  if (typeof initVehicleImageUpload === "function") {
    initVehicleImageUpload();
  }
  if (typeof initVehicleAdd === "function") {
    initVehicleAdd();
  }
  if (typeof updateVehicleStats === "function") {
    updateVehicleStats();
  }

  if (document.getElementById("add-driver-modal")) {
    if (typeof initDriverModal === "function") {
      initDriverModal();
    }
    if (typeof initDriverForm === "function") {
      initDriverForm();
    }
    if (typeof initDriverImageUpload === "function") {
      initDriverImageUpload();
    }
    if (typeof initDriverAdd === "function") {
      initDriverAdd();
    }
  }

  if (document.getElementById("view-driver-modal")) {
    if (typeof initViewDriverModal === "function") {
      initViewDriverModal();
    }
  }

  if (document.getElementById("edit-driver-modal")) {
    if (typeof initEditDriverModal === "function") {
      initEditDriverModal();
    }
  }

  if (document.getElementById("delete-driver-modal")) {
    if (typeof initDeleteDriverModal === "function") {
      initDeleteDriverModal();
    }
  }

  if (document.getElementById("add-reservation-modal")) {
    if (typeof initReservationModal === "function") {
      initReservationModal();
    }
    if (typeof initReservationAdd === "function") {
      initReservationAdd();
    }
  }

  if (document.getElementById("view-reservation-modal")) {
    if (typeof initViewReservationModal === "function") {
      initViewReservationModal();
    }
  }

  if (document.getElementById("edit-reservation-modal")) {
    if (typeof initEditReservationModal === "function") {
      initEditReservationModal();
    }
  }

  if (document.getElementById("delete-reservation-modal")) {
    if (typeof initDeleteReservationModal === "function") {
      initDeleteReservationModal();
    }
  }

  if (document.getElementById("add-dispatch-modal")) {
    if (typeof initDispatchModal === "function") {
      initDispatchModal();
    }
    if (typeof initDispatchAdd === "function") {
      initDispatchAdd();
    }
  }

  if (document.getElementById("view-dispatch-modal")) {
    if (typeof initViewDispatchModal === "function") {
      initViewDispatchModal();
    }
  }

  if (document.getElementById("edit-dispatch-modal")) {
    if (typeof initEditDispatchModal === "function") {
      initEditDispatchModal();
    }
  }

  if (document.getElementById("delete-dispatch-modal")) {
    if (typeof initDeleteDispatchModal === "function") {
      initDeleteDispatchModal();
    }
  }

  if (document.getElementById("add-maintenance-modal")) {
    if (typeof initMaintenanceModal === "function") {
      initMaintenanceModal();
    }
    if (typeof initMaintenanceAdd === "function") {
      initMaintenanceAdd();
    }
  }

  if (document.getElementById("view-maintenance-modal")) {
    if (typeof initViewMaintenanceModal === "function") {
      initViewMaintenanceModal();
    }
  }

  if (document.getElementById("edit-maintenance-modal")) {
    if (typeof initEditMaintenanceModal === "function") {
      initEditMaintenanceModal();
    }

    if (typeof initMaintenanceEdit === "function") {
      initMaintenanceEdit();
    }
  }

  if (document.getElementById("delete-maintenance-modal")) {
    if (typeof initDeleteMaintenanceModal === "function") {
      initDeleteMaintenanceModal();
    }
  }

  if (document.getElementById("maintenanceTableBody")) {
    /* Register listeners only — single pipeline paint below */
    if (typeof initMaintenanceSorting === "function") {
      initMaintenanceSorting();
    }

    if (typeof initMaintenancePagination === "function") {
      initMaintenancePagination();
    }

    if (typeof initMaintenanceSearch === "function") {
      initMaintenanceSearch();
    }

    if (typeof initMaintenanceBulkSelection === "function") {
      initMaintenanceBulkSelection();
    } else if (typeof initMaintenanceBulkActions === "function") {
      initMaintenanceBulkActions();
    }

    if (typeof refreshMaintenanceTable === "function") {
      refreshMaintenanceTable({ resetPage: true });
    }

    if (typeof updateMaintenanceStatistics === "function") {
      updateMaintenanceStatistics();
    } else if (typeof initMaintenanceStatistics === "function") {
      initMaintenanceStatistics();
    }

    if (typeof initMaintenanceExcelExport === "function") {
      initMaintenanceExcelExport();
    } else if (typeof initMaintenanceExport === "function") {
      initMaintenanceExport();
    }

    if (typeof initMaintenancePDFExport === "function") {
      initMaintenancePDFExport();
    }
  }

  if (document.getElementById("add-fuel-modal")) {
    if (typeof initFuelModal === "function") {
      initFuelModal();
    }
    if (typeof initFuelAdd === "function") {
      initFuelAdd();
    }
  }

  if (document.getElementById("view-fuel-modal")) {
    if (typeof initViewFuelModal === "function") {
      initViewFuelModal();
    }
  }

  if (document.getElementById("edit-fuel-modal")) {
    if (typeof initEditFuelModal === "function") {
      initEditFuelModal();
    }
    if (typeof initFuelEdit === "function") {
      initFuelEdit();
    }
  }

  if (document.getElementById("delete-fuel-modal")) {
    if (typeof initDeleteFuelModal === "function") {
      initDeleteFuelModal();
    }
  }

  if (document.getElementById("fuelTableBody")) {
    if (typeof initFuelSorting === "function") {
      initFuelSorting();
    }

    if (typeof initFuelPagination === "function") {
      initFuelPagination();
    }

    if (typeof initFuelSearch === "function") {
      initFuelSearch();
    }

    if (typeof initFuelBulkSelection === "function") {
      initFuelBulkSelection();
    } else if (typeof initFuelBulkActions === "function") {
      initFuelBulkActions();
    }

    if (typeof initFuelStatistics === "function") {
      initFuelStatistics();
    }

    if (typeof refreshFuelTable === "function") {
      refreshFuelTable({ resetPage: true });
    }

    if (typeof updateFuelStatistics === "function") {
      updateFuelStatistics();
    }

    if (typeof initFuelExcelExport === "function") {
      initFuelExcelExport();
    } else if (typeof initFuelExport === "function") {
      initFuelExport();
    }

    if (typeof initFuelPDFExport === "function") {
      initFuelPDFExport();
    }

    if (typeof initFuelPrint === "function") {
      initFuelPrint();
    }
  }

  if (document.getElementById("reportsPage")) {
    if (typeof initReportsPage === "function") {
      initReportsPage();
    }
  }

  if (document.getElementById("routePlanningPage")) {
    if (typeof initRoutePlanningPage === "function") {
      initRoutePlanningPage();
    }
  }

  if (document.getElementById("costAnalysisPage")) {
    if (typeof initCostAnalysisPage === "function") {
      initCostAnalysisPage();
    }
  }

  if (document.getElementById("settingsPage")) {
    if (typeof initSettingsPage === "function") {
      initSettingsPage();
    }
  }

  if (document.getElementById("userProfilePage")) {
    if (typeof initProfilePage === "function") {
      initProfilePage();
    }
  }

  /* One-shot cross-page intents (sessionStorage) */
  if (typeof consumePendingFleetAction === "function") {
    const pendingHandlers = {};
    if (document.getElementById("addVehicleBtn")) {
      pendingHandlers.addVehicle = () =>
        document.getElementById("addVehicleBtn")?.click();
    }
    if (document.getElementById("addReservationBtn")) {
      pendingHandlers.addReservation = () =>
        document.getElementById("addReservationBtn")?.click();
    }
    if (document.getElementById("createDispatchBtn")) {
      pendingHandlers.createDispatch = () =>
        document.getElementById("createDispatchBtn")?.click();
    }
    if (document.getElementById("addDriverBtn")) {
      pendingHandlers.addDriver = () =>
        document.getElementById("addDriverBtn")?.click();
    }
    if (document.getElementById("addMaintenanceBtn")) {
      pendingHandlers.addMaintenance = () =>
        document.getElementById("addMaintenanceBtn")?.click();
    }
    if (document.getElementById("addFuelBtn")) {
      pendingHandlers.addFuel = () =>
        document.getElementById("addFuelBtn")?.click();
    }
    if (document.getElementById("newRouteBtn")) {
      pendingHandlers.addRoute = () =>
        document.getElementById("newRouteBtn")?.click();
    }
    if (Object.keys(pendingHandlers).length) {
      consumePendingFleetAction(pendingHandlers);
    }
  }

  if (typeof initDispatchFilters === "function") {
    initDispatchFilters();
  }

  if (typeof updateDispatchStatistics === "function") {
    updateDispatchStatistics();
  }

  if (typeof initDispatchSorting === "function") {
    initDispatchSorting();
  }

  if (typeof initDispatchPagination === "function") {
    initDispatchPagination();
  }

  if (typeof initDispatchExport === "function") {
    initDispatchExport();
  }

  if (typeof initDispatchPrint === "function") {
    initDispatchPrint();
  }

  if (document.getElementById("dispatchTableBody")) {
    if (typeof initDispatchBulkActions === "function") {
      initDispatchBulkActions();
    }
  }

  if (document.getElementById("driverTableBody")) {
    if (typeof initDriverSearch === "function") {
      initDriverSearch();
    }
    if (typeof initDriverPagination === "function") {
      initDriverPagination();
    }
    if (typeof initDriverSorting === "function") {
      initDriverSorting();
    }
    if (typeof initDriverBulkActions === "function") {
      initDriverBulkActions();
    }
    if (typeof updateDriverStats === "function") {
      updateDriverStats();
    }
    if (typeof initDriverExport === "function") {
      initDriverExport();
    }
    if (typeof initDriverPDFExport === "function") {
      initDriverPDFExport();
    }
    if (typeof initDriverPrint === "function") {
      initDriverPrint();
    }
  }
});
