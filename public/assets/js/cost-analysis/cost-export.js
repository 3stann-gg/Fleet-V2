/* ==========================================
   Cost Analysis Print / PDF / Excel
========================================== */

let costExportBusy = false;

function showCostExportToast(message, type) {
  if (typeof showToast === "function") {
    showToast(message, type);
  } else if (typeof window.showToast === "function") {
    window.showToast(message, type);
  }
}

function getCostExportDateStamp() {
  const now = new Date();
  return (
    now.getFullYear() +
    "-" +
    String(now.getMonth() + 1).padStart(2, "0") +
    "-" +
    String(now.getDate()).padStart(2, "0")
  );
}

function getCostAnalysisFilterSummaryLines() {
  const filters = costAnalysisState.filters || getCostFilterValues();
  const range = costAnalysisState.range;
  const parts = [];
  if (range?.label) parts.push("Date Range: " + range.label);
  if (filters.vehicle && filters.vehicle !== "all") {
    parts.push("Vehicle: " + filters.vehicle);
  }
  if (filters.department && filters.department !== "all") {
    parts.push("Department: " + filters.department);
  }
  if (filters.category && filters.category !== "all") {
    parts.push("Category: " + filters.category);
  }
  parts.push(
    "Analysis View: " +
      (filters.analysisView || costAnalysisState.analysisView || "overview"),
  );
  const search =
    typeof costTableState !== "undefined"
      ? (costTableState.search || "").trim()
      : "";
  if (search) parts.push("Table Search: " + search);
  return parts;
}

/**
 * Shared output model from processed state (no re-normalize).
 */
function getCostAnalysisOutputModel() {
  const view = costAnalysisState.analysisView || "overview";
  const filtered = costAnalysisState.filtered || [];
  const rows =
    typeof getCostTableWorkingRows === "function"
      ? getCostTableWorkingRows()
      : filtered.slice();
  const columns =
    typeof costTableConfig !== "undefined" && costTableConfig?.columns
      ? costTableConfig.columns.slice()
      : [];
  const range = costAnalysisState.range;
  const budget = costAnalysisState.budgetConfig || {
      overallBudget: null,
      categoryBudgets: {
          Fuel: null,
          Maintenance: null,
          TripOperations: null,
          ReservationOperations: null,
          Other: null,
      },
      periodType: "filter",
      startDate: "",
      endDate: "",
      notes: "",
      createdAt: null,
      updatedAt: null,
  };
  const actual = sumCost(filtered);
  const overall = budget.overallBudget;
  const matching =
    typeof isCostBudgetMatchingPeriod === "function"
      ? isCostBudgetMatchingPeriod(budget, range)
      : true;
  const appliedBudget =
    matching && overall != null && overall >= 0 ? overall : null;
  let util = null;
  if (appliedBudget != null) {
      if (appliedBudget > 0) {
          util = (actual / appliedBudget) * 100;
      } else if (appliedBudget === 0 && actual > 0) {
          util = 999;
      } else if (appliedBudget === 0 && actual === 0) {
          util = 0;
      }
  }

  const now = new Date();
  let trendSummary = null;
  if (view === "trends" && range) {
    const filters = costAnalysisState.filters || getCostFilterValues();
    const prevRange = getPreviousComparableRange(range, filters.datePreset);
    const prevRecords = prevRange
      ? filterCostRecords(costAnalysisState.normalized || [], prevRange, filters)
      : [];
    const previous = sumCost(prevRecords);
    trendSummary = {
      current: actual,
      previous,
      change: actual - previous,
      avgDaily: actual / (range.days || 1),
    };
  }

  return {
    generatedAt: now,
    generatedDate: now.toLocaleDateString(),
    generatedTime: now.toLocaleTimeString(),
    title: "Cost Analysis",
    subtitle: "Fleet operating cost analytics",
    analysisView: view,
    dateRangeLabel: range?.label || "—",
    appliedFilters: getCostAnalysisFilterSummaryLines(),
    statistics: {
      totalOperatingCost: actual,
      fuelCost: sumCost(filtered.filter((r) => r.category === "Fuel")),
      maintenanceCost: sumCost(
        filtered.filter((r) => r.category === "Maintenance"),
      ),
      recordCount: filtered.length,
      tableRowCount: rows.length,
    },
    budgetSummary: {
      configured: overall != null,
      overallBudget: overall,
      actual,
      remaining: appliedBudget != null ? appliedBudget - actual : null,
      utilization: util,
      periodType: budget.periodType,
      matching,
      notes: budget.notes || "",
    },
    categoryBudgetRows:
      typeof buildCategoryBudgetRows === "function"
        ? buildCategoryBudgetRows(budget, filtered)
        : [],
    trendSummary,
    rows,
    columns,
    tripUnavailable:
      view === "trips" &&
      !filtered.some((r) => r.category === "Trip Operations"),
  };
}

