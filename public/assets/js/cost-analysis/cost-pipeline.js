/* ==========================================
   Cost Analysis pipeline: dates, filters, KPIs, views
========================================== */

let isRefreshingCostAnalysis = false;
let costAnalysisState = {
  sources: null,
  normalized: [],
  filtered: [],
  range: null,
  filters: null,
  analysisView: "overview",
  budgetAmount: null,
  budgetConfig: null,
  lastUpdated: null,
};

function costStartOfDay(date) {
  const d = new Date(date);
  d.setHours(0, 0, 0, 0);
  return d;
}

function costEndOfDay(date) {
  const d = new Date(date);
  d.setHours(23, 59, 59, 999);
  return d;
}

function costParseISO(iso) {
  if (!iso) return null;
  const p = String(iso).slice(0, 10).split("-");
  if (p.length !== 3) return null;
  const y = Number(p[0]);
  const m = Number(p[1]) - 1;
  const d = Number(p[2]);
  if ([y, m, d].some((n) => Number.isNaN(n))) return null;
  return new Date(y, m, d);
}

/**
 * Resolve cost analysis date range (local calendar).
 */
function getResolvedCostDateRange() {
  const preset = document.getElementById("costDateRange")?.value || "last30";
  const today = costStartOfDay(new Date());

  if (preset === "today") {
    return { start: today, end: costEndOfDay(today), label: "Today", days: 1 };
  }
  if (preset === "last7") {
    const start = new Date(today);
    start.setDate(start.getDate() - 6);
    return { start, end: costEndOfDay(today), label: "Last 7 Days", days: 7 };
  }
  if (preset === "last30") {
    const start = new Date(today);
    start.setDate(start.getDate() - 29);
    return { start, end: costEndOfDay(today), label: "Last 30 Days", days: 30 };
  }
  if (preset === "thisMonth") {
    const start = new Date(today.getFullYear(), today.getMonth(), 1);
    return {
      start,
      end: costEndOfDay(today),
      label: "This Month",
      days: Math.max(1, Math.round((today - start) / 86400000) + 1),
    };
  }
  if (preset === "lastMonth") {
    const start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
    const end = new Date(today.getFullYear(), today.getMonth(), 0);
    return {
      start: costStartOfDay(start),
      end: costEndOfDay(end),
      label: "Last Month",
      days: end.getDate(),
    };
  }
  if (preset === "thisQuarter") {
    const q = Math.floor(today.getMonth() / 3);
    const start = new Date(today.getFullYear(), q * 3, 1);
    return {
      start,
      end: costEndOfDay(today),
      label: "This Quarter",
      days: Math.max(1, Math.round((today - start) / 86400000) + 1),
    };
  }
  if (preset === "thisYear") {
    const start = new Date(today.getFullYear(), 0, 1);
    return {
      start,
      end: costEndOfDay(today),
      label: "This Year",
      days: Math.max(1, Math.round((today - start) / 86400000) + 1),
    };
  }
  if (preset === "custom") {
    const start = costParseISO(document.getElementById("costStartDate")?.value);
    const end = costParseISO(document.getElementById("costEndDate")?.value);
    if (!start || !end) {
      if (typeof showToast === "function") {
        showToast("Select a valid custom start and end date.", "warning");
      }
      return null;
    }
    if (start > end) {
      if (typeof showToast === "function") {
        showToast("Start date cannot be later than end date.", "warning");
      }
      return null;
    }
    const days =
      Math.round((costEndOfDay(end) - costStartOfDay(start)) / 86400000) + 1;
    return {
      start: costStartOfDay(start),
      end: costEndOfDay(end),
      label: "Custom Range",
      days,
    };
  }

  const start = new Date(today);
  start.setDate(start.getDate() - 29);
  return { start, end: costEndOfDay(today), label: "Last 30 Days", days: 30 };
}

function getPreviousComparableRange(range, preset) {
  if (!range) return null;
  const len = range.end.getTime() - range.start.getTime();

  if (preset === "thisMonth") {
    const start = new Date(range.start.getFullYear(), range.start.getMonth() - 1, 1);
    const end = new Date(range.start.getFullYear(), range.start.getMonth(), 0);
    return { start: costStartOfDay(start), end: costEndOfDay(end) };
  }
  if (preset === "lastMonth") {
    const start = new Date(range.start.getFullYear(), range.start.getMonth() - 1, 1);
    const end = new Date(range.start.getFullYear(), range.start.getMonth(), 0);
    return { start: costStartOfDay(start), end: costEndOfDay(end) };
  }
  if (preset === "thisQuarter") {
    const start = new Date(range.start.getFullYear(), range.start.getMonth() - 3, 1);
    const end = new Date(range.start.getFullYear(), range.start.getMonth(), 0);
    return { start: costStartOfDay(start), end: costEndOfDay(end) };
  }
  if (preset === "thisYear") {
    const y = range.start.getFullYear() - 1;
    return {
      start: costStartOfDay(new Date(y, 0, 1)),
      end: costEndOfDay(new Date(y, 11, 31)),
    };
  }

  const end = new Date(range.start.getTime() - 1);
  const start = new Date(end.getTime() - len);
  return { start: costStartOfDay(start), end: costEndOfDay(end) };
}

