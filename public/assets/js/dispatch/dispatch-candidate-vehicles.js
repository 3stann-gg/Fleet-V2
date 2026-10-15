/* ==========================================
   HIMS Fleet - Dispatch Candidate Vehicles
========================================== */

const dispatchCandidateState = new Map();

function dispatchCandidateBadgeClass(status) {
    return {
        suitable: "completed",
        suitable_with_advisory: "scheduled",
        insufficient_information: "pending",
        not_recommended: "cancelled",
    }[status] || "pending";
}

function appendDispatchCandidateText(parent, tagName, text, className = "") {
    const element = document.createElement(tagName);
    element.textContent = text || "Not Available";
    if (className) {
        element.className = className;
    }
    parent.appendChild(element);
    return element;
}

function dispatchCandidateDetailMessages(candidate) {
    return [
        ...(candidate.blocking_concerns || []),
        ...(candidate.warnings || []),
        ...(candidate.information_gaps || []),
        ...(candidate.reasons || []),
        ...(candidate.operational_health?.reasons || []),
    ].filter((message, index, values) => message && values.indexOf(message) === index);
}

function renderDispatchSelectedCandidate(prefix, candidate) {
    const summary = document.getElementById(`${prefix}SelectedVehicleSummary`);
    const selectedInput = document.getElementById(`${prefix}SelectedVehicleId`);
    const selectedDriverInput = document.getElementById(`${prefix}SelectedDriverId`);
    if (!summary || !selectedInput || !selectedDriverInput) {
        return;
    }

    selectedInput.value = candidate ? String(candidate.vehicle_id) : "";
    selectedDriverInput.value = candidate ? String(candidate.driver_id) : "";
    summary.replaceChildren();
    summary.hidden = !candidate;
    if (!candidate) {
        return;
    }

    appendDispatchCandidateText(summary, "strong", `Vehicle: ${candidate.vehicle_label}`);
    appendDispatchCandidateText(summary, "strong", `Driver: ${candidate.driver_name}`);
    const result = appendDispatchCandidateText(summary, "span", candidate.label, "status-badge");
    result.classList.add(dispatchCandidateBadgeClass(candidate.status));
    appendDispatchCandidateText(
        summary,
        "small",
        dispatchCandidateDetailMessages(candidate).slice(0, 3).join(" ") ||
            "No meaningful operational warning was found.",
    );

    const vehicleField = document.getElementById(`${prefix}Vehicle`);
    if (vehicleField) {
        vehicleField.value = candidate.vehicle_label || "";
    }

    const driverField = document.getElementById(`${prefix}Driver`);
    if (driverField) {
        driverField.value = candidate.driver_name || "";
    }

    if (prefix === "dispatch") {
        const values = {
            dispatchReadiness: candidate.readiness?.label,
            dispatchPowertrain: candidate.powertrain_label,
            dispatchFuelLevel:
                candidate.fuel_level_percent == null
                    ? "Not Available"
                    : `${candidate.fuel_level_percent}%`,
            dispatchBatteryLevel:
                candidate.battery_level_percent == null
                    ? "Not Available"
                    : `${candidate.battery_level_percent}%`,
            dispatchEstimatedRange:
                candidate.estimated_range_km == null
                    ? "Not Available"
                    : `${candidate.estimated_range_km} km`,
            dispatchEnergyAdvisory: `${candidate.energy?.label || "Energy"}: ${candidate.energy?.message || "Not Available"}`,
            dispatchCapabilityAdvisory: `${candidate.capabilities?.label || "Capabilities"}: ${candidate.capabilities?.message || "Not Available"}`,
            dispatchDriverAdvisory: `${candidate.driver_match?.label || "Driver"}: ${candidate.driver_match?.message || "Not Available"}`,
        };
        Object.entries(values).forEach(([id, value]) => {
            const element = document.getElementById(id);
            if (element) {
                element.value = value || "";
            }
        });
    }
}

