document.addEventListener("DOMContentLoaded", () => {
    const modal = document.getElementById("vehicleSanitationModal");
    const form = document.getElementById("vehicleSanitationForm");
    const openButton = document.getElementById("createVehicleSanitationRecord");
    const closeButtons = [
        document.getElementById("closeVehicleSanitationModal"),
        document.getElementById("cancelVehicleSanitation"),
    ];

    const close = () => modal?.classList.remove("show");

    window.openVehicleSanitationModal = (vehicleId, dispatchId = "") => {
        if (!vehicleId || !modal) return;
        document.getElementById("sanitationVehicleId").value = vehicleId;
        document.getElementById("sanitationDispatchId").value = dispatchId || "";
        modal.classList.add("show");
    };

    openButton?.addEventListener("click", () => {
        const vehicleId = document.getElementById("viewVehicleModal")?.currentVehicle?.id;
        window.openVehicleSanitationModal(vehicleId);
    });

    closeButtons.forEach((button) => button?.addEventListener("click", close));

    form?.addEventListener("submit", async (event) => {
        event.preventDefault();
        const response = await fetch("/sanitation", {
            method: "POST",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute("content"),
            },
            credentials: "same-origin",
            body: JSON.stringify({
                vehicle_id: Number(
                    document.getElementById("sanitationVehicleId").value,
                ),
                dispatch_id:
                    document.getElementById("sanitationDispatchId").value || null,
                reason: document.getElementById("sanitationReason").value,
                cleaning_type: document.getElementById(
                    "sanitationCleaningType",
                ).value,
                remarks:
                    document.getElementById("sanitationRemarks").value || null,
            }),
        });
        const data = await response.json();
        if (!response.ok) {
            window.showToast?.(
                data.message || "Unable to create sanitation record.",
                "error",
            );
            return;
        }
        close();
        form.reset();
        window.showToast?.(data.message, "success");
        if (typeof loadVehicles === "function") {
            await loadVehicles();
        }
    });
});
