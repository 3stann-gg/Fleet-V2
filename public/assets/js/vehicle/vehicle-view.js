/* ==========================================
   View Vehicle Modal
========================================== */

function setViewVehicleText(modal, id, value, fallback = "Not provided") {
    const element = modal.querySelector(`#${id}`);

    if (!element) {
        return;
    }

    const text =
        value !== null && value !== undefined && String(value).trim() !== ""
            ? String(value)
            : fallback;

    element.textContent = text;
}

function calculateVehicleFuelLevel(currentFuel, tankCapacity) {
    const fuel = Number(currentFuel);

    const tank = Number(tankCapacity);

    if (Number.isNaN(fuel) || Number.isNaN(tank) || tank <= 0) {
        return null;
    }

    const percentage = (fuel / tank) * 100;

    return Math.max(0, Math.min(100, percentage));
}

function formatVehicleFuelLevel(currentFuel, tankCapacity) {
    const percentage = calculateVehicleFuelLevel(currentFuel, tankCapacity);

    if (percentage === null) {
        return "0%";
    }

    return `${percentage.toFixed(1)}%`;
}

function formatVehicleFuelAmount(currentFuel) {
    const value = Number(currentFuel);

    if (Number.isNaN(value)) {
        return "Not available";
    }

    return `${value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })} L`;
}

function formatVehicleTankCapacity(tankCapacity) {
    const value = Number(tankCapacity);

    if (Number.isNaN(value)) {
        return "Not available";
    }

    return `${value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })} L`;
}

function formatVehicleMileage(mileage) {
    const value = Number(mileage);

    if (Number.isNaN(value)) {
        return "Not available";
    }

    return `${value.toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    })} km`;
}

function formatVehicleEnergyValue(value, unit) {
    const number = Number(value);

    return Number.isNaN(number) ? "Not available" : `${number.toLocaleString()} ${unit}`;
}

function formatVehicleViewDate(date) {
    if (!date) {
        return "Not provided";
    }

    const parsed = new Date(date);

    if (Number.isNaN(parsed.getTime())) {
        return "Not provided";
    }

    return parsed.toLocaleDateString(undefined, {
        year: "numeric",
        month: "short",
        day: "numeric",
    });
}

function openVehicleDetailsModal(modal) {
    if (!modal.classList.contains("show")) {
        modal.dataset.previousBodyOverflow = document.body.style.overflow;
    }

    modal.classList.add("show");

    document.body.style.overflow = "hidden";
}

function closeVehicleDetailsModal(modal) {
    if (!modal.classList.contains("show")) {
        return;
    }

    modal.classList.remove("show");
    document.body.style.overflow = modal.dataset.previousBodyOverflow || "";
    delete modal.dataset.previousBodyOverflow;
    modal.currentVehicle = null;
    modal.currentRow = null;
}