function costInRange(iso, range) {
  if (!range || !iso) return false;
  const d = costParseISO(iso);
  if (!d) return false;
  const t = d.getTime();
  return t >= range.start.getTime() && t <= range.end.getTime();
}

function getCostFilterValues() {
  return {
    vehicle: document.getElementById("costVehicleFilter")?.value || "all",
    department: document.getElementById("costDepartmentFilter")?.value || "all",
    vehicleType: document.getElementById("costVehicleTypeFilter")?.value || "all",
    powertrain: document.getElementById("costPowertrainFilter")?.value || "all",
    category: document.getElementById("costCategoryFilter")?.value || "all",
    analysisView: document.getElementById("costAnalysisView")?.value || "overview",
    datePreset: document.getElementById("costDateRange")?.value || "last30",
  };
}

function filterCostRecords(records, range, filters) {
  return (records || []).filter((r) => {
    if (range && !costInRange(r.date, range)) return false;
    if (filters.vehicle !== "all") {
      if (r.vehicleName !== filters.vehicle && r.vehicleId !== filters.vehicle) {
        return false;
      }
    }
    if (filters.department !== "all") {
      const dept = r.department || "Unassigned";
      if (dept !== filters.department) return false;
    }
    if (filters.vehicleType !== "all" && r.vehicleType !== filters.vehicleType) {
      return false;
    }
    if (filters.powertrain !== "all" && r.powertrainType !== filters.powertrain) {
      return false;
    }
    if (filters.category !== "all" && r.category !== filters.category) {
      return false;
    }
    return true;
  });
}

function formatCostCurrency(value) {
  const n = Number(value);
  if (Number.isNaN(n)) return "₱0.00";
  return (
    "₱" +
    n.toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })
  );
}

function formatCostNumber(value) {
  const n = Number(value) || 0;
  return n.toLocaleString(undefined, { maximumFractionDigits: 0 });
}

function formatCostPercent(value) {
  const n = Number(value);
  if (Number.isNaN(n) || !Number.isFinite(n)) return "—";
  return (
    n.toLocaleString(undefined, {
      minimumFractionDigits: 1,
      maximumFractionDigits: 1,
    }) + "%"
  );
}

function monthKey(iso) {
  return String(iso || "").slice(0, 7);
}

function monthLabel(key) {
  if (!key || key.length < 7) return key;
  const [y, m] = key.split("-");
  return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString(undefined, {
    month: "short",
    year: "2-digit",
  });
}

function buildMonthKeys(range) {
  if (!range) return [];
  const keys = [];
  const c = new Date(range.start.getFullYear(), range.start.getMonth(), 1);
  const end = new Date(range.end.getFullYear(), range.end.getMonth(), 1);
  while (c <= end && keys.length < 24) {
    keys.push(
      c.getFullYear() + "-" + String(c.getMonth() + 1).padStart(2, "0"),
    );
    c.setMonth(c.getMonth() + 1);
  }
  return keys;
}

function sumCost(records) {
  return (records || []).reduce((s, r) => s + (Number(r.totalCost) || 0), 0);
}

function setCostText(id, text) {
  const el = document.getElementById(id);
  if (el) el.textContent = text;
}

function updateCostLastUpdated() {
  const el = document.getElementById("costLastUpdated");
  if (!el) return;
  const now = new Date();
  costAnalysisState.lastUpdated = now;
  el.textContent =
    "Last updated: " +
    now.toLocaleDateString(undefined, {
      year: "numeric",
      month: "short",
      day: "numeric",
    }) +
    " " +
    now.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" });
}

function showCostAnalysisView(view) {
  document.querySelectorAll("[data-cost-view]").forEach((section) => {
    const active = section.dataset.costView === view;
    section.hidden = !active;
    section.setAttribute("aria-hidden", String(!active));
  });
  const title = document.getElementById("costActiveViewTitle");
  const labels = {
    overview: "Overview",
    vehicles: "Vehicle Costs",
    departments: "Department Costs",
    trips: "Trip Costs",
    budget: "Budget Analysis",
    trends: "Cost Trends",
    energy: "Energy Analytics",
  };
  if (title) title.textContent = labels[view] || "Overview";
}

