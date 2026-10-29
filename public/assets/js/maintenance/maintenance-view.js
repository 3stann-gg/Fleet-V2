/* ==========================================
   Maintenance View
========================================== */

let viewMaintenanceInitialized = false;

const viewMaintenanceModal = {
    currentRow: null,
    currentMaintenanceId: null,
    currentMaintenance: null,
};

function getMaintenanceRecordIdFromRow(row) {
    if (!row) {
        return "";
    }

    const id = (row.dataset.id || "").trim();

    if (!id || !/^\d+$/.test(id)) {
        return "";
    }

    return id;
}

async function openEditMaintenanceFromView() {
    const viewModal = document.getElementById("viewMaintenanceModal");
    const maintenanceId =
        viewMaintenanceModal.currentMaintenanceId ||
        viewModal?.dataset.maintenanceId ||
        "";
    const canEdit = window.FLEET_RBAC?.maintenance?.canUpdate === true;
    if (!canEdit) {
        if (typeof showToast === "function") {
            showToast(
                "You do not have permission to edit maintenance records.",
                "error",
            );
        }

        return;
    }
    const row = viewMaintenanceModal.currentRow;
    const maintenance = viewMaintenanceModal.currentMaintenance;

    if (!maintenanceId || !/^\d+$/.test(String(maintenanceId))) {
        if (typeof showToast === "function") {
            showToast("Invalid maintenance record ID.", "error");
        }

        return;
    }

    if (!row || !document.body.contains(row)) {
        if (typeof showToast === "function") {
            showToast("Maintenance record is no longer available.", "error");
        }

        return;
    }

    if (typeof openEditMaintenanceModal !== "function") {
        return;
    }

    const opened = openEditMaintenanceModal(row, maintenanceId, maintenance);

    if (opened) {
        closeViewMaintenanceModal();
    }
}

async function openViewMaintenanceModal(row) {
    const modal = document.getElementById("viewMaintenanceModal");

    if (!modal || !row) {
        return;
    }

    const maintenanceId = getMaintenanceRecordIdFromRow(row);

    if (!maintenanceId) {
        console.error("Invalid maintenance database ID:", row.dataset.id);

        if (typeof showToast === "function") {
            showToast("Invalid maintenance record ID.", "error");
        }

        return;
    }

    try {
        const response = await fetch(`/maintenance/${maintenanceId}`, {
            headers: {
                Accept: "application/json",
            },
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(
                data.message || "Failed to load maintenance details.",
            );
        }

        const maintenance = data.maintenance;

        if (!maintenance) {
            throw new Error("Maintenance record not found.");
        }

        viewMaintenanceModal.currentRow = row;
        viewMaintenanceModal.currentMaintenanceId = String(maintenance.id);
        viewMaintenanceModal.currentMaintenance = maintenance;

        modal.dataset.maintenanceId = String(maintenance.id);

        const editFromViewBtn = document.getElementById(
            "editMaintenanceFromViewBtn",
        );

        if (editFromViewBtn) {
            editFromViewBtn.dataset.maintenanceId = String(maintenance.id);
        }

        const vehicle = maintenance.vehicle;
        const vehicleText = vehicle
            ? [
                  [vehicle.brand, vehicle.model].filter(Boolean).join(" "),

                  vehicle.vehicle_type,
              ]
                  .filter(Boolean)
                  .join(" - ")
            : "Unassigned";

        const setText = (id, value, fallback = "Not provided") => {
            const element = document.getElementById(id);

            if (!element) {
                return;
            }

            element.textContent =
                value !== null &&
                value !== undefined &&
                String(value).trim() !== ""
                    ? value
                    : fallback;
        };

        setText("viewMaintenanceNumber", maintenance.maintenance_number);
        setText("viewMaintenanceVehicle", vehicleText, "Unassigned");
        setText("viewMaintenanceServiceType", maintenance.maintenance_type);
        setText(
            "viewMaintenanceCategory",
            maintenance.maintenance_category,
            "Other / Not Classified",
        );
        setText(
            "viewMaintenanceRequestType",
            maintenance.request_type,
            "Normal",
        );
        setText("viewMaintenanceTechnician", maintenance.technician);
        setText(
            "viewMaintenanceScheduledDate",
            formatMaintenanceViewDate(maintenance.maintenance_date),
        );
        setText(
            "viewMaintenanceCompletionDate",
            maintenance.completion_date
                ? formatMaintenanceViewDate(maintenance.completion_date)
                : "Not completed",
        );
        setText(
            "viewMaintenanceCost",
            formatMaintenanceViewCost(maintenance.cost),
        );
        setText("viewMaintenancePriority", maintenance.priority);
        setText("viewMaintenanceOdometer", maintenance.odometer);
        setText(
            "viewMaintenanceVehicleOdometer",
            formatMaintenanceViewOdometer(
                maintenance.vehicle_service_context?.current_odometer,
            ),
            "Not recorded",
        );
        setText(
            "viewMaintenancePurchaseDate",
            maintenance.vehicle_service_context?.purchase_date
                ? formatMaintenanceViewDate(
                      maintenance.vehicle_service_context.purchase_date,
                  )
                : null,
            "Not recorded",
        );
        const dueStatusLabel =
            maintenance.status === "In Progress" &&
            maintenance.maintenance_due_state === "overdue"
                ? "Scheduled date passed"
                : maintenance.maintenance_due_label;
        setText(
            "viewMaintenanceDueStatus",
            dueStatusLabel,
            "Not Scheduled",
        );
        setText(
            "viewMaintenanceDueBasis",
            maintenance.maintenance_due_basis,
            "Not available",
        );
        setText(
            "viewMaintenanceDueDate",
            maintenance.maintenance_due_date
                ? formatMaintenanceViewDate(maintenance.maintenance_due_date)
                : null,
            "Not scheduled",
        );
        setText(
            "viewMaintenanceNextSchedule",
            maintenance.next_schedule
                ? formatMaintenanceViewDate(maintenance.next_schedule)
                : null,
            "Not scheduled",
        );
        setText(
            "viewMaintenanceBasis",
            maintenance.vehicle_service_context?.basis,
            "Category is derived from the recorded request and service types. Due context is derived from maintenance status and recorded dates. Vehicle purchase date and odometer are display context only.",
        );
        setText("viewMaintenanceDescription", maintenance.description);
        setText("viewMaintenancePartsUsed", maintenance.parts_used);
        setText("viewMaintenanceNotes", maintenance.notes);

        const status = maintenance.status || "";

        const statusMap = {
            Scheduled: "scheduled",
            "In Progress": "trip",
            Completed: "completed",
            Cancelled: "cancelled",
        };

        const statusElement = document.getElementById("viewMaintenanceStatus");

        if (statusElement) {
            statusElement.className =
                "status-badge " + (statusMap[status] || "out");

            statusElement.textContent = status || "Not provided";
        }

        modal.classList.add("show");
        document.body.style.overflow = "hidden";
    } catch (error) {
        console.error("Maintenance view error:", error);

        if (typeof showToast === "function") {
            showToast(
                error.message || "Failed to load maintenance details.",
                "error",
            );
        }
    }
}

function formatMaintenanceViewDate(date) {
    if (!date) {
        return "Not provided";
    }

    const parsed = new Date(date);

    if (isNaN(parsed.getTime())) {
        return "Not provided";
    }

    return parsed.toLocaleDateString(undefined, {
        year: "numeric",
        month: "short",
        day: "numeric",
    });
}

function formatMaintenanceViewCost(cost) {
    if (cost === null || cost === undefined || cost === "") {
        return "₱0.00";
    }

    const value = Number(cost);

    if (isNaN(value)) {
        return "₱0.00";
    }

    return (
        "₱" +
        value.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })
    );
}

