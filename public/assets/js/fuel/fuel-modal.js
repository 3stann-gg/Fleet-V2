/* ==========================================
   Fuel Modal Helpers + Validation + Cost Calc
========================================== */

let fuelModalInitialized = false;

function formatFuelCurrency(value) {
    const num = Number.parseFloat(value);

    if (Number.isNaN(num)) {
        return "₱0.00";
    }

    return (
        "₱" +
        num.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })
    );
}

function formatFuelQuantity(value) {
    const num = Number.parseFloat(value);

    if (Number.isNaN(num)) {
        return "0.00 L";
    }

    return (
        num.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }) + " L"
    );
}

function formatFuelOdometer(value) {
    const num = Number.parseFloat(value);

    if (Number.isNaN(num)) {
        return "0 km";
    }

    return (
        Math.round(num).toLocaleString(undefined, {
            maximumFractionDigits: 0,
        }) + " km"
    );
}

function formatFuelDisplayDate(raw) {
    if (!raw) {
        return "";
    }

    const date = new Date(raw);

    if (Number.isNaN(date.getTime())) {
        return "";
    }

    return date.toLocaleDateString(undefined, {
        year: "numeric",
        month: "short",
        day: "numeric",
    });
}


function normalizeFuelTotal(quantity, costPerLiter) {
    const q = Number.parseFloat(quantity);
    const c = Number.parseFloat(costPerLiter);

    if (Number.isNaN(q) || Number.isNaN(c) || q < 0 || c < 0) {
        return "";
    }

    return (q * c).toFixed(2);
}

function syncFuelTotalCostFields(prefix) {
    const quantity = document.getElementById(prefix + "Quantity");

    const costPerLiter = document.getElementById(prefix + "CostPerLiter");

    const totalCost = document.getElementById(prefix + "TotalCost");

    if (!totalCost) {
        return;
    }

    totalCost.value = normalizeFuelTotal(quantity?.value, costPerLiter?.value);
}

function bindFuelTotalCostCalculation(prefix) {
    const quantity = document.getElementById(prefix + "Quantity");

    const costPerLiter = document.getElementById(prefix + "CostPerLiter");

    const sync = () => syncFuelTotalCostFields(prefix);

    quantity?.addEventListener("input", sync);
    quantity?.addEventListener("change", sync);

    costPerLiter?.addEventListener("input", sync);

    costPerLiter?.addEventListener("change", sync);
}


function showFuelFieldError(field, message) {
    if (!field) {
        return;
    }

    field.classList.add("is-invalid");

    let errorEl = field.parentElement
        ? field.parentElement.querySelector(
              ".field-error[data-field='" + field.id + "']",
          )
        : null;

    if (!errorEl) {
        errorEl = document.createElement("div");

        errorEl.className = "field-error";

        errorEl.setAttribute("data-field", field.id);

        const parent = field.parentElement || field;

        parent.appendChild(errorEl);
    }

    errorEl.textContent = message;
    errorEl.style.display = "block";
}

function clearFuelFieldError(field) {
    if (!field) {
        return;
    }

    field.classList.remove("is-invalid");

    const errorEl = field.parentElement
        ? field.parentElement.querySelector(
              ".field-error[data-field='" + field.id + "']",
          )
        : null;

    if (errorEl) {
        errorEl.textContent = "";
        errorEl.style.display = "none";
    }
}

function clearAllFuelErrors(form) {
    if (!form) {
        return;
    }

    form.querySelectorAll(".is-invalid").forEach((field) => {
        clearFuelFieldError(field);
    });
}

function getFuelModalRole() {
    return window.FleetRBAC?.getRole?.() || "";
}
function isFuelDriverRole() {
    return getFuelModalRole() === "driver";
}

