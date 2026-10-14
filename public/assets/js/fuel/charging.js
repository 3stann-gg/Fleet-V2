(function () {
    const state = {
        records: [],
        filtered: [],
        vehicles: [],
        page: 1,
        perPage: 10,
    };

    const powertrainLabels = {
        plug_in_hybrid: "Plug-in Hybrid (PHEV)",
        electric: "Electric (BEV)",
    };

    function escapeHtml(value) {
        const element = document.createElement("div");
        element.textContent = value ?? "";
        return element.innerHTML;
    }

    function vehicleName(vehicle) {
        return [vehicle?.brand, vehicle?.model].filter(Boolean).join(" ") ||
            vehicle?.plate_number ||
            "Vehicle";
    }

    function driverName(driver) {
        return [driver?.first_name, driver?.last_name].filter(Boolean).join(" ") || "Not assigned";
    }

    function currency(value) {
        return new Intl.NumberFormat("en-PH", {
            style: "currency",
            currency: "PHP",
        }).format(Number(value) || 0);
    }

    function openModal(modal) {
        if (!modal) return;
        modal.classList.add("show");
        document.body.style.overflow = "hidden";
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove("show");
        document.body.style.overflow = "";
    }

    function selectTab(tabName) {
        const charging = tabName === "charging";
        document.getElementById("fuelTransactionsPanel").hidden = charging;
        document.getElementById("chargingTransactionsPanel").hidden = !charging;
        document.getElementById("fuelTransactionsTab").classList.toggle("is-active", !charging);
        document.getElementById("chargingTransactionsTab").classList.toggle("is-active", charging);
        document.getElementById("fuelTransactionsTab").setAttribute("aria-selected", String(!charging));
        document.getElementById("chargingTransactionsTab").setAttribute("aria-selected", String(charging));
        const fuelButton = document.getElementById("addFuelBtn");
        const chargingButton = document.getElementById("addChargingBtn");
        if (fuelButton) fuelButton.hidden = charging;
        if (chargingButton) chargingButton.hidden = !charging;
        if (charging) loadRecords();
    }

    async function loadVehicles() {
        const response = await fetch("/fleet", { headers: { Accept: "application/json" } });
        if (!response.ok) throw new Error("Unable to load charging vehicles.");
        const data = await response.json();
        state.vehicles = (data.vehicles || []).filter((vehicle) =>
            ["plug_in_hybrid", "electric"].includes(vehicle.powertrain_type),
        );

        const formSelect = document.getElementById("chargingVehicle");
        const filterSelect = document.getElementById("chargingVehicleFilter");
        if (formSelect) formSelect.innerHTML = '<option value="">Select Vehicle</option>';
        if (filterSelect) filterSelect.innerHTML = '<option value="all">All Vehicles</option>';

        state.vehicles.forEach((vehicle) => {
            const label = `${vehicleName(vehicle)}${vehicle.plate_number ? ` (${vehicle.plate_number})` : ""}`;
            [formSelect, filterSelect].forEach((select) => {
                if (!select) return;
                const option = document.createElement("option");
                option.value = vehicle.id;
                option.textContent = label;
                select.appendChild(option);
            });
        });
    }

    async function loadRecords() {
        const body = document.getElementById("chargingTableBody");
        if (body) body.innerHTML = '<tr class="helper-row"><td colspan="10">Loading charging transactions...</td></tr>';
        try {
            const response = await fetch("/charging-records", { headers: { Accept: "application/json" } });
            if (!response.ok) throw new Error("Unable to load charging transactions.");
            const data = await response.json();
            state.records = data.chargingLogs || [];
            applyFilters(true);
        } catch (error) {
            if (body) body.innerHTML = `<tr class="helper-row"><td colspan="10">${escapeHtml(error.message)}</td></tr>`;
        }
    }

    function applyFilters(resetPage = false) {
        const search = document.getElementById("chargingSearch")?.value.trim().toLowerCase() || "";
        const vehicleId = document.getElementById("chargingVehicleFilter")?.value || "all";
        const powertrain = document.getElementById("chargingPowertrainFilter")?.value || "all";
        const date = document.getElementById("chargingDateFilter")?.value || "";

        state.filtered = state.records.filter((record) => {
            const haystack = [vehicleName(record.vehicle), record.vehicle?.plate_number, driverName(record.driver), record.charging_location].join(" ").toLowerCase();
            return (!search || haystack.includes(search)) &&
                (vehicleId === "all" || String(record.vehicle_id) === vehicleId) &&
                (powertrain === "all" || record.vehicle?.powertrain_type === powertrain) &&
                (!date || record.charging_date === date);
        });
        if (resetPage) state.page = 1;
        render();
    }

    function render() {
        const body = document.getElementById("chargingTableBody");
        if (!body) return;
        const start = (state.page - 1) * state.perPage;
        const pageRecords = state.filtered.slice(start, start + state.perPage);
        body.innerHTML = pageRecords.length
            ? pageRecords.map((record) => `
                <tr>
                    <td>${escapeHtml(vehicleName(record.vehicle))}<small>${escapeHtml(record.vehicle?.plate_number || "")}</small></td>
                    <td><span class="status-badge available">${escapeHtml(powertrainLabels[record.vehicle?.powertrain_type] || "Not specified")}</span></td>
                    <td>${escapeHtml(driverName(record.driver))}</td>
                    <td>${escapeHtml(record.charging_date)}${record.charging_time ? `<small>${escapeHtml(record.charging_time.slice(0, 5))}</small>` : ""}</td>
                    <td class="numeric-cell">${Number(record.battery_before_percent).toFixed(1)}%</td>
                    <td class="numeric-cell">${Number(record.battery_after_percent).toFixed(1)}%</td>
                    <td class="numeric-cell">${Number(record.energy_added_kwh).toFixed(2)} kWh</td>
                    <td>${escapeHtml(record.charging_location)}</td>
                    <td class="numeric-cell">${currency(record.charging_cost)}</td>
                    <td><button type="button" class="action-btn view js-view-charging" data-id="${record.id}" title="View" aria-label="View charging transaction"><i class="ph ph-eye" aria-hidden="true"></i></button></td>
                </tr>`).join("")
            : '<tr class="helper-row"><td colspan="10">No charging transactions found.</td></tr>';

        const totalEnergy = state.filtered.reduce((sum, record) => sum + Number(record.energy_added_kwh || 0), 0);
        const totalCost = state.filtered.reduce((sum, record) => sum + Number(record.charging_cost || 0), 0);
        document.getElementById("totalChargingRecords").textContent = state.filtered.length;
        document.getElementById("totalElectricityAdded").textContent = `${totalEnergy.toFixed(2)} kWh`;
        document.getElementById("totalChargingCost").textContent = currency(totalCost);
        document.getElementById("averageEnergyAdded").textContent = `${(state.filtered.length ? totalEnergy / state.filtered.length : 0).toFixed(2)} kWh`;
        renderPagination();
    }

    function renderPagination() {
        const total = state.filtered.length;
        const pages = Math.max(1, Math.ceil(total / state.perPage));
        state.page = Math.min(state.page, pages);
        const first = total ? (state.page - 1) * state.perPage + 1 : 0;
        const last = Math.min(state.page * state.perPage, total);
        document.getElementById("chargingPaginationInfo").innerHTML = `Showing <strong>${first}–${last}</strong> of <strong>${total}</strong> charging transactions`;
        const pagination = document.getElementById("chargingPagination");
        pagination.innerHTML = "";
        [["ph-caret-left", state.page - 1, state.page === 1], ["ph-caret-right", state.page + 1, state.page === pages]].forEach(([icon, page, disabled]) => {
            const button = document.createElement("button");
            button.type = "button";
            button.disabled = disabled;
            button.innerHTML = `<i class="ph ${icon}"></i>`;
            button.addEventListener("click", () => { state.page = page; render(); });
            pagination.appendChild(button);
        });
    }

    function showDetails(record) {
        const values = {
            viewChargingVehicle: vehicleName(record.vehicle),
            viewChargingPowertrain: powertrainLabels[record.vehicle?.powertrain_type] || "Not specified",
            viewChargingDriver: driverName(record.driver),
            viewChargingDateTime: `${record.charging_date}${record.charging_time ? ` ${record.charging_time.slice(0, 5)}` : ""}`,
            viewChargingBefore: `${Number(record.battery_before_percent).toFixed(1)}%`,
            viewChargingAfter: `${Number(record.battery_after_percent).toFixed(1)}%`,
            viewChargingEnergy: `${Number(record.energy_added_kwh).toFixed(2)} kWh`,
            viewChargingCost: currency(record.charging_cost),
            viewChargingLocation: record.charging_location,
            viewChargingOdometer: record.odometer ? `${Number(record.odometer).toLocaleString()} km` : "—",
            viewChargingNotes: record.notes || "—",
        };
        Object.entries(values).forEach(([id, value]) => { document.getElementById(id).textContent = value; });
        openModal(document.getElementById("viewChargingModal"));
    }

    async function submit(event) {
        event.preventDefault();
        const before = Number(document.getElementById("chargingBatteryBefore").value);
        const after = Number(document.getElementById("chargingBatteryAfter").value);
        if (after < before) {
            window.showToast("Battery after must be greater than or equal to battery before.", "error");
            return;
        }
        const payload = {
            vehicle_id: document.getElementById("chargingVehicle").value || null,
            driver_id: document.getElementById("chargingDriver").value || null,
            charging_date: document.getElementById("chargingDate").value,
            charging_time: document.getElementById("chargingTime").value || null,
            battery_before_percent: before,
            battery_after_percent: after,
            energy_added_kwh: document.getElementById("chargingEnergyAdded").value,
            charging_location: document.getElementById("chargingLocation").value.trim(),
            charging_cost: document.getElementById("chargingCost").value,
            odometer: document.getElementById("chargingOdometer").value || null,
            notes: document.getElementById("chargingNotes").value.trim() || null,
        };
        const button = document.getElementById("saveChargingBtn");
        button.disabled = true;
        try {
            const response = await fetch("/charging-records", {
                method: "POST",
                headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(payload),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || "Unable to save charging transaction.");
            document.getElementById("chargingForm").reset();
            closeModal(document.getElementById("addChargingModal"));
            window.showToast(data.message, "success");
            await Promise.all([loadRecords(), loadVehicles()]);
        } catch (error) {
            window.showToast(error.message, "error");
        } finally {
            button.disabled = false;
        }
    }

    document.addEventListener("DOMContentLoaded", async () => {
        document.getElementById("fuelTransactionsTab")?.addEventListener("click", () => selectTab("fuel"));
        document.getElementById("chargingTransactionsTab")?.addEventListener("click", () => selectTab("charging"));
        document.getElementById("addChargingBtn")?.addEventListener("click", () => openModal(document.getElementById("addChargingModal")));
        ["closeAddChargingModal", "cancelCharging"].forEach((id) => document.getElementById(id)?.addEventListener("click", () => closeModal(document.getElementById("addChargingModal"))));
        ["closeViewChargingModal", "closeViewChargingFooter"].forEach((id) => document.getElementById(id)?.addEventListener("click", () => closeModal(document.getElementById("viewChargingModal"))));
        document.getElementById("chargingForm")?.addEventListener("submit", submit);
        ["chargingSearch", "chargingVehicleFilter", "chargingPowertrainFilter", "chargingDateFilter"].forEach((id) => document.getElementById(id)?.addEventListener(id === "chargingSearch" ? "input" : "change", () => applyFilters(true)));
        document.getElementById("refreshCharging")?.addEventListener("click", loadRecords);
        document.getElementById("chargingTableBody")?.addEventListener("click", (event) => {
            const button = event.target.closest(".js-view-charging");
            if (button) showDetails(state.records.find((record) => String(record.id) === button.dataset.id));
        });
        const vehicleSelect = document.getElementById("chargingVehicle");
        vehicleSelect?.addEventListener("change", () => {
            const vehicle = state.vehicles.find((item) => String(item.id) === vehicleSelect.value);
            const driverSelect = document.getElementById("chargingDriver");
            driverSelect.innerHTML = '<option value="">No driver</option>';
            (vehicle?.drivers || []).forEach((driver) => {
                const option = document.createElement("option");
                option.value = driver.id;
                option.textContent = driverName(driver);
                driverSelect.appendChild(option);
            });
            if (vehicle?.battery_level_percent !== null && vehicle?.battery_level_percent !== undefined) document.getElementById("chargingBatteryBefore").value = vehicle.battery_level_percent;
            if (vehicle?.current_odometer !== null && vehicle?.current_odometer !== undefined) document.getElementById("chargingOdometer").value = vehicle.current_odometer;
        });
        try { await loadVehicles(); } catch (error) { console.error(error); }
        document.getElementById("chargingDate")?.setAttribute("value", new Date().toISOString().slice(0, 10));
    });
})();
