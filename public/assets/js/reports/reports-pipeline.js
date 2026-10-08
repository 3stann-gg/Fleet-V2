/* ==========================================
   Reports pipeline: dates, filters, KPIs, views
========================================== */

let isRefreshingReports = false;
let reportsState = {
  sources: null,
  range: null,
  filters: null,
  reportType: "overview",
  lastModel: null,
  lastOverview: null,
  table: {
    rows: [],
    columns: [],
    search: "",
    sortField: null,
    sortDir: null,
    page: 1,
    pageSize: 5,
  },
};

function getReportTypeLabel(type) {
  const labels = {
    overview: "Overview",
    utilization: "Fleet Utilization",
    trips: "Trip & Dispatch",
    reservations: "Reservations",
    maintenance: "Maintenance",
    fuel: "Fuel & Cost",
    drivers: "Driver Performance",
  };
  return labels[type] || "Overview";
}

/**
 * Human-readable filter summary for Print / PDF / Excel.
 * Omits default All / empty values.
 */
function getReportsFilterSummary() {
  const filters = reportsState.filters || getReportsFilterValues();
  const range = reportsState.range;
  const parts = [];

  if (range) {
    if (range.label === "Custom Range" && range.start && range.end) {
      parts.push(
        "Date Range: " +
          range.start.toLocaleDateString(undefined, {
            year: "numeric",
            month: "long",
            day: "numeric",
          }) +
          " – " +
          range.end.toLocaleDateString(undefined, {
            year: "numeric",
            month: "long",
            day: "numeric",
          }),
      );
    } else {
      parts.push("Date Range: " + (range.label || "—"));
    }
  }

  if (filters.vehicle && filters.vehicle !== "all") {
    parts.push("Vehicle: " + filters.vehicle);
  }

  if (filters.department && filters.department !== "all") {
    parts.push("Department: " + filters.department);
  }

  parts.push(
    "Report Type: " + getReportTypeLabel(filters.reportType || "overview"),
  );

  const search =
    typeof reportsTableState !== "undefined"
      ? (reportsTableState.search || "").trim()
      : "";
  if (search) {
    parts.push("Search: " + search);
  }

  return parts;
}

/**
 * Active report output model for Print / PDF / Excel.
 * Reuses processed pipeline state + table search/sort (no pagination).
 */
function getActiveReportOutputModel() {
  const filters = reportsState.filters || getReportsFilterValues();
  const range = reportsState.range;
  const model = reportsState.lastModel;
  const overview = reportsState.lastOverview;
  const reportType = filters.reportType || "overview";
  const now = new Date();

  const columns =
    typeof getReportsTableColumns === "function"
      ? getReportsTableColumns()
      : model?.table?.columns
        ? model.table.columns.slice()
        : [];

  const rows =
    typeof getProcessedReportsTableRows === "function"
      ? getProcessedReportsTableRows()
      : (model?.table?.rows || []).slice();

  const kpis =
    reportType === "overview" && overview?.kpis
      ? overview.kpis
      : model?.kpis || {};

  return {
    reportType,
    title: getReportTypeLabel(reportType) + " Report",
    subtitle: "Reports & Analytics",
    generatedAt: now,
    generatedDate: now.toLocaleDateString(),
    generatedTime: now.toLocaleTimeString(),
    filterSummary: getReportsFilterSummary(),
    kpis,
    charts: model?.charts || overview?.charts || {},
    columns,
    rows,
    sortState:
      typeof reportsTableState !== "undefined"
        ? {
            field: reportsTableState.sortField,
            direction: reportsTableState.sortDir,
          }
        : null,
    totalRecords: rows.length,
    rangeLabel: range?.label || "—",
    vehicle:
      !filters.vehicle || filters.vehicle === "all"
        ? "All Vehicles"
        : filters.vehicle,
    department:
      !filters.department || filters.department === "all"
        ? "All Departments"
        : filters.department,
  };
}

function updateReportsOutputMetaStrip() {
    const el = document.getElementById("reportsOutputMeta");
    if (!el) {
        return;
    }
    const summary = getReportsFilterSummary();
    const fragment = document.createDocumentFragment();
    summary.forEach((line) => {
        const span = document.createElement("span");
        const idx = line.indexOf(":");
        if (idx === -1) {
            span.textContent = line;
        } else {
            const strong = document.createElement("strong");
            strong.textContent = line.slice(0, idx + 1);
            span.appendChild(strong);
            span.appendChild(
                document.createTextNode(" " + line.slice(idx + 1).trim()),
            );
        }
        fragment.appendChild(span);
    });
    el.replaceChildren(fragment);
}