function computeOverviewKpis(filtered, vehicles, trips, budget) {
  const total = sumCost(filtered);
  const fuel = sumCost(filtered.filter((r) => r.category === "Fuel"));
  const maintenance = sumCost(
    filtered.filter((r) => r.category === "Maintenance"),
  );
  const vehicleSet = new Set(
    filtered.map((r) => r.vehicleName || r.vehicleId).filter(Boolean),
  );
  const avgVehicle =
    vehicleSet.size > 0 ? total / vehicleSet.size : 0;

  /* Trip cost only when monetary trip records exist */
  const tripCostRecords = filtered.filter(
    (r) => r.category === "Trip Operations",
  );
  const tripCostTotal = sumCost(tripCostRecords);
  const completedTrips = (trips || []).filter(
      (trip) =>
          String(trip.trip_status ?? trip.status ?? "").toLowerCase() ===
          "completed",
  ).length;
  let avgTrip = null;
  if (tripCostRecords.length > 0 && completedTrips > 0) {
    avgTrip = tripCostTotal / completedTrips;
  } else if (tripCostRecords.length > 0) {
    avgTrip = tripCostTotal / tripCostRecords.length;
  }

  let budgetUtil = null;
  let budgetStatus = "Not Configured";
  if (budget != null && budget > 0) {
    budgetUtil = (total / budget) * 100;
    if (budgetUtil < 80) budgetStatus = "Under Budget";
    else if (budgetUtil <= 100) budgetStatus = "Near Limit";
    else budgetStatus = "Over Budget";
  }

  return {
    total,
    fuel,
    maintenance,
    avgVehicle,
    avgTrip,
    budgetUtil,
    budgetStatus,
    budget,
    vehicleCount: vehicleSet.size,
  };
}

function buildCategoryCounts(filtered) {
  const map = {};
  filtered.forEach((r) => {
    map[r.category] = (map[r.category] || 0) + (Number(r.totalCost) || 0);
  });
  return map;
}

function buildMonthlyTotals(filtered, range) {
  const keys = buildMonthKeys(range);
  return keys.map((key) => ({
    key,
    label: monthLabel(key),
    value: sumCost(filtered.filter((r) => monthKey(r.date) === key)),
    fuel: sumCost(
      filtered.filter(
        (r) => monthKey(r.date) === key && r.category === "Fuel",
      ),
    ),
    maintenance: sumCost(
      filtered.filter(
        (r) => monthKey(r.date) === key && r.category === "Maintenance",
      ),
    ),
  }));
}

function buildVehicleAggregates(filtered, vehicles) {
  const map = new Map();
  filtered.forEach((r) => {
    const key = r.vehicleName || r.vehicleId || "Unknown";
    if (!map.has(key)) {
      map.set(key, {
        vehicle: key,
        plateNumber: r.plateNumber || "—",
        type: r.vehicleType || "—",
        department: r.department || "Unassigned",
        fuelCost: 0,
        maintenanceCost: 0,
        otherCost: 0,
        totalCost: 0,
      });
    }
    const row = map.get(key);
    const c = Number(r.totalCost) || 0;
    row.totalCost += c;
    if (r.category === "Fuel") row.fuelCost += c;
    else if (r.category === "Maintenance") row.maintenanceCost += c;
    else row.otherCost += c;
    if (r.plateNumber) row.plateNumber = r.plateNumber;
    if (r.vehicleType) row.type = r.vehicleType;
    if (r.department) row.department = r.department;
  });

  const total = sumCost(filtered) || 1;
  return Array.from(map.values())
    .map((row) => ({
      ...row,
      costShare: (row.totalCost / total) * 100,
    }))
    .sort((a, b) => b.totalCost - a.totalCost);
}

