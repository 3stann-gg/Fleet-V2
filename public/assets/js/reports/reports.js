/* ==========================================
   Reports page initialization
========================================== */

let reportsPageInitialized = false;

/* ==========================================
   FILTER OPTION HELPERS
========================================== */
function escapeReportOptionHtml(value) {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function applyReportsScopeUi() {
    const scope = reportsState?.sources?.scope || {};

    const departmentSelect = document.getElementById("reportDepartmentFilter");

    if (scope.department && departmentSelect) {
        departmentSelect.innerHTML = "";
        const option = document.createElement("option");
        option.value = scope.department;
        option.textContent = scope.department;
        departmentSelect.appendChild(option);
        departmentSelect.value = scope.department;
        departmentSelect.disabled = true;
    }
}

function applyReportsTypeAccess() {
    const select = document.getElementById("reportTypeFilter");
    if (!select) {
        return;
    }
    const permissions = window.FLEET_RBAC?.reports || {};
    const allowed = Array.isArray(permissions.allowedReportTypes)
        ? permissions.allowedReportTypes
        : [];
    if (!allowed.length) {
        return;
    }
    const labels = {
        overview: "Overview",
        utilization: "Fleet Utilization",
        trips: "Trip & Dispatch",
        reservations: "Reservations",
        maintenance: "Maintenance",
        fuel: "Fuel & Cost",
        drivers: "Driver Performance",
    };
    const current = select.value || "overview";
    select.replaceChildren();
    allowed.forEach((type) => {
        if (!labels[type]) {
            return;
        }
        const option = document.createElement("option");
        option.value = type;
        option.textContent = labels[type];
        select.appendChild(option);
    });

    if (allowed.includes(current)) {
        select.value = current;
    } else {
        select.value = allowed.includes("overview") ? "overview" : allowed[0];
    }
}

function populateReportVehicleFilter(sources) {
    const select = document.getElementById("reportVehicleFilter");

    if (!select) {
        return;
    }

    const current = select.value || "all";
    const names = Array.from(
        new Set(
            (sources?.vehicles || [])
                .map((vehicle) => vehicle.name)
                .filter(Boolean),
        ),
    ).sort((a, b) => a.localeCompare(b));
    select.innerHTML =
        '<option value="all">All Vehicles</option>' +
        names
            .map((name) => {
                const safeName = escapeReportOptionHtml(name);
                return `
          <option value="${safeName}">
            ${safeName}
          </option>
        `;
            })
            .join("");

    if ([...select.options].some((option) => option.value === current)) {
        select.value = current;
    }
}

function populateReportDepartmentFilter(sources) {
    const select = document.getElementById("reportDepartmentFilter");
    if (!select) {
        return;
    }
    const current = select.value || "all";
    const departments = new Set();
    (sources?.vehicles || []).forEach((vehicle) => {
        if (vehicle.department) {
            departments.add(vehicle.department);
        }
    });
    (sources?.reservations || []).forEach((reservation) => {
        if (reservation.department) {
            departments.add(reservation.department);
        }
    });
    (sources?.maintenance || []).forEach((maintenance) => {
        if (maintenance.department) {
            departments.add(maintenance.department);
        }
    });
    (sources?.fuel || []).forEach((fuel) => {
        if (fuel.department) {
            departments.add(fuel.department);
        }
    });

    const names = Array.from(departments).sort((a, b) => a.localeCompare(b));
    select.innerHTML =
        '<option value="all">All Departments</option>' +
        names
            .map((name) => {
                const safeName = escapeReportOptionHtml(name);
                return `
          <option value="${safeName}">
            ${safeName}
          </option>
        `;
            })
            .join("");

    if ([...select.options].some((option) => option.value === current)) {
        select.value = current;
    }
}

/* ==========================================
   CUSTOM DATE INPUTS
========================================== */

function syncCustomDateInputs() {
    const preset = document.getElementById("reportDateRange")?.value;
    const start = document.getElementById("reportStartDate");
    const end = document.getElementById("reportEndDate");
    const wrap = document.getElementById("reportCustomDateWrap");
    const isCustom = preset === "custom";
    if (wrap) {
        wrap.hidden = !isCustom;
    }
    if (start) {
        start.disabled = !isCustom;
        start.required = isCustom;
    }
    if (end) {
        end.disabled = !isCustom;
        end.required = isCustom;
    }
}

/* ==========================================
   REFRESH BUTTON STATE
========================================== */
function setReportsRefreshLoading(loading) {
    const button = document.getElementById("refreshReports");
    if (!button) {
        return;
    }
    button.disabled = loading;
    button.innerHTML = loading
        ? `
        <i class="ph ph-spinner"></i>
        Refreshing...
      `
        : `
        <i class="ph ph-arrows-clockwise"></i>
        Refresh
      `;
}

/* ==========================================
   PAGE INITIALIZATION
========================================== */
async function initReportsPage() {
    if (reportsPageInitialized) {
        return;
    }
    if (!document.getElementById("reportsPage")) {
        return;
    }
    reportsPageInitialized = true;
    applyReportsTypeAccess();
    /*
  |--------------------------------------------------------------------------
  | Initialize Table
  |--------------------------------------------------------------------------
  */
    if (typeof initReportsTable === "function") {
        initReportsTable();
    }
    /*
  |--------------------------------------------------------------------------
  | Load Initial Database Sources
  |--------------------------------------------------------------------------
  |
  | We load once here so Vehicle and Department filters
  | can be populated before the dashboard renders.
  |
  */
    try {
        if (typeof getAllReportsSourceData === "function") {
            reportsState.sources = await getAllReportsSourceData();
            populateReportVehicleFilter(reportsState.sources);
            populateReportDepartmentFilter(reportsState.sources);
            applyReportsScopeUi();
            applyReportsTypeAccess();
        }
    } catch (error) {
        console.error("Unable to load Reports filter sources:", error);
        if (typeof showToast === "function") {
            showToast(error.message || "Unable to load Reports data.", "error");
        }
    }
    syncCustomDateInputs();
    /*
  |--------------------------------------------------------------------------
  | Global Filters
  |--------------------------------------------------------------------------
  */
    const onFilterChange = async () => {
        syncCustomDateInputs();
        if (typeof refreshReportsDashboard !== "function") {
            return;
        }
        try {
            await refreshReportsDashboard({
                resetTablePage: true,
                reason: "filter",
            });
        } catch (error) {
            console.error("Unable to apply Reports filters:", error);
            if (typeof showToast === "function") {
                showToast(
                    error.message || "Unable to update Reports.",
                    "error",
                );
            }
        }
    };

    [
        "reportDateRange",
        "reportStartDate",
        "reportEndDate",
        "reportVehicleFilter",
        "reportDepartmentFilter",
        "reportTypeFilter",
    ].forEach((id) => {
        document.getElementById(id)?.addEventListener("change", onFilterChange);
    });
    /*
  |--------------------------------------------------------------------------
  | Manual Refresh
  |--------------------------------------------------------------------------
  */
    document
        .getElementById("refreshReports")
        ?.addEventListener("click", async () => {
            if (typeof refreshReportsDashboard !== "function") {
                return;
            }
            setReportsRefreshLoading(true);
            try {
                /*
          |--------------------------------------------------------------------------
          | Reload real Laravel/MySQL sources
          |--------------------------------------------------------------------------
          */
                await refreshReportsDashboard({
                    resetTablePage: false,
                    refreshSources: true,
                    reason: "refresh",
                });
                /*
          |--------------------------------------------------------------------------
          | Rebuild filters from refreshed source state
          |--------------------------------------------------------------------------
          */
                populateReportVehicleFilter(reportsState.sources);
                populateReportDepartmentFilter(reportsState.sources);
                applyReportsScopeUi();
                applyReportsTypeAccess();
                if (typeof showToast === "function") {
                    showToast("Reports refreshed.", "success");
                }
            } catch (error) {
                console.error("Unable to refresh Reports:", error);
                if (typeof showToast === "function") {
                    showToast(
                        error.message || "Unable to refresh Reports.",
                        "error",
                    );
                }
            } finally {
                setReportsRefreshLoading(false);
            }
        });
    /*
  |--------------------------------------------------------------------------
  | Presets
  |--------------------------------------------------------------------------
  */
    if (typeof initReportPresets === "function") {
        initReportPresets();
    }
    /*
  |--------------------------------------------------------------------------
  | Export
  |--------------------------------------------------------------------------
  */
    if (typeof initReportsExport === "function") {
        initReportsExport();
    }

    /*
  |--------------------------------------------------------------------------
  | Initial Dashboard Render
  |--------------------------------------------------------------------------
  | reportsState.sources was already loaded above,
  | so we don't need another six API requests.
  */
    if (typeof refreshReportsDashboard === "function") {
        try {
            await refreshReportsDashboard({
                resetTablePage: true,
                refreshSources: !reportsState.sources,
                reason: "init",
            });
        } catch (error) {
            console.error("Unable to initialize Reports dashboard:", error);
            if (typeof showToast === "function") {
                showToast(
                    error.message || "Unable to initialize Reports.",
                    "error",
                );
            }
        }
    }
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
        initReportsPage();
    });
} else {
    initReportsPage();
}