function startOfLocalDay(date) {
  const d = new Date(date);
  d.setHours(0, 0, 0, 0);
  return d;
}

function endOfLocalDay(date) {
  const d = new Date(date);
  d.setHours(23, 59, 59, 999);
  return d;
}

function parseISODateLocal(iso) {
  if (!iso) return null;
  const parts = String(iso).slice(0, 10).split("-");
  if (parts.length !== 3) return null;
  const y = Number(parts[0]);
  const m = Number(parts[1]) - 1;
  const day = Number(parts[2]);
  if ([y, m, day].some((n) => Number.isNaN(n))) return null;
  return new Date(y, m, day);
}

/**
 * Resolve global report date range (local calendar days).
 * @returns {{ start: Date, end: Date, label: string } | null}
 */
function getResolvedReportDateRange() {
  const preset = document.getElementById("reportDateRange")?.value || "last30";
  const today = startOfLocalDay(new Date());

  if (preset === "today") {
    return { start: today, end: endOfLocalDay(today), label: "Today" };
  }

  if (preset === "last7") {
    const start = new Date(today);
    start.setDate(start.getDate() - 6);
    return { start, end: endOfLocalDay(today), label: "Last 7 Days" };
  }

  if (preset === "last30") {
    const start = new Date(today);
    start.setDate(start.getDate() - 29);
    return { start, end: endOfLocalDay(today), label: "Last 30 Days" };
  }

  if (preset === "thisMonth") {
    const start = new Date(today.getFullYear(), today.getMonth(), 1);
    return { start, end: endOfLocalDay(today), label: "This Month" };
  }

  if (preset === "lastMonth") {
    const start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
    const end = new Date(today.getFullYear(), today.getMonth(), 0);
    return {
      start: startOfLocalDay(start),
      end: endOfLocalDay(end),
      label: "Last Month",
    };
  }

  if (preset === "thisYear") {
    const start = new Date(today.getFullYear(), 0, 1);
    return { start, end: endOfLocalDay(today), label: "This Year" };
  }

  if (preset === "custom") {
    const startRaw = document.getElementById("reportStartDate")?.value;
    const endRaw = document.getElementById("reportEndDate")?.value;
    const start = parseISODateLocal(startRaw);
    const end = parseISODateLocal(endRaw);

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

    return {
      start: startOfLocalDay(start),
      end: endOfLocalDay(end),
      label: "Custom Range",
    };
  }

  const start = new Date(today);
  start.setDate(start.getDate() - 29);
  return { start, end: endOfLocalDay(today), label: "Last 30 Days" };
}

function isDateInRange(isoDate, range) {
  if (!range || !isoDate) return false;
  const d = parseISODateLocal(isoDate);
  if (!d) return false;
  const t = d.getTime();
  return t >= range.start.getTime() && t <= range.end.getTime();
}

function getReportsFilterValues() {
  return {
    vehicle: (document.getElementById("reportVehicleFilter")?.value || "all").trim(),
    department: (document.getElementById("reportDepartmentFilter")?.value || "all").trim(),
    reportType: (document.getElementById("reportTypeFilter")?.value || "overview").trim(),
  };
}

function matchesVehicleFilter(record, vehicleFilter) {
  if (!vehicleFilter || vehicleFilter === "all") return true;
  return (
    record.vehicleName === vehicleFilter ||
    record.name === vehicleFilter ||
    record.vehicleId === vehicleFilter
  );
}

function matchesDepartmentFilter(record, departmentFilter) {
    if (!departmentFilter || departmentFilter === "all") {
        return true;
    }
    const department = record.department || "Unassigned";
    return department === departmentFilter;
}

function filterDatedRecords(records, range, filters, options = {}) {
  const applyDate = options.applyDate !== false;
  const applyVehicle = options.applyVehicle !== false;
  const applyDepartment = options.applyDepartment !== false;

  return (records || []).filter((row) => {
    if (applyDate && range && row.date && !isDateInRange(row.date, range)) {
      return false;
    }
    if (applyVehicle && !matchesVehicleFilter(row, filters.vehicle)) {
      return false;
    }
    if (applyDepartment && !matchesDepartmentFilter(row, filters.department)) {
      return false;
    }
    return true;
  });
}

function formatReportCurrency(value) {
  const num = Number(value) || 0;
  return (
    "₱" +
    num.toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })
  );
}

function formatReportNumber(value) {
  const num = Number(value) || 0;
  return num.toLocaleString(undefined, { maximumFractionDigits: 0 });
}

function formatReportLiters(value) {
  const num = Number(value) || 0;
  return (
    num.toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }) + " L"
  );
}