function formatMaintenanceViewOdometer(odometer) {
    if (odometer === null || odometer === undefined || odometer === "") {
        return "Not recorded";
    }

    const value = Number(odometer);

    if (Number.isNaN(value)) {
        return "Not recorded";
    }

    return `${value.toLocaleString(undefined, {
        maximumFractionDigits: 2,
    })} km`;
}

function closeViewMaintenanceModal() {
    const modal = document.getElementById("viewMaintenanceModal");

    if (!modal) {
        return; 
    }

    modal.classList.remove("show");

    document.body.style.overflow = "";

    modal.currentMaintenanceId = null;

    delete modal.dataset.maintenanceId;

    viewMaintenanceModal.currentRow = null;
    viewMaintenanceModal.currentMaintenanceId = null;
    viewMaintenanceModal.currentMaintenance = null;

    const editFromViewBtn = document.getElementById(
        "editMaintenanceFromViewBtn",
    );

    if (editFromViewBtn) {
        delete editFromViewBtn.dataset.maintenanceId;
    }
}

function initViewMaintenanceModal() {
    if (viewMaintenanceInitialized) {
        return;
    }

    const modal = document.getElementById("viewMaintenanceModal");

    if (!modal) {
        return;
    }

    viewMaintenanceInitialized = true;

    document.addEventListener("click", (event) => {
        const viewBtn = event.target.closest(".action-btn.view-maintenance");

        if (viewBtn) {
            const row = viewBtn.closest("tr");

            if (row) {
                openViewMaintenanceModal(row);
            }

            return;
        }

        const editFromViewBtn = event.target.closest(
            "#editMaintenanceFromViewBtn",
        );

        if (editFromViewBtn) {
            event.preventDefault();

            openEditMaintenanceFromView();

            return;
        }
        if (event.target.closest("#closeViewMaintenanceModal")) {
            closeViewMaintenanceModal();
            return;
        }
        if (event.target.closest("#closeViewMaintenanceBtn")) {
            closeViewMaintenanceModal();
            return;
        }
        if (event.target === modal) {
            closeViewMaintenanceModal();
        }
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && modal.classList.contains("show")) {
            closeViewMaintenanceModal();
        }
    });
}

document.addEventListener("DOMContentLoaded", () => {
    initViewMaintenanceModal();
});
