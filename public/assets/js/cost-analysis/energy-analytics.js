/* ==========================================
   Rule-based Fleet Energy Analytics
========================================== */

function energyDateValue(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}`;
}

function energyNumber(value, decimals = 2) {
    return Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

function energyCurrency(value) {
    return `₱${energyNumber(value)}`;
}

function escapeEnergyHtml(value) {
    const element = document.createElement("div");
    element.textContent = String(value ?? "");

    return element.innerHTML;
}

function renderEnergyBars(containerId, rows, valueKey, labelKey, valueFormatter) {
    const container = document.getElementById(containerId);
    const bars = container?.querySelector(".cost-bars");
    const empty = container?.querySelector(".cost-chart-empty");
    const usable = (rows || []).filter((row) => Number(row[valueKey]) > 0);

    if (!container || !bars || !empty) return;

    empty.hidden = usable.length > 0;
    bars.hidden = usable.length === 0;
    if (usable.length === 0) {
        bars.replaceChildren();
        return;
    }

    const maximum = Math.max(...usable.map((row) => Number(row[valueKey])));
    bars.innerHTML = usable
        .slice(0, 10)
        .map((row) => {
            const value = Number(row[valueKey]);
            const width = Math.max(3, Math.round((value / maximum) * 100));

            return `<div class="cost-bar-row">
                <span class="cost-bar-label">${escapeEnergyHtml(row[labelKey])}</span>
                <span class="cost-bar-track"><span class="cost-bar-fill" style="width:${width}%"></span></span>
                <strong>${escapeEnergyHtml(valueFormatter(value))}</strong>
            </div>`;
        })
        .join("");
}

async function loadEnergyAnalytics(range, filters, sources) {
    const parameters = new URLSearchParams({
        start_date: energyDateValue(range.start),
        end_date: energyDateValue(range.end),
    });
    const selectedVehicle = (sources?.vehicles || []).find(
        (vehicle) => getCostVehicleLabel(vehicle) === filters.vehicle,
    );

    if (selectedVehicle?.id) parameters.set("vehicle_id", selectedVehicle.id);
    if (filters.vehicleType !== "all") {
        parameters.set("vehicle_type", filters.vehicleType);
    }
    if (filters.powertrain !== "all") {
        parameters.set("powertrain_type", filters.powertrain);
    }
    if (filters.department !== "all") {
        parameters.set(
            "department",
            filters.department === "Unassigned"
                ? "__unassigned__"
                : filters.department,
        );
    }

    const response = await fetch(`/cost-analysis/energy-analytics?${parameters}`, {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
    });
    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || "Unable to load energy analytics.");
    }

    return data;
}

function renderEnergyAnalytics(data) {
    const summary = data?.summary || {};
    setCostText("energyKpiDistance", `${energyNumber(summary.distance_km)} km`);
    setCostText("energyKpiFuel", `${energyNumber(summary.fuel_liters)} L`);
    setCostText("energyKpiElectricity", `${energyNumber(summary.electricity_kwh)} kWh`);
    setCostText("energyKpiFuelCost", energyCurrency(summary.fuel_cost));
    setCostText("energyKpiChargingCost", energyCurrency(summary.charging_cost));
    setCostText("energyKpiTotalCost", energyCurrency(summary.total_energy_cost));
    setCostText(
        "energyKpiCostKm",
        summary.average_cost_per_km == null
            ? "Insufficient Data"
            : `${energyCurrency(summary.average_cost_per_km)} / km`,
    );
    setCostText(
        "energyKpiEstimatedFuelEfficiency",
        summary.estimated_fuel_efficiency_km_per_liter == null
            ? "N/A"
            : `${energyNumber(summary.estimated_fuel_efficiency_km_per_liter)} km/L`,
    );
    const intervalCount = Number(
        summary.valid_fuel_efficiency_interval_count || 0,
    );
    setCostText(
        "energyKpiFuelIntervals",
        `${intervalCount} valid ${intervalCount === 1 ? "interval" : "intervals"}`,
    );

    renderEnergyBars(
        "energyCostTrend",
        data?.trend,
        "total_cost",
        "period",
        energyCurrency,
    );

    const fuelRanked = (data?.vehicles || [])
        .filter(
            (vehicle) =>
                vehicle.estimated_fuel_efficiency_km_per_liter != null,
        )
        .sort(
            (first, second) =>
                second.estimated_fuel_efficiency_km_per_liter -
                first.estimated_fuel_efficiency_km_per_liter,
        );
    renderEnergyBars(
        "energyFuelEfficiencyRanking",
        fuelRanked,
        "estimated_fuel_efficiency_km_per_liter",
        "vehicle",
        (value) => `${energyNumber(value)} km/L`,
    );

    const electricRanked = (data?.vehicles || [])
        .filter((vehicle) => vehicle.electric_efficiency_km_per_kwh != null)
        .sort(
            (first, second) =>
                second.electric_efficiency_km_per_kwh -
                first.electric_efficiency_km_per_kwh,
        );
    renderEnergyBars(
        "energyElectricEfficiencyRanking",
        electricRanked,
        "electric_efficiency_km_per_kwh",
        "vehicle",
        (value) => `${energyNumber(value)} km/kWh`,
    );
}

window.loadEnergyAnalytics = loadEnergyAnalytics;
window.renderEnergyAnalytics = renderEnergyAnalytics;