function formatReportPercent(value) {
  const num = Number(value) || 0;
  return (
    num.toLocaleString(undefined, {
      minimumFractionDigits: 1,
      maximumFractionDigits: 1,
    }) + "%"
  );
}

function normalizeVehicleStatus(status) {
  const s = String(status || "")
    .trim()
    .toLowerCase()
    .replace(/\s+/g, " ");
  if (s.includes("available")) return "Available";
  if (s.includes("trip") || s.includes("ontrip") || s === "on trip") return "On Trip";
  if (s.includes("reserv")) return "Reserved";
  if (s.includes("maint")) return "Maintenance";
  if (s.includes("out")) return "Out of Service";
  return status || "Unknown";
}

function isActiveReservationStatus(status) {
    const normalized = String(status || "")
        .trim()
        .toLowerCase();
    return ["pending", "approved", "scheduled"].includes(normalized);
}

function monthKey(iso) {
  return String(iso || "").slice(0, 7);
}

function monthLabel(key) {
  if (!key || key.length < 7) return key;
  const [y, m] = key.split("-");
  const date = new Date(Number(y), Number(m) - 1, 1);
  return date.toLocaleDateString(undefined, { month: "short", year: "2-digit" });
}

function buildMonthBuckets(range, maxMonths = 12) {
  if (!range) return [];
  const keys = [];
  const cursor = new Date(range.start.getFullYear(), range.start.getMonth(), 1);
  const end = new Date(range.end.getFullYear(), range.end.getMonth(), 1);
  while (cursor <= end && keys.length < maxMonths) {
    const key =
      cursor.getFullYear() +
      "-" +
      String(cursor.getMonth() + 1).padStart(2, "0");
    keys.push(key);
    cursor.setMonth(cursor.getMonth() + 1);
  }
  return keys;
}

function setKpiText(id, text) {
  const el = document.getElementById(id);
  if (el) el.textContent = text;
}

function updateLastUpdatedIndicator() {
  const el = document.getElementById("reportsLastUpdated");
  if (!el) return;
  const now = new Date();
  el.textContent =
    "Last updated: " +
    now.toLocaleDateString(undefined, {
      year: "numeric",
      month: "short",
      day: "numeric",
    }) +
    " " +
    now.toLocaleTimeString(undefined, {
      hour: "2-digit",
      minute: "2-digit",
    });
}

function showReportView(reportType) {
  document.querySelectorAll("[data-report-view]").forEach((section) => {
    const active = section.dataset.reportView === reportType;
    section.hidden = !active;
    section.setAttribute("aria-hidden", String(!active));
  });

  const title = document.getElementById("activeReportTitle");
  const labels = {
    overview: "Overview",
    utilization: "Fleet Utilization",
    trips: "Trip & Dispatch",
    reservations: "Reservations",
    maintenance: "Maintenance",
    fuel: "Fuel & Cost",
    drivers: "Driver Performance",
  };
  if (title) title.textContent = labels[reportType] || "Overview";
}