function validateFuelForm(form) {
    if (!form) {
        return false;
    }

    clearAllFuelErrors(form);

    let firstInvalidField = null;

    function fail(field, message) {
        showFuelFieldError(field, message);

        if (!firstInvalidField && field) {
            firstInvalidField = field;
        }
    }

    function trackCorrection(field) {
        if (!field || field.dataset.fuelErrorClearBound === "true") {
            return;
        }

        field.dataset.fuelErrorClearBound = "true";

        field.addEventListener("input", () => clearFuelFieldError(field));

        field.addEventListener("change", () => clearFuelFieldError(field));
    }

    const isEdit = form.id === "editFuelForm";
    const isDriverAdd = !isEdit && isFuelDriverRole();
    const prefix = isEdit ? "editFuel" : "fuel";
    const fields = {
        number: form.querySelector("#" + prefix + "Number"),
        date: form.querySelector("#" + prefix + "RefuelDate"),
        vehicle: form.querySelector("#" + prefix + "Vehicle"),
        driver: form.querySelector("#" + prefix + "Driver"),
        driverId: form.querySelector("#" + prefix + "DriverId"),
        type: form.querySelector("#" + prefix + "Type"),
        quantity: form.querySelector("#" + prefix + "Quantity"),
        costPerLiter: form.querySelector("#" + prefix + "CostPerLiter"),
        totalCost: form.querySelector("#" + prefix + "TotalCost"),
        odometer: form.querySelector("#" + prefix + "Odometer"),
        station: form.querySelector("#" + prefix + "Station"),
        receipt: form.querySelector("#" + prefix + "Receipt"),
    };

    Object.values(fields).forEach(trackCorrection);

    syncFuelTotalCostFields(prefix);


    if (!fields.number || !fields.number.value.trim()) {
        fail(fields.number, "Fuel record number is required.");
    }

    if (!fields.date || !fields.date.value) {
        fail(fields.date, "Refueling date is required.");
    } else {
        const date = new Date(fields.date.value);

        if (Number.isNaN(date.getTime())) {
            fail(fields.date, "Refueling date is invalid.");
        }
    }

    if (!isDriverAdd && (!fields.vehicle || !fields.vehicle.value)) {
        fail(fields.vehicle, "Vehicle is required.");
    }

    if (!isDriverAdd) {
        if (!fields.driver || !fields.driver.value.trim()) {
            fail(fields.driver, "Assigned driver is required.");
        }
        if (!fields.driverId || !fields.driverId.value) {
            fail(
                fields.driver,
                "The selected vehicle must have an assigned driver.",
            );
        }
    }

    if (!isDriverAdd && (!fields.type || !fields.type.value)) {
        fail(fields.type, "Fuel type is required.");
    }

    const quantity = Number.parseFloat(fields.quantity?.value);

    if (
        !fields.quantity ||
        fields.quantity.value === "" ||
        Number.isNaN(quantity)
    ) {
        fail(fields.quantity, "Quantity is required.");
    } else if (quantity <= 0) {
        fail(fields.quantity, "Quantity must be greater than zero.");
    }

    const costPerLiter = Number.parseFloat(fields.costPerLiter?.value);

    if (
        !fields.costPerLiter ||
        fields.costPerLiter.value === "" ||
        Number.isNaN(costPerLiter)
    ) {
        fail(fields.costPerLiter, "Cost per liter is required.");
    } else if (costPerLiter <= 0) {
        fail(fields.costPerLiter, "Cost per liter must be greater than zero.");
    }


    const total = Number.parseFloat(fields.totalCost?.value);

    if (
        !fields.totalCost ||
        fields.totalCost.value === "" ||
        Number.isNaN(total) ||
        total < 0
    ) {
        fail(fields.totalCost, "Total cost is invalid.");
    }

   const odometerValue = fields.odometer?.value?.trim() ?? "";

   const odometer = Number.parseFloat(odometerValue);

   if (fields.odometer?.required && odometerValue === "") {
       fail(fields.odometer, "Odometer reading is required.");
   } else if (odometerValue !== "" && Number.isNaN(odometer)) {
       fail(fields.odometer, "Odometer reading is invalid.");
   } else if (odometerValue !== "" && odometer < 0) {
       fail(fields.odometer, "Odometer cannot be negative.");
   }

    const stationValue = fields.station?.value?.trim() ?? "";

    if (fields.station?.required && stationValue === "") {
        fail(fields.station, "Fuel station is required.");
    } else if (stationValue.length > 255) {
        fail(fields.station, "Fuel station name is too long.");
    }

    if (fields.receipt && fields.receipt.value.trim().length > 40) {
        fail(fields.receipt, "Receipt / reference is too long.");
    }

    if (firstInvalidField && typeof firstInvalidField.focus === "function") {
        firstInvalidField.focus();
    }

    return !firstInvalidField;
}


