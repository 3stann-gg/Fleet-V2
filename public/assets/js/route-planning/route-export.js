/* ==========================================
   Route Planning Print / PDF / Excel
========================================== */

let routeExportBusy = false;

function showRouteExportToast(message, type) {
  if (typeof showToast === "function") {
    showToast(message, type);
  } else if (typeof window.showToast === "function") {
    window.showToast(message, type);
  }
}

function getRouteExportDateStamp() {
  const now = new Date();
  return (
    now.getFullYear() +
    "-" +
    String(now.getMonth() + 1).padStart(2, "0") +
    "-" +
    String(now.getDate()).padStart(2, "0")
  );
}

function getRouteOutputModel() {
  const rows =
    typeof getProcessedRouteRecords === "function"
      ? getProcessedRouteRecords()
      : [];
  const filters =
    typeof getRouteFilterSummaryLines === "function"
      ? getRouteFilterSummaryLines()
      : [];
  const allActive =
    typeof getAllRouteRecords === "function"
      ? getAllRouteRecords({ includeArchived: false })
      : [];

  const ready = allActive.filter((r) => r.status === "Ready For Dispatch").length;
  const high = allActive.filter(
    (r) => r.priority === "High" || r.priority === "Emergency",
  ).length;
  const avgDist =
    allActive.length === 0
      ? 0
      : allActive.reduce((s, r) => s + (Number(r.estimatedDistance) || 0), 0) /
        allActive.length;
  const avgMin =
    allActive.length === 0
      ? 0
      : allActive.reduce(
          (s, r) => s + (Number(r.estimatedTravelTimeMinutes) || 0),
          0,
        ) / allActive.length;

  const now = new Date();
  return {
    rows,
    filterSummary: filters,
    generatedDate: now.toLocaleDateString(),
    generatedTime: now.toLocaleTimeString(),
    stats: {
      total: allActive.length,
      ready,
      highPriority: high,
      avgDistance: avgDist,
      avgMinutes: avgMin,
      vehicles: new Set(allActive.map((r) => r.vehicle).filter(Boolean)).size,
    },
  };
}

function formatRouteExportDistance(km) {
  const n = Number(km) || 0;
  return (
    n.toLocaleString(undefined, {
      minimumFractionDigits: 1,
      maximumFractionDigits: 1,
    }) + " km"
  );
}

function formatRouteExportVehicle(vehicle) {
    if (!vehicle) {
        return "Unassigned";
    }
    if (typeof vehicle === "string") {
        return vehicle.trim() || "Unassigned";
    }
    const brandModel = [vehicle.brand, vehicle.model]
        .filter(Boolean)
        .map((value) => String(value).trim())
        .filter(Boolean)
        .join(" ")
        .trim();
    const vehicleType = String(
        vehicle.vehicle_type || vehicle.type || "",
    ).trim();
    if (brandModel && vehicleType) {
        return `${brandModel} - ${vehicleType}`;
    }
    if (brandModel) {
        return brandModel;
    }
    if (vehicleType) {
        return vehicleType;
    }
    return (
        vehicle.vehicle_name ||
        vehicle.name ||
        vehicle.plate_number ||
        "Unassigned"
    );
}