function computeOverviewModel(sources, range, filters) {
  /* Vehicle availability is a current snapshot — not date-filtered */
  const vehicles = sources.vehicles.filter((v) =>
    matchesVehicleFilter(v, filters.vehicle) &&
    matchesDepartmentFilter(v, filters.department),
  );
  const trips = filterDatedRecords(sources.dispatches, range, filters);
  const reservations = filterDatedRecords(sources.reservations, range, filters);
  const maintenance = filterDatedRecords(sources.maintenance, range, filters);
  const fuel = filterDatedRecords(sources.fuel, range, filters);

  const available = vehicles.filter(
    (v) => normalizeVehicleStatus(v.status) === "Available",
  ).length;
  const completedTrips = trips.filter(
    (t) => String(t.status).toLowerCase() === "completed",
  ).length;
  const activeReservations = reservations.filter((r) =>
    isActiveReservationStatus(r.status),
  ).length;
  const completedMaintenance = maintenance.filter(
      (record) => String(record.status).toLowerCase() === "completed",
  );
  const maintenanceCost = completedMaintenance.reduce(
      (sum, record) => sum + (Number(record.cost) || 0),
      0,
  );
  const fuelCost = fuel.reduce(
    (sum, f) => sum + (Number(f.totalCost) || 0),
    0,
  );

  const statusCounts = {};
  vehicles.forEach((v) => {
    const key = normalizeVehicleStatus(v.status);
    statusCounts[key] = (statusCounts[key] || 0) + 1;
  });

  const months = buildMonthBuckets(range);
  const tripByMonth = months.map((key) => ({
    key,
    label: monthLabel(key),
    value: trips.filter(
      (t) =>
        monthKey(t.date) === key &&
        String(t.status).toLowerCase() === "completed",
    ).length,
  }));

  const costByMonth = months.map((key) => ({
      key,
      label: monthLabel(key),
      fuel: fuel
          .filter((f) => monthKey(f.date) === key)
          .reduce((s, f) => s + (Number(f.totalCost) || 0), 0),
      maintenance: completedMaintenance
          .filter(
              (record) =>
                  monthKey(record.completionDate || record.date) === key,
          )
          .reduce((sum, record) => sum + (Number(record.cost) || 0), 0),
  }));

  const tripCountsByVehicle = {};
  trips
    .filter((t) => String(t.status).toLowerCase() === "completed")
    .forEach((t) => {
      const name = t.vehicleName || "Unknown";
      tripCountsByVehicle[name] = (tripCountsByVehicle[name] || 0) + 1;
    });
  const utilization = Object.entries(tripCountsByVehicle)
    .map(([name, count]) => ({ name, value: count }))
    .sort((a, b) => b.value - a.value)
    .slice(0, 8);

  return {
    kpis: {
      totalVehicles: vehicles.length,
      availableVehicles: available,
      completedTrips,
      activeReservations,
      maintenanceCost,
      fuelCost,
    },
    charts: {
      fleetStatus: statusCounts,
      tripActivity: tripByMonth,
      costComparison: costByMonth,
      vehicleUtilization: utilization,
    },
    table: {
      columns: [
        { key: "name", label: "Metric", type: "text" },
        { key: "value", label: "Value", type: "text" },
      ],
      rows: [
        { name: "Total Vehicles", value: String(vehicles.length) },
        { name: "Available Vehicles", value: String(available) },
        { name: "Completed Trips", value: String(completedTrips) },
        { name: "Active Reservations", value: String(activeReservations) },
        { name: "Maintenance Cost", value: formatReportCurrency(maintenanceCost) },
        { name: "Fuel Cost", value: formatReportCurrency(fuelCost) },
      ],
      emptyMessage: "No overview metrics available.",
      searchable: ["name", "value"],
    },
  };
}

function utilizationLevel(trips) {
  if (trips >= 5) return "High";
  if (trips >= 2) return "Moderate";
  if (trips >= 1) return "Low";
  return "No Activity";
}

function computeUtilizationModel(sources, range, filters) {
  const vehicles = sources.vehicles.filter(
    (v) =>
      matchesVehicleFilter(v, filters.vehicle) &&
      matchesDepartmentFilter(v, filters.department),
  );
  const trips = filterDatedRecords(sources.dispatches, range, filters).filter(
    (t) => String(t.status).toLowerCase() === "completed",
  );
  const reservations = filterDatedRecords(sources.reservations, range, filters);

  const rows = vehicles.map((v) => {
    const completedTrips = trips.filter(
        (trip) => String(trip.vehicleId) === String(v.id),
    ).length;
    const resCount = reservations.filter(
        (reservation) => String(reservation.vehicleId) === String(v.id),
    ).length;
    const distance = trips
      .filter((t) => t.vehicleName === v.name)
      .reduce((s, t) => s + (Number(t.distance) || 0), 0);
    return {
      vehicle: v.name,
      plateNumber: v.plateNumber,
      type: v.type,
      department: v.department || "—",
      completedTrips,
      reservations: resCount,
      totalDistance: distance,
      utilizationStatus: utilizationLevel(completedTrips + resCount),
    };
  });

  const used = rows.filter(
    (r) => r.completedTrips > 0 || r.reservations > 0,
  ).length;
  const available = vehicles.filter(
    (v) => normalizeVehicleStatus(v.status) === "Available",
  ).length;
  const rate = vehicles.length ? (used / vehicles.length) * 100 : 0;

  const byType = {};
  rows.forEach((r) => {
    byType[r.type] = (byType[r.type] || 0) + r.completedTrips;
  });

  return {
    kpis: {
      totalVehicles: vehicles.length,
      vehiclesUsed: used,
      availableVehicles: available,
      utilizationRate: rate,
    },
    charts: {
      usageRanking: rows
        .map((r) => ({
          name: r.vehicle,
          value: r.completedTrips + r.reservations,
        }))
        .sort((a, b) => b.value - a.value)
        .slice(0, 8),
      byType: Object.entries(byType).map(([name, value]) => ({ name, value })),
    },
    table: {
      columns: [
        { key: "vehicle", label: "Vehicle", type: "text" },
        { key: "plateNumber", label: "Plate Number", type: "text" },
        { key: "type", label: "Type", type: "text" },
        { key: "department", label: "Department", type: "text" },
        { key: "completedTrips", label: "Completed Trips", type: "number" },
        { key: "reservations", label: "Reservations", type: "number" },
        { key: "totalDistance", label: "Total Distance (km)", type: "number" },
        { key: "utilizationStatus", label: "Utilization Status", type: "text" },
      ],
      rows,
      emptyMessage: "No vehicle utilization data found.",
      searchable: [
        "vehicle",
        "plateNumber",
        "type",
        "department",
        "utilizationStatus",
      ],
    },
  };
}