function buildDepartmentAggregates(filtered) {
    const map = new Map();
    const includeUnassigned =
        window.costAnalysisModuleSettings?.includeUnassignedDepartment !==
        false;
    filtered.forEach((r) => {
        const rawDepartment = String(r.department || "").trim();
        /*
        |--------------------------------------------------------------------------
        | Optional Unassigned Group
        |--------------------------------------------------------------------------
        */
        if (!rawDepartment && !includeUnassigned) {
            return;
        }
        const key = rawDepartment || "Unassigned";
        if (!map.has(key)) {
            map.set(key, {
                department: key,
                fuelCost: 0,
                maintenanceCost: 0,
                tripCost: 0,
                otherCost: 0,
                totalCost: 0,
            });
        }

        const row = map.get(key);
        const cost = Number(r.totalCost) || 0;
        row.totalCost += cost;
        if (r.category === "Fuel") {
            row.fuelCost += cost;
        } else if (r.category === "Maintenance") {
            row.maintenanceCost += cost;
        } else if (r.category === "Trip Operations") {
            row.tripCost += cost;
        } else {
            row.otherCost += cost;
        }
    });
    const departmentTotal =
        Array.from(map.values()).reduce((sum, row) => sum + row.totalCost, 0) ||
        1;
    return Array.from(map.values())
        .map((row) => ({
            ...row,

            costShare: (row.totalCost / departmentTotal) * 100,
        }))
        .sort((a, b) => b.totalCost - a.totalCost);
}

function renderOverviewKpis(kpis) {
  setCostText("costKpiTotal", formatCostCurrency(kpis.total));
  setCostText("costKpiFuel", formatCostCurrency(kpis.fuel));
  setCostText("costKpiMaintenance", formatCostCurrency(kpis.maintenance));
  setCostText("costKpiAvgVehicle", formatCostCurrency(kpis.avgVehicle));
  setCostText(
    "costKpiAvgTrip",
    kpis.avgTrip == null ? "N/A" : formatCostCurrency(kpis.avgTrip),
  );
  if (kpis.budget == null || kpis.budget <= 0) {
    setCostText("costKpiBudgetUtil", "Not Configured");
  } else {
    setCostText(
      "costKpiBudgetUtil",
      formatCostPercent(kpis.budgetUtil) + " · " + kpis.budgetStatus,
    );
  }
}

function getBudgetPeriodLabel(config) {
  if (!config || config.overallBudget == null) return "Not Configured";
  const labels = {
    filter: "Current Filter Period",
    monthly: "Monthly",
    quarterly: "Quarterly",
    yearly: "Yearly",
    custom: "Custom",
  };
  const base = labels[config.periodType] || config.periodType || "—";
  if (config.periodType === "custom" && config.startDate && config.endDate) {
    return base + " (" + config.startDate + " → " + config.endDate + ")";
  }
  return base;
}

function renderBudgetPanel(kpis, options = {}) {
  const config = options.budgetConfig || costAnalysisState.budgetConfig || null;
  const matching = options.matching !== false;
  const configuredBudget =
    config && config.overallBudget != null ? config.overallBudget : null;
  const appliedBudget = matching ? configuredBudget : null;
  const actual = kpis.total;

  setCostText(
    "budgetKpiBudget",
    configuredBudget == null
      ? "Not Configured"
      : formatCostCurrency(configuredBudget),
  );
  setCostText("budgetKpiActual", formatCostCurrency(actual));
  setCostText("budgetKpiPeriod", getBudgetPeriodLabel(config));

  const updatedAt = config?.updatedAt ? new Date(config.updatedAt) : null;
  setCostText(
    "budgetKpiLastUpdated",
    updatedAt && !Number.isNaN(updatedAt.getTime())
      ? updatedAt.toLocaleString()
      : "—",
  );

  const mismatch = document.getElementById("costBudgetMismatchNote");
  if (mismatch) {
      const allowMismatchNote =
          window.costAnalysisModuleSettings?.showBudgetMismatch !== false;
      const showMismatch =
          allowMismatchNote &&
          configuredBudget != null &&
          configuredBudget >= 0 &&
          !matching;
      mismatch.hidden = !showMismatch;
  }

  const catWarn = document.getElementById("costCategoryBudgetWarn");
  const catRows =
    typeof buildCategoryBudgetRows === "function"
      ? buildCategoryBudgetRows(config, options.filtered || [])
      : [];
  if (catWarn && config?.categoryBudgets) {
    const catTotal = Object.values(config.categoryBudgets).reduce(
      (s, v) => s + (Number(v) || 0),
      0,
    );
    const show =
      configuredBudget != null && catTotal > configuredBudget + 0.001;
    catWarn.hidden = !show;
  }
  if (typeof renderCategoryBudgetTable === "function") {
    renderCategoryBudgetTable(catRows);
  }
  if (typeof renderBudgetHistoryTable === "function") {
      void renderBudgetHistoryTable();
  }

  if (appliedBudget == null || appliedBudget < 0) {
    setCostText("budgetKpiRemaining", "—");
    setCostText(
      "budgetKpiUtil",
      configuredBudget == null ? "Not Configured" : "Period Mismatch",
    );
    setCostText("budgetKpiVariance", "—");
    setCostText(
      "budgetKpiStatus",
      configuredBudget == null ? "Not Configured" : "Period Mismatch",
    );
    return;
  }

  const remaining = appliedBudget - actual;
  const variance = actual - appliedBudget;
  let varianceLabel =
    (variance > 0 ? "+" : "") + formatCostCurrency(variance);
  if (Math.abs(variance) < 0.005) {
    varianceLabel = formatCostCurrency(0) + " · Neutral";
  } else if (variance < 0) {
    varianceLabel += " · Favorable";
  } else {
    varianceLabel += " · Unfavorable";
  }

  setCostText("budgetKpiRemaining", formatCostCurrency(remaining));
  setCostText("budgetKpiUtil", formatCostPercent(kpis.budgetUtil));
  setCostText("budgetKpiVariance", varianceLabel);
  setCostText("budgetKpiStatus", kpis.budgetStatus);
}

