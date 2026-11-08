/* ==========================================
   Fuel Statistics — all real rows, ignore UI filters
========================================== */

function isFuelStatisticsDataRow(row) {
  if (!row || row.nodeType !== Node.ELEMENT_NODE) return false;

  const isHelper =
    row.classList.contains("fuel-no-results") ||
    row.classList.contains("helper-row") ||
    row.classList.contains("empty-state") ||
    row.dataset.helperRow === "true";

  if (isHelper) return false;

  return Boolean(
    row.querySelector(".fuel-number") ||
      row.querySelector(".fuel-checkbox") ||
      row.dataset.fuelId ||
      row.dataset.fuelNumber,
  );
}

function getFuelStatisticsRows(tableBody) {
  if (!tableBody) return [];
  return Array.from(tableBody.querySelectorAll("tr")).filter(
    isFuelStatisticsDataRow,
  );
}

let latestFuelEfficiencySummary = null;

function updateFuelStatistics(fuelLogs = null, efficiencySummary = undefined) {
  const totalEl = document.getElementById("totalFuelRecords");
  const consumedEl = document.getElementById("totalFuelConsumed");
  const costEl = document.getElementById("totalFuelCost");
  const avgEl = document.getElementById("averageCostPerLiter");
  const tableBody = document.getElementById("fuelTableBody");

  if (!totalEl || !consumedEl || !costEl || !avgEl || !tableBody) return;

  let total = 0;
  let liters = 0;
  let cost = 0;

  getFuelStatisticsRows(tableBody).forEach((row) => {
    total += 1;
    const q = Number.parseFloat(row.dataset.quantity);
    const c = Number.parseFloat(row.dataset.totalCost);
    if (!Number.isNaN(q)) liters += q;
    if (!Number.isNaN(c)) cost += c;
  });

  totalEl.textContent = String(total);
  consumedEl.textContent = formatFuelQuantity(liters);
  costEl.textContent = formatFuelCurrency(cost);

  if (liters > 0) {
    avgEl.textContent = formatFuelCurrency(cost / liters) + "/L";
  } else {
    avgEl.textContent = "₱0.00/L";
  }

  if (efficiencySummary !== undefined) {
    latestFuelEfficiencySummary = efficiencySummary;
  }

  updateFuelEfficiencySummary(latestFuelEfficiencySummary);
}

function updateFuelEfficiencySummary(summary) {
  const latestEl = document.getElementById("latestEstimatedFuelEfficiency");
  const aggregateEl = document.getElementById(
    "aggregateEstimatedFuelEfficiency",
  );
  const intervalsEl = document.getElementById("validFuelEfficiencyIntervals");

  if (!latestEl || !aggregateEl || !intervalsEl) return;

  const formatEfficiency = (value) => {
    if (value === null || value === undefined || value === "") return "N/A";

    const number = Number(value);
    if (Number.isNaN(number)) return "N/A";

    return `${number.toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })} km/L`;
  };

  latestEl.textContent = formatEfficiency(
    summary?.latest_valid_estimated_efficiency_km_per_liter,
  );
  aggregateEl.textContent = formatEfficiency(
    summary?.aggregate_estimated_efficiency_km_per_liter,
  );

  const count = Number(summary?.valid_efficiency_interval_count || 0);
  intervalsEl.textContent = `${count} valid ${count === 1 ? "interval" : "intervals"}`;
}

function initFuelStatistics() {
  const tableBody = document.getElementById("fuelTableBody");
  if (!tableBody) return;
  if (tableBody.dataset.fuelStatisticsInitialized === "true") return;
  tableBody.dataset.fuelStatisticsInitialized = "true";
}

function updateFuelPeriodComparison(comparison, failed = false) {
  const setText = (id, value) => {
    const element = document.getElementById(id);
    if (element) element.textContent = value;
  };
  const formatNumber = (value, unit = "") => {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) {
      return "N/A";
    }
    return Number(value).toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }) + unit;
  };
  const formatChange = (value, direction) => {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) {
      return "N/A — Unavailable";
    }
    if (Number(value) === 0 && direction !== "Stable") {
      return `${direction === "Increase" || direction === "Improving" ? "+" : "−"}<0.01% — ${direction}`;
    }
    return `${Number(value) > 0 ? "+" : ""}${formatNumber(value, "%")} — ${direction}`;
  };

  setText("fuelComparisonStatus", failed
    ? "Unable to load comparison. It will retry with the next fuel refresh."
    : comparison ? `Complete calendar days • ${comparison.timezone}` : "Comparison unavailable.");

  for (const [key, label] of [["previous", "Previous"], ["current", "Current"]]) {
    const period = comparison?.[key];
    setText(`fuelComparison${label}Dates`, period ? `${period.start_date} through ${period.end_date} (inclusive)` : "N/A");
    setText(`fuelComparison${label}Cost`, period ? `₱${formatNumber(period.fuel_cost)}` : "N/A");
    setText(`fuelComparison${label}Efficiency`, formatNumber(period?.estimated_efficiency_km_per_liter, " km/L"));
    setText(`fuelComparison${label}Context`, period
      ? `${period.record_count} transactions; ${period.valid_interval_count} valid intervals. ${period.expense_reason || ""} ${period.efficiency_reason || ""}`.trim()
      : "");
  }

  const changes = comparison?.changes;
  setText("fuelComparisonCostChange", formatChange(changes?.fuel_cost_percent, changes?.cost_direction));
  setText("fuelComparisonEfficiencyChange", formatChange(changes?.estimated_efficiency_percent, changes?.efficiency_direction));
  setText("fuelComparisonReasons", [changes?.cost_reason, changes?.efficiency_reason].filter(Boolean).join(" "));
  if (comparison) {
    setText("fuelComparisonScope", comparison.scope === "own_records"
      ? "Based on your authorized fuel records and authorized historical intervals."
      : "Based on authorized fleet fuel records.");
  }
  const advisory = document.getElementById("fuelComparisonAdvisory");
  if (advisory) {
    advisory.textContent = comparison?.advisory || "";
    advisory.hidden = !comparison?.advisory;
  }
}
