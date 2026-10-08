/* ==========================================
   Vehicle Power / Energy Fields
========================================== */

(function () {
    const fuelPowertrains = new Set([
        "gasoline",
        "diesel",
        "hybrid",
        "plug_in_hybrid",
    ]);
    const batteryPowertrains = new Set([
        "hybrid",
        "plug_in_hybrid",
        "electric",
    ]);
    const externallyChargedPowertrains = new Set([
        "plug_in_hybrid",
        "electric",
    ]);

    function isVisible(fieldType, powertrainType) {
        if (fieldType === "fuel") {
            return fuelPowertrains.has(powertrainType);
        }

        if (fieldType === "battery") {
            return batteryPowertrains.has(powertrainType);
        }

        return externallyChargedPowertrains.has(powertrainType);
    }

    function apply(form, powertrainSelect) {
        if (!form || !powertrainSelect) {
            return;
        }

        const powertrainType = powertrainSelect.value;

        form.querySelectorAll("[data-powertrain-field]").forEach((wrapper) => {
            const visible = isVisible(
                wrapper.dataset.powertrainField,
                powertrainType,
            );

            wrapper.hidden = !visible;
            wrapper.querySelectorAll("input, select, textarea").forEach((field) => {
                field.disabled = !visible;

                if (field.dataset.energyRequired === "true") {
                    field.required = visible;
                }
            });
        });
    }

    function init(formId, powertrainSelectId) {
        const form = document.getElementById(formId);
        const powertrainSelect = document.getElementById(powertrainSelectId);

        if (!form || !powertrainSelect) {
            return;
        }

        if (powertrainSelect.dataset.energyVisibilityInitialized !== "true") {
            powertrainSelect.addEventListener("change", () => {
                apply(form, powertrainSelect);
            });
            powertrainSelect.dataset.energyVisibilityInitialized = "true";
        }

        apply(form, powertrainSelect);
    }

    window.VehicleEnergyFields = {
        apply,
        init,
        supportsFuel: (powertrainType) => fuelPowertrains.has(powertrainType),
        supportsBattery: (powertrainType) =>
            batteryPowertrains.has(powertrainType),
        supportsCharging: (powertrainType) =>
            externallyChargedPowertrains.has(powertrainType),
    };
})();
