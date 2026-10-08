/* ==========================================
   Reports charts — CSS/SVG (no third-party chart lib)
========================================== */

const reportChartPalette = [
  "#00a86b",
  "#3b82f6",
  "#f59e0b",
  "#ef4444",
  "#8b5cf6",
  "#06b6d4",
  "#64748b",
  "#ec4899",
];

function escapeReportChartHtml(value) {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function setChartEmptyState(container, empty) {
  if (!container) return;
  const emptyEl = container.querySelector(".report-chart-empty");
  const body = container.querySelector(".report-chart-body");
  if (emptyEl) emptyEl.hidden = !empty;
  if (body) body.hidden = empty;
}

function renderDonutChart(containerId, counts) {
  const container = document.getElementById(containerId);
  if (!container) return;

  const entries = Object.entries(counts || {}).filter(([, v]) => Number(v) > 0);
  const total = entries.reduce((s, [, v]) => s + Number(v), 0);
  setChartEmptyState(container, total === 0);
  if (total === 0) {
      const donut = container.querySelector(".report-donut");
      const legend = container.querySelector(".report-legend");
      if (donut) {
          donut.style.background = "";
      }
      if (legend) {
          legend.innerHTML = "";
      }
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
      color: reportChartPalette[index % reportChartPalette.length],
      start,
      end: cursor,
    };
  });

  const gradient = segments
    .map((s) => `${s.color} ${s.start}% ${s.end}%`)
    .join(", ");

  const donut = container.querySelector(".report-donut");
  const legend = container.querySelector(".report-legend");
  if (donut) {
    donut.style.background = `conic-gradient(${gradient})`;
  }
  if (legend) {
    legend.innerHTML = segments
        .map(
            (s) => `
        <li>
          <span class="report-legend-swatch" style="background:${s.color}"></span>
          <span>${escapeReportChartHtml(s.label)}</span>
          <strong>${s.value}</strong>
        </li>`,
        )
        .join("");
  }
}

function renderBarChart(containerId, items, options = {}) {
    const container = document.getElementById(containerId);
    if (!container) {
        return;
    }
    const list = (items || []).filter((item) => Number(item.value) >= 0);
    const hasData = list.some((item) => Number(item.value) > 0);
    setChartEmptyState(container, !hasData);
    const body = container.querySelector(".report-bars");
    if (!body) {
        return;
    }
    if (!hasData) {
        body.innerHTML = "";
        return;
    }
    const max = Math.max(...list.map((item) => Number(item.value) || 0), 1);
    const horizontal = options.horizontal === true;
    body.classList.toggle("is-horizontal", horizontal);
    const formatValue =
        options.currency && typeof formatReportCurrency === "function"
            ? (value) => formatReportCurrency(value)
            : (value) =>
                  Number(value).toLocaleString(undefined, {
                      maximumFractionDigits: 1,
                  });
    body.innerHTML = list
        .map((item, index) => {
            const value = Number(item.value) || 0;
            const pct =
                value > 0 ? Math.max(4, Math.round((value / max) * 100)) : 0;
            const color = reportChartPalette[index % reportChartPalette.length];
            const label = item.name || item.label || "";
            const safeLabel =
                typeof escapeReportChartHtml === "function"
                    ? escapeReportChartHtml(label)
                    : String(label);
            if (horizontal) {
                return `
              <div class="report-bar-row">
                <span
                  class="report-bar-label"
                  title="${safeLabel}"
                >
                  ${safeLabel}
                </span>
                <div class="report-bar-track">
                  <div
                    class="report-bar-fill"
                    style="
                      width:${pct}%;
                      background:${color};
                    "
                  ></div>
                </div>

                <span class="report-bar-value">
                  ${formatValue(value)}
                </span>
              </div>
            `;
            }
            return `
            <div class="report-bar-col">
              <div class="report-bar-col-track">
                <div
                  class="report-bar-fill vertical"
                  style="
                    height:${pct}%;
                    background:${color};
                  "
                ></div>
              </div>
              <span
                class="report-bar-label"
                title="${safeLabel}"
              >
                ${safeLabel}
              </span>
              <span class="report-bar-value">
                ${formatValue(value)}
              </span>
            </div>
          `;
        })
        .join("");
}

