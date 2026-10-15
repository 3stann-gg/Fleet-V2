/* ==========================================
   Cost Analysis filter presets
========================================== */

const HIMS_FLEET_COST_PRESETS_KEY = "himsFleetCostAnalysisPresets";
const COST_PRESETS_MAX = 30;

function emptyCostPresetShell() {
  return [];
}

function normalizeCostPreset(raw, index) {
  if (!raw || typeof raw !== "object") return null;
  const name = String(raw.name || "").trim();
  if (!name) return null;
  return {
    id: String(raw.id || "preset-" + index + "-" + Date.now().toString(36)),
    name: name.slice(0, 60),
    dateRange: raw.dateRange || "last30",
    customStartDate: String(raw.customStartDate || "").slice(0, 10),
    customEndDate: String(raw.customEndDate || "").slice(0, 10),
    vehicle: raw.vehicle || "all",
    department: raw.department || "all",
    vehicleType: raw.vehicleType || "all",
    powertrain: raw.powertrain || "all",
    category: raw.category || "all",
    analysisView: raw.analysisView || "overview",
    tableSearch: String(raw.tableSearch || ""),
    tableSourceFilter: raw.tableSourceFilter || "all",
    tableCategoryFilter: raw.tableCategoryFilter || "all",
    tableStatusFilter: raw.tableStatusFilter || "all",
    sortKey: raw.sortKey || null,
    sortDirection: raw.sortDirection || null,
    rowsPerPage: Number(raw.rowsPerPage) || 5,
    createdAt: raw.createdAt || new Date().toISOString(),
    updatedAt: raw.updatedAt || raw.createdAt || new Date().toISOString(),
  };
}

function loadCostAnalysisPresets() {
  try {
    const raw = localStorage.getItem(HIMS_FLEET_COST_PRESETS_KEY);
    if (!raw) return emptyCostPresetShell();
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return emptyCostPresetShell();
    const seenIds = new Set();
    const list = [];
    parsed.forEach((item, index) => {
      const p = normalizeCostPreset(item, index);
      if (!p || seenIds.has(p.id)) return;
      seenIds.add(p.id);
      list.push(p);
    });
    return list.slice(0, COST_PRESETS_MAX);
  } catch (error) {
    console.error("Malformed cost presets:", error);
    return emptyCostPresetShell();
  }
}

function persistCostAnalysisPresets(list) {
  try {
    localStorage.setItem(
      HIMS_FLEET_COST_PRESETS_KEY,
      JSON.stringify((list || []).slice(0, COST_PRESETS_MAX)),
    );
    return true;
  } catch (error) {
    console.error("Unable to save cost presets:", error);
    if (typeof showToast === "function") {
      showToast("Unable to save presets (storage unavailable).", "error");
    }
    return false;
  }
}

function captureCostAnalysisPreset(name) {
  const now = new Date().toISOString();
  return {
    id: "cp-" + Date.now().toString(36) + "-" + Math.random().toString(36).slice(2, 5),
    name: String(name || "").trim().slice(0, 60),
    dateRange: document.getElementById("costDateRange")?.value || "last30",
    customStartDate: document.getElementById("costStartDate")?.value || "",
    customEndDate: document.getElementById("costEndDate")?.value || "",
    vehicle: document.getElementById("costVehicleFilter")?.value || "all",
    department: document.getElementById("costDepartmentFilter")?.value || "all",
    vehicleType: document.getElementById("costVehicleTypeFilter")?.value || "all",
    powertrain: document.getElementById("costPowertrainFilter")?.value || "all",
    category: document.getElementById("costCategoryFilter")?.value || "all",
    analysisView: document.getElementById("costAnalysisView")?.value || "overview",
    tableSearch: document.getElementById("costTableSearch")?.value || "",
    tableSourceFilter:
      document.getElementById("costTableSourceFilter")?.value || "all",
    tableCategoryFilter:
      document.getElementById("costTableCategoryFilter")?.value || "all",
    tableStatusFilter:
      document.getElementById("costTableStatusFilter")?.value || "all",
    sortKey:
      typeof costTableState !== "undefined" ? costTableState.sortField : null,
    sortDirection:
      typeof costTableState !== "undefined" ? costTableState.sortDir : null,
    rowsPerPage:
      typeof costTableState !== "undefined" ? costTableState.pageSize : 5,
    createdAt: now,
    updatedAt: now,
  };
}

