/* ==========================================
   Cost Analysis — DB-backed budget + history
========================================== */

const COST_BUDGET_HISTORY_MAX = 50;

const COST_BUDGET_CATEGORIES = [
    "Fuel",
    "Maintenance",
    "Trip Operations",
    "Reservation Operations",
    "Other",
];

function emptyCostBudgetConfig() {
    return {
        version: 1,
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
}

//   RBAC
function canManageCostBudget() {
    return (
        window.FleetRBAC?.hasPermission?.(
            "cost_analysis",
            "canManageBudget",
        ) === true
    );
}

function parseBudgetNumber(value) {
    if (value == null || value === "") return null;
    const n = Number(value);
    if (Number.isNaN(n) || !Number.isFinite(n) || n < 0) {
        return null;
    }

    return n;
}

function normalizeCostBudgetConfiguration(raw) {
    const base = emptyCostBudgetConfig();

    if (!raw || typeof raw !== "object") {
        return base;
    }

    const categoryBudgets = {
        ...base.categoryBudgets,
    };
    const srcCats = raw.categoryBudgets || raw.category_budgets || {};
    categoryBudgets.Fuel = parseBudgetNumber(srcCats.Fuel);
    categoryBudgets.Maintenance = parseBudgetNumber(srcCats.Maintenance);
    categoryBudgets.TripOperations = parseBudgetNumber(
        srcCats.TripOperations ?? srcCats["Trip Operations"],
    );
    categoryBudgets.ReservationOperations = parseBudgetNumber(
        srcCats.ReservationOperations ?? srcCats["Reservation Operations"],
    );
    categoryBudgets.Other = parseBudgetNumber(srcCats.Other);
    const periodType = [
        "filter",
        "monthly",
        "quarterly",
        "yearly",
        "custom",
    ].includes(raw.periodType || raw.period_type)
        ? raw.periodType || raw.period_type
        : "filter";
    return {
        version: 1,
        overallBudget: parseBudgetNumber(
            raw.overallBudget ??
                raw.overall_budget ??
                raw.budgetAmount ??
                raw.amount,
        ),
        categoryBudgets,
        periodType,
        startDate: String(raw.startDate ?? raw.start_date ?? "").slice(0, 10),
        endDate: String(raw.endDate ?? raw.end_date ?? "").slice(0, 10),
        notes: String(raw.notes || "").slice(0, 500),
        createdAt: raw.createdAt ?? raw.created_at ?? null,
        updatedAt: raw.updatedAt ?? raw.updated_at ?? null,
    };
}

/* ==========================================
   API HELPER
========================================== */

async function costBudgetApi(url, options = {}) {
    const response = await fetch(url, {
        credentials: "same-origin",
        headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            "X-CSRF-TOKEN":
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute("content") || "",
            ...(options.headers || {}),
        },
        ...options,
    });
    let data = null;
    try {
        data = await response.json();
    } catch {
        data = null;
    }

    if (!response.ok) {
        const error = new Error(data?.message || "Budget request failed.");
        error.errors = data?.errors || null;
        error.status = response.status;
        throw error;
    }

    return data;
}

/* ==========================================
   LOAD ACTIVE BUDGET
========================================== */
async function loadCostBudgetConfiguration() {
    try {
        const data = await costBudgetApi("/cost-analysis/budget", {
            method: "GET",
        });
        if (!data?.budget) {
            return emptyCostBudgetConfig();
        }
        return normalizeCostBudgetConfiguration(data.budget);
    } catch (error) {
        console.error("Unable to load cost budget:", error);

        return emptyCostBudgetConfig();
    }
}

/* ==========================================
   LOAD HISTORY
========================================== */
async function loadCostBudgetHistory() {
    try {
        const data = await costBudgetApi("/cost-analysis/budget/history", {
            method: "GET",
        });
        return Array.isArray(data?.history)
            ? data.history.slice(0, COST_BUDGET_HISTORY_MAX)
            : [];
    } catch (error) {
        console.error("Unable to load budget history:", error);

        return [];
    }
}

