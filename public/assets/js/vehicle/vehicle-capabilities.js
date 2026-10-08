let vehicleCapabilityCache = null;

async function loadVehicleCapabilities() {
    if (vehicleCapabilityCache) {
        return vehicleCapabilityCache;
    }

    const response = await fetch("/fleet/capabilities", {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
    });
    const data = await response.json();

    if (!response.ok) {
        throw new Error(data.message || "Unable to load vehicle capabilities.");
    }

    vehicleCapabilityCache = Array.isArray(data.capabilities)
        ? data.capabilities
        : [];

    return vehicleCapabilityCache;
}

async function renderVehicleCapabilities(containerId, selectedIds = []) {
    const container = document.getElementById(containerId);
    if (!container) return;

    try {
        const capabilities = await loadVehicleCapabilities();
        const selected = new Set(selectedIds.map(String));

        container.innerHTML = capabilities.length
            ? capabilities
                  .map(
                      (capability) => `
                        <label class="checklist-option">
                            <input
                                type="checkbox"
                                value="${capability.id}"
                                ${selected.has(String(capability.id)) ? "checked" : ""}
                            />
                            <span>${capability.name}</span>
                        </label>
                    `,
                  )
                  .join("")
            : '<span class="form-hint">No capabilities are configured.</span>';
    } catch (error) {
        container.innerHTML =
            '<span class="form-hint">Unable to load capabilities.</span>';
    }
}

function selectedVehicleCapabilities(containerId) {
    return Array.from(
        document.querySelectorAll(`#${containerId} input[type="checkbox"]:checked`),
    ).map((input) => Number(input.value));
}

window.VehicleCapabilityFields = {
    render: renderVehicleCapabilities,
    selected: selectedVehicleCapabilities,
};

document.addEventListener("DOMContentLoaded", () => {
    void renderVehicleCapabilities("vehicleCapabilities");
});
