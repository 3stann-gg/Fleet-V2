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
    if (!summary || !selectedInput) {
        return;
    }

    selectedInput.value = candidate ? String(candidate.vehicle_id) : "";
    summary.replaceChildren();
    summary.hidden = !candidate;
    if (!candidate) {
        return;
    }

    appendDispatchCandidateText(summary, "strong", `Selected Vehicle: ${candidate.vehicle_label}`);
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

    const vehicleCell = row.insertCell();
    appendDispatchCandidateText(vehicleCell, "strong", candidate.vehicle_label);
    appendDispatchCandidateText(
        vehicleCell,
        "small",
        [candidate.plate_number, candidate.vehicle_type, candidate.powertrain_label]
            .filter(Boolean)
            .join(" · "),
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
        button.textContent = candidate.selected ? "Selected" : "Select Vehicle";
        button.disabled = candidate.selected;
        button.addEventListener("click", () => {
            const state = dispatchCandidateState.get(prefix);
            if (!state) {
                return;
            }
            state.candidates.forEach((item) => {
                item.selected = item.vehicle_id === candidate.vehicle_id;
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

async function loadDispatchCandidateVehicles(prefix, reservationId, driverId = null) {
    clearDispatchCandidateVehicles(prefix);
    if (!reservationId) {
        return null;
    }

    const query = driverId ? `?driver_id=${encodeURIComponent(driverId)}` : "";
    const response = await fetch(
        `/dispatch/candidate-vehicles/${encodeURIComponent(reservationId)}${query}`,
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
    if (section) {
        section.hidden = true;
    }
    body?.replaceChildren();
    if (selectedInput) {
        selectedInput.value = "";
    }
}