/* ==========================================
   PERIOD MATCHING
========================================== */
function isCostBudgetMatchingPeriod(config, range) {
    if (!config || config.overallBudget == null) {
        return false;
    }
    if (!range) {
        return false;
    }
    const type = config.periodType || "filter";
    if (type === "filter") {
        return true;
    }
    if (type === "custom") {
        if (!config.startDate || !config.endDate) {
            return false;
        }
        const s = costParseISO(config.startDate);
        const e = costParseISO(config.endDate);

        if (!s || !e) {
            return false;
        }

        return (
            costStartOfDay(s).getTime() === range.start.getTime() &&
            costEndOfDay(e).getTime() === range.end.getTime()
        );
    }

    if (type === "monthly") {
        return (
            range.start.getFullYear() === range.end.getFullYear() &&
            range.start.getMonth() === range.end.getMonth() &&
            range.start.getDate() === 1
        );
    }

    if (type === "quarterly") {
        const qStart = Math.floor(range.start.getMonth() / 3) * 3;

        return (
            range.start.getMonth() === qStart &&
            range.start.getDate() === 1 &&
            range.start.getFullYear() === range.end.getFullYear()
        );
    }

    if (type === "yearly") {
        return (
            range.start.getMonth() === 0 &&
            range.start.getDate() === 1 &&
            range.start.getFullYear() === range.end.getFullYear()
        );
    }

    return true;
}

/* ==========================================
   CATEGORY HELPERS
========================================== */
function categoryBudgetKey(category) {
    if (category === "Trip Operations") {
        return "TripOperations";
    }
    if (category === "Reservation Operations") {
        return "ReservationOperations";
    }
    return category;
}

function budgetStatusFromUtil(util, hasBudget, hasActual) {
    if (!hasBudget) {
        return "Not Configured";
    }
    if (!hasActual) {
        return "No Cost Data";
    }
    if (util < 80) {
        return "Under Budget";
    }
    if (util <= 100) {
        return "Near Limit";
    }
    return "Over Budget";
}

function buildCategoryBudgetRows(config, filtered) {
    const rows = [];

    const cats = [
        "Fuel",
        "Maintenance",
        "Trip Operations",
        "Reservation Operations",
        "Other",
    ];

    cats.forEach((cat) => {
        const key = categoryBudgetKey(cat);
        const budget = config?.categoryBudgets?.[key];
        const actual = sumCost(
            (filtered || []).filter((record) => record.category === cat),
        );
        const hasBudget = budget != null;
        const util =
            hasBudget && budget > 0
                ? (actual / budget) * 100
                : hasBudget && budget === 0
                  ? actual > 0
                      ? Infinity
                      : 0
                  : null;
        const remaining = hasBudget ? budget - actual : null;
        let status = "Not Configured";
        if (hasBudget) {
            if (budget === 0 && actual === 0) {
                status = "No Cost Data";
            } else if (!Number.isFinite(util)) {
                status = "Over Budget";
            } else {
                status = budgetStatusFromUtil(util, true, actual > 0);
            }
        }

        rows.push({
            category: cat,
            budget: hasBudget ? budget : null,
            actual,
            remaining,
            utilization: Number.isFinite(util)
                ? util
                : util === Infinity
                  ? 999
                  : null,
            status,
        });
    });

    return rows;
}

/* ==========================================
   HISTORY TABLE
========================================== */