function computeTripsModel(sources, range, filters) {
    const trips = filterDatedRecords(sources.dispatches, range, filters);
    const statusCounts = {};
    trips.forEach((trip) => {
        const status = trip.status || "Unknown";
        statusCounts[status] = (statusCounts[status] || 0) + 1;
    });
    const ongoing =
        (statusCounts["En Route"] || 0) + (statusCounts["Arrived"] || 0);
    const months = buildMonthBuckets(range);
    const overTime = months.map((key) => ({
        key,
        label: monthLabel(key),
        value: trips.filter((trip) => monthKey(trip.date) === key).length,
    }));
    const destCounts = {};
    trips.forEach((trip) => {
        if (!trip.destination) {
            return;
        }
        destCounts[trip.destination] = (destCounts[trip.destination] || 0) + 1;
    });
    return {
        kpis: {
            total: trips.length,
            completed: statusCounts["Completed"] || 0,
            ongoing,
            cancelled: statusCounts["Cancelled"] || 0,
        },
        charts: {
            status: statusCounts,
            overTime,
            destinations: Object.entries(destCounts)
                .map(([name, value]) => ({
                    name,
                    value,
                }))
                .sort((a, b) => b.value - a.value)
                .slice(0, 6),
        },
        table: {
            columns: [
                { key: "tripNumber", label: "Trip No.", type: "text" },
                { key: "date", label: "Date", type: "date" },
                { key: "vehicleName", label: "Vehicle", type: "text" },
                { key: "driverName", label: "Driver", type: "text" },
                { key: "origin", label: "Origin", type: "text" },
                { key: "destination", label: "Destination", type: "text" },
                { key: "status", label: "Status", type: "text" },
                { key: "distance", label: "Distance (km)", type: "number" },
                { key: "duration", label: "Duration (min)", type: "number" },
            ],

            rows: trips,
            emptyMessage: "No trip or dispatch records found.",
            searchable: [
                "tripNumber",
                "vehicleName",
                "driverName",
                "origin",
                "destination",
                "status",
            ],
        },
    };
}

function computeReservationsModel(sources, range, filters) {
  const list = filterDatedRecords(sources.reservations, range, filters);
  const statusCounts = {};
  list.forEach((r) => {
    statusCounts[r.status] = (statusCounts[r.status] || 0) + 1;
  });
  const months = buildMonthBuckets(range);
  const overTime = months.map((key) => ({
    label: monthLabel(key),
    value: list.filter((r) => monthKey(r.date) === key).length,
  }));
  const byDept = {};
  list.forEach((r) => {
    const d = r.department || "Unassigned";
    byDept[d] = (byDept[d] || 0) + 1;
  });

  return {
    kpis: {
      total: list.length,
      pending: statusCounts.Pending || 0,
      approved: statusCounts.Approved || 0,
      completed: statusCounts.Completed || 0,
      cancelled: statusCounts.Cancelled || 0,
    },
    charts: {
      status: statusCounts,
      overTime,
      byDepartment: Object.entries(byDept).map(([name, value]) => ({
        name,
        value,
      })),
    },
    table: {
      columns: [
        { key: "reservationNumber", label: "Reservation No.", type: "text" },
        { key: "date", label: "Date", type: "date" },
        { key: "requester", label: "Requester", type: "text" },
        { key: "department", label: "Department", type: "text" },
        { key: "vehicleName", label: "Vehicle", type: "text" },
        { key: "purpose", label: "Purpose", type: "text" },
        { key: "status", label: "Status", type: "text" },
      ],
      rows: list,
      emptyMessage: "No reservation records found.",
      searchable: [
        "reservationNumber",
        "requester",
        "department",
        "vehicleName",
        "purpose",
        "status",
      ],
    },
  };
}