function populateViewVehicleModal(modal, vehicle) {
    if (!modal || !vehicle) {
        return;
    }

    const driver = vehicle.drivers?.[0];
    const vehicleName =
        [vehicle.brand, vehicle.model].filter(Boolean).join(" ") ||
        "Not provided";
    const driverName = driver
        ? [driver.first_name, driver.last_name].filter(Boolean).join(" ")
        : "Not Assigned";
    const fuelType = vehicle.fuel_type || "Not provided";
    const tankCapacity = vehicle.tank_capacity;
    const currentFuel = vehicle.current_fuel;
    const currentMileage = vehicle.current_odometer;

    setViewVehicleText(modal, "viewVehicleName", vehicleName);
    setViewVehicleText(
        modal,
        "viewVehicleSubtitle",
        vehicle.vehicle_type === "Motorcycle"
            ? "Hospital Courier / Light Logistics"
            : "Hospital Fleet Vehicle",
        "",
    );

    setViewVehicleText(modal, "viewPlateNumber", vehicle.plate_number);
    setViewVehicleText(modal, "viewVehicleType", vehicle.vehicle_type);
    setViewVehicleText(
        modal,
        "viewPowertrainType",
        vehicle.powertrain_label || "Not specified",
    );
    setViewVehicleText(
        modal,
        "viewVehicleDepartment",
        vehicle.department,
        "Unassigned",
    );
    setViewVehicleText(modal, "viewDriver", driverName, "Not Assigned");
    setViewVehicleText(
        modal,
        "viewVehicleCapabilities",
        (vehicle.capabilities || []).map((capability) => capability.name).join(", "),
        "No capabilities recorded",
    );
    setViewVehicleText(
        modal,
        "viewLtoVehicleCategory",
        vehicle.lto_vehicle_category,
        "Not Recorded",
    );
    setViewVehicleText(
        modal,
        "viewRequiredDlCode",
        vehicle.required_dl_code,
        "Not Determined - Vehicle Category Not Recorded",
    );
    setViewVehicleText(
        modal,
        "viewVehicleSanitation",
        (vehicle.sanitation_logs || []).length
            ? vehicle.sanitation_logs
                  .map(
                      (record) =>
                          `${record.cleaning_type_label || record.cleaning_type}: ${record.status_label || record.status}`,
                  )
                  .join(" · ")
            : "No sanitation records",
    );
    const trafficViolationContainer = modal.querySelector("#viewVehicleTrafficViolations");
    if (trafficViolationContainer) {
        const violations = vehicle.traffic_violations || [];
        if (!violations.length) {
            trafficViolationContainer.textContent = "No traffic violation records";
        } else {
            trafficViolationContainer.replaceChildren(...violations.map((violation) => {
                const item = document.createElement("p");
                const driverName = violation.driver
                    ? [violation.driver.first_name, violation.driver.last_name].filter(Boolean).join(" ")
                    : "No driver identified";
                item.textContent = `${violation.violation_date || "Date not recorded"} · ${violation.violation_type_label || violation.violation_type} · ${driverName} · ${violation.responsibility_label || violation.responsibility} · ${violation.status_label || violation.status}`;
                return item;
            }));
        }
    }
    setViewVehicleText(modal, "viewFuelType", fuelType);
    setViewVehicleText(
        modal,
        "viewTankCapacity",
        formatVehicleTankCapacity(tankCapacity),
        "Not available",
    );
    setViewVehicleText(
        modal,
        "viewCurrentFuel",
        formatVehicleFuelAmount(currentFuel),
        "Not available",
    );
    setViewVehicleText(
        modal,
        "viewFuelLevel",
        formatVehicleFuelLevel(currentFuel, tankCapacity),
        "Not available",
    );
    setViewVehicleText(
        modal,
        "viewMileage",
        formatVehicleMileage(currentMileage),
        "Not available",
    );
    setViewVehicleText(
        modal,
        "viewBatteryCapacity",
        formatVehicleEnergyValue(vehicle.battery_capacity_kwh, "kWh"),
    );
    setViewVehicleText(
        modal,
        "viewBatteryLevel",
        formatVehicleEnergyValue(vehicle.battery_level_percent, "%"),
    );
    setViewVehicleText(
        modal,
        "viewEstimatedRange",
        formatVehicleEnergyValue(vehicle.estimated_range_km, "km"),
    );
    setViewVehicleText(modal, "viewChargingPort", vehicle.charging_port_type);
    const operationalHealth = vehicle.operational_health || {};
    const batteryHealth = operationalHealth.battery_health || {};
    setViewVehicleText(
        modal,
        "viewBatteryHealth",
        batteryHealth.value == null ? null : `${batteryHealth.value}%`,
        batteryHealth.applicable === false ? "Not Applicable" : "Not Recorded",
    );
    setViewVehicleText(
        modal,
        "viewBatteryHealthUpdated",
        formatVehicleViewDate(batteryHealth.updated_at),
        "Not Recorded",
    );
    setViewVehicleText(modal, "viewBatteryHealthStatus", batteryHealth.label, "Not Recorded");
    setViewVehicleText(
        modal,
        "viewBatteryInspectionDate",
        formatVehicleViewDate(vehicle.battery_last_inspection_date),
        "Not Recorded",
    );
    setViewVehicleText(modal, "viewOperationalHealth", operationalHealth.label, "Not Recorded");
    setViewVehicleText(
        modal,
        "viewMaintenanceAdvisory",
        operationalHealth.maintenance?.label,
        "Not Enough Data",
    );
    setViewVehicleText(
        modal,
        "viewRegistrationCompliance",
        operationalHealth.registration?.label,
        "Not Recorded",
    );
    setViewVehicleText(
        modal,
        "viewInsuranceCompliance",
        operationalHealth.insurance?.label,
        "Not Recorded",
    );
    setViewVehicleText(
        modal,
        "viewOperationalHealthReasons",
        (operationalHealth.reasons || []).join(" · "),
        "No operational warnings identified.",
    );

    modal.querySelectorAll("[data-view-energy-field]").forEach((item) => {
        const type = item.dataset.viewEnergyField;
        const isLegacyLiquidFuelVehicle =
            !vehicle.powertrain_type && vehicle.fuel_type !== "Electric";
        const visible =
            type === "fuel"
                ? isLegacyLiquidFuelVehicle ||
                  window.VehicleEnergyFields?.supportsFuel(
                      vehicle.powertrain_type,
                  )
                : type === "battery"
                  ? window.VehicleEnergyFields?.supportsBattery(vehicle.powertrain_type)
                  : window.VehicleEnergyFields?.supportsCharging(
                        vehicle.powertrain_type,
                    );

        item.hidden = visible === false;
    });
    setViewVehicleText(
        modal,
        "viewPurchaseDate",
        formatVehicleViewDate(vehicle.purchase_date),
    );
    setViewVehicleText(
        modal,
        "viewInsuranceExpiry",
        formatVehicleViewDate(vehicle.insurance_expiry),
    );
    const isMotorcycle = vehicle.vehicle_type === "Motorcycle";
    modal.querySelectorAll("[data-view-motorcycle-field]").forEach((item) => {
        item.hidden =
            !isMotorcycle ||
            (item.hasAttribute("data-view-motorcycle-engine-field") &&
                vehicle.powertrain_type === "electric");
    });
    setViewVehicleText(
        modal,
        "viewEngineDisplacement",
        vehicle.engine_displacement_cc == null
            ? null
            : `${vehicle.engine_displacement_cc} cc`,
        "Not recorded",
    );
    setViewVehicleText(
        modal,
        "viewTransmissionType",
        vehicle.transmission_type
            ? String(vehicle.transmission_type).replaceAll("_", " ")
            : null,
        "Not recorded",
    );
    setViewVehicleText(
        modal,
        "viewHelmetAvailable",
        vehicle.helmet_available == null
            ? null
            : vehicle.helmet_available
              ? "Yes"
              : "No",
        "Not recorded",
    );
    setViewVehicleText(
        modal,
        "viewCargoBoxAvailable",
        vehicle.cargo_box_available == null
            ? null
            : vehicle.cargo_box_available
              ? "Yes"
              : "No",
        "Not recorded",
    );
    setViewVehicleText(
        modal,
        "viewCargoCapacity",
        vehicle.cargo_capacity_kg == null ? null : `${vehicle.cargo_capacity_kg} kg`,
        "Not recorded",
    );
    setViewVehicleText(
        modal,
        "viewRegistrationExpiry",
        formatVehicleViewDate(vehicle.registration_expiry),
        "Not recorded",
    );
    setViewVehicleText(
        modal,
        "viewVehicleNotes",
        vehicle.notes,
        "No additional information",
    );

    const status = vehicle.status || "Not provided";
    const statusClass =
        typeof getVehicleStatusClass === "function"
            ? getVehicleStatusClass(status)
            : "out";
    const statusBadge = modal.querySelector("#viewVehicleStatus");
    const summaryStatus = modal.querySelector("#viewVehicleStatusSummary");
    const readinessStatus = modal.querySelector("#viewVehicleReadiness");

    if (statusBadge) {
        statusBadge.className = `status-badge ${statusClass}`;

        statusBadge.textContent = status;
    }

    if (summaryStatus) {
        summaryStatus.className = `status-badge ${statusClass}`;

        summaryStatus.textContent = status;
    }

    if (readinessStatus) {
        const readinessLabel = vehicle.readiness_label || "Not Specified";
        readinessStatus.className = `status-badge ${
            typeof getVehicleStatusClass === "function"
                ? getVehicleStatusClass(readinessLabel)
                : "out"
        }`;
        readinessStatus.textContent = readinessLabel;
    }
}