async function applyCostAnalysisPreset(preset) {
    if (!preset) {
        return false;
    }
    const setSelect = (id, value, fallback) => {
        const el = document.getElementById(id);
        if (!el) {
            return;
        }
        if ([...el.options].some((option) => option.value === value)) {
            el.value = value;
        } else {
            el.value = fallback;
        }
    };
    setSelect("costDateRange", preset.dateRange, "last30");
    const start = document.getElementById("costStartDate");
    const end = document.getElementById("costEndDate");
    if (start) {
        start.value = preset.customStartDate || "";
    }
    if (end) {
        end.value = preset.customEndDate || "";
    }
    setSelect("costVehicleFilter", preset.vehicle, "all");
    setSelect("costDepartmentFilter", preset.department, "all");
    setSelect("costVehicleTypeFilter", preset.vehicleType, "all");
    setSelect("costPowertrainFilter", preset.powertrain, "all");
    setSelect("costCategoryFilter", preset.category, "all");
    setSelect("costAnalysisView", preset.analysisView, "overview");
    if (typeof costTableState !== "undefined") {
        costTableState.search = preset.tableSearch || "";
        costTableState.sourceFilter = preset.tableSourceFilter || "all";
        costTableState.categoryFilter = preset.tableCategoryFilter || "all";
        costTableState.statusFilter = preset.tableStatusFilter || "all";
        costTableState.sortField = preset.sortKey || null;
        costTableState.sortDir = preset.sortDirection || null;
        costTableState.pageSize = Number(preset.rowsPerPage) || 5;
        costTableState.page = 1;
    }
    const search = document.getElementById("costTableSearch");
    if (search) {
        search.value = preset.tableSearch || "";
    }
    setSelect("costTableSourceFilter", preset.tableSourceFilter, "all");
    setSelect("costTableCategoryFilter", preset.tableCategoryFilter, "all");
    setSelect("costTableStatusFilter", preset.tableStatusFilter, "all");
    const pageSize = document.getElementById("costTablePageSize");
    if (pageSize) {
        pageSize.value = String(Number(preset.rowsPerPage) || 5);
    }
    if (typeof syncCostCustomDates === "function") {
        syncCostCustomDates();
    }
    if (typeof refreshCostAnalysis === "function") {
        await refreshCostAnalysis({
            resetTablePage: true,
            reason: "preset-apply",
        });
    }
    return true;
}

function escapeCostPresetHtml(value) {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function renderCostPresetSelect() {
  const select = document.getElementById("costPresetSelect");
  if (!select) return;
  const current = select.value;
  const list = loadCostAnalysisPresets();
  select.innerHTML =
    '<option value="">Saved presets…</option>' +
    list
      .map((p) => {
        const safe = escapeCostPresetHtml(p.name);
        return `<option value="${p.id}">${safe}</option>`;
      })
      .join("");
  if (current && [...select.options].some((o) => o.value === current)) {
    select.value = current;
  }
  const has = Boolean(select.value);
  ["applyCostPreset", "renameCostPreset", "deleteCostPreset"].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.disabled = !has;
  });
}

async function saveCostAnalysisPreset() {
  const nameInput = document.getElementById("costPresetName");
  const name = (nameInput?.value || "").trim();
  if (!name) {
    if (typeof showToast === "function") {
      showToast("Enter a preset name.", "warning");
    }
    nameInput?.focus();
    return;
  }
  if (name.length > 60) {
    if (typeof showToast === "function") {
      showToast("Preset name is too long.", "warning");
    }
    return;
  }

  const preset = captureCostAnalysisPreset(name);
  const list = loadCostAnalysisPresets();
  const existing = list.find(
    (p) => p.name.toLowerCase() === name.toLowerCase(),
  );
  if (existing) {
    const ok = await window.showFleetConfirm({
      title: "Replace Cost Analysis Preset?",
      message: 'A preset named "' + name + '" already exists. Replace it?',
      confirmLabel: "Replace",
      confirmIcon: "ph ph-arrows-clockwise",
    });
    if (!ok) return;
    preset.id = existing.id;
    preset.createdAt = existing.createdAt;
  }

  if (!existing && list.length >= COST_PRESETS_MAX) {
    if (typeof showToast === "function") {
      showToast(
        "Preset limit reached (" + COST_PRESETS_MAX + "). Delete one first.",
        "warning",
      );
    }
    return;
  }

  const next = existing
    ? list.map((p) => (p.id === existing.id ? preset : p))
    : [preset, ...list];

  if (!persistCostAnalysisPresets(next)) return;
  renderCostPresetSelect();
  const select = document.getElementById("costPresetSelect");
  if (select) select.value = preset.id;
  ["applyCostPreset", "renameCostPreset", "deleteCostPreset"].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.disabled = false;
  });
  if (nameInput) nameInput.value = "";
  if (typeof showToast === "function") {
    showToast(existing ? "Preset updated." : "Preset saved.", "success");
  }
}

