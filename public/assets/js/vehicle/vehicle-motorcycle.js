/* ==========================================
   Motorcycle Vehicle Fields
========================================== */

(function () {
    function apply(form, vehicleTypeSelect, powertrainSelect) {
        if (!form || !vehicleTypeSelect || !powertrainSelect) return;

        const isMotorcycle = vehicleTypeSelect.value === "Motorcycle";
        const isElectric = powertrainSelect.value === "electric";

        form.querySelectorAll("[data-motorcycle-field]").forEach((wrapper) => {
            const visible =
                isMotorcycle &&
                !(wrapper.hasAttribute("data-motorcycle-engine-field") && isElectric);

            wrapper.hidden = !visible;
            wrapper.querySelectorAll("input, select, textarea").forEach((field) => {
                field.disabled = !visible;
            });
        });

        Array.from(powertrainSelect.options).forEach((option) => {
            if (!option.value) return;
            option.disabled =
                isMotorcycle && !["gasoline", "electric"].includes(option.value);
        });

        if (
            isMotorcycle &&
            !["gasoline", "electric"].includes(powertrainSelect.value)
        ) {
            powertrainSelect.value = "";
            window.VehicleEnergyFields?.apply(form, powertrainSelect);
        }
    }

    function init(formId, vehicleTypeId, powertrainId) {
        const form = document.getElementById(formId);
        const vehicleTypeSelect = document.getElementById(vehicleTypeId);
        const powertrainSelect = document.getElementById(powertrainId);
        if (!form || !vehicleTypeSelect || !powertrainSelect) return;

        const refresh = () => apply(form, vehicleTypeSelect, powertrainSelect);
        if (form.dataset.motorcycleFieldsInitialized !== "true") {
            vehicleTypeSelect.addEventListener("change", refresh);
            powertrainSelect.addEventListener("change", refresh);
            form.dataset.motorcycleFieldsInitialized = "true";
        }
        refresh();
    }

    window.VehicleMotorcycleFields = { apply, init };

    document.addEventListener("DOMContentLoaded", () => {
        init("vehicleForm", "vehicleType", "vehiclePowertrain");
        init("editVehicleForm", "editVehicleType", "editVehiclePowertrain");
    });
})();