function computeMaintenanceModel(sources, range, filters) {
  const list = filterDatedRecords(sources.maintenance, range, filters);
  const statusCounts = {};
  const typeCounts = {};
  list.forEach((m) => {
    statusCounts[m.status] = (statusCounts[m.status] || 0) + 1;
    typeCounts[m.type] = (typeCounts[m.type] || 0) + 1;
  });
  const totalCost = list
      .filter((record) => String(record.status).toLowerCase() === "completed")
      .reduce((sum, record) => sum + (Number(record.cost) || 0), 0);
  const months = buildMonthBuckets(range);
  const costOverTime = months.map((key) => ({
    label: monthLabel(key),
    value: list
      .filter((m) => monthKey(m.date) === key)
      .reduce((s, m) => s + (Number(m.cost) || 0), 0),
  }));
  const byVehicle = {};
  list.forEach((m) => {
    byVehicle[m.vehicleName] =
      (byVehicle[m.vehicleName] || 0) + (Number(m.cost) || 0);
  });

  return {
      kpis: {
          total: list.length,
          scheduled: statusCounts.Scheduled || 0,
          inProgress: statusCounts["In Progress"] || 0,
          completed: statusCounts.Completed || 0,
          totalCost,
      },
      charts: {
          byType: Object.entries(typeCounts).map(([name, value]) => ({
              name,
              value,
          })),
          status: statusCounts,
          costOverTime,
          topVehicles: Object.entries(byVehicle)
              .map(([name, value]) => ({ name, value }))
              .sort((a, b) => b.value - a.value)
              .slice(0, 6),
      },
      table: {
          columns: [
              {
                  key: "maintenanceNumber",
                  label: "Maintenance No.",
                  type: "text",
              },
              { key: "date", label: "Date", type: "date" },
              { key: "vehicleName", label: "Vehicle", type: "text" },
              { key: "type", label: "Type", type: "text" },
              { key: "status", label: "Status", type: "text" },
              { key: "cost", label: "Cost", type: "currency" },
              { key: "serviceProvider", label: "Technician", type: "text" },
          ],
          rows: list,
          emptyMessage: "No maintenance records found.",
          searchable: [
              "maintenanceNumber",
              "vehicleName",
              "type",
              "status",
              "serviceProvider",
          ],
      },
  };
}

function computeFuelModel(sources, range, filters) {
  const list = filterDatedRecords(sources.fuel, range, filters);
  const quantity = list.reduce((s, f) => s + (Number(f.quantity) || 0), 0);
  const totalCost = list.reduce((s, f) => s + (Number(f.totalCost) || 0), 0);
  const avg = quantity > 0 ? totalCost / quantity : 0;
  const months = buildMonthBuckets(range);
  const qtyOverTime = months.map((key) => ({
    label: monthLabel(key),
    value: list
      .filter((f) => monthKey(f.date) === key)
      .reduce((s, f) => s + (Number(f.quantity) || 0), 0),
  }));
  const costOverTime = months.map((key) => ({
    label: monthLabel(key),
    value: list
      .filter((f) => monthKey(f.date) === key)
      .reduce((s, f) => s + (Number(f.totalCost) || 0), 0),
  }));
  const byVehicle = {};
  const byType = {};
  list.forEach((f) => {
    byVehicle[f.vehicleName] =
      (byVehicle[f.vehicleName] || 0) + (Number(f.quantity) || 0);
    byType[f.fuelType] = (byType[f.fuelType] || 0) + (Number(f.quantity) || 0);
  });

  return {
      kpis: {
          total: list.length,
          quantity,
          totalCost,
          averagePerLiter: avg,
      },
      charts: {
          qtyOverTime,
          costOverTime,
          topVehicles: Object.entries(byVehicle)
              .map(([name, value]) => ({ name, value }))
              .sort((a, b) => b.value - a.value)
              .slice(0, 6),
          byType: Object.entries(byType).map(([name, value]) => ({
              name,
              value,
          })),
      },
      table: {
          columns: [
              {
                  key: "fuelRecordNumber",
                  label: "Fuel Record No.",
                  type: "text",
              },
              { key: "date", label: "Date", type: "date" },
              { key: "vehicleName", label: "Vehicle", type: "text" },
              { key: "fuelType", label: "Fuel Type", type: "text" },
              { key: "quantity", label: "Quantity (L)", type: "number" },
              { key: "costPerLiter", label: "Cost per Liter", type: "currency" },
              { key: "totalCost", label: "Total Cost", type: "currency"},
              { key: "odometer", label: "Odometer", type: "number" },
              { key: "station", label: "Fuel Station", type: "text" },
          ],
          rows: list,
          emptyMessage: "No fuel records found.",
          searchable: [
              "fuelRecordNumber",
              "vehicleName",
              "fuelType",
              "station",
          ],
      },
  };
}