function renderGroupedCostChart(containerId, months) {
  const container = document.getElementById(containerId);
  if (!container) return;

  const list = months || [];
  const hasData = list.some((m) => Number(m.fuel) > 0 || Number(m.maintenance) > 0);
  setChartEmptyState(container, !hasData);
  if (!hasData) {
      const body = container.querySelector(".report-bars");
      if (body) {
          body.innerHTML = "";
      }
      return;
  }

  const max = Math.max(
    ...list.flatMap((m) => [Number(m.fuel) || 0, Number(m.maintenance) || 0]),
    1,
  );
  const body = container.querySelector(".report-bars");
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
        <div class="report-bar-col grouped">
          <div class="report-bar-col-track grouped">
            <div class="report-bar-fill vertical" style="height:${fuelPct}%;background:#00a86b" title="Fuel"></div>
            <div class="report-bar-fill vertical" style="height:${mntPct}%;background:#3b82f6" title="Maintenance"></div>
          </div>
          <span class="report-bar-label">${m.label}</span>
        </div>`;
    })
    .join("");
}

function hideAllReportChartCards() {
  document.querySelectorAll("[data-chart-for]").forEach((card) => {
    card.hidden = true;
  });
}

function showChartsFor(reportType) {
  hideAllReportChartCards();
  document
    .querySelectorAll(`[data-chart-for="${reportType}"], [data-chart-for="all"]`)
    .forEach((card) => {
      card.hidden = false;
    });
}

/**
 * Render charts for the active report type.
 * Overview charts also receive the overview model separately.
 */
function renderReportsCharts(reportType, model, overviewModel) {
  showChartsFor(reportType === "overview" ? "overview" : reportType);

  if (reportType === "overview" && overviewModel) {
    renderDonutChart("chartFleetStatus", overviewModel.charts.fleetStatus);
    renderBarChart("chartTripActivity", overviewModel.charts.tripActivity);
    renderGroupedCostChart(
      "chartCostComparison",
      overviewModel.charts.costComparison,
    );
    renderBarChart(
      "chartVehicleUtilization",
      overviewModel.charts.vehicleUtilization,
      { horizontal: true },
    );
    return;
  }

  if (reportType === "utilization") {
    renderBarChart("chartUsageRanking", model.charts.usageRanking, {
      horizontal: true,
    });
    renderBarChart("chartUsageByType", model.charts.byType);
  } else if (reportType === "trips") {
    renderDonutChart("chartTripStatus", model.charts.status);
    renderBarChart("chartTripsOverTime", model.charts.overTime);
    renderBarChart("chartTopDestinations", model.charts.destinations, {
      horizontal: true,
    });
  } else if (reportType === "reservations") {
    renderDonutChart("chartReservationStatus", model.charts.status);
    renderBarChart("chartReservationsOverTime", model.charts.overTime);
    renderBarChart("chartReservationsByDept", model.charts.byDepartment, {
      horizontal: true,
    });
  } else if (reportType === "maintenance") {
    renderBarChart("chartMaintenanceByType", model.charts.byType, {
      horizontal: true,
    });
    renderDonutChart("chartMaintenanceStatus", model.charts.status);
    renderBarChart("chartMaintenanceCostOverTime", model.charts.costOverTime, {
        currency: true,
    });
    renderBarChart("chartTopMaintenanceVehicles", model.charts.topVehicles, {
        horizontal: true,
        currency: true,
    });
  } else if (reportType === "fuel") {
    renderBarChart("chartFuelQtyOverTime", model.charts.qtyOverTime);
    renderBarChart("chartFuelCostOverTime", model.charts.costOverTime, {
        currency: true,
    });
    renderBarChart("chartTopFuelVehicles", model.charts.topVehicles, {
      horizontal: true,
    });
    renderDonutChart(
      "chartFuelType",
      Object.fromEntries(
        (model.charts.byType || []).map((i) => [i.name, i.value]),
      ),
    );
  } else if (reportType === "drivers") {
    renderBarChart("chartTopDrivers", model.charts.topDrivers, {
      horizontal: true,
    });
    renderDonutChart("chartDriverStatus", model.charts.status);
  }
}
