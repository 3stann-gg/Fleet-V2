/* ==========================================
   Report filter presets (localStorage only)
========================================== */

const HIMS_FLEET_REPORT_PRESETS_KEY = "himsFleetReportPresets";
const REPORT_PRESETS_MAX = 25;

function readReportPresets() {
  try {
    const raw = localStorage.getItem(HIMS_FLEET_REPORT_PRESETS_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed
        .filter(
            (preset) =>
                preset &&
                typeof preset === "object" &&
                preset.id &&
                preset.name,
        )
        .slice(0, REPORT_PRESETS_MAX);
  } catch (error) {
    console.error("Malformed report presets storage:", error);
    return [];
  }
}

function writeReportPresets(list) {
  try {
    localStorage.setItem(
      HIMS_FLEET_REPORT_PRESETS_KEY,
      JSON.stringify(list.slice(0, REPORT_PRESETS_MAX)),
    );
    return true;
  } catch (error) {
    console.error("Unable to write report presets:", error);
    if (typeof showToast === "function") {
      showToast("Unable to save report presets (storage unavailable).", "error");
    }
    return false;
  }
}

function captureCurrentReportPreset(name) {
  const filters =
    typeof getReportsFilterValues === "function"
      ? getReportsFilterValues()
      : {};

  const now = new Date().toISOString();
  return {
    id:
      "preset-" +
      Date.now().toString(36) +
      "-" +
      Math.random().toString(36).slice(2, 6),
    name: String(name || "").trim(),
    reportType: filters.reportType || "overview",
    dateRange: document.getElementById("reportDateRange")?.value || "last30",
    startDate: document.getElementById("reportStartDate")?.value || "",
    endDate: document.getElementById("reportEndDate")?.value || "",
    vehicle: filters.vehicle || "all",
    department: filters.department || "all",
    search:
      typeof getReportsTableSearchValue === "function"
        ? getReportsTableSearchValue()
        : document.getElementById("reportsTableSearch")?.value || "",
    sortField:
      typeof reportsTableState !== "undefined"
        ? reportsTableState.sortField
        : null,
    sortDir:
      typeof reportsTableState !== "undefined"
        ? reportsTableState.sortDir
        : null,
    pageSize:
      typeof reportsTableState !== "undefined"
        ? reportsTableState.pageSize
        : 5,
    createdAt: now,
    updatedAt: now,
  };
}

async function applyReportPreset(preset) {
    if (!preset) return false;

    const dateRange = document.getElementById("reportDateRange");
    const start = document.getElementById("reportStartDate");
    const end = document.getElementById("reportEndDate");
    const vehicle = document.getElementById("reportVehicleFilter");
    const department = document.getElementById("reportDepartmentFilter");
    const reportType = document.getElementById("reportTypeFilter");

    if (dateRange) {
        dateRange.value = preset.dateRange || "last30";
    }
    if (start) {
        start.value = preset.startDate || "";
    }
    if (end) {
        end.value = preset.endDate || "";
    }
    if (vehicle) {
        const hasVehicle = [...vehicle.options].some(
            (option) => option.value === preset.vehicle,
        );
        vehicle.value = hasVehicle ? preset.vehicle : "all";
    }
    if (department) {
        const hasDepartment = [...department.options].some(
            (option) => option.value === preset.department,
        );
        department.value = hasDepartment ? preset.department : "all";
    }
    if (reportType) {
        const hasType = [...reportType.options].some(
            (option) => option.value === preset.reportType,
        );
        reportType.value = hasType ? preset.reportType : "overview";
    }
    if (typeof applyReportsTablePresetState === "function") {
        applyReportsTablePresetState({
            search: preset.search || "",
            sortField: preset.sortField || null,
            sortDir: preset.sortDir || null,
            pageSize: preset.pageSize || 5,
        });
    }
    if (typeof syncCustomDateInputs === "function") {
        syncCustomDateInputs();
    }
    if (typeof applyReportsScopeUi === "function") {
        applyReportsScopeUi();
    }
    if (typeof refreshReportsDashboard === "function") {
        await refreshReportsDashboard({
            resetTablePage: true,
            reason: "preset-load",
        });
    }
    return true;
}

function escapeReportPresetHtml(value) {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function renderReportPresetsSelect() {
  const select = document.getElementById("reportPresetSelect");
  if (!select) return;

  const current = select.value;
  const presets = readReportPresets();

  select.innerHTML =
    '<option value="">Saved presets…</option>' +
    presets
      .map((p) => {
        const safe = escapeReportPresetHtml(p.name);
        return `<option value="${p.id}">${safe}</option>`;
      })
      .join("");

  if (current && [...select.options].some((o) => o.value === current)) {
    select.value = current;
  }

  const deleteBtn = document.getElementById("deleteReportPreset");
  const renameBtn = document.getElementById("renameReportPreset");
  const hasSelection = Boolean(select.value);
  if (deleteBtn) deleteBtn.disabled = !hasSelection;
  if (renameBtn) renameBtn.disabled = !hasSelection;
}

function saveCurrentReportPreset() {
  const nameInput = document.getElementById("reportPresetName");
  const name = (nameInput?.value || "").trim();

  if (!name) {
    if (typeof showToast === "function") {
      showToast("Enter a name for this report preset.", "warning");
    }
    nameInput?.focus();
    return;
  }

  if (name.length > 60) {
    if (typeof showToast === "function") {
      showToast("Preset name is too long (max 60 characters).", "warning");
    }
    return;
  }

  const preset = captureCurrentReportPreset(name);
  const list = readReportPresets();
  const existingIndex = list.findIndex(
    (p) => p.name.toLowerCase() === preset.name.toLowerCase(),
  );

  if (existingIndex >= 0) {
      const confirmed = window.confirm(
          'A preset named "' + name + '" already exists. Replace it?',
      );
      if (!confirmed) {
          return;
      }
      preset.id = list[existingIndex].id;
      preset.createdAt = list[existingIndex].createdAt || preset.createdAt;
      list[existingIndex] = preset;
  } else {
      if (list.length >= REPORT_PRESETS_MAX) {
          if (typeof showToast === "function") {
              showToast(
                  "Preset limit reached (" +
                      REPORT_PRESETS_MAX +
                      "). Delete one first.",
                  "warning",
              );
          }
          return;
      }
      list.unshift(preset);
  }

  if (!writeReportPresets(list)) return;

  renderReportPresetsSelect();
  const select = document.getElementById("reportPresetSelect");
  if (select) select.value = preset.id;
  if (nameInput) nameInput.value = "";

  if (typeof showToast === "function") {
    showToast(
      existingIndex >= 0 ? "Report preset updated." : "Report preset saved.",
      "success",
    );
  }
}

function renameSelectedReportPreset() {
  const select = document.getElementById("reportPresetSelect");
  const id = select?.value;
  if (!id) return;

  const list = readReportPresets();
  const preset = list.find((p) => p.id === id);
  if (!preset) {
    renderReportPresetsSelect();
    return;
  }

  const next = window.prompt("Rename preset", preset.name);
  if (next == null) return;

  const name = next.trim();
  if (name.length > 60) {
      if (typeof showToast === "function") {
          showToast("Preset name is too long (max 60 characters).", "warning");
      }
      return;
  }
  if (!name) {
    if (typeof showToast === "function") {
      showToast("Preset name cannot be empty.", "warning");
    }
    return;
  }

  const clash = list.find(
    (p) => p.id !== id && p.name.toLowerCase() === name.toLowerCase(),
  );
  if (clash) {
    if (typeof showToast === "function") {
      showToast("A preset with that name already exists.", "warning");
    }
    return;
  }

  preset.name = name;
  preset.updatedAt = new Date().toISOString();
  if (!writeReportPresets(list)) return;

  renderReportPresetsSelect();
  if (select) select.value = id;

  if (typeof showToast === "function") {
    showToast("Preset renamed.", "success");
  }
}

function deleteSelectedReportPreset() {
  const select = document.getElementById("reportPresetSelect");
  const id = select?.value;
  if (!id) return;

  const list = readReportPresets();
  const preset = list.find((p) => p.id === id);
  if (!preset) {
    renderReportPresetsSelect();
    return;
  }

  const confirmed = window.confirm(
    'Delete preset "' + preset.name + '"?\n\nThis cannot be undone.',
  );
  if (!confirmed) return;

  if (!writeReportPresets(list.filter((p) => p.id !== id))) return;

  renderReportPresetsSelect();
  if (typeof showToast === "function") {
    showToast("Report preset deleted.", "success");
  }
}

function initReportPresets() {
  const select = document.getElementById("reportPresetSelect");
  if (!select) return;

  if (select.dataset.presetsInitialized === "true") {
    renderReportPresetsSelect();
    return;
  }

  select.dataset.presetsInitialized = "true";
  renderReportPresetsSelect();

  select.addEventListener("change", () => {
    const has = Boolean(select.value);
    const deleteBtn = document.getElementById("deleteReportPreset");
    const renameBtn = document.getElementById("renameReportPreset");
    if (deleteBtn) deleteBtn.disabled = !has;
    if (renameBtn) renameBtn.disabled = !has;
  });

  document
      .getElementById("applyReportPreset")
      ?.addEventListener("click", async (event) => {
          event.preventDefault();
          const id = select.value;
          if (!id) {
              if (typeof showToast === "function") {
                  showToast("Select a preset to load.", "warning");
              }
              return;
          }
          const preset = readReportPresets().find((item) => item.id === id);
          if (!preset) {
              if (typeof showToast === "function") {
                  showToast("Preset not found.", "error");
              }
              renderReportPresetsSelect();
              return;
          }
          const button = document.getElementById("applyReportPreset");
          if (button) {
              button.disabled = true;
          }
          try {
              await applyReportPreset(preset);
              if (typeof showToast === "function") {
                  showToast("Preset loaded: " + preset.name, "success");
              }
          } catch (error) {
              console.error("Unable to apply report preset:", error);
              if (typeof showToast === "function") {
                  showToast(error.message || "Unable to load preset.", "error");
              }
          } finally {
              if (button) {
                  button.disabled = false;
              }
          }
      });

  document
    .getElementById("saveReportPreset")
    ?.addEventListener("click", (event) => {
      event.preventDefault();
      saveCurrentReportPreset();
    });

  document
    .getElementById("renameReportPreset")
    ?.addEventListener("click", (event) => {
      event.preventDefault();
      renameSelectedReportPreset();
    });

  document
    .getElementById("deleteReportPreset")
    ?.addEventListener("click", (event) => {
      event.preventDefault();
      deleteSelectedReportPreset();
    });
}