async function renderBudgetHistoryTable() {
    const tbody = document.getElementById("costBudgetHistoryBody");

    if (!tbody) {
        return;
    }

    const history = await loadCostBudgetHistory();

    if (!history.length) {
        tbody.innerHTML = `
      <tr class="helper-row">
        <td colspan="5">
          No budget history yet.
        </td>
      </tr>
    `;

        return;
    }

    tbody.innerHTML = history
        .map((historyItem) => {
            const when = new Date(historyItem.changedAt);

            const whenLabel = Number.isNaN(when.getTime())
                ? "—"
                : when.toLocaleString();

            const prev =
                historyItem.previousValue == null
                    ? "—"
                    : formatCostCurrency(historyItem.previousValue);

            const next =
                historyItem.newValue == null
                    ? "—"
                    : formatCostCurrency(historyItem.newValue);

            return `
          <tr>
            <td>
              ${historyItem.action || "—"}
            </td>

            <td>
              ${prev}
            </td>

            <td>
              ${next}
            </td>

            <td>
              ${historyItem.periodType || "filter"}
            </td>

            <td>
              ${whenLabel}
            </td>
          </tr>
        `;
        })
        .join("");
}

/* ==========================================
   CATEGORY BUDGET TABLE
========================================== */

function renderCategoryBudgetTable(rows) {
    const tbody = document.getElementById("costCategoryBudgetBody");

    if (!tbody) {
        return;
    }

    if (!rows || !rows.length) {
        tbody.innerHTML = `
      <tr class="helper-row">
        <td colspan="6">
          No category budgets configured.
        </td>
      </tr>
    `;

        return;
    }

    tbody.innerHTML = rows
        .map((row) => {
            const budget =
                row.budget == null ? "—" : formatCostCurrency(row.budget);

            const actual = formatCostCurrency(row.actual);

            const remaining =
                row.remaining == null ? "—" : formatCostCurrency(row.remaining);

            const util =
                row.utilization == null
                    ? "—"
                    : formatCostPercent(row.utilization);

            return `
          <tr>
            <td>
              ${row.category}
            </td>

            <td>
              ${budget}
            </td>

            <td>
              ${actual}
            </td>

            <td>
              ${remaining}
            </td>

            <td>
              ${util}
            </td>

            <td>
              <span
                class="cost-budget-status"
                data-status="${row.status}"
              >
                ${row.status}
              </span>
            </td>
          </tr>
        `;
        })
        .join("");
}

/* ==========================================
   MODAL
========================================== */
function openCostBudgetModal() {
    if (!canManageCostBudget()) {
        return;
    }
    const modal = document.getElementById("costBudgetModal");
    if (!modal) {
        return;
    }
    const config = costAnalysisState.budgetConfig || emptyCostBudgetConfig();
    document.getElementById("budgetPeriodType").value =
        config.periodType || "filter";
    document.getElementById("budgetOverallInput").value =
        config.overallBudget != null ? String(config.overallBudget) : "";
    document.getElementById("budgetCatFuel").value =
        config.categoryBudgets?.Fuel != null
            ? String(config.categoryBudgets.Fuel)
            : "";
    document.getElementById("budgetCatMaintenance").value =
        config.categoryBudgets?.Maintenance != null
            ? String(config.categoryBudgets.Maintenance)
            : "";
    document.getElementById("budgetCatTrip").value =
        config.categoryBudgets?.TripOperations != null
            ? String(config.categoryBudgets.TripOperations)
            : "";
    document.getElementById("budgetCatReservation").value =
        config.categoryBudgets?.ReservationOperations != null
            ? String(config.categoryBudgets.ReservationOperations)
            : "";
    document.getElementById("budgetCatOther").value =
        config.categoryBudgets?.Other != null
            ? String(config.categoryBudgets.Other)
            : "";
    document.getElementById("budgetNotes").value = config.notes || "";
    document.getElementById("budgetCustomStart").value = config.startDate || "";
    document.getElementById("budgetCustomEnd").value = config.endDate || "";
    syncBudgetModalPeriodFields();
    modal.classList.add("show");
    document.body.style.overflow = "hidden";
    document.getElementById("budgetOverallInput")?.focus();
}

function closeCostBudgetModal() {
    const modal = document.getElementById("costBudgetModal");

    if (!modal) {
        return;
    }
    modal.classList.remove("show");
    document.body.style.overflow = "";
}