function driverPerformanceLevel(trips) {
  if (trips >= 4) return "High";
  if (trips >= 2) return "Moderate";
  if (trips >= 1) return "Low";
  return "No Activity";
}

function computeDriversModel(sources, range, filters) {
  /* Drivers themselves are not date-filtered; trip metrics are */
  const drivers = sources.drivers.filter((driver) => {
      if (
          filters.vehicle &&
          filters.vehicle !== "all" &&
          driver.assignedVehicle !== filters.vehicle
      ) {
          return false;
      }
      if (
          filters.department &&
          filters.department !== "all" &&
          driver.department !== filters.department
      ) {
          return false;
      }
      return true;
  });
  const trips = filterDatedRecords(sources.dispatches, range, filters);

  const rows = drivers.map((d) => {
    const mine = trips.filter((trip) => String(trip.driverId) === String(d.id));
    const completed = mine.filter(
      (t) => String(t.status).toLowerCase() === "completed",
    ).length;
    const ongoing = mine.filter((trip) => {
        const status = String(trip.status || "").toLowerCase();
        return status === "en route" || status === "arrived";
    }).length;
    const cancelled = mine.filter(
      (t) => String(t.status).toLowerCase() === "cancelled",
    ).length;
    const distance = mine.reduce((s, t) => s + (Number(t.distance) || 0), 0);
    return {
      driver: d.name,
      status: d.status,
      assignedVehicle: d.assignedVehicle || "—",
      completedTrips: completed,
      ongoingTrips: ongoing,
      cancelledTrips: cancelled,
      totalDistance: distance,
      performanceLevel: driverPerformanceLevel(completed),
    };
  });

  const active = drivers.filter((d) =>
    String(d.status).toLowerCase().includes("active"),
  ).length;
  const withTrips = rows.filter((r) => r.completedTrips > 0).length;
  const avg =
    active > 0
      ? rows.reduce((s, r) => s + r.completedTrips, 0) / active
      : 0;

  const statusCounts = {};
  drivers.forEach((d) => {
    statusCounts[d.status] = (statusCounts[d.status] || 0) + 1;
  });

  return {
    kpis: {
      total: drivers.length,
      active,
      withTrips,
      avgTrips: avg,
    },
    charts: {
      topDrivers: rows
        .map((r) => ({ name: r.driver, value: r.completedTrips }))
        .sort((a, b) => b.value - a.value)
        .slice(0, 6),
      status: statusCounts,
    },
    table: {
      columns: [
        { key: "driver", label: "Driver", type: "text" },
        { key: "status", label: "Status", type: "text" },
        { key: "assignedVehicle", label: "Assigned Vehicle", type: "text" },
        { key: "completedTrips", label: "Completed Trips", type: "number" },
        { key: "ongoingTrips", label: "Ongoing Trips", type: "number" },
        { key: "cancelledTrips", label: "Cancelled Trips", type: "number" },
        { key: "totalDistance", label: "Total Distance (km)", type: "number" },
        { key: "performanceLevel", label: "Performance Level", type: "text" },
      ],
      rows,
      emptyMessage: "No driver performance data found.",
      searchable: ["driver", "status", "assignedVehicle", "performanceLevel"],
    },
  };
}

function buildActiveReportModel(sources, range, filters) {
  switch (filters.reportType) {
    case "utilization":
      return computeUtilizationModel(sources, range, filters);
    case "trips":
      return computeTripsModel(sources, range, filters);
    case "reservations":
      return computeReservationsModel(sources, range, filters);
    case "maintenance":
      return computeMaintenanceModel(sources, range, filters);
    case "fuel":
      return computeFuelModel(sources, range, filters);
    case "drivers":
      return computeDriversModel(sources, range, filters);
    case "overview":
    default:
      return computeOverviewModel(sources, range, filters);
  }
}

function renderOverviewKpis(kpis) {
  setKpiText("kpiTotalVehicles", formatReportNumber(kpis.totalVehicles));
  setKpiText("kpiAvailableVehicles", formatReportNumber(kpis.availableVehicles));
  setKpiText("kpiCompletedTrips", formatReportNumber(kpis.completedTrips));
  setKpiText("kpiActiveReservations", formatReportNumber(kpis.activeReservations));
  setKpiText("kpiMaintenanceCost", formatReportCurrency(kpis.maintenanceCost));
  setKpiText("kpiFuelCost", formatReportCurrency(kpis.fuelCost));
}

