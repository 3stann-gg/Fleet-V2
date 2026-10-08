(function () {
  "use strict";

  const state = {
    records: [],
    filteredRecords: [],
    currentPage: 1,
    perPage: 10,
  };

  const chartPalette = [
    "#00a86b",
    "#3b82f6",
    "#f59e0b",
    "#ef4444",
    "#8b5cf6",
    "#06b6d4",
    "#64748b",
  ];

  function escapeHtml(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function formatNumber(value, maximumFractionDigits = 0) {
    return Number(value || 0).toLocaleString(undefined, {
      maximumFractionDigits,
    });
  }

  function formatDate(value) {
    if (!value) {
      return "—";
    }

    const date = new Date(`${value}T00:00:00`);

    if (Number.isNaN(date.getTime())) {
      return value;
    }

    return date.toLocaleDateString(undefined, {
      year: "numeric",
      month: "short",
      day: "numeric",
    });
  }

  function statusClass(status) {
    const normalized = String(status || "").toLowerCase();

    if (normalized === "available" || normalized === "completed") {
      return "completed";
    }

    if (
      normalized === "on duty" ||
      normalized === "en route" ||
      normalized === "arrived"
    ) {
      return "ongoing";
    }

    if (normalized === "on leave" || normalized === "pending") {
      return "pending";
    }

    if (normalized === "cancelled") {
      return "cancelled";
    }

    return "inactive";
  }

  function performanceClass(level) {
    if (level === "High") {
      return "completed";
    }

    if (level === "Moderate") {
      return "pending";
    }

    return "inactive";
  }

  function setText(id, value) {
    const element = document.getElementById(id);

    if (element) {
      element.textContent = value;
    }
  }

  function populateSelect(select, values, defaultLabel) {
    if (!select) {
      return;
    }

    const previousValue = select.value;
    select.replaceChildren();

    const defaultOption = document.createElement("option");
    defaultOption.value = "all";
    defaultOption.textContent = defaultLabel;
    select.appendChild(defaultOption);

    values.forEach((value) => {
      const option = document.createElement("option");
      option.value = value;
      option.textContent = value;
      select.appendChild(option);
    });

    select.value = values.includes(previousValue) ? previousValue : "all";
  }

  function refreshFilterOptions() {
    const statuses = [...new Set(state.records.map((record) => record.status))]
      .filter(Boolean)
      .sort();
    const vehicles = [
      ...new Set(state.records.map((record) => record.assigned_vehicle)),
    ]
      .filter(Boolean)
      .sort();

    populateSelect(
      document.getElementById("performanceStatusFilter"),
      statuses,
      "All Statuses",
    );
    populateSelect(
      document.getElementById("performanceVehicleFilter"),
      vehicles,
      "All Vehicles",
    );
  }

  function applyFilters(resetPage = true) {
    const search = String(
      document.getElementById("performanceDriverSearch")?.value || "",
    )
      .trim()
      .toLowerCase();
    const status =
      document.getElementById("performanceStatusFilter")?.value || "all";
    const vehicle =
      document.getElementById("performanceVehicleFilter")?.value || "all";

    state.filteredRecords = state.records.filter((record) => {
      const matchesSearch =
        search === "" ||
        String(record.name || "").toLowerCase().includes(search) ||
        String(record.driver_number || "").toLowerCase().includes(search);
      const matchesStatus = status === "all" || record.status === status;
      const matchesVehicle =
        vehicle === "all" || record.assigned_vehicle === vehicle;

      return matchesSearch && matchesStatus && matchesVehicle;
    });

    if (resetPage) {
      state.currentPage = 1;
    }

    renderPage();
  }

  function renderKpis() {
    const totals = state.filteredRecords.reduce(
      (result, record) => {
        result.completed += Number(record.completed_trips) || 0;
        result.cancelled += Number(record.cancelled_trips) || 0;
        result.distance += Number(record.total_distance) || 0;
        result.fuel += Number(record.fuel_used) || 0;
        result.electricity += Number(record.electricity_used) || 0;
        return result;
      },
      { completed: 0, cancelled: 0, distance: 0, fuel: 0, electricity: 0 },
    );

    setText("performanceCompletedTrips", formatNumber(totals.completed));
    setText("performanceCancelledTrips", formatNumber(totals.cancelled));
    setText(
      "performanceTotalDistance",
      `${formatNumber(totals.distance, 2)} km`,
    );
    setText("performanceFuelUsed", `${formatNumber(totals.fuel, 2)} L`);
    setText(
      "performanceElectricityUsed",
      `${formatNumber(totals.electricity, 2)} kWh`,
    );
  }

  function setChartEmptyState(container, isEmpty) {
    if (!container) {
      return;
    }

    const empty = container.querySelector(".report-chart-empty");
    const body = container.querySelector(".report-chart-body");

    if (empty) {
      empty.hidden = !isEmpty;
    }

    if (body) {
      body.hidden = isEmpty;
    }
  }

  function renderTopDriversChart() {
    const container = document.getElementById("topPerformingDriversChart");
    const body = container?.querySelector(".report-bars");
    const records = [...state.filteredRecords]
      .filter((record) => Number(record.completed_trips) > 0)
      .sort(
        (left, right) =>
          Number(right.completed_trips) - Number(left.completed_trips),
      )
      .slice(0, 6);

    setChartEmptyState(container, records.length === 0);

    if (!body) {
      return;
    }

    if (records.length === 0) {
      body.replaceChildren();
      return;
    }

    const maximum = Math.max(
      ...records.map((record) => Number(record.completed_trips)),
      1,
    );

    body.innerHTML = records
      .map((record, index) => {
        const completedTrips = Number(record.completed_trips) || 0;
        const percentage = Math.max(
          4,
          Math.round((completedTrips / maximum) * 100),
        );

        return `
          <div class="report-bar-row">
            <span class="report-bar-label" title="${escapeHtml(record.name)}">
              ${escapeHtml(record.name)}
            </span>
            <div class="report-bar-track">
              <div
                class="report-bar-fill"
                style="width:${percentage}%;background:${chartPalette[index % chartPalette.length]}"
              ></div>
            </div>
            <span class="report-bar-value">${formatNumber(completedTrips)}</span>
          </div>
        `;
      })
      .join("");
  }

  function renderStatusChart() {
    const container = document.getElementById("driverStatusDistributionChart");
    const donut = container?.querySelector(".report-donut");
    const legend = container?.querySelector(".report-legend");
    const counts = state.filteredRecords.reduce((result, record) => {
      const status = record.status || "Unknown";
      result[status] = (result[status] || 0) + 1;
      return result;
    }, {});
    const entries = Object.entries(counts);
    const total = entries.reduce((sum, [, value]) => sum + value, 0);

    setChartEmptyState(container, total === 0);

    if (!donut || !legend) {
      return;
    }

    if (total === 0) {
      donut.style.background = "";
      legend.replaceChildren();
      return;
    }

    let cursor = 0;
    const segments = entries.map(([label, value], index) => {
      const start = cursor;
      cursor += (value / total) * 100;

      return {
        label,
        value,
        start,
        end: cursor,
        color: chartPalette[index % chartPalette.length],
      };
    });

    donut.style.background = `conic-gradient(${segments
      .map(
        (segment) =>
          `${segment.color} ${segment.start}% ${segment.end}%`,
      )
      .join(", ")})`;
    legend.innerHTML = segments
      .map(
        (segment) => `
          <li>
            <span class="report-legend-swatch" style="background:${segment.color}"></span>
            <span>${escapeHtml(segment.label)}</span>
            <strong>${formatNumber(segment.value)}</strong>
          </li>
        `,
      )
      .join("");
  }

  function renderTable() {
    const body = document.getElementById("driverPerformanceTableBody");

    if (!body) {
      return;
    }

    const start = (state.currentPage - 1) * state.perPage;
    const pageRecords = state.filteredRecords.slice(start, start + state.perPage);

    if (pageRecords.length === 0) {
      body.innerHTML = `
        <tr class="empty-state">
          <td colspan="10">No driver performance records match the selected filters.</td>
        </tr>
      `;
      return;
    }

    body.innerHTML = pageRecords
      .map((record) => {
        const photo = record.photo_url
          ? `<img src="${escapeHtml(record.photo_url)}" alt="${escapeHtml(record.name)}" data-driver-photo>`
          : "";

        return `
          <tr>
            <td>
              <div class="driver-performance-driver">
                <span class="driver-performance-avatar">
                  <span data-avatar-fallback>${escapeHtml(record.initials || "D")}</span>
                  ${photo}
                </span>
                <span>
                  <strong class="driver-performance-driver-name">${escapeHtml(record.name)}</strong>
                  <small class="driver-performance-driver-status">${escapeHtml(record.status || "—")}</small>
                </span>
              </div>
            </td>
            <td>${escapeHtml(record.driver_number || "—")}</td>
            <td>${escapeHtml(record.assigned_vehicle || "Unassigned")}</td>
            <td>${formatNumber(record.completed_trips)}</td>
            <td>${formatNumber(record.cancelled_trips)}</td>
            <td>${formatNumber(record.total_distance, 2)} km</td>
            <td>${formatNumber(record.fuel_used, 2)} L</td>
            <td>${formatNumber(record.electricity_used, 2)} kWh</td>
            <td>
              <span class="status-badge ${performanceClass(record.performance_level)}">
                ${escapeHtml(record.performance_level)}
              </span>
            </td>
            <td>
              <button
                type="button"
                class="table-btn btn-outline js-view-driver-performance"
                data-driver-id="${escapeHtml(record.id)}"
                title="View Details"
                aria-label="View performance details for ${escapeHtml(record.name)}"
              >
                <i class="ph ph-eye"></i>
                View Details
              </button>
            </td>
          </tr>
        `;
      })
      .join("");

    body.querySelectorAll("[data-driver-photo]").forEach((image) => {
      image.addEventListener("error", () => {
        image.remove();
      });
    });

    body.querySelectorAll(".js-view-driver-performance").forEach((button) => {
      button.addEventListener("click", () => {
        const record = state.records.find(
          (item) => String(item.id) === String(button.dataset.driverId),
        );

        if (record) {
          openDetailsModal(record);
        }
      });
    });
  }

  function renderPagination() {
    const pagination = document.getElementById("driverPerformancePagination");
    const summary = document.getElementById("driverPerformanceTableSummary");
    const total = state.filteredRecords.length;
    const totalPages = Math.max(1, Math.ceil(total / state.perPage));

    if (state.currentPage > totalPages) {
      state.currentPage = totalPages;
    }

    if (summary) {
      const first = total === 0 ? 0 : (state.currentPage - 1) * state.perPage + 1;
      const last = Math.min(state.currentPage * state.perPage, total);
      summary.textContent = `Showing ${first}–${last} of ${total} records`;
    }

    if (!pagination) {
      return;
    }

    pagination.replaceChildren();

    const addButton = (label, page, options = {}) => {
      const button = document.createElement("button");
      button.type = "button";
      button.textContent = label;
      button.disabled = options.disabled === true;

      if (options.active) {
        button.classList.add("active");
        button.setAttribute("aria-current", "page");
      }

      button.addEventListener("click", () => {
        state.currentPage = page;
        renderTable();
        renderPagination();
      });
      pagination.appendChild(button);
    };

    addButton("‹", Math.max(1, state.currentPage - 1), {
      disabled: state.currentPage === 1,
    });

    for (let page = 1; page <= totalPages; page += 1) {
      addButton(String(page), page, { active: page === state.currentPage });
    }

    addButton("›", Math.min(totalPages, state.currentPage + 1), {
      disabled: state.currentPage === totalPages,
    });
  }

  function renderPage() {
    renderKpis();
    renderTopDriversChart();
    renderStatusChart();
    renderTable();
    renderPagination();
  }

  function setModalPhoto(record) {
    const photo = document.getElementById("performanceDetailPhoto");
    const initials = document.getElementById("performanceDetailInitials");

    if (!photo || !initials) {
      return;
    }

    initials.textContent = record.initials || "D";
    photo.hidden = !record.photo_url;
    photo.removeAttribute("src");

    if (record.photo_url) {
      photo.src = record.photo_url;
      photo.alt = `${record.name} photo`;
      photo.onerror = () => {
        photo.hidden = true;
        photo.removeAttribute("src");
      };
    }
  }

  function renderRecentTrips(record) {
    const body = document.getElementById("performanceRecentTripsBody");
    const trips = Array.isArray(record.recent_trips) ? record.recent_trips : [];

    if (!body) {
      return;
    }

    if (trips.length === 0) {
      body.innerHTML = `
        <tr class="empty-state">
          <td colspan="5">No trips are available for the selected period.</td>
        </tr>
      `;
      return;
    }

    body.innerHTML = trips
      .map(
        (trip) => `
          <tr>
            <td>${escapeHtml(trip.dispatch_number || "—")}</td>
            <td>${escapeHtml(formatDate(trip.date))}</td>
            <td class="driver-performance-route">
              ${escapeHtml(trip.origin || "—")} → ${escapeHtml(trip.destination || "—")}
            </td>
            <td>${escapeHtml(trip.vehicle || "Unassigned")}</td>
            <td>
              <span class="status-badge ${statusClass(trip.status)}">
                ${escapeHtml(trip.status || "—")}
              </span>
            </td>
          </tr>
        `,
      )
      .join("");
  }

  function openDetailsModal(record) {
    const modal = document.getElementById("viewDriverPerformanceModal");

    if (!modal) {
      return;
    }

    setModalPhoto(record);
    setText("performanceDetailName", record.name || "—");
    setText("performanceDetailNumber", record.driver_number || "—");
    setText("performanceIdentityNumber", record.driver_number || "—");
    setText(
      "performanceDetailVehicle",
      record.assigned_vehicle || "Unassigned",
    );
    setText("performanceDetailLicenseNumber", record.license_number || "—");
    setText("performanceDetailLicenseClass", record.license_class || "—");
    setText(
      "performanceDetailLicenseExpiry",
      formatDate(record.license_expiry),
    );
    setText(
      "performanceDetailCompleted",
      formatNumber(record.completed_trips),
    );
    setText(
      "performanceDetailCancelled",
      formatNumber(record.cancelled_trips),
    );
    setText(
      "performanceDetailDistance",
      `${formatNumber(record.total_distance, 2)} km`,
    );
    setText(
      "performanceDetailFuel",
      `${formatNumber(record.fuel_used, 2)} L`,
    );
    setText(
      "performanceDetailElectricity",
      `${formatNumber(record.electricity_used, 2)} kWh`,
    );

    const status = document.getElementById("performanceDetailStatus");

    if (status) {
      status.className = `status-badge ${statusClass(record.status)}`;
      status.textContent = record.status || "—";
    }

    renderRecentTrips(record);
    modal.dataset.previousBodyOverflow = document.body.style.overflow;
    modal.classList.add("show");
    document.body.style.overflow = "hidden";
  }

  function closeDetailsModal() {
    const modal = document.getElementById("viewDriverPerformanceModal");

    if (!modal || !modal.classList.contains("show")) {
      return;
    }

    modal.classList.remove("show");
    document.body.style.overflow = modal.dataset.previousBodyOverflow || "";
    delete modal.dataset.previousBodyOverflow;
  }

  async function loadRecords() {
    const page = document.getElementById("driverPerformancePage");
    const refreshButton = document.getElementById("refreshDriverPerformance");
    const tableBody = document.getElementById("driverPerformanceTableBody");

    if (!page?.dataset.dataUrl) {
      return;
    }

    if (refreshButton) {
      refreshButton.disabled = true;
    }

    if (tableBody) {
      tableBody.innerHTML = `
        <tr class="helper-row">
          <td colspan="9">Loading driver performance records...</td>
        </tr>
      `;
    }

    try {
      const url = new URL(page.dataset.dataUrl, window.location.origin);
      url.searchParams.set(
        "date_range",
        document.getElementById("performanceDateRange")?.value || "last30",
      );

      const response = await fetch(url, {
        headers: {
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        credentials: "same-origin",
        cache: "no-store",
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || "Failed to load driver performance.");
      }

      state.records = Array.isArray(data.records) ? data.records : [];
      refreshFilterOptions();
      applyFilters();
    } catch (error) {
      console.error("DRIVER PERFORMANCE LOAD ERROR:", error);
      state.records = [];
      state.filteredRecords = [];
      renderPage();

      if (typeof window.showToast === "function") {
        window.showToast("Failed to load driver performance records.", "error");
      }
    } finally {
      if (refreshButton) {
        refreshButton.disabled = false;
      }
    }
  }

  function initialize() {
    const page = document.getElementById("driverPerformancePage");

    if (!page) {
      return;
    }

    document
      .getElementById("performanceDriverSearch")
      ?.addEventListener("input", () => applyFilters());
    document
      .getElementById("performanceStatusFilter")
      ?.addEventListener("change", () => applyFilters());
    document
      .getElementById("performanceVehicleFilter")
      ?.addEventListener("change", () => applyFilters());
    document
      .getElementById("performanceDateRange")
      ?.addEventListener("change", loadRecords);
    document
      .getElementById("refreshDriverPerformance")
      ?.addEventListener("click", loadRecords);
    document
      .getElementById("closeDriverPerformanceModal")
      ?.addEventListener("click", closeDetailsModal);
    document
      .getElementById("closeDriverPerformanceModalFooter")
      ?.addEventListener("click", closeDetailsModal);

    document
      .getElementById("viewDriverPerformanceModal")
      ?.addEventListener("click", (event) => {
        if (event.target.id === "viewDriverPerformanceModal") {
          closeDetailsModal();
        }
      });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        closeDetailsModal();
      }
    });

    loadRecords();
  }

  document.addEventListener("DOMContentLoaded", initialize);
})();