function syncBudgetModalPeriodFields() {
    const type = document.getElementById("budgetPeriodType")?.value;
    const wrap = document.getElementById("budgetCustomPeriodWrap");
    const start = document.getElementById("budgetCustomStart");
    const end = document.getElementById("budgetCustomEnd");
    const custom = type === "custom";
    if (wrap) {
        wrap.hidden = !custom;
    }
    if (start) {
        start.required = custom;
    }
    if (end) {
        end.required = custom;
    }
}

/* ==========================================
   SAVE BUDGET
========================================== */
async function saveCostBudgetFromModal() {
    if (!canManageCostBudget()) {
        return;
    }
    const overall = parseBudgetNumber(
        document.getElementById("budgetOverallInput")?.value,
    );
    if (overall == null) {
        if (typeof showToast === "function") {
            showToast(
                "Enter a valid overall budget (0 or greater).",
                "warning",
            );
        }
        document.getElementById("budgetOverallInput")?.focus();
        return;
    }

    const periodType =
        document.getElementById("budgetPeriodType")?.value || "filter";
    let startDate = document.getElementById("budgetCustomStart")?.value || "";
    let endDate = document.getElementById("budgetCustomEnd")?.value || "";
    if (periodType === "custom") {
        if (!startDate || !endDate) {
            if (typeof showToast === "function") {
                showToast(
                    "Custom budget period requires start and end dates.",
                    "warning",
                );
            }
            return;
        }
        if (startDate > endDate) {
            if (typeof showToast === "function") {
                showToast("Budget start cannot be later than end.", "warning");
            }

            return;
        }
    } else {
        startDate = "";
        endDate = "";
    }

    const categoryBudgets = {
        Fuel: parseBudgetNumber(
            document.getElementById("budgetCatFuel")?.value,
        ),
        Maintenance: parseBudgetNumber(
            document.getElementById("budgetCatMaintenance")?.value,
        ),
        TripOperations: parseBudgetNumber(
            document.getElementById("budgetCatTrip")?.value,
        ),
        ReservationOperations: parseBudgetNumber(
            document.getElementById("budgetCatReservation")?.value,
        ),
        Other: parseBudgetNumber(
            document.getElementById("budgetCatOther")?.value,
        ),
    };
    const catTotal = Object.values(categoryBudgets).reduce(
        (sum, value) => sum + (value || 0),
        0,
    );
    if (catTotal > overall + 0.001) {
        if (typeof showToast === "function") {
            showToast(
                "Category budgets exceed overall budget. Saving anyway.",
                "warning",
            );
        }
    }
    const submitButton = document.querySelector(
        '#saveCostBudgetForm button[type="submit"]',
    );
    if (submitButton) {
        submitButton.disabled = true;
    }
    try {
        const result = await costBudgetApi("/cost-analysis/budget", {
            method: "PUT",
            body: JSON.stringify({
                overall_budget: overall,
                category_budgets: categoryBudgets,
                period_type: periodType,
                start_date: startDate || null,
                end_date: endDate || null,
                notes: document.getElementById("budgetNotes")?.value || null,
            }),
        });
        costAnalysisState.budgetConfig = normalizeCostBudgetConfiguration(
            result.budget,
        );
        costAnalysisState.budgetAmount =
            costAnalysisState.budgetConfig.overallBudget;
        closeCostBudgetModal();
        if (typeof refreshCostAnalysis === "function") {
            await refreshCostAnalysis({
                resetTablePage: false,
                reason: "budget-save",
            });
        }
        if (typeof showToast === "function") {
            showToast(
                result.message || "Budget configuration saved.",
                "success",
            );
        }
    } catch (error) {
        console.error("Unable to save Cost Analysis budget:", error);
        if (typeof showToast === "function") {
            showToast(
                error.message || "Unable to save budget configuration.",
                "error",
            );
        }
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
        }
    }
}

