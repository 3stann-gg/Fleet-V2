/* ==========================================
   Cost Analysis charts (CSS/SVG, no new library)
========================================== */

const costChartPalette = [
  "#00a86b",
  "#3b82f6",
  "#f59e0b",
  "#ef4444",
  "#8b5cf6",
  "#06b6d4",
  "#64748b",
  "#ec4899",
];

/** Registry — CSS charts re-render in place (no canvas instances) */
const costAnalysisCharts = {
  categoryBreakdown: null,
  monthlyTrend: null,
  fuelMaintenance: null,
  vehicleCosts: null,
  departmentCosts: null,
};

function escapeCostChartHtml(value) {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function setCostChartEmpty(container, empty) {
  if (!container) return;
  const emptyEl = container.querySelector(".cost-chart-empty");
  const body = container.querySelector(".cost-chart-body");
  if (emptyEl) emptyEl.hidden = !empty;
  if (body) body.hidden = empty;
}

function renderCostDonut(containerId, counts) {
  const container = document.getElementById(containerId);
  if (!container) return;

  const entries = Object.entries(counts || {}).filter(([, v]) => Number(v) > 0);
  const total = entries.reduce((s, [, v]) => s + Number(v), 0);
  setCostChartEmpty(container, total === 0);
  if (total === 0) {
      const donut = container.querySelector(".cost-donut");
      const legend = container.querySelector(".cost-legend");
      if (donut) {
          donut.style.background = "";
      }
      if (legend) {
          legend.innerHTML = "";
      }
      costAnalysisCharts.categoryBreakdown = null;
      return;
  }

  let cursor = 0;
  const segments = entries.map(([label, value], index) => {
    const pct = (Number(value) / total) * 100;
    const start = cursor;
    cursor += pct;
    return {
      label,
      value: Number(value),
      color: costChartPalette[index % costChartPalette.length],
      start,
      end: cursor,
    };
  });

  const donut = container.querySelector(".cost-donut");
  const legend = container.querySelector(".cost-legend");
  if (donut) {
    donut.style.background =
      "conic-gradient(" +
      segments.map((s) => `${s.color} ${s.start}% ${s.end}%`).join(", ") +
      ")";
  }
  if (legend) {
    legend.innerHTML = segments
        .map(
            (s) => `
      <li>
        <span class="cost-legend-swatch" style="background:${s.color}"></span>
        <span>${escapeCostChartHtml(s.label)}</span>
        <strong>${typeof formatCostCurrency === "function" ? formatCostCurrency(s.value) : s.value}</strong>
      </li>`,
        )
        .join("");
  }
  costAnalysisCharts.categoryBreakdown = { type: "donut", data: segments };
}

function renderCostBars(containerId, items, options = {}) {
    const container = document.getElementById(containerId);
    if (!container) {
        return;
    }
    const list = items || [];
    const hasData = list.some((item) => Number(item.value) > 0);
    setCostChartEmpty(container, !hasData);
    const body = container.querySelector(".cost-bars");
    if (!hasData) {
        if (body) {
            body.innerHTML = "";
        }
        return;
    }
    if (!body) {
        return;
    }
    const max = Math.max(...list.map((item) => Number(item.value) || 0), 1);
    const horizontal = options.horizontal === true;
    body.classList.toggle("is-horizontal", horizontal);
    const format =
        options.currency && typeof formatCostCurrency === "function"
            ? (value) => formatCostCurrency(value)
            : (value) =>
                  Number(value).toLocaleString(undefined, {
                      maximumFractionDigits: 1,
                  });
    body.innerHTML = list
        .map((item, index) => {
            const value = Number(item.value) || 0;
            const pct =
                value > 0 ? Math.max(4, Math.round((value / max) * 100)) : 0;
            const color = costChartPalette[index % costChartPalette.length];
            const label = item.label || item.name || "";
            const safeLabel =
                typeof escapeCostChartHtml === "function"
                    ? escapeCostChartHtml(label)
                    : String(label);

            if (horizontal) {
                return `
          <div class="cost-bar-row">
            <span
              class="cost-bar-label"
              title="${safeLabel}"
            >
              ${safeLabel}
            </span>
            <div class="cost-bar-track">
              <div
                class="cost-bar-fill"
                style="
                  width:${pct}%;
                  background:${color};
                "
              ></div>
            </div>
            <span class="cost-bar-value">
              ${format(value)}
            </span>
          </div>
        `;
            }
            return `
        <div class="cost-bar-col">
          <div class="cost-bar-col-track">
            <div
              class="cost-bar-fill vertical"
              style="
                height:${pct}%;
                background:${color};
              "
            ></div>
          </div>
          <span
            class="cost-bar-label"
            title="${safeLabel}"
          >
            ${safeLabel}
          </span>
          <span class="cost-bar-value">
            ${format(value)}
          </span>
        </div>
      `;
        })
        .join("");
}

function renderCostGroupedBars(containerId, months) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const list = months || [];
  const hasData = list.some(
    (m) => Number(m.fuel) > 0 || Number(m.maintenance) > 0,
  );
  setCostChartEmpty(container, !hasData);
  if (!hasData) return;

  const max = Math.max(
    ...list.flatMap((m) => [Number(m.fuel) || 0, Number(m.maintenance) || 0]),
    1,
  );
  const body = container.querySelector(".cost-bars");
  if (!body) return;
  body.classList.remove("is-horizontal");
  body.innerHTML = list
    .map((m) => {
      const fuelValue = Number(m.fuel) || 0;
      const maintenanceValue = Number(m.maintenance) || 0;
      const fuelPct =
          fuelValue > 0 ? Math.max(3, Math.round((fuelValue / max) * 100)) : 0;
      const mntPct =
          maintenanceValue > 0
              ? Math.max(3, Math.round((maintenanceValue / max) * 100))
              : 0;
      return `
        <div class="cost-bar-col grouped">
          <div class="cost-bar-col-track grouped">
            <div class="cost-bar-fill vertical" style="height:${fuelPct}%;background:#00a86b" title="Fuel"></div>
            <div class="cost-bar-fill vertical" style="height:${mntPct}%;background:#3b82f6" title="Maintenance"></div>
          </div>
          <span class="cost-bar-label">${m.label}</span>
        </div>`;
    })
    .join("");
  costAnalysisCharts.fuelMaintenance = { type: "grouped", data: list };
}

