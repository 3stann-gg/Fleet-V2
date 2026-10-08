/* ==========================================
   Edit Vehicle Modal
========================================== */

/* ==========================================
   Vehicle Module Settings
========================================== */
async function getEditVehicleSettings() {
    if (
        typeof window.getVehicleModuleSettings ===
        "function"
    ) {
        return await window.getVehicleModuleSettings();
    }
    try {
        const response =
            await fetch(
                "/settings/data",
                {
                    headers: {
                        Accept:
                            "application/json",
                    },
                    credentials:
                        "same-origin",
                }
            );

        if (!response.ok) {
            throw new Error();
        }
        const data =
            await response.json();
        return {
            requirePlateNumber:
                data?.settings?.vehicles?.requirePlateNumber !== false,
            requireDepartment:
                data?.settings?.vehicles?.requireDepartment === true,
        };
    } catch {
        return {
            requirePlateNumber: true,
            requireDepartment: false,
        };
    }
}

function applyEditVehicleSettings(settings) {
    const plate = document.getElementById("editVehiclePlate");
    const plateMark = document.getElementById("editVehiclePlateRequiredMark");
    const department = document.getElementById("editVehicleDepartment");
    const departmentMark = document.getElementById(
        "editVehicleDepartmentRequiredMark",
    );
    const requirePlate = settings?.requirePlateNumber !== false;
    const requireDepartment = settings?.requireDepartment === true;
    if (plate) {
        plate.required = requirePlate;
    }
    if (plateMark) {
        plateMark.hidden = !requirePlate;
    }
    if (department) {
        department.required = requireDepartment;
    }
    if (departmentMark) {
        departmentMark.hidden = !requireDepartment;
    }
}

//    RBAC
function getVehicleEditRole() {
    return window.FleetRBAC?.getRole?.() || "";
}
function canEditVehicle() {
    return window.FleetRBAC?.hasPermission?.("vehicles", "canUpdate") === true;
}
function setEditVehicleFieldAccess(fieldId, visible) {
    const field = document.getElementById(fieldId);
    if (!field) {
        return;
    }
    const wrapper = field.closest(".form-group");
    if (wrapper) {
        wrapper.hidden = !visible;
    }
    /*
     * Disabled fields:
     * - are not validated by the browser
     * - cannot be edited
     */
    field.disabled = !visible;
}
function applyVehicleEditRbac() {
    const role = getVehicleEditRole();
    const managerFields = [
        "editVehiclePlate",
        "editVehicleType",
        "editVehicleDepartment",
        "editVehicleCapacity",
        "editVehicleDriver",
        "editVehiclePowertrain",
        "editVehicleFuel",
        "editVehicleTankCapacity",
        "editVehicleBatteryCapacity",
        "editVehicleBatteryLevel",
        "editVehicleEstimatedRange",
        "editVehicleChargingPort",
        "editVehicleBatteryHealth",
        "editVehicleBatteryInspectionDate",
        "editVehicleMileage",
        "editVehicleStatus",
        "editVehicleReadiness",
        "editVehicleNotes",
    ];
    /*
     * Always reset first because the same modal
     * can be opened multiple times.
     */
    managerFields.forEach((id) => {
        setEditVehicleFieldAccess(id, role === "fleet_manager");
    });
    /*
     * Limited Vehicle editors
     */
    if (role === "dispatcher" || role === "maintenance") {
        setEditVehicleFieldAccess("editVehicleStatus", true);
        setEditVehicleFieldAccess("editVehicleReadiness", true);
        setEditVehicleFieldAccess("editVehicleNotes", true);
        if (role === "maintenance") {
            setEditVehicleFieldAccess("editVehicleBatteryHealth", true);
            setEditVehicleFieldAccess("editVehicleBatteryInspectionDate", true);
        }
    }
}
function applyVehicleEditStatusOptions(currentStatus) {
    const select = document.getElementById("editVehicleStatus");
    if (!select) {
        return;
    }
    const role = getVehicleEditRole();
    let allowedStatuses = [];
    if (role === "fleet_manager") {
        allowedStatuses = [
            "Available",
            "On Trip",
            "Maintenance",
            "Out of Service",
        ];
    } else if (role === "dispatcher") {
        allowedStatuses = ["Available", "Out of Service"];
    } else if (role === "maintenance") {
        allowedStatuses = ["Available", "Maintenance", "Out of Service"];
    }
    /*
     * Preserve current status for display.
     */
    if (currentStatus && !allowedStatuses.includes(currentStatus)) {
        allowedStatuses.unshift(currentStatus);
    }
    select.innerHTML = allowedStatuses
        .map(
            (status) => `
                <option
                    value="${status}"
                    ${status === currentStatus ? "selected" : ""}
                >
                    ${status}
                </option>
            `,
        )
        .join("");
    /*
     * On Trip is lifecycle-controlled.
     * Limited users can see it but not
     * manually change it.
     */
    if (role !== "fleet_manager" && currentStatus === "On Trip") {
        select.disabled = true;
    }
}