function escapeRouteExportHtml(value) {
  return String(value == null ? "" : value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function buildRoutePrintHtml(model) {
  const stats = model.stats;
  const filters = (model.filterSummary || [])
    .map((line) => `<p class="meta">${escapeRouteExportHtml(line)}</p>`)
    .join("");
  const body = (model.rows || [])
    .map((r) => {
      return `<tr>
        <td>${escapeRouteExportHtml(r.routeNumber)}</td>
        <td>${escapeRouteExportHtml(r.origin)}</td>
        <td>${escapeRouteExportHtml(r.destination)}</td>
        <td>${escapeRouteExportHtml((r.stops || []).join("; "))}</td>
        <td>${escapeRouteExportHtml(formatRouteExportVehicle(r.vehicle))}</td>
        <td>${escapeRouteExportHtml(r.driver)}</td>
        <td>${escapeRouteExportHtml(r.priority)}</td>
        <td>${escapeRouteExportHtml(formatRouteExportDistance(r.estimatedDistance))}</td>
        <td>${escapeRouteExportHtml(r.estimatedTravelTime)}</td>
        <td>${escapeRouteExportHtml(r.optimizationStrategy)}</td>
        <td>${escapeRouteExportHtml(r.optimizationScore)}</td>
        <td>${escapeRouteExportHtml(r.status)}</td>
        <td>${escapeRouteExportHtml(
            (r.departureDate || "") + " " + (r.departureTime || ""),
        )}</td>
      </tr>`;
    })
    .join("");

  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Route Planning Report</title>
  <style>
    @page { size: A4 landscape; margin: 12mm; }
    body { font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; font-size: 10px; }
    h1 { margin: 0 0 2px; font-size: 16px; color: #00a86b; }
    h2 { margin: 0 0 4px; font-size: 13px; }
    h3 { margin: 0 0 8px; font-size: 14px; }
    .meta { margin: 2px 0; }
    .summary { margin: 8px 0 12px; padding: 8px; border: 1px solid #ddd; display: flex; flex-wrap: wrap; gap: 10px 16px; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    thead { display: table-header-group; }
    th, td { border: 1px solid #333; padding: 4px; text-align: left; word-wrap: break-word; vertical-align: top; }
    th { background: #e8f8f2; font-size: 9px; }
    tr { page-break-inside: avoid; }
    @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
  </style>
</head>
<body>
  <h1>Fleet &amp; Transportation Management</h1>
  <h2>Hospital Information Management System</h2>
  <h3>Route Planning</h3>
  <p class="meta">Generated Date: ${escapeRouteExportHtml(model.generatedDate)}</p>
  <p class="meta">Generated Time: ${escapeRouteExportHtml(model.generatedTime)}</p>
  ${filters || '<p class="meta">Applied Filters: None</p>'}
  <div class="summary">
    <span><strong>Total Planned:</strong> ${stats.total}</span>
    <span><strong>Ready For Dispatch:</strong> ${stats.ready}</span>
    <span><strong>High Priority:</strong> ${stats.highPriority}</span>
    <span><strong>Avg Distance:</strong> ${escapeRouteExportHtml(formatRouteExportDistance(stats.avgDistance))}</span>
    <span><strong>Assigned Vehicles:</strong> ${stats.vehicles}</span>
  </div>
  <table>
    <thead>
      <tr>
        <th>Route No.</th><th>Origin</th><th>Destination</th><th>Stops</th>
        <th>Vehicle</th><th>Driver</th><th>Priority</th><th>Distance</th>
        <th>Est. Time</th><th>Strategy</th><th>Score</th><th>Status</th><th>Departure</th>
      </tr>
    </thead>
    <tbody>${body}</tbody>
  </table>
</body>
</html>`;
}

function printRoutes() {
  if (routeExportBusy) return;
  const model = getRouteOutputModel();
  if (!model.rows.length) {
    showRouteExportToast("No routes available to print.", "warning");
    return;
  }

  let win;
  try {
    win = window.open("", "_blank", "noopener,noreferrer,width=1100,height=760");
  } catch (error) {
    console.error(error);
    showRouteExportToast("Unable to open the print report.", "error");
    return;
  }
  if (!win) {
    showRouteExportToast("Unable to open the print report.", "error");
    return;
  }

  routeExportBusy = true;
  try {
    win.document.open();
    win.document.write(buildRoutePrintHtml(model));
    win.document.close();
    const run = () => {
      try {
        win.focus();
        win.print();
      } catch (error) {
        console.error(error);
        showRouteExportToast("Unable to print routes.", "error");
      }
      const close = () => {
        try {
          win.close();
        } catch {
          /* ignore */
        }
        routeExportBusy = false;
      };
      if (typeof win.addEventListener === "function") {
        win.addEventListener("afterprint", close, { once: true });
      }
      setTimeout(close, 1500);
    };
    if (win.document.readyState === "complete") run();
    else win.onload = run;
  } catch (error) {
    console.error(error);
    showRouteExportToast("Unable to print routes.", "error");
    routeExportBusy = false;
  }
}

function exportRoutesToPdf() {
  if (routeExportBusy) return;
  const jsPDF = window.jspdf?.jsPDF;
  if (!jsPDF) {
    showRouteExportToast("PDF export is unavailable.", "error");
    return;
  }

  const model = getRouteOutputModel();
  if (!model.rows.length) {
    showRouteExportToast("No routes available to export.", "warning");
    return;
  }

  routeExportBusy = true;
  try {
    const pdf = new jsPDF({
      orientation: "landscape",
      unit: "mm",
      format: "a4",
      ...(window.consumeSecurePdfEncryption?.() || {}),
    });
    if (typeof pdf.autoTable !== "function") {
      showRouteExportToast("PDF export is unavailable.", "error");
      return;
    }

    let y = 14;
    pdf.setFontSize(16);
    pdf.setTextColor(0, 168, 107);
    pdf.text("Hospital Information Management System", 14, y);
    y += 7;
    pdf.setFontSize(11);
    pdf.setTextColor(0, 0, 0);
    pdf.text("Fleet & Transportation Management", 14, y);
    y += 6;
    pdf.setFontSize(14);
    pdf.text("Route Planning", 14, y);
    y += 8;
    pdf.setFontSize(9);
    pdf.text("Generated Date: " + model.generatedDate, 14, y);
    y += 5;
    pdf.text("Generated Time: " + model.generatedTime, 14, y);
    y += 5;
    (model.filterSummary || []).forEach((line) => {
      pdf.text(String(line), 14, y);
      y += 4.5;
    });
    const s = model.stats;
    pdf.text(
      "Total: " +
        s.total +
        "  ·  Ready: " +
        s.ready +
        "  ·  High Priority: " +
        s.highPriority +
        "  ·  Avg Distance: " +
        formatRouteExportDistance(s.avgDistance),
      14,
      y,
    );
    y += 8;

    pdf.autoTable({
      head: [
        [
          "Route No.",
          "Origin",
          "Destination",
          "Vehicle",
          "Driver",
          "Priority",
          "Distance",
          "Time",
          "Strategy",
          "Status",
          "Departure",
        ],
      ],
      body: model.rows.map((r) => [
        r.routeNumber,
        r.origin,
        r.destination,
        formatRouteExportVehicle(r.vehicle),
        r.driver,
        r.priority,
        formatRouteExportDistance(r.estimatedDistance),
        r.estimatedTravelTime,
        r.optimizationStrategy,
        r.status,
        (r.departureDate || "") + " " + (r.departureTime || ""),
      ]),
      startY: y,
      styles: { fontSize: 7, cellPadding: 1.5, overflow: "linebreak" },
      headStyles: {
        fillColor: [0, 168, 107],
        textColor: 255,
        fontStyle: "bold",
        fontSize: 7,
      },
      alternateRowStyles: { fillColor: [245, 247, 250] },
      margin: { top: 14, right: 10, bottom: 14, left: 10 },
      showHead: "everyPage",
    });

    pdf.save("route-planning-" + getRouteExportDateStamp() + ".pdf");
    showRouteExportToast("Route PDF exported successfully.", "success");
  } catch (error) {
    console.error(error);
    showRouteExportToast("Unable to export route PDF.", "error");
  } finally {
    routeExportBusy = false;
  }
}

function exportRoutesToExcel() {
  if (routeExportBusy) return;
  const xlsx = window.XLSX;
  if (!xlsx?.utils) {
    showRouteExportToast("Excel export is unavailable.", "error");
    return;
  }

  const model = getRouteOutputModel();
  if (!model.rows.length) {
    showRouteExportToast("No routes available to export.", "warning");
    return;
  }

  routeExportBusy = true;
  try {
    const s = model.stats;
    const summary = [
      ["Fleet & Transportation Management"],
      ["Hospital Information Management System"],
      ["Route Planning"],
      [],
      ["Generated Date", model.generatedDate],
      ["Generated Time", model.generatedTime],
      ["Total Matching Routes", model.rows.length],
      [],
      ["Statistics"],
      ["Total Planned Routes", s.total],
      ["Ready For Dispatch", s.ready],
      ["High Priority Routes", s.highPriority],
      ["Average Estimated Distance (km)", Number(s.avgDistance.toFixed(1))],
      ["Average Estimated Time (min)", Math.round(s.avgMinutes)],
      ["Assigned Vehicles", s.vehicles],
      [],
      ["Applied Filters"],
      ...((model.filterSummary || []).length
        ? model.filterSummary.map((line) => [line])
        : [["None"]]),
    ];

    const data = [
        [
            "Route No.",
            "Origin",
            "Destination",
            "Stops",
            "Vehicle",
            "Driver",
            "Priority",
            "Department",
            "Purpose",
            "Distance (km)",
            "Estimated Time",
            "Optimization Strategy",
            "Optimization Score",
            "Status",
            "Departure Date",
            "Departure Time",
            "Notes",
        ],
        ...model.rows.map((r) => [
            r.routeNumber,
            r.origin,
            r.destination,
            (r.stops || []).join("; "),
            formatRouteExportVehicle(r.vehicle),
            r.driver,
            r.priority,
            r.department,
            r.purpose,
            Number(r.estimatedDistance) || 0,
            r.estimatedTravelTime,
            r.optimizationStrategy,
            r.optimizationScore,
            r.status,
            r.departureDate,
            r.departureTime,
            r.notes,
        ]),
    ];

    const workbook = xlsx.utils.book_new();
    const summarySheet = xlsx.utils.aoa_to_sheet(summary);
    const routeSheet = xlsx.utils.aoa_to_sheet(data);
    summarySheet["!cols"] = [{ wch: 34 }, { wch: 24 }];
    routeSheet["!cols"] = [
        { wch: 18 }, // Route No.
        { wch: 24 }, // Origin
        { wch: 24 }, // Destination
        { wch: 30 }, // Stops
        { wch: 28 }, // Vehicle
        { wch: 22 }, // Driver
        { wch: 14 }, // Priority
        { wch: 20 }, // Department
        { wch: 28 }, // Purpose
        { wch: 16 }, // Distance
        { wch: 18 }, // Estimated Time
        { wch: 24 }, // Optimization Strategy
        { wch: 18 }, // Optimization Score
        { wch: 20 }, // Status
        { wch: 18 }, // Departure Date
        { wch: 16 }, // Departure Time
        { wch: 32 }, // Notes
    ];
    xlsx.utils.book_append_sheet(workbook, summarySheet, "Summary");
    xlsx.utils.book_append_sheet(workbook, routeSheet, "Route Data");
    xlsx.writeFile(
      workbook,
      "route-planning-" + getRouteExportDateStamp() + ".xlsx",
    );
    showRouteExportToast("Routes exported to Excel successfully.", "success");
  } catch (error) {
    console.error(error);
    showRouteExportToast("Unable to export routes to Excel.", "error");
  } finally {
    routeExportBusy = false;
  }
}

function initRouteExport() {
  const printBtn = document.getElementById("printRoutes");
  const pdfBtn = document.getElementById("exportRoutesPDF");
  const excelBtn = document.getElementById("exportRoutesExcel");

  if (printBtn && printBtn.dataset.routePrintInit !== "true") {
    printBtn.dataset.routePrintInit = "true";
    printBtn.addEventListener("click", (e) => {
      e.preventDefault();
      printRoutes();
    });
  }
  if (pdfBtn && pdfBtn.dataset.routePdfInit !== "true") {
    pdfBtn.dataset.routePdfInit = "true";
    pdfBtn.addEventListener("click", (e) => {
      e.preventDefault();
      exportRoutesToPdf();
    });
  }
  if (excelBtn && excelBtn.dataset.routeExcelInit !== "true") {
    excelBtn.dataset.routeExcelInit = "true";
    excelBtn.addEventListener("click", (e) => {
      e.preventDefault();
      exportRoutesToExcel();
    });
  }
}
