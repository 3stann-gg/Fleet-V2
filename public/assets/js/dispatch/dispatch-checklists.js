/* ==========================================
   Dispatch Pre/Post-Trip Checklists
========================================== */

function getDispatchChecklistCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || "";
}

function setDispatchChecklistModalOpen(isOpen) {
    const modal = document.getElementById("dispatchChecklistModal");
    if (!modal) return;
    modal.classList.toggle("show", isOpen);
    document.body.style.overflow = isOpen ? "hidden" : "";
}

function resetDispatchChecklistForm() {
    const form = document.getElementById("dispatchChecklistForm");
    form?.reset();
    document.getElementById("dispatchChecklistReadOnly")?.replaceChildren();
    const contextActions = document.getElementById("postTripContextActions");
    if (contextActions) contextActions.hidden = true;
}

function configurePreTripChecklist(vehicleType, capabilityValue) {
    const isMotorcycle = String(vehicleType).toLowerCase() === "motorcycle";
    const capabilities = new Set(
        String(capabilityValue || "")
            .split("|")
            .filter(Boolean),
    );

    document.querySelectorAll("[data-pre-trip-vehicle]").forEach((wrapper) => {
        const appliesTo = wrapper.dataset.preTripVehicle;
        const requiredCapability = wrapper.dataset.requiredCapability;
        const isVisible =
            appliesTo === "shared" ||
            (appliesTo === "motorcycle" && isMotorcycle) ||
            (appliesTo === "standard" && !isMotorcycle);
        const hasRequiredCapability =
            !requiredCapability || capabilities.has(requiredCapability);
        wrapper.hidden = !isVisible || !hasRequiredCapability;

        const field = wrapper.querySelector("[data-pre-check]");
        if (field) {
            field.disabled = wrapper.hidden;
            if (wrapper.hidden) field.checked = false;
        }
    });
}

function configurePostTripEnergy(powertrainType) {
    const normalizedPowertrain = String(powertrainType || "").toLowerCase();

    document.querySelectorAll("[data-post-trip-energy]").forEach((wrapper) => {
        const energyType = wrapper.dataset.postTripEnergy;
        const isVisible =
            normalizedPowertrain === "electric"
                ? energyType === "battery"
                : normalizedPowertrain === "gasoline"
                  ? energyType === "fuel"
                  : true;
        wrapper.hidden = !isVisible;

        const field = wrapper.querySelector("input");
        if (field) {
            field.disabled = !isVisible;
            if (!isVisible) field.value = "";
        }
    });
}

function openDispatchChecklistForm(type, dispatchId, context = {}) {
    resetDispatchChecklistForm();
    const modal = document.getElementById("dispatchChecklistModal");
    const preFields = document.getElementById("preTripChecklistFields");
    const postFields = document.getElementById("postTripChecklistFields");
    const readOnly = document.getElementById("dispatchChecklistReadOnly");
    const save = document.getElementById("saveDispatchChecklist");
    if (!modal || !preFields || !postFields || !readOnly || !save) return;

    modal.dataset.checklistType = type;
    document.getElementById("checklistDispatchId").value = dispatchId;
    preFields.hidden = type !== "pre";
    postFields.hidden = type !== "post";
    readOnly.hidden = true;
    save.hidden = false;
    configurePreTripChecklist(context.vehicleType, context.capabilities);
    configurePostTripEnergy(context.powertrainType);
    document.getElementById("dispatchChecklistModalTitle").textContent =
        type === "pre" ? "Pre-Trip Checklist" : "Post-Trip Checklist";
    setDispatchChecklistModalOpen(true);
}

function checklistDetail(label, value) {
    const wrapper = document.createElement("div");
    wrapper.className = "view-dispatch-detail-item";
    const heading = document.createElement("label");
    heading.textContent = label;
    const text = document.createElement("p");
    text.textContent = value ?? "Not provided";
    wrapper.append(heading, text);
    return wrapper;
}