function populateEditDriverDropdown(drivers, vehicle) {
    const select = document.getElementById("editVehicleDriver");

    if (!select) {
        return;
    }

    select.innerHTML = `
        <option value="">Select Driver</option>
    `;

    (drivers || []).forEach((driver) => {
        const option = document.createElement("option");
        option.value = driver.id;
        option.textContent = `${driver.first_name} ${driver.last_name}`;
        if (
            vehicle?.drivers?.length &&
            String(vehicle.drivers[0].id) === String(driver.id)
        ) {
            option.selected = true;
        }
        select.appendChild(option);
    });
}


function setEditVehicleFieldValue(id, value) {
    const field = document.getElementById(id);
    if (field) {
        field.value = value ?? "";
    }
}

function setEditVehicleSelectValue(id, value) {
    const select = document.getElementById(id);
    if (!select) {
        return;
    }
    const normalizedValue = value == null ? "" : String(value);
    if (
        normalizedValue &&
        !Array.from(select.options).some(
            (option) => option.value === normalizedValue,
        )
    ) {
        const option = document.createElement("option");
        option.value = normalizedValue;
        option.textContent = normalizedValue;
        select.appendChild(option);
    }
    select.value = normalizedValue;
}


function openEditVehicleModal(modal) {
    if (!modal) {
        return;
    }
    if (!modal.classList.contains("show")) {
        modal.dataset.previousBodyOverflow = document.body.style.overflow;
    }
    modal.classList.add("show");
    document.body.style.overflow = "hidden";
}

function closeEditVehicleModal(modal) {
    if (!modal || !modal.classList.contains("show")) {
        return;
    }
    modal.classList.remove("show");
    document.body.style.overflow = modal.dataset.previousBodyOverflow || "";
    delete modal.dataset.previousBodyOverflow;
    modal.currentRow = null;
}