function renderViewKpis(reportType, model) {
  const box = document.getElementById("reportViewKpis");
  if (!box) return;

  if (reportType === "overview") {
    box.hidden = true;
    return;
  }

  box.hidden = false;
  let cards = [];

  if (reportType === "utilization") {
    cards = [
      ["Total Vehicles", formatReportNumber(model.kpis.totalVehicles)],
      ["Vehicles Used", formatReportNumber(model.kpis.vehiclesUsed)],
      ["Available Vehicles", formatReportNumber(model.kpis.availableVehicles)],
      ["Utilization Rate", formatReportPercent(model.kpis.utilizationRate)],
    ];
  } else if (reportType === "trips") {
    cards = [
      ["Total Trips", formatReportNumber(model.kpis.total)],
      ["Completed", formatReportNumber(model.kpis.completed)],
      ["Ongoing", formatReportNumber(model.kpis.ongoing)],
      ["Cancelled", formatReportNumber(model.kpis.cancelled)],
    ];
  } else if (reportType === "reservations") {
    cards = [
      ["Total Reservations", formatReportNumber(model.kpis.total)],
      ["Pending", formatReportNumber(model.kpis.pending)],
      ["Approved", formatReportNumber(model.kpis.approved)],
      ["Completed", formatReportNumber(model.kpis.completed)],
    ];
  } else if (reportType === "maintenance") {
    cards = [
      ["Total Records", formatReportNumber(model.kpis.total)],
      ["Scheduled", formatReportNumber(model.kpis.scheduled)],
      ["In Progress", formatReportNumber(model.kpis.inProgress)],
      ["Completed", formatReportNumber(model.kpis.completed)],
      ["Total Cost", formatReportCurrency(model.kpis.totalCost)],
    ];
  } else if (reportType === "fuel") {
    cards = [
      ["Total Fuel Records", formatReportNumber(model.kpis.total)],
      ["Total Fuel Consumed", formatReportLiters(model.kpis.quantity)],
      ["Total Fuel Cost", formatReportCurrency(model.kpis.totalCost)],
      ["Avg Cost / L", formatReportCurrency(model.kpis.averagePerLiter) + "/L"],
    ];
  } else if (reportType === "drivers") {
    cards = [
      ["Total Drivers", formatReportNumber(model.kpis.total)],
      ["Active Drivers", formatReportNumber(model.kpis.active)],
      ["With Completed Trips", formatReportNumber(model.kpis.withTrips)],
      [
        "Avg Trips / Active",
        (model.kpis.avgTrips || 0).toLocaleString(undefined, {
          maximumFractionDigits: 1,
        }),
      ],
    ];
  }

  box.innerHTML = cards
    .map(
      ([label, value]) => `
      <div class="stat-card report-kpi-card">
        <div class="stat-content">
          <h3>${value}</h3>
          <p>${label}</p>
        </div>
      </div>`,
    )
    .join("");
}

/**
 * Centralized Reports dashboard pipeline.
 */
async function refreshReportsDashboard(options = {}) {
    if (isRefreshingReports) return;
    isRefreshingReports = true;

    try {
        const resetTablePage = options.resetTablePage === true;
        const refreshSources = options.refreshSources === true;

        const range = getResolvedReportDateRange();
        if (!range) {
            return;
        }

        const filters = getReportsFilterValues();
        reportsState.filters = filters;
        reportsState.range = range;
        reportsState.reportType = filters.reportType;

        if (refreshSources || !reportsState.sources) {
            reportsState.sources =
                typeof getAllReportsSourceData === "function"
                    ? await getAllReportsSourceData()
                    : {
                          vehicles: [],
                          reservations: [],
                          dispatches: [],
                          drivers: [],
                          maintenance: [],
                          fuel: [],
                      };
        }

        const sources = reportsState.sources;
        const model = buildActiveReportModel(sources, range, filters);

        /* Overview six-card KPIs — visible on Overview; still used for overview charts */
        const overview = computeOverviewModel(sources, range, filters);
        reportsState.lastModel = model;
        reportsState.lastOverview = overview;

        renderOverviewKpis(overview.kpis);

        const overviewKpis = document.getElementById("overviewKpis");
        if (overviewKpis) {
            overviewKpis.hidden = filters.reportType !== "overview";
        }

        showReportView(filters.reportType);
        renderViewKpis(filters.reportType, model);

        if (typeof renderReportsCharts === "function") {
            renderReportsCharts(filters.reportType, model, overview);
        }

        if (typeof setReportsTableConfig === "function") {
            setReportsTableConfig(model.table, { resetPage: resetTablePage });
        }

        updateLastUpdatedIndicator();
        updateReportsOutputMetaStrip();
    } catch (error) {
        console.error("refreshReportsDashboard failed:", error);
        throw error;
    } finally {
        queueMicrotask(() => {
            isRefreshingReports = false;
        });
    }
}