function initViewVehicleModal() {
    const modal = document.getElementById("viewVehicleModal");
    const closeButton = document.getElementById("closeViewVehicleModal");
    const footerCloseButton = document.getElementById("closeViewBtn");
    const editFromViewButton = document.getElementById("editVehicleBtn");

    if (!modal || modal.dataset.viewVehicleModalInitialized === "true") {
        return;
    }

    modal.dataset.viewVehicleModalInitialized = "true";


    document.addEventListener("click", async (event) => {
        const viewButton = event.target.closest(".action-btn.view");

        if (!viewButton) {
            return;
        }

        const vehicleId = viewButton.dataset.id;

        if (!vehicleId) {
            window.showToast("Vehicle ID not found.", "error");

            return;
        }

        try {
            const response = await fetch(`/fleet/${vehicleId}`, {
                headers: {
                    Accept: "application/json",
                },
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(
                    data.message || "Failed to load vehicle details.",
                );
            }

            if (!data.vehicle) {
                throw new Error("Vehicle record not found.");
            }

            modal.currentVehicle = data.vehicle;
            modal.currentVehicle.operational_health = data.operational_health || null;

            modal.availableDrivers = Array.isArray(data.drivers)
                ? data.drivers
                : [];

            const row = viewButton.closest("tr");

            modal.currentRow = row || null;

            populateViewVehicleModal(modal, data.vehicle);

            openVehicleDetailsModal(modal);
        } catch (error) {
            console.error("VIEW VEHICLE ERROR:", error);

            window.showToast(
                error.message || "Unable to load vehicle details.",
                "error",
            );
        }
    });


    closeButton?.addEventListener("click", () =>
        closeVehicleDetailsModal(modal),
    );

    footerCloseButton?.addEventListener("click", () =>
        closeVehicleDetailsModal(modal),
    );

    modal.addEventListener("click", (event) => {
        if (event.target === modal) {
            closeVehicleDetailsModal(modal);
        }
    });

    editFromViewButton?.addEventListener("click", () => {
        const vehicle = modal.currentVehicle;

        const editModal = document.getElementById("editVehicleModal");

        if (!vehicle || !editModal) {
            return;
        }

        /*
            |--------------------------------------------------------------------------
            | No second fetch needed.
            | We already have the complete vehicle data.
            |--------------------------------------------------------------------------
            */
        populateEditVehicleModal(vehicle);

        /*
            |--------------------------------------------------------------------------
            | VehicleController@show already provides available drivers.
            | Store them when View opened.
            |--------------------------------------------------------------------------
            */
        if (
            Array.isArray(modal.availableDrivers) &&
            typeof populateEditDriverDropdown === "function"
        ) {
            populateEditDriverDropdown(modal.availableDrivers, vehicle);
        }

        closeVehicleDetailsModal(modal);

        openEditVehicleModal(editModal);
    });


    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && modal.classList.contains("show")) {
            closeVehicleDetailsModal(modal);
        }
    });
}

document.addEventListener("DOMContentLoaded", () => {
    initViewVehicleModal();
});