function populateEditVehicleModal(vehicle) {
    if (!vehicle) {
        return;
    }

    setEditVehicleFieldValue("editVehicleId", vehicle.id);
    setEditVehicleFieldValue("editVehiclePlate", vehicle.plate_number);
    setEditVehicleSelectValue("editVehicleType", vehicle.vehicle_type);
    setEditVehicleSelectValue("editVehicleDepartment", vehicle.department);
    setEditVehicleFieldValue("editVehicleCapacity", vehicle.capacity);
    setEditVehicleSelectValue(
        "editVehiclePowertrain",
        vehicle.powertrain_type,
    );
    setEditVehicleSelectValue("editVehicleFuel", vehicle.fuel_type);
    setEditVehicleFieldValue("editVehicleTankCapacity", vehicle.tank_capacity);
    setEditVehicleFieldValue(
        "editVehicleBatteryCapacity",
        vehicle.battery_capacity_kwh,
    );
    setEditVehicleFieldValue(
        "editVehicleBatteryLevel",
        vehicle.battery_level_percent,
    );
    setEditVehicleFieldValue(
        "editVehicleEstimatedRange",
        vehicle.estimated_range_km,
    );
    setEditVehicleFieldValue(
        "editVehicleChargingPort",
        vehicle.charging_port_type,
    );
    setEditVehicleFieldValue(
        "editVehicleBatteryHealth",
        vehicle.battery_health_percent,
    );
    setEditVehicleFieldValue(
        "editVehicleBatteryInspectionDate",
        String(vehicle.battery_last_inspection_date || "").slice(0, 10),
    );
    /*
    |--------------------------------------------------------------------------
    | Current Fuel is controlled by Fuel Management.
    |--------------------------------------------------------------------------
    */
    setEditVehicleFieldValue("editVehicleCurrentFuel", vehicle.current_fuel);
    /*
    |--------------------------------------------------------------------------
    | Current Mileage is controlled by Fuel Management.
    |--------------------------------------------------------------------------
    */
    setEditVehicleFieldValue("editVehicleMileage", vehicle.current_odometer);
    setEditVehicleSelectValue("editVehicleStatus", vehicle.status);
    setEditVehicleSelectValue(
        "editVehicleReadiness",
        vehicle.readiness_status || "",
    );
    setEditVehicleFieldValue("editVehicleNotes", vehicle.notes);
    setEditVehicleFieldValue(
        "editVehicleEngineDisplacement",
        vehicle.engine_displacement_cc,
    );
    setEditVehicleSelectValue(
        "editVehicleTransmissionType",
        vehicle.transmission_type || "",
    );
    setEditVehicleSelectValue(
        "editVehicleHelmetAvailable",
        vehicle.helmet_available == null ? "" : vehicle.helmet_available ? "1" : "0",
    );
    setEditVehicleSelectValue(
        "editVehicleCargoBoxAvailable",
        vehicle.cargo_box_available == null
            ? ""
            : vehicle.cargo_box_available
              ? "1"
              : "0",
    );
    setEditVehicleFieldValue("editVehicleCargoCapacity", vehicle.cargo_capacity_kg);
    setEditVehicleFieldValue(
        "editVehicleRegistrationExpiry",
        String(vehicle.registration_expiry || "").slice(0, 10),
    );
    void window.VehicleCapabilityFields?.render(
        "editVehicleCapabilities",
        (vehicle.capabilities || []).map((capability) => capability.id),
    );

    window.VehicleEnergyFields?.init(
        "editVehicleForm",
        "editVehiclePowertrain",
    );
    window.VehicleMotorcycleFields?.apply(
        document.getElementById("editVehicleForm"),
        document.getElementById("editVehicleType"),
        document.getElementById("editVehiclePowertrain"),
    );
}

function updateVehicleActionLabels(row, name) {
    if (!row) {
        return;
    }

    const checkbox = row.querySelector(".vehicle-checkbox");
    const viewButton = row.querySelector(".action-btn.view");
    const editButton = row.querySelector(".action-btn.edit");
    const deleteButton = row.querySelector(".action-btn.delete");

    checkbox?.setAttribute("aria-label", `Select ${name}`);
    viewButton?.setAttribute("aria-label", `View ${name}`);
    editButton?.setAttribute("aria-label", `Edit ${name}`);
    deleteButton?.setAttribute("aria-label", `Delete ${name}`);
}


function refreshVehicleAfterEdit() {
    if (typeof updateVehicleStats === "function") {
        updateVehicleStats();
    }

    if (typeof applyVehicleFilters === "function") {
        applyVehicleFilters();
    } else if (typeof refreshVehiclePagination === "function") {
        refreshVehiclePagination();
    }

    if (typeof refreshVehicleBulkState === "function") {
        refreshVehicleBulkState();
    }
}


function validateEditVehicleFuelFields() {
    const tankCapacity = document.getElementById("editVehicleTankCapacity");

    const currentFuel = document.getElementById("editVehicleCurrentFuel");

    if (!tankCapacity) {
        return true;
    }

    const tankValue = Number(tankCapacity.value);

    const currentFuelValue = Number(currentFuel?.value || 0);

    if (Number.isNaN(tankValue) || tankValue <= 0) {
        tankCapacity.setCustomValidity(
            "Tank capacity must be greater than zero.",
        );

        tankCapacity.reportValidity();

        return false;
    }

    if (!Number.isNaN(currentFuelValue) && currentFuelValue > tankValue) {
        tankCapacity.setCustomValidity(
            "Tank capacity cannot be lower than the current fuel level.",
        );

        tankCapacity.reportValidity();

        return false;
    }

    tankCapacity.setCustomValidity("");

    return true;
}