async function viewDispatchChecklist(type, checklistId) {
    resetDispatchChecklistForm();
    const endpoint =
        type === "pre"
            ? `/dispatch/pre-trip-checklists/${encodeURIComponent(checklistId)}`
            : `/dispatch/post-trip-checklists/${encodeURIComponent(checklistId)}`;
    const response = await fetch(endpoint, {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || "Unable to load checklist.");

    const checklist = data.checklist || {};
    const preFields = document.getElementById("preTripChecklistFields");
    const postFields = document.getElementById("postTripChecklistFields");
    const readOnly = document.getElementById("dispatchChecklistReadOnly");
    preFields.hidden = true;
    postFields.hidden = true;
    readOnly.hidden = false;
    document.getElementById("saveDispatchChecklist").hidden = true;
    document.getElementById("dispatchChecklistModalTitle").textContent =
        type === "pre" ? "Pre-Trip Checklist Details" : "Post-Trip Checklist Details";

    const details = type === "pre"
        ? [
              ["Result", checklist.result_label],
              ["Checked By", checklist.checked_by?.name],
              ["Checked At", checklist.checked_at],
              ["Remarks", checklist.remarks],
          ]
        : [
              ["Final Odometer", checklist.final_odometer],
              ["Fuel Remaining", checklist.fuel_level_remaining == null ? null : `${checklist.fuel_level_remaining}%`],
              ["Battery Remaining", checklist.battery_level_remaining == null ? null : `${checklist.battery_level_remaining}%`],
              ["Maintenance Required", checklist.maintenance_required ? "Yes" : "No"],
              ["Cleaning Required", checklist.cleaning_required ? "Yes" : "No"],
              ["Submitted By", checklist.submitted_by?.name],
              ["Submitted At", checklist.submitted_at],
              ["Remarks", checklist.remarks],
          ];
    details.forEach(([label, value]) => readOnly.append(checklistDetail(label, value)));
    setDispatchChecklistModalOpen(true);
}

async function submitDispatchChecklist(event) {
    event.preventDefault();
    const modal = document.getElementById("dispatchChecklistModal");
    const type = modal?.dataset.checklistType;
    const dispatchId = document.getElementById("checklistDispatchId")?.value;
    if (!type || !dispatchId) return;

    let payload;
    let endpoint;
    if (type === "pre") {
        const checklistData = {};
        document.querySelectorAll("[data-pre-check]").forEach((field) => {
            checklistData[field.dataset.preCheck] = field.checked;
        });
        payload = {
            dispatch_id: Number(dispatchId),
            result: document.getElementById("preTripResult").value,
            remarks: document.getElementById("tripChecklistRemarks").value || null,
            checklist_data: checklistData,
        };
        endpoint = "/dispatch/pre-trip-checklists";
    } else {
        const optionalNumber = (id) => {
            const value = document.getElementById(id).value;
            return value === "" ? null : Number(value);
        };
        payload = {
            dispatch_id: Number(dispatchId),
            final_odometer: optionalNumber("postTripOdometer"),
            fuel_level_remaining: optionalNumber("postTripFuel"),
            battery_level_remaining: optionalNumber("postTripBattery"),
            tire_condition: document.getElementById("postTripTires").value,
            lights_condition: document.getElementById("postTripLights").value,
            brake_condition: document.getElementById("postTripBrakes").value,
            visible_damage: document.getElementById("postTripVisibleDamage").checked,
            issue_reported: document.getElementById("postTripIssueReported").checked,
            maintenance_required: document.getElementById("postTripMaintenanceRequired").checked,
            cleaning_required: document.getElementById("postTripCleaningRequired").checked,
            remarks: document.getElementById("tripChecklistRemarks").value || null,
        };
        endpoint = "/dispatch/post-trip-checklists";
    }

    const response = await fetch(endpoint, {
        method: "POST",
        headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": getDispatchChecklistCsrfToken(),
        },
        credentials: "same-origin",
        body: JSON.stringify(payload),
    });
    const data = await response.json();
    if (!response.ok) {
        const firstError = data.errors ? Object.values(data.errors).flat()[0] : null;
        throw new Error(firstError || data.message || "Unable to save checklist.");
    }

    await loadDispatches();
    window.showToast?.(data.message || "Checklist saved.", "success");

    const contextActions = document.getElementById("postTripContextActions");
    const sanitationButton = document.getElementById("createChecklistSanitation");
    const maintenanceLink = document.getElementById("createChecklistMaintenance");
    const shouldShowActions =
        type === "post" &&
        (data.actions?.create_sanitation_record || data.actions?.create_maintenance_request);
    if (!shouldShowActions || !contextActions) {
        setDispatchChecklistModalOpen(false);
        return;
    }

    contextActions.hidden = false;
    sanitationButton.hidden = !data.actions.create_sanitation_record;
    maintenanceLink.hidden = !data.actions.create_maintenance_request;
    document.getElementById("saveDispatchChecklist").hidden = true;
    sanitationButton.dataset.vehicleId = data.checklist?.vehicle_id || "";
    sanitationButton.dataset.dispatchId = dispatchId;
}

document.addEventListener("DOMContentLoaded", () => {
    const modal = document.getElementById("dispatchChecklistModal");
    const close = () => setDispatchChecklistModalOpen(false);
    document.getElementById("closeDispatchChecklistModal")?.addEventListener("click", close);
    document.getElementById("cancelDispatchChecklist")?.addEventListener("click", close);
    document.getElementById("dispatchChecklistForm")?.addEventListener("submit", (event) => {
        submitDispatchChecklist(event).catch((error) => {
            window.showToast?.(error.message || "Unable to save checklist.", "error");
        });
    });
    modal?.addEventListener("click", (event) => {
        if (event.target === modal) close();
    });
    document.addEventListener("click", (event) => {
        const button = event.target.closest?.("[data-checklist-action]");
        if (!button) return;
        const action = button.dataset.checklistAction;
        const type = button.dataset.checklistType;
        if (action === "create") {
            openDispatchChecklistForm(type, button.dataset.dispatchId, button.dataset);
            return;
        }
        viewDispatchChecklist(type, button.dataset.checklistId).catch((error) => {
            window.showToast?.(error.message || "Unable to load checklist.", "error");
        });
    });
    document.getElementById("createChecklistSanitation")?.addEventListener("click", (event) => {
        const button = event.currentTarget;
        window.openVehicleSanitationModal?.(
            button.dataset.vehicleId,
            button.dataset.dispatchId,
        );
    });
});