function renderVehicleViewKpis(vehicleRows, filtered) {
  const total = sumCost(filtered);
  const highest = vehicleRows[0];
  const avg =
    vehicleRows.length > 0 ? total / vehicleRows.length : 0;
  const fuel = sumCost(filtered.filter((r) => r.category === "Fuel"));
  const mnt = sumCost(filtered.filter((r) => r.category === "Maintenance"));
  const ratio =
    mnt > 0 ? fuel / mnt : fuel > 0 ? null : null;

  setCostText("vehicleKpiCount", formatCostNumber(vehicleRows.length));
  setCostText(
    "vehicleKpiHighest",
    highest
      ? highest.vehicle + " (" + formatCostCurrency(highest.totalCost) + ")"
      : "—",
  );
  setCostText("vehicleKpiAvg", formatCostCurrency(avg));
  setCostText(
    "vehicleKpiRatio",
    ratio == null
      ? mnt === 0 && fuel === 0
        ? "—"
        : mnt === 0
          ? "Fuel only"
          : "—"
      : ratio.toLocaleString(undefined, { maximumFractionDigits: 2 }) + " : 1",
  );
}

function renderDepartmentViewKpis(deptRows) {
  const highest = deptRows[0];
  const total = deptRows.reduce((s, r) => s + r.totalCost, 0);
  const avg = deptRows.length ? total / deptRows.length : 0;
  setCostText("deptKpiCount", formatCostNumber(deptRows.length));
  setCostText(
    "deptKpiHighest",
    highest
      ? highest.department + " (" + formatCostCurrency(highest.totalCost) + ")"
      : "—",
  );
  setCostText("deptKpiAvg", formatCostCurrency(avg));
  setCostText(
    "deptKpiShare",
    highest ? formatCostPercent(highest.costShare) : "—",
  );
}

function renderTripViewState(filtered, trips) {
  const tripCosts = filtered.filter((r) => r.category === "Trip Operations");
  const note = document.getElementById("tripCostAvailabilityNote");
  const hasTripCost = tripCosts.length > 0;

  if (note) {
    note.hidden = hasTripCost;
  }

  const total = sumCost(tripCosts);
  const dist = tripCosts.reduce((s, r) => s + (Number(r.distance) || 0), 0);
  const avgTrip = tripCosts.length ? total / tripCosts.length : null;
  const avgKm = dist > 0 ? total / dist : null;

  setCostText(
    "tripKpiCount",
    hasTripCost ? formatCostNumber(tripCosts.length) : "0",
  );
  setCostText(
    "tripKpiTotal",
    hasTripCost ? formatCostCurrency(total) : "N/A",
  );
  setCostText(
    "tripKpiAvg",
    avgTrip == null ? "N/A" : formatCostCurrency(avgTrip),
  );
  setCostText(
    "tripKpiPerKm",
    avgKm == null ? "N/A" : formatCostCurrency(avgKm) + "/km",
  );

  return tripCosts;
}

function renderTrendsKpis(filtered, range, filters, allNormalized) {
  const current = sumCost(filtered);
  const prevRange = getPreviousComparableRange(range, filters.datePreset);
  const prevRecords = prevRange
    ? filterCostRecords(allNormalized, prevRange, {
        ...filters,
        /* keep vehicle/dept/category */
      })
    : [];
  const previous = sumCost(prevRecords);
  const change = current - previous;
  const days = range?.days || 1;
  const avgDaily = current / days;

  setCostText("trendKpiCurrent", formatCostCurrency(current));
  setCostText("trendKpiPrevious", formatCostCurrency(previous));
  let changeLabel = "No Change";
  if (change > 0.009) changeLabel = "Increased";
  else if (change < -0.009) changeLabel = "Decreased";
  setCostText(
    "trendKpiChange",
    (change > 0 ? "+" : "") +
      formatCostCurrency(change) +
      " · " +
      changeLabel,
  );
  setCostText("trendKpiDaily", formatCostCurrency(avgDaily));
}