async function initEditVehicleModal() {
    if (!canEditVehicle()) {
        return;
    }
    const modal = document.getElementById("editVehicleModal");
    const form = document.getElementById("editVehicleForm");
    const closeButton = document.getElementById("closeEditVehicleModal");
    const cancelButton = document.getElementById("cancelEditVehicle");

    if (
        !modal ||
        !form ||
        modal.dataset.editVehicleModalInitialized === "true"
    ) {
        return;
    }

    const vehicleSettings = await getEditVehicleSettings();

    applyEditVehicleSettings(vehicleSettings);

    modal.dataset.editVehicleModalInitialized = "true";

    document.addEventListener("click", async (event) => {
        const button = event.target.closest(".action-btn.edit");

        if (!button) {
            return;
        }

        const vehicleId = button.dataset.id;

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
                throw new Error(data.message || "Failed to load vehicle.");
            }

            console.log("EDIT VEHICLE RESPONSE:", data);
            const row = button.closest("tr");
            modal.currentRow = row || null;

            populateEditVehicleModal(data.vehicle);
            populateEditDriverDropdown(data.drivers || [], data.vehicle);
            applyVehicleEditRbac();
            applyVehicleEditStatusOptions(data.vehicle.status);
            openEditVehicleModal(modal);
        } catch (error) {
            console.error("EDIT VEHICLE LOAD ERROR:", error);

            window.showToast(
                error.message || "Unable to load vehicle information.",
                "error",
            );
        }
    });


    closeButton?.addEventListener("click", () => closeEditVehicleModal(modal));
    cancelButton?.addEventListener("click", () => closeEditVehicleModal(modal));

    modal.addEventListener("click", (event) => {
        if (event.target === modal) {
            closeEditVehicleModal(modal);
        }
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && modal.classList.contains("show")) {
            closeEditVehicleModal(modal);
        }
    });

    document
        .getElementById("editVehicleTankCapacity")
        ?.addEventListener("input", () => {
            validateEditVehicleFuelFields();
        });

    form.addEventListener("submit", async (event) => {
        event.preventDefault();

        const vehicleId = document.getElementById("editVehicleId")?.value;

        if (!vehicleId) {
            window.showToast("Vehicle ID not found.", "error");

            return;
        }
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        if (
            getVehicleEditRole() === "fleet_manager" &&
            window.VehicleEnergyFields?.supportsFuel(
                document.getElementById("editVehiclePowertrain")?.value || "",
            ) &&
            !validateEditVehicleFuelFields()
        ) {
            return;
        }

        const csrfToken = document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute("content");

        if (!csrfToken) {
            window.showToast("CSRF token not found.", "error");

            return;
        }

        /*
            |--------------------------------------------------------------------------
            | Current Fuel + Mileage are NOT submitted.
            | Fuel Management owns those values.
            |--------------------------------------------------------------------------
            */
        const role = getVehicleEditRole();
        let formData = {};
        if (role === "fleet_manager") {
            const powertrainType =
                document.getElementById("editVehiclePowertrain")?.value || "";
            formData = {
                plate_number:
                    document.getElementById("editVehiclePlate")?.value.trim() ||
                    "",
                vehicle_type:
                    document.getElementById("editVehicleType")?.value || "",
                department:
                    document.getElementById("editVehicleDepartment")?.value ||
                    null,
                capacity:
                    document.getElementById("editVehicleCapacity")?.value || "",
                powertrain_type: powertrainType,
                status:
                    document.getElementById("editVehicleStatus")?.value || "",
                readiness_status:
                    document.getElementById("editVehicleReadiness")?.value || null,
                notes:
                    document.getElementById("editVehicleNotes")?.value.trim() ||
                    null,
                assigned_driver_id:
                    document.getElementById("editVehicleDriver")?.value || null,
                capabilities:
                    window.VehicleCapabilityFields?.selected(
                        "editVehicleCapabilities",
                    ) || [],
            };

            if (formData.vehicle_type === "Motorcycle") {
                formData.engine_displacement_cc =
                    document.getElementById("editVehicleEngineDisplacement")?.value ||
                    null;
                formData.transmission_type =
                    document.getElementById("editVehicleTransmissionType")?.value ||
                    null;
                formData.helmet_available =
                    document.getElementById("editVehicleHelmetAvailable")?.value || null;
                formData.cargo_box_available =
                    document.getElementById("editVehicleCargoBoxAvailable")?.value ||
                    null;
                formData.cargo_capacity_kg =
                    document.getElementById("editVehicleCargoCapacity")?.value || null;
                formData.registration_expiry =
                    document.getElementById("editVehicleRegistrationExpiry")?.value ||
                    null;
            }

            if (window.VehicleEnergyFields?.supportsFuel(powertrainType)) {
                formData.fuel_type =
                    document.getElementById("editVehicleFuel")?.value || "";
                formData.tank_capacity =
                    document.getElementById("editVehicleTankCapacity")?.value ||
                    "";
            }

            if (window.VehicleEnergyFields?.supportsBattery(powertrainType)) {
                formData.battery_capacity_kwh =
                    document.getElementById("editVehicleBatteryCapacity")?.value ||
                    null;
                formData.battery_level_percent =
                    document.getElementById("editVehicleBatteryLevel")?.value ||
                    null;
            }

            if (window.VehicleEnergyFields?.supportsCharging(powertrainType)) {
                formData.estimated_range_km =
                    document.getElementById("editVehicleEstimatedRange")?.value ||
                    null;
                formData.charging_port_type =
                    document.getElementById("editVehicleChargingPort")?.value.trim() ||
                    null;
                formData.battery_health_percent =
                    document.getElementById("editVehicleBatteryHealth")?.value ||
                    null;
                formData.battery_last_inspection_date =
                    document.getElementById("editVehicleBatteryInspectionDate")?.value ||
                    null;
            }
        }
        if (role === "dispatcher" || role === "maintenance") {
            formData = {
                notes:
                    document.getElementById("editVehicleNotes")?.value.trim() ||
                    null,
            };
            const status = document.getElementById("editVehicleStatus");
            if (status && !status.disabled) {
                formData.status = status.value;
            }
            const readiness = document.getElementById("editVehicleReadiness");
            if (readiness && !readiness.disabled) {
                formData.readiness_status = readiness.value || null;
            }
            if (
                role === "maintenance" &&
                window.VehicleEnergyFields?.supportsCharging(powertrainType)
            ) {
                formData.battery_health_percent =
                    document.getElementById("editVehicleBatteryHealth")?.value ||
                    null;
                formData.battery_last_inspection_date =
                    document.getElementById("editVehicleBatteryInspectionDate")?.value ||
                    null;
            }
        }

        const updateButton = document.getElementById("updateVehicleBtn");

        if (updateButton) {
            updateButton.disabled = true;

            updateButton.innerHTML =
                '<i class="ph ph-spinner"></i> Updating...';
        }

        try {
            const response = await fetch(`/fleet/${vehicleId}`, {
                method: "PUT",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-CSRF-TOKEN": csrfToken,
                },

                body: JSON.stringify(formData),
            });

            const contentType = response.headers.get("content-type") || "";
            let data = {};
            if (contentType.includes("application/json")) {
                data = await response.json();
            } else {
                const text = await response.text();
                console.error("NON-JSON VEHICLE UPDATE RESPONSE:", text);
                throw new Error(`Server error (${response.status}).`);
            }

            if (!response.ok) {
                const firstError = data.errors
                    ? Object.values(data.errors).flat()[0]
                    : null;

                throw new Error(
                    firstError || data.message || "Failed to update vehicle.",
                );
            }

            closeEditVehicleModal(modal);

            if (typeof refreshVehicleAfterEdit === "function") {
                refreshVehicleAfterEdit();
            }
            if (typeof loadVehicles === "function") {
                await loadVehicles();
            }

            window.showToast(
                data.message || "Vehicle updated successfully.",
                "success",
            );
        } catch (error) {
            console.error("EDIT VEHICLE ERROR:", error);

            window.showToast(
                error.message || "Unable to update vehicle.",
                "error",
            );
        } finally {
            if (updateButton) {
                updateButton.disabled = false;

                updateButton.innerHTML =
                    '<i class="ph ph-floppy-disk"></i> Update Vehicle';
            }
        }
    });
}


document.addEventListener("DOMContentLoaded", async () => {
    await initEditVehicleModal();
});