async function openAddFuelModal() {
    const modal = document.getElementById("addFuelModal");

    const form = document.getElementById("fuelForm");

    if (!modal || !form) {
        return;
    }

    /*
    |--------------------------------------------------------------------------
    | Reset form immediately
    |--------------------------------------------------------------------------
    */

    form.reset();

    clearAllFuelErrors(form);

    /*
    |--------------------------------------------------------------------------
    | Reset Vehicle-Dependent Fields
    |--------------------------------------------------------------------------
    */

    const vehicleSelect = document.getElementById("fuelVehicle");

    const fuelType = document.getElementById("fuelType");

    const fuelDriver = document.getElementById("fuelDriver");

    const fuelDriverId = document.getElementById("fuelDriverId");

    const fuelPlate = document.getElementById("fuelPlate");

    const currentFuel = document.getElementById("fuelCurrentFuel");

    const tankCapacity = document.getElementById("fuelTankCapacity");

    if (vehicleSelect) {
        vehicleSelect.value = "";
    }

    if (fuelType) {
        fuelType.value = "";
        fuelType.disabled = true;
    }

    if (fuelDriver) {
        fuelDriver.value = "";
    }

    if (fuelDriverId) {
        fuelDriverId.value = "";
    }

    if (fuelPlate) {
        fuelPlate.value = "";
    }

    if (currentFuel) {
        currentFuel.value = "";
    }

    if (tankCapacity) {
        tankCapacity.value = "";
    }

    /*
    |--------------------------------------------------------------------------
    | Default Date
    |--------------------------------------------------------------------------
    */

    const dateField = document.getElementById("fuelRefuelDate");

    if (dateField) {
        dateField.value = new Date().toISOString().slice(0, 10);
    }

    /*
    |--------------------------------------------------------------------------
    | Clear calculated total
    |--------------------------------------------------------------------------
    */

    syncFuelTotalCostFields("fuel");

    const highCostWarning = document.getElementById("fuelHighCostWarning");

    if (highCostWarning) {
        highCostWarning.hidden = true;
        highCostWarning.textContent = "";
    }

    /*
    |--------------------------------------------------------------------------
    | OPEN MODAL IMMEDIATELY
    |--------------------------------------------------------------------------
    */

    modal.classList.add("show");

    document.body.style.overflow = "hidden";

    requestAnimationFrame(() => {
        if (isFuelDriverRole()) {
            document.getElementById("fuelQuantity")?.focus();
            return;
        }
        document.getElementById("fuelVehicle")?.focus();
    });

    /*
    |--------------------------------------------------------------------------
    | Load data in background AFTER modal is already visible
    |--------------------------------------------------------------------------
    */

    if (!isFuelDriverRole() && typeof loadFuelVehicles === "function") {
        loadFuelVehicles().catch((error) => {
            console.error("Fuel vehicle loading failed:", error);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Generate fuel number in background
    |--------------------------------------------------------------------------
    */

    const numberField = document.getElementById("fuelNumber");

    if (numberField) {
        numberField.value = "Generating...";

        generateFuelRecordNumber()
            .then((fuelNumber) => {
                /*
                    |--------------------------------------------------------------------------
                    | Only update if modal is still open.
                    |--------------------------------------------------------------------------
                    */

                if (modal.classList.contains("show")) {
                    numberField.value = fuelNumber || "Unable to generate";
                }
            })
            .catch((error) => {
                console.error("Fuel number generation failed:", error);

                if (modal.classList.contains("show")) {
                    numberField.value = "Unable to generate";
                }
            });
    }
}

function closeAddFuelModal() {
    const modal = document.getElementById("addFuelModal");

    if (!modal) {
        return;
    }

    modal.classList.remove("show");

    document.body.style.overflow = "";
}

function initFuelModal() {
    if (fuelModalInitialized) {
        return;
    }

    const modal = document.getElementById("addFuelModal");

    const openBtn = document.getElementById("addFuelBtn");

    if (!modal || !openBtn) {
        return;
    }

    fuelModalInitialized = true;

    openBtn.addEventListener("click", openAddFuelModal);

    document
        .getElementById("closeAddFuelModal")
        ?.addEventListener("click", closeAddFuelModal);
    document
        .getElementById("cancelAddFuel")
        ?.addEventListener("click", closeAddFuelModal);
    modal.addEventListener("click", (event) => {
        if (event.target === modal) {
            closeAddFuelModal();
        }
    });
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && modal.classList.contains("show")) {
            closeAddFuelModal();
        }
    });

    bindFuelTotalCostCalculation("fuel");
}

document.addEventListener("DOMContentLoaded", () => {
    initFuelModal();
});
