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

function updateFuelStatistics() {
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
}

function initFuelStatistics() {
  const tableBody = document.getElementById("fuelTableBody");
  if (!tableBody) return;
  if (tableBody.dataset.fuelStatisticsInitialized === "true") return;
  tableBody.dataset.fuelStatisticsInitialized = "true";
}
