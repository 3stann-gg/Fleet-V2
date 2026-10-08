let reservationCapabilityCache = null;

async function loadReservationCapabilities() {
    if (reservationCapabilityCache) return reservationCapabilityCache;
    const response = await fetch("/reservation/capabilities", {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
    });
    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || "Unable to load capabilities.");
    }
    reservationCapabilityCache = data.capabilities || [];
    return reservationCapabilityCache;
}

async function renderReservationCapabilities(containerId, selectedIds = []) {
    const container = document.getElementById(containerId);
    if (!container) return;
    try {
        const capabilities = await loadReservationCapabilities();
        const selected = new Set(selectedIds.map(String));
        container.innerHTML = capabilities.length
            ? capabilities
                  .map(
                      (capability) => `
                        <label class="checklist-option">
                          <input type="checkbox" value="${capability.id}" ${selected.has(String(capability.id)) ? "checked" : ""} />
                          <span>${capability.name}</span>
                        </label>
                      `,
                  )
                  .join("")
            : '<span class="form-hint">No capabilities are configured.</span>';
    } catch {
        container.innerHTML = '<span class="form-hint">Unable to load capabilities.</span>';
    }
}

function selectedReservationCapabilities(containerId) {
    return Array.from(
        document.querySelectorAll(`#${containerId} input[type="checkbox"]:checked`),
    ).map((input) => Number(input.value));
}

window.ReservationCapabilityFields = {
    render: renderReservationCapabilities,
    selected: selectedReservationCapabilities,
};

document.addEventListener("DOMContentLoaded", () => {
    void renderReservationCapabilities("reservationRequiredCapabilities");
});