/**
 * Central Cost Analysis pipeline.
 */
async function refreshCostAnalysis(options = {}) {
  if (isRefreshingCostAnalysis) return;
  isRefreshingCostAnalysis = true;

  try {
      const resetTablePage = options.resetTablePage === true;
      const refreshSources = options.refreshSources === true;

      const range = getResolvedCostDateRange();
      if (!range) return;

      const filters = getCostFilterValues();
      costAnalysisState.range = range;
      costAnalysisState.filters = filters;
      costAnalysisState.analysisView = filters.analysisView;

      if (refreshSources || !costAnalysisState.sources) {
          costAnalysisState.sources = await loadCostAnalysisSources();
          costAnalysisState.normalized = normalizeCostRecords(
              costAnalysisState.sources,
          );
      }

      const normalized = costAnalysisState.normalized;
      const filtered = filterCostRecords(normalized, range, filters);
      costAnalysisState.filtered = filtered;

      const vehicles = costAnalysisState.sources.vehicles || [];
      const trips = costAnalysisState.sources.dispatches || [];
      let energyAnalytics = null;

      if (
          filters.analysisView === "energy" &&
          typeof window.loadEnergyAnalytics === "function"
      ) {
          energyAnalytics = await window.loadEnergyAnalytics(
              range,
              filters,
              costAnalysisState.sources,
          );
          window.renderEnergyAnalytics?.(energyAnalytics);
      }

      if (
          !costAnalysisState.budgetConfig &&
          typeof loadCostBudgetConfiguration === "function"
      ) {
          costAnalysisState.budgetConfig = await loadCostBudgetConfiguration();
      }
      const budgetConfig = costAnalysisState.budgetConfig;
      const budgetMatches =
          typeof isCostBudgetMatchingPeriod === "function"
              ? isCostBudgetMatchingPeriod(budgetConfig, range)
              : true;
      const effectiveBudget =
          budgetMatches && budgetConfig && budgetConfig.overallBudget != null
              ? budgetConfig.overallBudget
              : null;
      costAnalysisState.budgetAmount = effectiveBudget;

      const overviewKpis = computeOverviewKpis(
          filtered,
          vehicles,
          trips,
          effectiveBudget,
      );
      renderOverviewKpis(overviewKpis);

      const vehicleRows = buildVehicleAggregates(filtered, vehicles);
      const deptRows = buildDepartmentAggregates(filtered);
      const monthly = buildMonthlyTotals(filtered, range);
      const categoryCounts = buildCategoryCounts(filtered);

      showCostAnalysisView(filters.analysisView);

      if (filters.analysisView === "vehicles") {
          renderVehicleViewKpis(vehicleRows, filtered);
      } else if (filters.analysisView === "departments") {
          renderDepartmentViewKpis(deptRows);
          const note = document.getElementById("deptAvailabilityNote");
          if (note) {
              const includeUnassigned =
                  window.costAnalysisModuleSettings
                      ?.includeUnassignedDepartment !== false;
              const unassigned = includeUnassigned
                  ? deptRows.find(
                        (department) => department.department === "Unassigned",
                    )
                  : null;
              note.hidden = !(
                  includeUnassigned &&
                  unassigned &&
                  unassigned.totalCost > 0
              );
          }
      } else if (filters.analysisView === "trips") {
          renderTripViewState(filtered, trips);
      } else if (filters.analysisView === "budget") {
          renderBudgetPanel(overviewKpis, {
              budgetConfig,
              matching: budgetMatches,
              filtered,
          });
      } else if (filters.analysisView === "trends") {
          renderTrendsKpis(filtered, range, filters, normalized);
      }

      if (typeof renderCostAnalysisCharts === "function") {
          renderCostAnalysisCharts(filters.analysisView, {
              categoryCounts,
              monthly,
              vehicleRows,
              deptRows,
              filtered,
              budget: overviewKpis.budget,
              actual: overviewKpis.total,
          });
      }

      if (typeof setCostTableConfig === "function") {
          let tableConfig;
          if (filters.analysisView === "energy") {
              tableConfig = {
                  columns: [
                      { key: "vehicle", label: "Vehicle", type: "text" },
                      { key: "vehicle_type", label: "Type", type: "text" },
                      { key: "powertrain_type", label: "Powertrain", type: "text" },
                      { key: "distance_km", label: "Distance (km)", type: "number" },
                      { key: "fuel_liters", label: "Fuel (L)", type: "number" },
                      { key: "electricity_kwh", label: "Electricity (kWh)", type: "number" },
                      { key: "fuel_efficiency_display", label: "km/L", type: "text" },
                      { key: "electric_efficiency_display", label: "km/kWh", type: "text" },
                      { key: "total_energy_cost", label: "Energy Cost", type: "currency" },
                      { key: "cost_per_km_display", label: "Cost/km", type: "text" },
                      { key: "data_status", label: "Data Status", type: "text" },
                  ],
                  rows: (energyAnalytics?.vehicles || []).map((vehicle) => ({
                      ...vehicle,
                      fuel_efficiency_display:
                          vehicle.fuel_efficiency_km_per_liter == null
                              ? "Insufficient Data"
                              : `${vehicle.fuel_efficiency_km_per_liter} km/L`,
                      electric_efficiency_display:
                          vehicle.electric_efficiency_km_per_kwh == null
                              ? "Insufficient Data"
                              : `${vehicle.electric_efficiency_km_per_kwh} km/kWh`,
                      cost_per_km_display:
                          vehicle.cost_per_km == null
                              ? "Insufficient Data"
                              : `${formatCostCurrency(vehicle.cost_per_km)} / km`,
                  })),
                  emptyMessage: "No energy analytics data is available.",
                  searchable: ["vehicle", "plate_number", "vehicle_type", "powertrain_type"],
              };
          } else if (filters.analysisView === "vehicles") {
              tableConfig = {
                  columns: [
                      { key: "vehicle", label: "Vehicle", type: "text" },
                      {
                          key: "plateNumber",
                          label: "Plate Number",
                          type: "text",
                      },
                      { key: "type", label: "Type", type: "text" },
                      { key: "department", label: "Department", type: "text" },
                      { key: "fuelCost", label: "Fuel Cost", type: "currency" },
                      {
                          key: "maintenanceCost",
                          label: "Maintenance Cost",
                          type: "currency",
                      },
                      {
                          key: "otherCost",
                          label: "Other Cost",
                          type: "currency",
                      },
                      {
                          key: "totalCost",
                          label: "Total Cost",
                          type: "currency",
                      },
                      {
                          key: "costShare",
                          label: "Cost Share",
                          type: "percent",
                      },
                  ],
                  rows: vehicleRows,
                  emptyMessage: "No vehicle cost data is available.",
                  searchable: ["vehicle", "plateNumber", "type", "department"],
              };
          } else if (filters.analysisView === "departments") {
              tableConfig = {
                  columns: [
                      { key: "department", label: "Department", type: "text" },
                      { key: "fuelCost", label: "Fuel Cost", type: "currency" },
                      {
                          key: "maintenanceCost",
                          label: "Maintenance Cost",
                          type: "currency",
                      },
                      { key: "tripCost", label: "Trip Cost", type: "currency" },
                      {
                          key: "otherCost",
                          label: "Other Cost",
                          type: "currency",
                      },
                      {
                          key: "totalCost",
                          label: "Total Cost",
                          type: "currency",
                      },
                      {
                          key: "costShare",
                          label: "Cost Share",
                          type: "percent",
                      },
                  ],
                  rows: deptRows,
                  emptyMessage: "No department cost data is available.",
                  searchable: ["department"],
              };
          } else if (filters.analysisView === "trips") {
              const tripRows = filtered.filter(
                  (r) => r.category === "Trip Operations",
              );
              tableConfig = {
                  columns: [
                      {
                          key: "referenceNumber",
                          label: "Trip No.",
                          type: "text",
                      },
                      { key: "date", label: "Date", type: "date" },
                      { key: "vehicleName", label: "Vehicle", type: "text" },
                      { key: "driverName", label: "Driver", type: "text" },
                      { key: "description", label: "Route", type: "text" },
                      { key: "distance", label: "Distance", type: "number" },
                      {
                          key: "totalCost",
                          label: "Total Cost",
                          type: "currency",
                      },
                      { key: "status", label: "Status", type: "text" },
                  ],
                  rows: tripRows,
                  emptyMessage: "No trip cost data is available.",
                  searchable: [
                      "referenceNumber",
                      "vehicleName",
                      "driverName",
                      "description",
                      "status",
                  ],
              };
          } else {
              tableConfig = {
                  columns: [
                      { key: "date", label: "Date", type: "date" },
                      {
                          key: "referenceNumber",
                          label: "Reference",
                          type: "text",
                      },
                      { key: "category", label: "Category", type: "text" },
                      {
                          key: "description",
                          label: "Description",
                          type: "text",
                      },
                      { key: "vehicleName", label: "Vehicle", type: "text" },
                      { key: "department", label: "Department", type: "text" },
                      { key: "sourceModule", label: "Source", type: "text" },
                      { key: "quantity", label: "Quantity", type: "number" },
                      { key: "unitCost", label: "Unit Cost", type: "currency" },
                      {
                          key: "totalCost",
                          label: "Total Cost",
                          type: "currency",
                      },
                      { key: "status", label: "Status", type: "text" },
                  ],
                  rows: filtered.map((r) => ({
                      ...r,
                      department: r.department || "Unassigned",
                  })),
                  emptyMessage:
                      "No cost records found for the selected filters.",
                  searchable: [
                      "referenceNumber",
                      "category",
                      "description",
                      "vehicleName",
                      "plateNumber",
                      "department",
                      "sourceModule",
                      "status",
                  ],
              };
          }

          setCostTableConfig(tableConfig, { resetPage: resetTablePage });
      }

      populateCostFilterOptions(costAnalysisState.sources, filtered);
      updateCostLastUpdated();
  } catch (error) {
      console.error("refreshCostAnalysis failed:", error);
      throw error;
  } finally {
      queueMicrotask(() => {
          isRefreshingCostAnalysis = false;
      });
  }
}