function renderBudgetVsActualChart(containerId, budget, actual) {
  const container = document.getElementById(containerId);
  if (!container) return;
  if (budget == null || budget <= 0) {
    setCostChartEmpty(container, true);
    const empty = container.querySelector(".cost-chart-empty");
    if (empty) empty.textContent = "Budget has not been configured.";
    return;
  }
  renderCostBars(
    containerId,
    [
      { label: "Budget", value: budget },
      { label: "Actual", value: actual },
    ],
    { currency: true },
  );
}

function hideCostChartCards() {
  document.querySelectorAll("[data-cost-chart]").forEach((card) => {
    card.hidden = true;
  });
}

function showCostChartsFor(view) {
  hideCostChartCards();
  document
    .querySelectorAll(
      `[data-cost-chart="${view}"], [data-cost-chart="all"]`,
    )
    .forEach((card) => {
      card.hidden = false;
    });
}

function renderCostAnalysisCharts(view, data) {
  const v = view || "overview";
  showCostChartsFor(v === "overview" ? "overview" : v);

  if (v === "overview") {
    renderCostDonut("costChartCategory", data.categoryCounts);
    renderCostBars(
      "costChartMonthly",
      (data.monthly || []).map((m) => ({ label: m.label, value: m.value })),
      { currency: true },
    );
    renderCostGroupedBars("costChartFuelMnt", data.monthly);
    renderCostBars(
      "costChartTopVehicles",
      (data.vehicleRows || []).slice(0, 8).map((r) => ({
        name: r.vehicle,
        value: r.totalCost,
      })),
      { horizontal: true, currency: true },
    );
    renderCostBars(
      "costChartDepartments",
      (data.deptRows || []).slice(0, 8).map((r) => ({
        name: r.department,
        value: r.totalCost,
      })),
      { horizontal: true, currency: true },
    );
    costAnalysisCharts.monthlyTrend = data.monthly;
    costAnalysisCharts.vehicleCosts = data.vehicleRows;
    costAnalysisCharts.departmentCosts = data.deptRows;
  } else if (v === "vehicles") {
    renderCostBars(
      "costChartVehicleRank",
      (data.vehicleRows || []).slice(0, 10).map((r) => ({
        name: r.vehicle,
        value: r.totalCost,
      })),
      { horizontal: true, currency: true },
    );
    renderCostBars(
      "costChartVehicleFuelMnt",
      (data.vehicleRows || []).slice(0, 8).map((r) => ({
        name: r.vehicle,
        value: r.fuelCost + r.maintenanceCost,
      })),
      { horizontal: true, currency: true },
    );
  } else if (v === "departments") {
    renderCostBars(
      "costChartDeptRank",
      (data.deptRows || []).map((r) => ({
        name: r.department,
        value: r.totalCost,
      })),
      { horizontal: true, currency: true },
    );
  } else if (v === "trips") {
    const tripRecords = (data.filtered || []).filter(
      (r) => r.category === "Trip Operations",
    );
    renderCostBars(
      "costChartTripTime",
      tripRecords.map((r) => ({
        label: r.referenceNumber || r.date,
        value: r.totalCost,
      })),
      { currency: true },
    );
  } else if (v === "budget") {
    renderBudgetVsActualChart(
      "costChartBudgetVsActual",
      data.budget,
      data.actual,
    );
    renderCostBars(
      "costChartBudgetMonthly",
      (data.monthly || []).map((m) => ({ label: m.label, value: m.value })),
      { currency: true },
    );
  } else if (v === "trends") {
    renderCostBars(
      "costChartTrend",
      (data.monthly || []).map((m) => ({ label: m.label, value: m.value })),
      { currency: true },
    );
    let running = 0;
    const cumulative = (data.monthly || []).map((m) => {
      running += Number(m.value) || 0;
      return { label: m.label, value: running };
    });
    renderCostBars("costChartCumulative", cumulative, { currency: true });
  }
}