function createDispatchCandidateRow(prefix, candidate, allowSelection) {
    const row = document.createElement("tr");
    row.dataset.vehicleId = String(candidate.vehicle_id);
    row.dataset.driverId = String(candidate.driver_id);

    const vehicleCell = row.insertCell();
    appendDispatchCandidateText(vehicleCell, "strong", candidate.vehicle_label);
    appendDispatchCandidateText(
        vehicleCell,
        "small",
        [candidate.plate_number, candidate.vehicle_type, candidate.powertrain_label]
            .filter(Boolean)
            .join(" · "),
    );
    appendDispatchCandidateText(
        vehicleCell,
        "small",
        `Driver: ${candidate.driver_name || "Not Available"}`,
    );

    [
        candidate.readiness,
        candidate.capabilities,
        candidate.driver_match,
        candidate.driver_license,
        candidate.energy,
        candidate.operational_health,
    ].forEach((check) => {
        const cell = row.insertCell();
        appendDispatchCandidateText(cell, "span", check?.label, "dispatch-candidate-check");
    });

    const resultCell = row.insertCell();
    const badge = appendDispatchCandidateText(resultCell, "span", candidate.label, "status-badge");
    badge.classList.add(dispatchCandidateBadgeClass(candidate.status));

    const details = document.createElement("details");
    const detailsSummary = document.createElement("summary");
    detailsSummary.textContent = "View Details";
    details.appendChild(detailsSummary);
    const list = document.createElement("ul");
    dispatchCandidateDetailMessages(candidate).forEach((message) => {
        appendDispatchCandidateText(list, "li", message);
    });
    details.appendChild(list);
    resultCell.appendChild(details);

    const actionCell = row.insertCell();
    if (allowSelection) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "btn-outline dispatch-select-candidate";
        button.textContent = candidate.selected ? "Selected" : "Select Pair";
        button.disabled = candidate.selected || !candidate.pair_selectable;
        if (!candidate.pair_selectable && !candidate.selected) {
            button.title = "This vehicle and driver pair is not currently eligible for dispatch.";
        }
        button.addEventListener("click", () => {
            const state = dispatchCandidateState.get(prefix);
            if (!state) {
                return;
            }
            state.candidates.forEach((item) => {
                item.selected =
                    item.vehicle_id === candidate.vehicle_id &&
                    item.driver_id === candidate.driver_id;
            });
            renderDispatchCandidateComparison(prefix, state);
            renderDispatchSelectedCandidate(prefix, candidate);
        });
        actionCell.appendChild(button);
    } else {
        actionCell.textContent = "Advisory only";
    }

    return row;
}

function renderDispatchCandidateComparison(prefix, data) {
    const section = document.getElementById(`${prefix}CandidateComparison`);
    const body = document.getElementById(`${prefix}CandidateTableBody`);
    const empty = document.getElementById(`${prefix}CandidateEmpty`);
    const priority = document.getElementById(`${prefix}CandidatePriority`);
    if (!section || !body || !empty) {
        return;
    }

    const candidates = Array.isArray(data.candidates) ? data.candidates : [];
    const allowSelection = section.dataset.allowSelection === "true";
    section.hidden = false;
    body.replaceChildren();
    empty.hidden = candidates.length > 0;
    if (priority) {
        priority.textContent = data.priority || "Priority Not Specified";
    }
    candidates.forEach((candidate) => {
        body.appendChild(createDispatchCandidateRow(prefix, candidate, allowSelection));
    });

    renderDispatchSelectedCandidate(
        prefix,
        candidates.find((candidate) => candidate.selected) || null,
    );
}

async function loadDispatchCandidateVehicles(prefix, reservationId) {
    clearDispatchCandidateVehicles(prefix);
    if (!reservationId) {
        return null;
    }

    const response = await fetch(
        `/dispatch/candidate-vehicles/${encodeURIComponent(reservationId)}`,
        {
            headers: { Accept: "application/json" },
            credentials: "same-origin",
        },
    );
    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.message || "Unable to load candidate vehicles.");
    }

    dispatchCandidateState.set(prefix, data);
    renderDispatchCandidateComparison(prefix, data);
    return data;
}

function clearDispatchCandidateVehicles(prefix) {
    dispatchCandidateState.delete(prefix);
    const section = document.getElementById(`${prefix}CandidateComparison`);
    const body = document.getElementById(`${prefix}CandidateTableBody`);
    const selectedInput = document.getElementById(`${prefix}SelectedVehicleId`);
    const selectedDriverInput = document.getElementById(`${prefix}SelectedDriverId`);
    if (section) {
        section.hidden = true;
    }
    body?.replaceChildren();
    if (selectedInput) {
        selectedInput.value = "";
    }
    if (selectedDriverInput) {
        selectedDriverInput.value = "";
    }
}