function populateCostFilterOptions(sources, filtered) {
  const vehicleSelect = document.getElementById("costVehicleFilter");
  const deptSelect = document.getElementById("costDepartmentFilter");
  const vehicleTypeSelect = document.getElementById("costVehicleTypeFilter");
  const powertrainSelect = document.getElementById("costPowertrainFilter");
  if (vehicleSelect) {
    const current = vehicleSelect.value;
    const names = Array.from(
        new Set(
            (sources.vehicles || [])
                .map((vehicle) => getCostVehicleLabel(vehicle))
                .filter(Boolean),
        ),
    ).sort((a, b) => a.localeCompare(b));
    vehicleSelect.innerHTML =
      '<option value="all">All Vehicles</option>' +
      names.map((n) => `<option value="${n}">${n}</option>`).join("");
    if ([...vehicleSelect.options].some((o) => o.value === current)) {
      vehicleSelect.value = current;
    }
  }
  if (deptSelect) {
    const current = deptSelect.value;
    const depts = new Set();
    (sources.vehicles || []).forEach((v) => v.department && depts.add(v.department));
    (filtered || []).forEach((r) => {
      if (r.department) depts.add(r.department);
    });
    const names = Array.from(depts).sort((a, b) => a.localeCompare(b));
    const includeUnassigned =
        window.costAnalysisModuleSettings?.includeUnassignedDepartment !==
        false;
    deptSelect.innerHTML =
        '<option value="all">All Departments</option>' +
        names
            .map((name) => `<option value="${name}">${name}</option>`)
            .join("") +
        (includeUnassigned
            ? '<option value="Unassigned">Unassigned</option>'
            : "");
    if ([...deptSelect.options].some((o) => o.value === current)) {
      deptSelect.value = current;
    }
  }
  if (vehicleTypeSelect) {
    const current = vehicleTypeSelect.value;
    const types = Array.from(
      new Set((sources.vehicles || []).map((vehicle) => vehicle.vehicle_type).filter(Boolean)),
    ).sort((a, b) => a.localeCompare(b));
    vehicleTypeSelect.innerHTML =
      '<option value="all">All Vehicle Types</option>' +
      types.map((type) => `<option value="${type}">${type}</option>`).join("");
    if ([...vehicleTypeSelect.options].some((option) => option.value === current)) {
      vehicleTypeSelect.value = current;
    }
  }
  if (powertrainSelect) {
    const current = powertrainSelect.value;
    const powertrains = Array.from(
      new Set((sources.vehicles || []).map((vehicle) => vehicle.powertrain_type).filter(Boolean)),
    ).sort((a, b) => a.localeCompare(b));
    powertrainSelect.innerHTML =
      '<option value="all">All Powertrains</option>' +
      powertrains
        .map((powertrain) => `<option value="${powertrain}">${powertrain.replaceAll("_", " ")}</option>`)
        .join("");
    if ([...powertrainSelect.options].some((option) => option.value === current)) {
      powertrainSelect.value = current;
    }
  }
}