function hasCostExportableData(model) {
  if (!model) return false;
  if (model.rows && model.rows.length > 0) return true;
  if (model.analysisView === "budget" && model.budgetSummary?.configured) {
    return true;
  }
  if (model.tripUnavailable) return true;
  if (model.statistics?.totalOperatingCost > 0) return true;
  return false;
}

function escapeCostHtml(value) {
  return String(value == null ? "" : value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function formatCostPdfCurrency(value) {
    const n = Number(value);
    if (Number.isNaN(n)) {
        return "PHP 0.00";
    }
    return (
        "PHP " +
        n.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })
    );
}
function formatCostPdfCell(value, col) {
    if (value == null || value === "") {
        return "—";
    }
    if (col?.type === "currency") {
        return formatCostPdfCurrency(value);
    }
    if (col?.type === "percent") {
        return formatCostPercent(value);
    }
    if (col?.type === "number") {
        const n = Number(value);
        if (Number.isNaN(n)) {
            return String(value);
        }
        return n.toLocaleString(undefined, {
            maximumFractionDigits: 2,
        });
    }
    return String(value);
}

function formatCostExportCell(value, col) {
  if (value == null || value === "") return "—";
  if (col?.type === "currency") return formatCostCurrency(value);
  if (col?.type === "percent") return formatCostPercent(value);
  if (col?.type === "number" && typeof value === "number") {
    return value.toLocaleString(undefined, { maximumFractionDigits: 2 });
  }
  return String(value);
}

function buildCostPrintHtml(model) {
  const filters = (model.appliedFilters || [])
    .map((l) => `<p class="meta">${escapeCostHtml(l)}</p>`)
    .join("");
  const s = model.statistics;
  const b = model.budgetSummary;
  const columns = model.columns || [];
  const rows = model.rows || [];

  const head = columns
    .map((c) => `<th>${escapeCostHtml(c.label)}</th>`)
    .join("");
  const body =
    rows.length === 0
      ? `<tr><td colspan="${Math.max(columns.length, 1)}">${
          model.tripUnavailable
            ? "Trip cost analysis is unavailable because no valid monetary trip-cost records exist."
            : "No table rows for this view."
        }</td></tr>`
      : rows
          .map(
            (row) =>
              "<tr>" +
              columns
                .map(
                  (col) =>
                    `<td>${escapeCostHtml(
                      formatCostExportCell(row[col.key], col),
                    )}</td>`,
                )
                .join("") +
              "</tr>",
          )
          .join("");

  return `<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8" />
<title>Cost Analysis</title>
<style>
@page { size: A4 landscape; margin: 12mm; }
body { font-family: Arial, Helvetica, sans-serif; color:#000; background:#fff; font-size:10px; }
h1 { margin:0 0 2px; font-size:16px; color:#00a86b; }
h2 { margin:0 0 4px; font-size:13px; }
h3 { margin:0 0 8px; font-size:14px; }
.meta { margin:2px 0; }
.summary { margin:8px 0 12px; padding:8px; border:1px solid #ddd; display:flex; flex-wrap:wrap; gap:10px 16px; }
table { width:100%; border-collapse:collapse; table-layout:fixed; }
thead { display:table-header-group; }
th,td { border:1px solid #333; padding:4px; text-align:left; word-wrap:break-word; vertical-align:top; }
th { background:#e8f8f2; font-size:9px; }
tr { page-break-inside:avoid; }
@media print { body { -webkit-print-color-adjust:exact; print-color-adjust:exact; } }
</style></head><body>
<h1>Hospital Information Management System</h1>
<h2>Fleet &amp; Transportation Management</h2>
<h3>Cost Analysis — ${escapeCostHtml(model.analysisView)}</h3>
<p class="meta">Generated Date: ${escapeCostHtml(model.generatedDate)}</p>
<p class="meta">Generated Time: ${escapeCostHtml(model.generatedTime)}</p>
<p class="meta">Analysis Period: ${escapeCostHtml(model.dateRangeLabel)}</p>
${filters}
<div class="summary">
  <span><strong>Total Operating Cost:</strong> ${escapeCostHtml(formatCostCurrency(s.totalOperatingCost))}</span>
  <span><strong>Fuel:</strong> ${escapeCostHtml(formatCostCurrency(s.fuelCost))}</span>
  <span><strong>Maintenance:</strong> ${escapeCostHtml(formatCostCurrency(s.maintenanceCost))}</span>
  <span><strong>Records:</strong> ${s.tableRowCount}</span>
  ${
    b.configured
      ? `<span><strong>Budget:</strong> ${escapeCostHtml(formatCostCurrency(b.overallBudget))}</span>
         <span><strong>Utilization:</strong> ${escapeCostHtml(formatCostPercent(b.utilization))}</span>`
      : "<span><strong>Budget:</strong> Not Configured</span>"
  }
</div>
${
  model.tripUnavailable
    ? "<p class='meta'><em>Trip cost analysis is unavailable because no valid monetary trip-cost records exist.</em></p>"
    : ""
}
${
  model.trendSummary
    ? `<div class="summary">
        <span><strong>Current Period:</strong> ${escapeCostHtml(formatCostCurrency(model.trendSummary.current))}</span>
        <span><strong>Previous Period:</strong> ${escapeCostHtml(formatCostCurrency(model.trendSummary.previous))}</span>
        <span><strong>Change:</strong> ${escapeCostHtml(formatCostCurrency(model.trendSummary.change))}</span>
        <span><strong>Avg Daily:</strong> ${escapeCostHtml(formatCostCurrency(model.trendSummary.avgDaily))}</span>
      </div>`
    : ""
}
${
  columns.length
    ? `<table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>`
    : rows.length === 0 && !model.tripUnavailable
      ? "<p class='meta'>No cost analysis data is available for the selected filters.</p>"
      : ""
}
</body></html>`;
}