/* ==========================================
   CLEAR ACTIVE BUDGET
========================================== */
async function clearActiveCostBudget() {
    if (!canManageCostBudget()) {
        return;
    }
    const ok = window.confirm(
        "Clear the active budget configuration?\n\nBudget history will be kept.",
    );
    if (!ok) {
        return;
    }
    try {
        const result = await costBudgetApi("/cost-analysis/budget", {
            method: "DELETE",
        });
        costAnalysisState.budgetConfig = emptyCostBudgetConfig();
        costAnalysisState.budgetAmount = null;
        if (typeof refreshCostAnalysis === "function") {
            await refreshCostAnalysis({
                resetTablePage: false,
                reason: "budget-clear",
            });
        }
        if (typeof showToast === "function") {
            showToast(result.message || "Budget cleared.", "success");
        }
    } catch (error) {
        console.error("Unable to clear budget:", error);
        if (typeof showToast === "function") {
            showToast(error.message || "Unable to clear budget.", "error");
        }
    }
}

/* ==========================================
   CLEAR HISTORY
========================================== */
async function clearCostBudgetHistory() {
    if (!canManageCostBudget()) {
        return;
    }
    const ok = window.confirm("Clear all budget history entries?");
    if (!ok) {
        return;
    }
    try {
        const result = await costBudgetApi("/cost-analysis/budget/history", {
            method: "DELETE",
        });
        await renderBudgetHistoryTable();
        if (typeof showToast === "function") {
            showToast(result.message || "Budget history cleared.", "success");
        }
    } catch (error) {
        console.error("Unable to clear budget history:", error);
        if (typeof showToast === "function") {
            showToast(
                error.message || "Unable to clear budget history.",
                "error",
            );
        }
    }
}


async function initCostBudgetControls() {
    if (document.body.dataset.costBudgetInit === "true") {
        return;
    }
    document.body.dataset.costBudgetInit = "true";
    /*
  |--------------------------------------------------------------------------
  | Load active budget from MySQL
  |--------------------------------------------------------------------------
  */
    costAnalysisState.budgetConfig = await loadCostBudgetConfiguration();
    costAnalysisState.budgetAmount =
        costAnalysisState.budgetConfig.overallBudget;
    /*
  |--------------------------------------------------------------------------
  | Modal Controls
  |--------------------------------------------------------------------------
  */
    if (canManageCostBudget()) {
        document
            .getElementById("openCostBudgetModal")
            ?.addEventListener("click", openCostBudgetModal);
        document
            .getElementById("editCostBudget")
            ?.addEventListener("click", openCostBudgetModal);
        document
            .getElementById("closeCostBudgetModal")
            ?.addEventListener("click", closeCostBudgetModal);
        document
            .getElementById("cancelCostBudgetModal")
            ?.addEventListener("click", closeCostBudgetModal);
        document
            .getElementById("costBudgetModal")
            ?.addEventListener("click", (event) => {
                if (event.target.id === "costBudgetModal") {
                    closeCostBudgetModal();
                }
            });
        document
            .getElementById("budgetPeriodType")
            ?.addEventListener("change", syncBudgetModalPeriodFields);
        document
            .getElementById("saveCostBudgetForm")
            ?.addEventListener("submit", async (event) => {
                event.preventDefault();
                await saveCostBudgetFromModal();
            });
        document
            .getElementById("clearCostBudget")
            ?.addEventListener("click", clearActiveCostBudget);
        document
            .getElementById("clearCostBudgetHistory")
            ?.addEventListener("click", clearCostBudgetHistory);
    }
    /*
  |--------------------------------------------------------------------------
  | Escape Key
  |--------------------------------------------------------------------------
  */
    document.addEventListener("keydown", (event) => {
        if (event.key !== "Escape") {
            return;
        }
        if (
            document
                .getElementById("costBudgetModal")
                ?.classList.contains("show")
        ) {
            closeCostBudgetModal();
        }
    });
}