function initCostAnalysisPresets() {
  const select = document.getElementById("costPresetSelect");
  if (!select || select.dataset.presetsInit === "true") {
    renderCostPresetSelect();
    return;
  }
  select.dataset.presetsInit = "true";
  renderCostPresetSelect();

  select.addEventListener("change", () => {
    const has = Boolean(select.value);
    ["applyCostPreset", "renameCostPreset", "deleteCostPreset"].forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.disabled = !has;
    });
  });

  document.getElementById("saveCostPreset")?.addEventListener("click", (e) => {
    e.preventDefault();
    saveCostAnalysisPreset();
  });

  document.getElementById("applyCostPreset")?.addEventListener("click", async (event) => {
    event.preventDefault();
    const id = select.value;
      if (!id) {
        return;
      }
      const preset = loadCostAnalysisPresets().find(
        (item) => item.id === id,
      );
      if (!preset) {
        renderCostPresetSelect();
        return;
      }
      const button = document.getElementById("applyCostPreset");
      if (button) {
        button.disabled = true;
      }
      try {
        await applyCostAnalysisPreset(preset);
        if (typeof showToast === "function") {
          showToast("Preset applied: " + preset.name, "success");
        }
      } catch (error) {
        console.error("Unable to apply Cost Analysis preset:", error);
        if (typeof showToast === "function") {
          showToast(
            error.message || "Unable to apply preset.",
            "error",
          );
        }
      } finally {
        if (button) {
          button.disabled = false;
        }
      }
    });

  document.getElementById("renameCostPreset")?.addEventListener("click", async (e) => {
    e.preventDefault();
    const id = select.value;
    if (!id) return;
    const list = loadCostAnalysisPresets();
    const preset = list.find((p) => p.id === id);
    if (!preset) return;
    const next = await window.showInputPrompt({
      title: "Rename Cost Analysis Preset",
      message: "Enter a new name for this Cost Analysis preset.",
      label: "Preset name",
      value: preset.name,
      confirmText: "Rename Preset",
    });
    if (next == null) return;
    const name = next.trim();
    if (!name) return;
    if (
      list.some(
        (p) => p.id !== id && p.name.toLowerCase() === name.toLowerCase(),
      )
    ) {
      if (typeof showToast === "function") {
        showToast("A preset with that name already exists.", "warning");
      }
      return;
    }
    if (name.length > 60) {
        if (typeof showToast === "function") {
            showToast(
                "Preset name is too long. Maximum is 60 characters.",
                "warning",
            );
        }
        return;
    }
    preset.name = name;
    preset.updatedAt = new Date().toISOString();
    persistCostAnalysisPresets(list);
    renderCostPresetSelect();
    select.value = id;
    if (typeof showToast === "function") {
      showToast("Preset renamed.", "success");
    }
  });

  document.getElementById("deleteCostPreset")?.addEventListener("click", async (e) => {
    e.preventDefault();
    const id = select.value;
    if (!id) return;
    const list = loadCostAnalysisPresets();
    const preset = list.find((p) => p.id === id);
    if (!preset) return;
    const confirmed = await window.showFleetConfirm({
      title: "Delete Cost Analysis Preset?",
      message: 'Delete preset "' + preset.name + '"? This cannot be undone.',
      confirmLabel: "Delete",
      confirmIcon: "ph ph-trash",
      tone: "danger",
    });
    if (!confirmed) return;
    persistCostAnalysisPresets(list.filter((p) => p.id !== id));
    renderCostPresetSelect();
    if (typeof showToast === "function") {
      showToast("Preset deleted.", "success");
    }
  });
}