function printCostAnalysis() {
  if (costExportBusy) return;
  const model = getCostAnalysisOutputModel();
  if (!hasCostExportableData(model)) {
    showCostExportToast(
      "No cost analysis data is available for the selected filters.",
      "warning",
    );
    return;
  }

  let win;
  try {
    win = window.open("", "_blank", "noopener,noreferrer,width=1100,height=760");
  } catch (error) {
    console.error(error);
    showCostExportToast("Unable to open the print report.", "error");
    return;
  }
  if (!win) {
    showCostExportToast("Unable to open the print report.", "error");
    return;
  }

  costExportBusy = true;
  try {
    win.document.open();
    win.document.write(buildCostPrintHtml(model));
    win.document.close();
    const run = () => {
      try {
        win.focus();
        win.print();
      } catch (error) {
        console.error(error);
        showCostExportToast("Unable to print cost analysis.", "error");
      }
      const close = () => {
        try {
          win.close();
        } catch {
          /* ignore */
        }
        costExportBusy = false;
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
    showCostExportToast("Unable to print cost analysis.", "error");
    costExportBusy = false;
  }
}

function exportCostAnalysisToPdf() {
  if (costExportBusy) return;
  const jsPDF = window.jspdf?.jsPDF;
  if (!jsPDF) {
    showCostExportToast("PDF export is unavailable.", "error");
    return;
  }
  const model = getCostAnalysisOutputModel();
  if (!hasCostExportableData(model)) {
    showCostExportToast(
      "No cost analysis data is available for the selected filters.",
      "warning",
    );
    return;
  }

  costExportBusy = true;
  try {
    const pdf = new jsPDF({
      orientation: "landscape",
      unit: "mm",
      format: "a4",
      ...(window.consumeSecurePdfEncryption?.() || {}),
    });
    if (typeof pdf.autoTable !== "function") {
      showCostExportToast("PDF export is unavailable.", "error");
      costExportBusy = false;
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
    pdf.text("Cost Analysis — " + model.analysisView, 14, y);
    y += 8;
    pdf.setFontSize(9);
    pdf.text("Generated: " + model.generatedDate + " " + model.generatedTime, 14, y);
    y += 5;
    pdf.text("Period: " + model.dateRangeLabel, 14, y);
    y += 5;
    (model.appliedFilters || []).forEach((line) => {
      pdf.text(String(line), 14, y);
      y += 4.5;
    });
    const s = model.statistics;
    pdf.text(
        "Total: " +
            formatCostPdfCurrency(s.totalOperatingCost) +
            "  ·  Fuel: " +
            formatCostPdfCurrency(s.fuelCost) +
            "  ·  Maintenance: " +
            formatCostPdfCurrency(s.maintenanceCost) +
            "  ·  Rows: " +
            s.tableRowCount,
        14,
        y,
    );
    y += 6;
    if (model.budgetSummary?.configured) {
      pdf.text(
          "Budget: " +
              formatCostPdfCurrency(model.budgetSummary.overallBudget) +
              "  ·  Utilization: " +
              formatCostPercent(model.budgetSummary.utilization),
          14,
          y,
      );
      y += 6;
    }
    if (model.tripUnavailable) {
      pdf.text(
        "Trip cost analysis is unavailable (no monetary trip-cost records).",
        14,
        y,
      );
      y += 6;
    }

    if (model.columns?.length && model.rows?.length) {
      pdf.autoTable({
          head: [model.columns.map((c) => c.label)],
          body: model.rows.map((row) =>
              model.columns.map((col) => formatCostPdfCell(row[col.key], col)),
          ),
          startY: y,
          styles: { fontSize: 7, cellPadding: 1.4, overflow: "linebreak" },
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
    }

    const viewSlug = String(model.analysisView || "overview").replace(
      /[^a-z0-9]+/gi,
      "-",
    );
    pdf.save(
      "cost-analysis-" + viewSlug + "-" + getCostExportDateStamp() + ".pdf",
    );
    showCostExportToast("Cost analysis PDF exported successfully.", "success");
  } catch (error) {
    console.error(error);
    showCostExportToast("Unable to export cost analysis PDF.", "error");
  } finally {
    costExportBusy = false;
  }
}

async function exportCostAnalysisToExcel() {
    if (costExportBusy) return;
    const xlsx = window.XLSX;
    if (!xlsx?.utils) {
        showCostExportToast("Excel export is unavailable.", "error");
        return;
    }
    const model = getCostAnalysisOutputModel();
    if (!hasCostExportableData(model)) {
        showCostExportToast(
            "No cost analysis data is available for the selected filters.",
            "warning",
        );
        return;
    }

    costExportBusy = true;
    try {
        const s = model.statistics;
        const b = model.budgetSummary;
        const summary = [
            ["Hospital Information Management System"],
            ["Fleet & Transportation Management"],
            ["Cost Analysis"],
            ["Analysis View", model.analysisView],
            ["Generated Date", model.generatedDate],
            ["Generated Time", model.generatedTime],
            ["Period", model.dateRangeLabel],
            [],
            ["Applied Filters"],
            ...(model.appliedFilters || []).map((l) => [l]),
            [],
            ["KPIs"],
            ["Total Operating Cost", s.totalOperatingCost],
            ["Fuel Cost", s.fuelCost],
            ["Maintenance Cost", s.maintenanceCost],
            ["Matching Records", s.tableRowCount],
            [],
            ["Budget"],
            [
                "Overall Budget",
                b.configured ? b.overallBudget : "Not Configured",
            ],
            ["Actual", b.actual],
            ["Remaining", b.remaining],
            ["Utilization %", b.utilization],
            ["Period Type", b.periodType],
            ["Matches Active Range", b.matching ? "Yes" : "No"],
        ];

        if (model.tripUnavailable) {
            summary.push([]);
            summary.push([
                "Note",
                "Trip cost analysis is unavailable because no valid monetary trip-cost records exist.",
            ]);
        }

        const workbook = xlsx.utils.book_new();
        const summarySheet = xlsx.utils.aoa_to_sheet(summary);
        summarySheet["!cols"] = [{ wch: 32 }, { wch: 48 }];
        xlsx.utils.book_append_sheet(workbook, summarySheet, "Summary");

        if (model.columns?.length) {
            const data = [
                model.columns.map((c) => c.label),
                ...model.rows.map((row) =>
                    model.columns.map((col) => {
                        const raw = row[col.key];
                        if (
                            (col.type === "currency" ||
                                col.type === "number") &&
                            typeof raw === "number"
                        ) {
                            return raw;
                        }
                        if (col.type === "percent" && typeof raw === "number")
                            return raw;
                        return raw == null ? "" : raw;
                    }),
                ),
            ];
            const sheetName =
                model.analysisView === "vehicles"
                    ? "Vehicle Costs"
                    : model.analysisView === "departments"
                      ? "Department Costs"
                      : model.analysisView === "trips"
                        ? "Trip Costs"
                        : model.analysisView === "budget"
                          ? "Budget Analysis"
                          : model.analysisView === "trends"
                            ? "Cost Trends"
                            : "Cost Records";
            const dataSheet = xlsx.utils.aoa_to_sheet(data);
            dataSheet["!cols"] = model.columns.map((column) => {
                if (column.type === "currency") {
                    return { wch: 18 };
                }
                if (column.type === "percent") {
                    return { wch: 16 };
                }
                if (column.type === "date") {
                    return { wch: 14 };
                }
                if (column.key === "description") {
                    return { wch: 36 };
                }
                if (column.key === "vehicle" || column.key === "vehicleName") {
                    return { wch: 30 };
                }
                if (column.key === "department") {
                    return { wch: 24 };
                }
                return { wch: 20 };
            });
            xlsx.utils.book_append_sheet(
                workbook,
                dataSheet,
                sheetName.slice(0, 31),
            );
        }

        if (
            model.analysisView === "budget" &&
            model.categoryBudgetRows?.length
        ) {
            const cat = [
                [
                    "Category",
                    "Budget",
                    "Actual",
                    "Remaining",
                    "Utilization",
                    "Status",
                ],
                ...model.categoryBudgetRows.map((r) => [
                    r.category,
                    r.budget,
                    r.actual,
                    r.remaining,
                    r.utilization,
                    r.status,
                ]),
            ];
            const categorySheet = xlsx.utils.aoa_to_sheet(cat);
            categorySheet["!cols"] = [
                { wch: 26 },
                { wch: 18 },
                { wch: 18 },
                { wch: 18 },
                { wch: 16 },
                { wch: 18 },
            ];
            xlsx.utils.book_append_sheet(
                workbook,
                categorySheet,
                "Category Budgets",
            );
        }

        if (model.analysisView === "budget") {
            let history = [];

            if (typeof loadCostBudgetHistory === "function") {
                history = await loadCostBudgetHistory();
            }

            const hist = [
                [
                    "Action",
                    "Previous Budget",
                    "New Budget",
                    "Period",
                    "Changed At",
                ],

                ...history.map((item) => [
                    item.action || "",
                    item.previousValue,
                    item.newValue,
                    item.periodType || "",
                    item.changedAt || "",
                ]),
            ];

            const historySheet = xlsx.utils.aoa_to_sheet(hist);

            historySheet["!cols"] = [
                { wch: 16 },
                { wch: 20 },
                { wch: 20 },
                { wch: 18 },
                { wch: 28 },
            ];

            xlsx.utils.book_append_sheet(
                workbook,
                historySheet,
                "Budget History",
            );
        }

        const viewSlug = String(model.analysisView || "overview").replace(
            /[^a-z0-9]+/gi,
            "-",
        );
        xlsx.writeFile(
            workbook,
            "cost-analysis-" +
                viewSlug +
                "-" +
                getCostExportDateStamp() +
                ".xlsx",
        );
        showCostExportToast(
            "Cost analysis Excel exported successfully.",
            "success",
        );
    } catch (error) {
        console.error(error);
        showCostExportToast("Unable to export cost analysis Excel.", "error");
    } finally {
        costExportBusy = false;
    }
}

function initCostAnalysisExport() {
  const printBtn = document.getElementById("printCostAnalysis");
  const pdfBtn = document.getElementById("exportCostAnalysisPDF");
  const excelBtn = document.getElementById("exportCostAnalysisExcel");

  if (printBtn && printBtn.dataset.costPrintInit !== "true") {
    printBtn.dataset.costPrintInit = "true";
    printBtn.addEventListener("click", (e) => {
      e.preventDefault();
      printCostAnalysis();
    });
  }
  if (pdfBtn && pdfBtn.dataset.costPdfInit !== "true") {
    pdfBtn.dataset.costPdfInit = "true";
    pdfBtn.addEventListener("click", (e) => {
      e.preventDefault();
      exportCostAnalysisToPdf();
    });
  }
  if (excelBtn && excelBtn.dataset.costExcelInit !== "true") {
    excelBtn.dataset.costExcelInit = "true";
    excelBtn.addEventListener("click", async (event) => {
        event.preventDefault();
        await exportCostAnalysisToExcel();
    });
  }
}
