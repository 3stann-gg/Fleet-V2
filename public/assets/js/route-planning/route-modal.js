/* ==========================================
   HIMS Fleet - Route Planning Modals

   Purpose:
   - New Route
   - View Route
   - Edit Route
   - Delete Route
   - Archive / Restore
   - Duplicate Route
   - Approved Reservation auto-population
   - Laravel/MySQL persistence
========================================== */

let routeModalsInitialized = false;

let routeFormMode = "add"; // add | edit
let routeEditingId = null;
let routeDeleteTargetId = null;

/* ==========================================
   Route Planning Settings
========================================== */

let routeModuleSettings = {
    preferOptimized: true,
};

async function loadRouteModuleSettings() {
    const defaults = {
        preferOptimized: true,
    };
    try {
        const response = await fetch(
            "/settings/data",
            {
                headers: {
                    Accept: "application/json",
                },
                credentials: "same-origin",
            }
        );

        if (!response.ok) {
            throw new Error(
                "Unable to load Route Planning settings."
            );
        }

        const data = await response.json();
        const settings =
            data?.settings?.routes;

        if (
            !settings ||
            typeof settings !== "object"
        ) {
            routeModuleSettings = defaults;
            return routeModuleSettings;
        }

        routeModuleSettings = {
            preferOptimized:
                settings.preferOptimized !== false,
        };
        return routeModuleSettings;
    } catch (error) {
        console.error(
            "Route settings load error:",
            error
        );
        routeModuleSettings = defaults;
        return routeModuleSettings;
    }
}

//   RBAC
function canCreateRoute() {
    return (
        window.FleetRBAC?.hasPermission?.(
            "route_planning",
            "canCreate"
        ) === true
    );
}
function canUpdateRoute() {
    return (
        window.FleetRBAC?.hasPermission?.(
            "route_planning",
            "canUpdate"
        ) === true
    );
}
/*
function canDeleteRoute() {
    return (
        window.FleetRBAC?.hasPermission?.(
            "route_planning",
            "canDelete"
        ) === true
    );
}
*/   
function canArchiveRoute() {
    return (
        window.FleetRBAC?.hasPermission?.(
            "route_planning",
            "canArchive"
        ) === true
    );
}
function canRestoreRoute() {
    return (
        window.FleetRBAC?.hasPermission?.(
            "route_planning",
            "canRestore"
        ) === true
    );
}
function canDuplicateRoute() {
    return (
        window.FleetRBAC?.hasPermission?.(
            "route_planning",
            "canDuplicate"
        ) === true
    );
}

let routeLastOptimization = null;
let routePendingOptimization = null;

let routeOptimizationGeneration = 0;
let routeOptimizationRequestPending = false;
let routeComparisonSnapshot = null;
let routeComparisonTimer = null;
let routeTrafficCooldownTimer = null;
let routeFormGeneration = 0;
let routeSaveOperation = null;

function invalidateRouteSaveContext() {
    routeFormGeneration++;
    if (routeSaveOperation?.ownsSubmitButton) {
        routeSaveOperation.submitButton.disabled = routeSaveOperation.submitWasDisabled;
    }
    routeSaveOperation = null;
}

function isRouteSaveCurrent(operation, checkInputs = true) {
    return routeSaveOperation === operation &&
        operation.generation === routeFormGeneration &&
        operation.mode === routeFormMode &&
        operation.routeId === routeEditingId &&
        operation.reservationId === document.getElementById("routeReservation")?.value &&
        (!checkInputs || (operation.optimizationGeneration === routeOptimizationGeneration &&
            operation.fingerprint === routeCalculationFingerprint()));
}

function refreshRouteTrafficCooldownControls() {
    window.clearTimeout?.(routeTrafficCooldownTimer);
    routeTrafficCooldownTimer = null;
    const button = document.getElementById("optimizeRouteBtn");
    if (!button) return;
    const error = getRouteTrafficRateLimitError(document.getElementById("routeComparisonEnabled")?.checked === true);
    if (error) {
        if (button.dataset.routeCooldownLocked !== "true") button.dataset.routeCooldownTitle = button.title || "";
        button.dataset.routeCooldownLocked = "true";
        button.disabled = true;
        button.title = error.message;
        routeTrafficCooldownTimer = window.setTimeout?.(refreshRouteTrafficCooldownControls, Math.min(error.retryAfter * 1000 + 10, 2147483647));
    } else if (button.dataset.routeCooldownLocked === "true") {
        button.disabled = routeOptimizationRequestPending;
        button.title = button.dataset.routeCooldownTitle || "";
        delete button.dataset.routeCooldownLocked;
        delete button.dataset.routeCooldownTitle;
    }
}

function routeCalculationFingerprint() {
    const ids = ["routeReservation", "routeOrigin", "routeDestination", "routeDepartureDate", "routeDepartureTime", "routeVehicle", "routeDriver"];
    return JSON.stringify({
        mode: routeFormMode,
        id: routeEditingId,
        fields: ids.map(id => {
            const field = document.getElementById(id);
            return [field?.value || "", field?.dataset.latitude ?? null, field?.dataset.longitude ?? null];
        }),
        stops: getRouteStopsFromForm(),
        stopCoordinates: Array.from(document.getElementById("routeStopsList")?.querySelectorAll("input.route-stop-input") || []).map(input => [input.dataset.latitude ?? null, input.dataset.longitude ?? null]),
        comparison: document.getElementById("routeComparisonEnabled")?.checked === true,
    });
}

function clearRouteComparison() {
    window.clearTimeout?.(routeComparisonTimer);
    routeComparisonTimer = null;
    routeComparisonSnapshot = null;
    const panel = document.getElementById("routeComparisonSummary");
    if (panel) {
        panel.hidden = true;
        panel.innerHTML = "";
    }
}

function renderRouteComparison(result) {
    clearRouteComparison();
    const panel = document.getElementById("routeComparisonSummary");
    if (!panel || document.getElementById("routeComparisonEnabled")?.checked !== true) return;
    const comparison = result?.comparison;
    const originalStops = result?.originalRouteCoordinates?.stops || [];
    const candidateStops = result?.routeCoordinates?.stops || [];
    const expiry = Date.parse(comparison?.expires_at);
    let available = comparison?.status === "available" && result?.provider === "TomTom" && !result?.fallback && Number.isFinite(expiry) && expiry > Date.now();
    let reason = comparison?.reason || "Comparable TomTom estimates are unavailable. OSRM fallback is not a verified optimization comparison.";
    const metric = value => typeof value === "number" && Number.isFinite(value) && value >= 0;
    if (available) {
        const points = [result.originalRouteCoordinates?.origin, ...originalStops.map(stop => stop.coordinate), result.originalRouteCoordinates?.destination];
        const source = comparison.source_coordinates, order = comparison.candidate_order, context = comparison.routing_context;
        available = comparison.provider === "TomTom" && context?.api_version === "1" && context.route_type === "fastest" && context.travel_mode === "car" && context.traffic === true &&
            Array.isArray(source) && source.length === points.length && points.every((point, i) => point && source[i]?.latitude === point.lat && source[i]?.longitude === point.lng) &&
            Array.isArray(order) && order.length === originalStops.length && new Set(order).size === order.length &&
            order.every((index, i) => Number.isInteger(index) && index >= 0 && index < originalStops.length && candidateStops[i]?.location === originalStops[index]?.location && candidateStops[i]?.coordinate?.lat === originalStops[index]?.coordinate?.lat && candidateStops[i]?.coordinate?.lng === originalStops[index]?.coordinate?.lng);
        for (const key of ["distance_meters", "travel_time_seconds"]) {
            const original = comparison.original?.[key], candidate = comparison.candidate?.[key], delta = comparison.difference?.[key];
            const percent = comparison.difference?.[key + "_percent"];
            const expectedPercent = original > 0 ? ((original - candidate) / original) * 100 : null;
            available = available && metric(original) && metric(candidate) && typeof delta === "number" && Number.isFinite(delta) && Math.abs(delta - (original - candidate)) < 0.000001 &&
                (expectedPercent === null ? percent === null : typeof percent === "number" && Number.isFinite(percent) && Math.abs(percent - expectedPercent) < 0.000001);
        }
        if (!available) reason = "Comparison unavailable: measurements or route context do not match this calculation.";
    } else if (comparison?.status === "available") {
        reason = "Comparison expired or is not comparable. Run Optimize again.";
    }
    const escape = value => escapeRouteHtml(String(value));
    const value = (number, divisor, unit, signed = false) => {
        if (typeof number !== "number" || !Number.isFinite(number)) return "Unavailable";
        return (signed && number > 0 ? "+" : "") + (number / divisor).toFixed(2) + unit;
    };
    const originalOrder = originalStops.map((stop, index) => "<li>" + (index + 1) + ". " + escape(stop.location) + "</li>").join("");
    const candidateOrder = candidateStops.map(stop => "<li>" + escape(stop.location) + "</li>").join("");
    const context = comparison?.routing_context;
    const row = (label, key, divisor, unit) => "<tr><th scope='row'>" + label + "</th><td>" + (available ? value(comparison.original[key], divisor, unit) : "Unavailable") + "</td><td>" + (available ? value(comparison.candidate[key], divisor, unit) : "Unavailable") + "</td><td>" + (available ? value(comparison.difference[key], divisor, unit, true) : "Unavailable") + "</td><td>" + (available ? value(comparison.difference[key + "_percent"], 1, "%", true) : "Unavailable") + "</td></tr>";
    panel.hidden = false;
    panel.innerHTML = [
        "<h3 id='routeComparisonTitle'>Original vs Candidate Route Estimates</h3>",
        "<p class='route-comparison-status'><strong>" + (available ? "Comparable estimates" : "Unavailable") + "</strong> — " + escape(reason) + "</p>",
        "<div class='route-comparison-table-wrap'><table><thead><tr><th>Metric</th><th>Original input order</th><th>Candidate order</th><th>Difference (original − candidate)</th><th>Difference (%)</th></tr></thead><tbody>",
        row("Estimated distance", "distance_meters", 1000, " km"),
        row("Estimated travel duration", "travel_time_seconds", 60, " min"),
        "</tbody></table></div>",
        "<p>Positive differences mean shorter distance or duration; negative differences mean longer. These are estimates, not verified savings.</p>",
        "<div class='route-comparison-orders'><div><strong>Original input order</strong><ol>" + (originalOrder || "<li>No intermediate stops</li>") + "</ol></div><div><strong>Candidate order</strong><ol>" + (candidateOrder || "<li>No intermediate stops</li>") + "</ol></div></div>",
        "<p>Provider: " + escape(comparison?.provider || result?.provider || "Unavailable") + (context ? " · Routing API " + escape(context.api_version) + " · " + escape(context.travel_mode) + " · " + escape(context.route_type) + " · Traffic " + (context.traffic === true ? "enabled" : "unavailable") + " · Departure: " + escape(context.depart_at) : "") + "</p>",
        "<p>Original calculated: " + escape(comparison?.original_calculated_at || "Unavailable") + " · Candidate calculated: " + escape(comparison?.candidate_calculated_at || "Unavailable") + "</p>",
        "<p>Estimated differences only. Actual travel outcomes may vary.</p>",
    ].join("");
    const snapshot = { result, fingerprint: routeCalculationFingerprint(), expiry };
    routeComparisonSnapshot = snapshot;
    if (available && typeof window.setTimeout === "function") {
        routeComparisonTimer = window.setTimeout(() => {
            if (routeComparisonSnapshot === snapshot) renderRouteComparison({ ...result, comparison: { ...comparison, status: "unavailable", reason: "Comparison expired. Run Optimize again." } });
        }, Math.max(0, expiry - Date.now()));
    }
}

function verifyRouteComparisonSnapshot() {
    if (routeComparisonSnapshot && (routeComparisonSnapshot.fingerprint !== routeCalculationFingerprint() || routeComparisonSnapshot.expiry <= Date.now())) {
        const result = routeComparisonSnapshot.result;
        renderRouteComparison({ ...result, comparison: { ...result.comparison, status: "unavailable", reason: "Comparison inputs changed or expired. Run Optimize again." } });
    }
}

function invalidateRouteComparisonContext() {
    routeOptimizationGeneration++;
    routeOptimizationRequestPending = false;
    const result = routeComparisonSnapshot?.result;
    if (result) {
        renderRouteComparison({ ...result, comparison: { ...result.comparison, status: "unavailable", reason: "Comparison inputs changed. Run Optimize again." } });
    }
}

const ROUTE_STATUS_TRANSITIONS = {
    Draft: ["Planned"],
    Planned: ["Draft", "Ready For Dispatch"],
    "Ready For Dispatch": [],
    Completed: [],
    Archived: [],
};

function applyRouteStatusTransitions(
    selectElement,
    currentStatus,
    isEmergency = false,
) {
    if (!selectElement) {
        return;
    }
    const normalizedStatus = String(currentStatus || "").trim();
    const allowedTransitions = ROUTE_STATUS_TRANSITIONS[normalizedStatus] || [];
    Array.from(selectElement.options).forEach((option) => {
        const optionStatus = String(option.value || "").trim();
        // Current status must always remain selectable.
        if (optionStatus === normalizedStatus) {
            option.disabled = false;
            option.hidden = false;
            return;
        }
        if (isEmergency && optionStatus === "Ready For Dispatch") {
            option.disabled = true;
            option.hidden = false;
            return;
        }
        // Keep the option visible, but disable invalid transitions.
        option.disabled = !allowedTransitions.includes(optionStatus);
        option.hidden = false;
    });

    // Keep the current status selected when the modal opens.
    selectElement.value = normalizedStatus;
}

function setRouteWorkflowSteps(containerId, activeStep, isComplete = false) {
    const container = document.getElementById(containerId);
    if (!container) {
        return;
    }
    container.querySelectorAll("[data-workflow-step]").forEach((step) => {
        const stepIndex = Number(step.dataset.workflowStep);
        const completed = isComplete || stepIndex < activeStep;
        const active = !isComplete && stepIndex === activeStep;
        step.classList.toggle("is-complete", completed);
        step.classList.toggle("is-active", active);
        step.classList.toggle("is-locked", isComplete);
        if (active) {
            step.setAttribute("aria-current", "step");
        } else {
            step.removeAttribute("aria-current");
        }
    });
}

function getRouteWorkflowState(record) {
    const status = String(record?.status || "Draft").trim();
    const hasOptimization =
        record?.estimatedDistance !== null &&
        record?.estimatedDistance !== undefined &&
        String(record?.optimizationStrategy || "").trim() !== "";

    if (status === "Completed") {
        return { step: 3, complete: true, optimization: "Applied" };
    }
    if (status === "Ready For Dispatch") {
        return { step: 3, complete: false, optimization: "Applied" };
    }
    if (status === "Archived") {
        return {
            step: hasOptimization ? 2 : 1,
            complete: false,
            optimization: hasOptimization ? "Applied" : "Not generated",
        };
    }
    if (hasOptimization) {
        return { step: 2, complete: false, optimization: "Applied" };
    }
    if (status === "Planned") {
        return { step: 1, complete: false, optimization: "Not generated" };
    }

    return { step: 0, complete: false, optimization: "Not generated" };
}

function updateRouteWorkflowPresentation(record = null) {
    const heading = document.getElementById("routeWorkflowHeading");
    const identity = document.getElementById("routeWorkflowIdentity");
    const priority = document.getElementById("routeWorkflowPriority");
    const status = document.getElementById("routeWorkflowStatus");
    const optimization = document.getElementById(
        "routeWorkflowOptimization",
    );
    const optimizationState = document.getElementById(
        "routeOptimizationStateLabel",
    );
    const emergency = document.getElementById("routeEmergencyIndicator");
    const workflow = getRouteWorkflowState(record);

    if (heading) {
        heading.textContent = record
            ? record.routeNumber || "Selected route"
            : "Plan a route for dispatch";
    }
    if (identity) {
        identity.textContent = record
            ? `${record.reservationNumber || "No reservation reference"} · ${record.origin || "Origin"} → ${record.destination || "Destination"}`
            : "Select a route from history or request a new route.";
    }
    if (priority) {
        priority.textContent = record
            ? record.priorityLabel || getRoutePriorityLabel(record.priority)
            : "Not selected";
    }
    if (status) {
        status.textContent = record?.status || "Planning";
    }
    if (optimization) {
        optimization.textContent = workflow.optimization;
    }
    if (optimizationState) {
        optimizationState.textContent = workflow.optimization;
        optimizationState.classList.toggle(
            "is-applied",
            workflow.optimization === "Applied",
        );
    }
    if (emergency) {
        emergency.hidden = record?.isEmergency !== true;
    }
    setRouteWorkflowSteps(
        "routeWorkflowSteps",
        workflow.step,
        workflow.complete,
    );
}

function setRouteWorkflowPrimaryAction(primaryButton) {
    ["optimizeRouteBtn", "applyRouteOptimizationBtn", "saveRouteBtn"].forEach(
        (id) => {
            const button = document.getElementById(id);
            if (!button) {
                return;
            }
            const isPrimary = id === primaryButton;
            button.classList.toggle("btn-primary", isPrimary);
            button.classList.toggle("btn-outline", !isPrimary);
        },
    );
}

function updateRouteFormWorkflowUi(reservation = null) {
    updateRouteStrategyPresentation();
    const form = document.getElementById("routeForm");
    const status = String(document.getElementById("routeStatus")?.value || "Draft");
    const priority = String(document.getElementById("routePriority")?.value || "");
    const selectedReservation = reservation || getSelectedRouteReservation();
    const isEmergency =
        selectedReservation?.is_emergency === true ||
        priority.toLowerCase() === "emergency";
    const hasPlan = Boolean(
        document.getElementById("routeReservation")?.value &&
            document.getElementById("routeOrigin")?.value.trim() &&
            document.getElementById("routeDestination")?.value.trim(),
    );
    const persistedOptimization =
        form?.dataset.persistedOptimization === "true";
    const hasAppliedOptimization =
        routeLastOptimization?.optimizationApplied === true ||
        persistedOptimization;
    const hasGeneratedOptimization = routePendingOptimization !== null;
    const isCompleted = status === "Completed";
    let activeStep = 0;
    let message = "Complete the route plan, then optimize it.";
    let primaryAction = "saveRouteBtn";

    if (status === "Ready For Dispatch" || status === "Completed") {
        activeStep = 3;
        message =
            status === "Completed"
                ? "This route is complete and shown for reference."
                : "Ready for Dispatch was confirmed by the server.";
    } else if (hasGeneratedOptimization) {
        activeStep = 2;
        message = "Optimization generated. Review it before applying.";
        primaryAction = "applyRouteOptimizationBtn";
    } else if (hasAppliedOptimization) {
        activeStep = 2;
        message =
            routeLastOptimization?.optimizationApplied === true
                ? "Optimization applied. Save the route to persist the result."
                : "Optimization is applied and already persisted.";
    } else if (hasPlan) {
        activeStep = 1;
        message = "Route details are ready. Run optimization to continue.";
        primaryAction = "optimizeRouteBtn";
    }

    const messageElement = document.getElementById(
        "routeFormWorkflowMessage",
    );
    if (messageElement) {
        messageElement.textContent = message;
    }
    const emergencyIndicator = document.getElementById(
        "routeFormEmergencyIndicator",
    );
    if (emergencyIndicator) {
        emergencyIndicator.hidden = !isEmergency;
    }
    document
        .querySelector(".route-priority-readout")
        ?.classList.toggle("is-emergency", isEmergency);
    setRouteWorkflowSteps(
        "routeFormWorkflowSteps",
        activeStep,
        isCompleted,
    );
    setRouteWorkflowPrimaryAction(primaryAction);
    refreshRouteTrafficCooldownControls();
}

function initRouteSectionNavigation() {
    const links = Array.from(
        document.querySelectorAll(".route-section-link[data-route-section]"),
    );
    links.forEach((link) => {
        link.addEventListener("click", (event) => {
            const target = document.getElementById(link.dataset.routeSection);
            if (!target) {
                return;
            }
            links.forEach((candidate) => {
                const isActive = candidate === link;
                candidate.classList.toggle("is-active", isActive);
                if (isActive) {
                    candidate.setAttribute("aria-current", "location");
                } else {
                    candidate.removeAttribute("aria-current");
                }
            });
            target.scrollIntoView({ behavior: "smooth", block: "start" });
            if (event.detail === 0) {
                target.focus({ preventScroll: true });
            }
        });
    });
}

window.updateRouteWorkflowPresentation = updateRouteWorkflowPresentation;

/* ==========================================
   SELECT HELPERS 
========================================== */
function fillRouteSelect(select, options, placeholder) {
    if (!select) {
        return;
    }

    const currentValue = select.value;
    select.innerHTML = `<option value="">${escapeRouteHtml(placeholder)}</option>`;
    options.forEach((optionValue) => {
        const option = document.createElement("option");
        option.value = String(optionValue);
        option.textContent = String(optionValue);
        select.appendChild(option);
    });

    if (
        currentValue &&
        [...select.options].some((option) => option.value === currentValue)
    ) {
        select.value = currentValue;
    }
}

function populateRouteFormOptions() {
    fillRouteSelect(
        document.getElementById("routeDepartment"),
        ROUTE_DEPARTMENTS,
        "Select department",
    );
}

/* ==========================================
   FIELD ERROR HELPERS
========================================== */
function clearRouteFieldErrors(form) {
    form?.querySelectorAll(".is-invalid").forEach((element) => {
        element.classList.remove("is-invalid");
    });
    form?.querySelectorAll(".field-error").forEach((element) => {
        element.textContent = "";
        element.style.display = "none";
    });
}

function showRouteFieldError(field, message) {
    if (!field) {
        return;
    }
    field.classList.add("is-invalid");
    let error = field.parentElement?.querySelector(
        ".field-error[data-field='" + field.id + "']",
    );
    if (!error) {
        error = document.createElement("div");
        error.className = "field-error";
        error.setAttribute("data-field", field.id);
        field.parentElement?.appendChild(error);
    }
    error.textContent = message;
    error.style.display = "block";
}

function applyLaravelValidationErrors(errors) {
    if (!errors || typeof errors !== "object") {
        return;
    }
    const fieldMap = {
        reservation_id: "routeReservation",
        origin: "routeOrigin",
        destination: "routeDestination",
        priority: "routePriority",
        department: "routeDepartment",
        status: "routeStatus",
        departure_date: "routeDepartureDate",
        departure_time: "routeDepartureTime",
        estimated_distance: "routeEstimatedDistance",
        estimated_time: "routeEstimatedTime",
        optimization_strategy: "routeOptStrategy",
        optimization_score: "routeOptScore",
        purpose: "routePurpose",
        notes: "routeNotes",
    };

    Object.entries(errors).forEach(([key, messages]) => {
        let baseKey = key;
        if (key.startsWith("stops.")) {
            baseKey = null;
        }
        if (!baseKey) {
            return;
        }
        const elementId = fieldMap[baseKey];
        if (!elementId) {
            return;
        }
        const field = document.getElementById(elementId);
        const message = Array.isArray(messages)
            ? messages[0]
            : String(messages);
        showRouteFieldError(field, message);
    });
}

function hasRouteCoordinateValue(value) {
    return (
        value !== null &&
        value !== undefined &&
        value !== "" &&
        Number.isFinite(Number(value))
    );
}

/* ==========================================
   STOPS
========================================== */
function getRouteStopsFromForm() {
    const list = document.getElementById("routeStopsList");

    if (!list) {
        return [];
    }
    return Array.from(list.querySelectorAll("input.route-stop-input"))
        .map((input) => input.value.trim())
        .filter(Boolean);
}

function renderRouteStops(stops) {
    const list = document.getElementById("routeStopsList");

    if (!list) {
        return;
    }

    list.innerHTML = "";
    const items = stops && stops.length ? stops : [""];
    items.forEach((stop, index) => {
        const row = document.createElement("div");
        row.className = "route-stop-row";
        const input = document.createElement("input");
        input.type = "text";
        input.className = "route-stop-input";
        input.id = `routeStop_${index}`;
        input.placeholder = `Stop ${index + 1}`;
        input.value = String(stop || "");
        input.setAttribute("aria-label", `Route stop ${index + 1}`);
        const button = document.createElement("button");
        button.type = "button";
        button.className = "btn-outline route-remove-stop";
        button.setAttribute("aria-label", `Remove stop ${index + 1}`);
        button.innerHTML = '<i class="ph ph-x" aria-hidden="true"></i>';
        row.appendChild(input);
        row.appendChild(button);
        list.appendChild(row);
    });
}

/* ==========================================
   RESERVATION HELPERS
========================================== */

function getSelectedRouteReservation() {
    const reservationId = document.getElementById("routeReservation")?.value;
    if (!reservationId) {
        return null;
    }
    return (
        routeAvailableReservations.find(
            (reservation) => String(reservation.id) === String(reservationId),
        ) || null
    );
}

function setRouteResourceSelect(select, value, label) {
    if (!select) {
        return;
    }
    select.innerHTML = "";
    const option = document.createElement("option");
    option.value = value || "";
    option.textContent = label || "Not assigned";
    option.selected = true;
    select.appendChild(option);
    select.disabled = true;
}

function applyReservationResourceDisplay(reservation) {
    const vehicle = reservation?.vehicle || null;
    const driver = reservation?.driver || null;
    const vehicleLabel = getRouteVehicleLabel(vehicle) || "No vehicle assigned";
    const driverLabel = getRouteDriverLabel(driver) || "No driver assigned";
    setRouteResourceSelect(
        document.getElementById("routeVehicle"),
        vehicle?.id ? String(vehicle.id) : "",
        vehicleLabel,
    );
    setRouteResourceSelect(
        document.getElementById("routeDriver"),
        driver?.id ? String(driver.id) : "",
        driverLabel,
    );
}

function resetRouteResourceDisplay() {
    setRouteResourceSelect(
        document.getElementById("routeVehicle"),
        "",
        "Select reservation first",
    );
    setRouteResourceSelect(
        document.getElementById("routeDriver"),
        "",
        "Select reservation first",
    );
}

function updateRouteStrategyPresentation() {
    const strategy = document.getElementById("routeOptStrategy");
    const presentation = document.getElementById("routeStrategyPresentation");
    if (!strategy || !presentation) {
        return;
    }
    const label = formatRouteStrategyLabel(strategy.value);
    const hasLegacyLabel = label !== strategy.value;
    strategy.hidden = hasLegacyLabel;
    presentation.hidden = !hasLegacyLabel;
    presentation.textContent = label;
}

function resetRouteOptimization() {
    routeOptimizationGeneration++;
    routeOptimizationRequestPending = false;
    clearRouteComparison();
    routeLastOptimization = null;
    const distance = document.getElementById("routeEstimatedDistance");
    const time = document.getElementById("routeEstimatedTime");
    const strategy = document.getElementById("routeOptStrategy");
    const score = document.getElementById("routeOptScore");
    const summary = document.getElementById("routeOptimizeSummary");
    const applyButton = document.getElementById("applyRouteOptimizationBtn");
    routePendingOptimization = null;
    if (distance) {
        distance.value = "";
        distance.dataset.raw = "";
    }
    if (time) {
        time.value = "";
        time.dataset.minutes = "";
    }
    if (strategy) {
        strategy.value = "";
    }
    if (score) {
        score.value = "";
        score.dataset.raw = "";
    }
    if (summary) {
        summary.hidden = true;
        summary.innerHTML = "";
    }
    if (applyButton) {
        applyButton.disabled = true;
    }
    const form = document.getElementById("routeForm");
    if (form) {
        form.dataset.persistedOptimization = "false";
    }
    updateRouteFormWorkflowUi();
}

function applyReservationToRouteForm(reservation) {
    if (!reservation) {
        resetRouteResourceDisplay();
        return;
    }
    const originInput = document.getElementById("routeOrigin");
    const destinationInput = document.getElementById("routeDestination");
    const priorityInput = document.getElementById("routePriority");
    const departmentInput = document.getElementById("routeDepartment");
    const departureDateInput = document.getElementById("routeDepartureDate");
    const departureTimeInput = document.getElementById("routeDepartureTime");
    if (originInput) {
    /*
    |--------------------------------------------------------------------------
    | Route Planning uses the HospitalFacility name when available.
    |
    | Normal Hospital/Facility Reservation:
    |   pickup_facility.name
    |
    | Supply Delivery / legacy fallback:
    |   pickup_location
    |--------------------------------------------------------------------------
    */
        originInput.value =
            reservation.pickup_facility?.name ||
            reservation.pickup_location ||
            "";
        delete originInput.dataset.latitude;
        delete originInput.dataset.longitude;
    }
    if (destinationInput) {
    /*
    |--------------------------------------------------------------------------
    | Use the HospitalFacility name when available.
    | Otherwise preserve the existing Reservation destination.
    |--------------------------------------------------------------------------
    */
        destinationInput.value =
            reservation.destination_facility?.name ||
            reservation.destination ||
            "";
        delete destinationInput.dataset.latitude;
        delete destinationInput.dataset.longitude;
    }
    if (priorityInput) {
        priorityInput.value =
            reservation.priority_label ||
            getRoutePriorityLabel(reservation.canonical_priority || reservation.priority);
    }
    if (departmentInput) {
        departmentInput.value = reservation.department || "";
    }
    if (departureDateInput) {
        departureDateInput.value = String(
            reservation.schedule_date || "",
        ).slice(0, 10);
    }
    if (departureTimeInput) {
        departureTimeInput.value = String(
            reservation.schedule_time || "",
        ).slice(0, 5);
    }
    applyReservationResourceDisplay(reservation);
    resetRouteOptimization();
    updateRouteFormWorkflowUi(reservation);
}

async function populateRouteReservations(selectedReservation = null) {
    const select = document.getElementById("routeReservation");
    if (!select) {
        return [];
    }
    select.disabled = true;
    select.innerHTML = '<option value="">Loading reservations...</option>';
    try {
        const available = await fetchAvailableRouteReservations();
        let reservations = available.slice();
        /*
    |--------------------------------------------------------------------------
    | Edit Mode
    |--------------------------------------------------------------------------
    | The selected RoutePlan already owns one Reservation,
    | so it will not appear in availableReservations().
    | Add the existing Reservation manually to the dropdown.
    */
        if (selectedReservation && selectedReservation.id) {
            const exists = reservations.some(
                (reservation) =>
                    String(reservation.id) === String(selectedReservation.id),
            );
            if (!exists) {
                reservations.unshift(selectedReservation);
            }
        }
        routeAvailableReservations = reservations;
        select.innerHTML =
            '<option value="">Select approved reservation</option>';
        reservations.forEach((reservation) => {
            const option = document.createElement("option");
            option.value = String(reservation.id);
            const number =
                reservation.reservation_number ||
                `Reservation #${reservation.id}`;
            const patient = reservation.patient_name
                ? ` — ${reservation.patient_name}`
                : "";
            option.textContent = number + patient;
            select.appendChild(option);
        });
        if (selectedReservation?.id) {
            select.value = String(selectedReservation.id);
        }
        if (routeFormMode === "edit") {
            select.disabled = true;
        } else {
            select.disabled = false;
        }
        if (reservations.length === 0 && routeFormMode === "add") {
            select.innerHTML =
                '<option value="">No approved reservations available</option>';
            select.disabled = true;
        }
        return reservations;
    } catch (error) {
        console.error("Unable to load Route Planning reservations:", error);
        select.innerHTML =
            '<option value="">Unable to load reservations</option>';
        select.disabled = true;
        if (typeof showToast === "function") {
            showToast(
                error.message || "Unable to load approved reservations.",
                "error",
            );
        }
        return [];
    }
}

/* ==========================================
   COLLECT FORM DATA
========================================== */
function collectRouteFormData() {
    const get = (id) => document.getElementById(id)?.value?.trim() || "";
    const stops = getRouteStopsFromForm();
    const optimization = routeLastOptimization;
    if (optimization?.optimizationApplied === true) {
        assertRouteOptimizationStops(optimization);
        if (
            stops.length !== optimization.optimizedStops.length ||
            stops.some((location, index) => location !== optimization.optimizedStops[index])
        ) {
            throw new Error("Route stops changed after optimization. Run optimization again.");
        }
    }
    let originCoordinate = null;
    let destinationCoordinate = null;
    /*
    |--------------------------------------------------------------------------
    | Only use coordinates generated by the current route optimization.
    |--------------------------------------------------------------------------
    |
    | HospitalFacility latitude/longitude and input dataset coordinates
    | are intentionally NOT used as routing sources.
    |
    |--------------------------------------------------------------------------
    */
    if (
        optimization?.optimizationApplied === true &&
        optimization?.routeCoordinates
    ) {
        originCoordinate = optimization.routeCoordinates.origin || null;
        destinationCoordinate =
            optimization.routeCoordinates.destination || null;
    }

    /*
    |--------------------------------------------------------------------------
    | Preview / pending optimization result
    |--------------------------------------------------------------------------
    */
    if (!originCoordinate) {
        originCoordinate =
            optimization?.originalRouteCoordinates?.origin ||
            optimization?.routeCoordinates?.origin ||
            null;
    }
    if (!destinationCoordinate) {
        destinationCoordinate =
            optimization?.originalRouteCoordinates?.destination ||
            optimization?.routeCoordinates?.destination ||
            null;
    }
    const originLatitude = originCoordinate?.lat ?? null;
    const originLongitude = originCoordinate?.lng ?? null;
    const destinationLatitude = destinationCoordinate?.lat ?? null;
    const destinationLongitude = destinationCoordinate?.lng ?? null;
    const stopCoordinates = stops.map((location, index) => {
        const stopCoordinate =
            optimization?.routeCoordinates?.stops?.[index]?.coordinate ||
            optimization?.originalRouteCoordinates?.stops?.[index]
                ?.coordinate ||
            null;
        return {
            location,
            latitude: stopCoordinate?.lat ?? null,
            longitude: stopCoordinate?.lng ?? null,
        };
    });

    return {
        reservationId: get("routeReservation"),
        routeNumber: get("routeNumber"),
        origin: get("routeOrigin"),
        destination: get("routeDestination"),
        originLatitude,
        originLongitude,
        destinationLatitude,
        destinationLongitude,
        stops,
        vehicle: get("routeVehicle"),
        driver: get("routeDriver"),
        priority: get("routePriority"),
        department: get("routeDepartment"),
        purpose: get("routePurpose"),
        departureDate: get("routeDepartureDate"),
        departureTime: get("routeDepartureTime"),
        status: get("routeStatus"),
        notes: get("routeNotes"),
        estimatedDistance:
            document.getElementById("routeEstimatedDistance")?.dataset.raw ||
            "",
        estimatedTravelTime: get("routeEstimatedTime"),
        estimatedTravelTimeMinutes:
            document.getElementById("routeEstimatedTime")?.dataset.minutes ||
            "",
        optimizationStrategy: get("routeOptStrategy"),
        optimizationScore:
            document.getElementById("routeOptScore")?.dataset.raw || "",
        optimizationApplied: optimization?.optimizationApplied === true,
        optimizationProof: optimization?.optimizationProof || null,
        stopCoordinates,
    };
}

/**
 * Calculate an explainable Route Planning score.
 *
 * This is an application-level score.
 * TomTom does not provide this score directly.
 *
 * Factors:
 * - Traffic condition
 * - Waypoint optimization
 * - Routing provider availability
 */
function calculateRouteOptimizationScore({
    provider,
    fallback,
    trafficDelayMinutes,
    liveTrafficTravelTimeSeconds,
    noTrafficTravelTimeSeconds,
    stopsCount,
    optimizedWaypoints,
}) {
    let score = 100;
    /*
    |--------------------------------------------------------------------------
    | Fallback penalty
    |--------------------------------------------------------------------------
    |
    | If TomTom is unavailable and OSRM is used,
    | traffic-aware routing could not be evaluated.
    |
    */
    if (fallback === true) {
        score -= 15;
    }
    /*
    |--------------------------------------------------------------------------
    | Traffic penalty
    |--------------------------------------------------------------------------
    |
    | Prefer the actual live-vs-no-traffic travel time
    | when both values are available.
    |
    */
    const liveTime =
        Number(
            liveTrafficTravelTimeSeconds
        );
    const noTrafficTime =
        Number(
            noTrafficTravelTimeSeconds
        );
    let trafficPenalty = 0;
    if (
        provider === "TomTom" &&
        Number.isFinite(liveTime) &&
        Number.isFinite(noTrafficTime) &&
        liveTime > 0 &&
        noTrafficTime > 0
    ) {
        const delayRatio =
            Math.max(
                0,
                (liveTime - noTrafficTime) /
                    liveTime
            );
        /*
        | Up to 30 points can be lost
        | from traffic congestion.
        */
        trafficPenalty = Math.min(
            30,
            delayRatio * 100
        );
    } else if (
        provider === "TomTom" &&
        Number.isFinite(
            Number(trafficDelayMinutes)
        ) &&
        Number(trafficDelayMinutes) > 0
    ) {
        /*
        | Fallback when TomTom's explicit
        | traffic delay is the only available metric.
        */
        trafficPenalty = Math.min(
            30,
            Number(trafficDelayMinutes) * 1.5
        );
    }
    score -= trafficPenalty;
    /*
    |--------------------------------------------------------------------------
    | Waypoint optimization
    |--------------------------------------------------------------------------
    |
    | This is only a small supporting factor.
    | It does not claim that TomTom's result is
    | globally optimal.
    |
    */
    if (
        provider === "TomTom" &&
        Number(stopsCount) >= 2
    ) {
        const waypointCount =
            Array.isArray(
                optimizedWaypoints
            )
                ? optimizedWaypoints.length
                : 0;

        if (
            waypointCount ===
            Number(stopsCount)
        ) {
            score += 2;
        }
    }
    return Math.max(
        0,
        Math.min(
            100,
            Math.round(score)
        )
    );
}

async function optimizeRouteWithOsrm(data, options = {}) {
    if (!data.origin || !data.destination) {
        throw new Error("Origin and destination are required.");
    }
    const stops = Array.isArray(data.stops)
        ? data.stops.map((stop) => String(stop || "").trim()).filter(Boolean)
        : [];
    /*
    |--------------------------------------------------------------------------
    | TomTom Traffic-Aware Routing
    |--------------------------------------------------------------------------
    |
    | calculateRouteWithTraffic() handles:
    |
    | 1. Geocoding
    | 2. TomTom traffic routing
    | 3. Waypoint optimization
    | 4. OSRM fallback
    |
    */
    const result = await calculateRouteWithTraffic(
        {
            origin: data.origin,
            destination: data.destination,
            originLatitude: null,
            originLongitude: null,
            destinationLatitude: null,
            destinationLongitude: null,
            stops,
            stopCoordinates: data.stopCoordinates,
            departureDate: data.departureDate,
            departureTime: data.departureTime,
            reservationId: data.reservationId,
            includeComparison: data.includeComparison === true,
        },
        {
            draw: true,
            silent: true,
            isCurrent: options.isCurrent,
        },
    );

    if (!result) {
        throw new Error("Unable to calculate the route.");
    }
    /*
    |--------------------------------------------------------------------------
    | Route coordinates
    |--------------------------------------------------------------------------
    */
    const routeCoordinates = result.routeCoordinates || null;
    /*
    |--------------------------------------------------------------------------
    | Determine optimized stop order
    |--------------------------------------------------------------------------
    */
    if (!Array.isArray(routeCoordinates?.stops) || routeCoordinates.stops.length !== stops.length) {
        throw new Error("The optimized route returned an incomplete stop sequence.");
    }
    const optimizedStops = routeCoordinates.stops.map((stop) => stop.location);

    /*
    |--------------------------------------------------------------------------
    | Validate route values
    |--------------------------------------------------------------------------
    */
    const estimatedDistance = Number(result.distanceKm);
    const estimatedTravelTimeMinutes = Number(result.durationMinutes);

    if (!Number.isFinite(estimatedDistance) || estimatedDistance <= 0) {
        throw new Error("The routing service returned an invalid distance.");
    }

    if (
        !Number.isFinite(estimatedTravelTimeMinutes) ||
        estimatedTravelTimeMinutes <= 0
    ) {
        throw new Error("The routing service returned an invalid travel time.");
    }

    /*
    |--------------------------------------------------------------------------
    | Strategy label
    |--------------------------------------------------------------------------
    */
    let optimizationStrategy;

    if (result.provider === "TomTom") {
        if (stops.length >= 2) {
            optimizationStrategy = "TomTom Traffic-Aware Optimized";
        } else if (stops.length === 1) {
            optimizationStrategy = "TomTom Traffic-Aware Multi-stop";
        } else {
            optimizationStrategy = "TomTom Traffic-Aware Fastest";
        }
    } else {
        optimizationStrategy = "OSRM Route — Existing Stop Order (Fallback)";
    }

    const optimizationScore = calculateRouteOptimizationScore({
        provider: result.provider || "OSRM",
        fallback: result.fallback === true,
        trafficDelayMinutes: result.trafficDelayMinutes,
        liveTrafficTravelTimeSeconds: result.liveTrafficTravelTimeSeconds,
        noTrafficTravelTimeSeconds: result.noTrafficTravelTimeSeconds,
        stopsCount: stops.length,
        optimizedWaypoints: result.optimizedWaypoints,
    });
    /*
    |--------------------------------------------------------------------------
    | Assigned Reservation resources
    |--------------------------------------------------------------------------
    */
    const vehicleSelect = document.getElementById("routeVehicle");
    const driverSelect = document.getElementById("routeDriver");
    /*
    |--------------------------------------------------------------------------
    | Traffic metadata
    |--------------------------------------------------------------------------
    */
    const trafficDelayMinutes = Number(result.trafficDelayMinutes);
    return {
        estimatedDistance,
        estimatedTravelTimeMinutes,
        estimatedTravelTime: formatRouteMinutes(estimatedTravelTimeMinutes),
        optimizationStrategy,
        optimizationScore,
        recommendedVehicle:
            vehicleSelect?.selectedOptions?.[0]?.textContent?.trim() || "—",
        recommendedDriver:
            driverSelect?.selectedOptions?.[0]?.textContent?.trim() || "—",
        optimizedStops,
        routeGeometry: result.geometry || null,
        routeCoordinates,
        originalRouteCoordinates:
            result.originalRouteCoordinates || routeCoordinates,
        provider: result.provider || "OSRM",
        fallback: result.fallback === true,
        trafficDelayMinutes: Number.isFinite(trafficDelayMinutes)
            ? trafficDelayMinutes
            : 0,
        liveTrafficTravelTimeSeconds: result.liveTrafficTravelTimeSeconds,
        noTrafficTravelTimeSeconds: result.noTrafficTravelTimeSeconds,
        optimizedWaypoints: Array.isArray(result.optimizedWaypoints)
            ? result.optimizedWaypoints
            : [],
        optimizationProof: result.optimizationProof || null,
        comparison: result.comparison || null,
    };
}

/* ==========================================
   OPTIMIZATION
========================================== */
function applyOptimizationToForm(result) {
    routeLastOptimization = result;
    const distance = document.getElementById("routeEstimatedDistance");
    const time = document.getElementById("routeEstimatedTime");
    const strategy = document.getElementById("routeOptStrategy");
    const score = document.getElementById("routeOptScore");
    const summary = document.getElementById("routeOptimizeSummary");
    if (distance) {
        distance.value = formatRouteDistance(result.estimatedDistance);

        distance.dataset.raw = String(result.estimatedDistance);
    }
    if (time) {
        time.value = result.estimatedTravelTime;

        time.dataset.minutes = String(result.estimatedTravelTimeMinutes);
    }
    if (strategy) {
        strategy.value = result.optimizationStrategy;
    }
    if (score) {
        score.value =
            result.optimizationScore != null
                ? String(result.optimizationScore)
                : "";
        score.dataset.raw =
            result.optimizationScore != null
                ? String(result.optimizationScore)
                : "";
    }
    if (summary) {
        const provider = result.provider || "Routing Service";
        const trafficDelay = Number(result.trafficDelayMinutes);
        const trafficLabel = result.fallback
            ? "Traffic-aware routing is unavailable. Stops remain in the supplied order."
            : Number.isFinite(trafficDelay) && trafficDelay > 0
              ? `${Math.round(trafficDelay)} min traffic delay detected.`
              : "No significant traffic delay detected.";
        summary.hidden = false;
        summary.innerHTML = `
            <strong>
                Route Calculation Complete
            </strong>
            <p>
                Provider:
                ${escapeRouteHtml(provider)}
            </p>
            <p>
                Strategy:
                ${escapeRouteHtml(formatRouteStrategyLabel(result.optimizationStrategy) || "—")}
                ${
                    result.optimizationScore != null
                        ? ` · Route Assessment Score:
                           ${escapeRouteHtml(result.optimizationScore)}/100`
                        : ""
                }
            </p>
            <p>
                Distance:
                ${escapeRouteHtml(
                    formatRouteDistance(result.estimatedDistance),
                )}
                · Time:
                ${escapeRouteHtml(result.estimatedTravelTime || "—")}
            </p>
            <p>
                Traffic:
                ${escapeRouteHtml(trafficLabel)}
            </p>
            <p>
                Assigned vehicle:
                ${escapeRouteHtml(result.recommendedVehicle || "—")}
                · Driver:
                ${escapeRouteHtml(result.recommendedDriver || "—")}
            </p>
            <p>
                Stop order:
                ${escapeRouteHtml(
                    Array.isArray(result.optimizedStops) &&
                        result.optimizedStops.length > 0
                        ? result.optimizedStops.join(" → ")
                        : "Direct route",
                )}
            </p>
        `;
    }
}

function assertRouteOptimizationStops(result) {
    const stops = result.routeCoordinates?.stops;
    if (
        !Array.isArray(result.optimizedStops) || !Array.isArray(stops) ||
        result.optimizedStops.length !== stops.length ||
        stops.some((stop, index) =>
            typeof stop?.location !== "string" ||
            stop.location !== result.optimizedStops[index] ||
            !Number.isFinite(stop.coordinate?.lat) || !Number.isFinite(stop.coordinate?.lng)
        )
    ) {
        throw new Error("Route stop labels do not match their optimized coordinates.");
    }
}

function applyPendingRouteOptimization({
    showToast: shouldShowToast = true,
} = {}) {
    if (!routePendingOptimization) {
        return false;
    }
    const result = routePendingOptimization;
    if (routeOptimizationRequestPending) {
        return false;
    }
    if (result.sourceFingerprint && result.sourceFingerprint !== routeCalculationFingerprint()) {
        resetRouteOptimization();
        throw new Error("Route inputs changed. Run optimization again before applying.");
    }
    assertRouteOptimizationStops(result);

    /*
    |--------------------------------------------------------------------------
    | Mark as applied
    |--------------------------------------------------------------------------
    */
    routeLastOptimization = {
        ...result,
        optimizationApplied: true,
    };
    /*
    |--------------------------------------------------------------------------
    | Render TomTom's optimized stop order
    |--------------------------------------------------------------------------
    */
    if (Array.isArray(result.optimizedStops)) {
        renderRouteStops(result.optimizedStops);
    }
    /*
    |--------------------------------------------------------------------------
    | Disable Apply button
    |--------------------------------------------------------------------------
    */
    const applyButton = document.getElementById("applyRouteOptimizationBtn");
    if (applyButton) {
        applyButton.disabled = true;
    }
    routePendingOptimization = null;
    /*
    |--------------------------------------------------------------------------
    | Update summary state
    |--------------------------------------------------------------------------
    */
    renderRouteComparison(result);
    const summary = document.getElementById("routeOptimizeSummary");
    if (summary) {
        const provider = result.provider || "Routing Service";
        const trafficDelay = Number(result.trafficDelayMinutes);
        const trafficLabel = result.fallback
            ? "Traffic-aware routing is unavailable. Stops remain in the supplied order."
            : Number.isFinite(trafficDelay) && trafficDelay > 0
              ? `${Math.round(trafficDelay)} min traffic delay detected.`
              : "No significant traffic delay detected.";

        summary.innerHTML = `
            <strong>
                ${result.fallback ? "Route Stop Order Applied" : "Optimization Applied"}
            </strong>

            <p>
                Provider:
                ${escapeRouteHtml(provider)}
            </p>

            <p>
                Strategy:
                ${escapeRouteHtml(formatRouteStrategyLabel(result.optimizationStrategy) || "—")}
                ${
                    result.optimizationScore != null
                        ? ` · Route Assessment Score:
                           ${escapeRouteHtml(result.optimizationScore)}/100`
                        : ""
                }
            </p>

            <p>
                Distance:
                ${escapeRouteHtml(
                    formatRouteDistance(result.estimatedDistance),
                )}

                · Time:
                ${escapeRouteHtml(result.estimatedTravelTime || "—")}
            </p>

            <p>
                Traffic:
                ${escapeRouteHtml(trafficLabel)}
            </p>

            <p>
                The route stop order is now
                applied to this route.
            </p>
            <p>
                Stop order:
                ${escapeRouteHtml(
                    Array.isArray(result.optimizedStops) &&
                        result.optimizedStops.length > 0
                        ? result.optimizedStops.join(" → ")
                        : "Direct route",
                )}
            </p>
        `;
    }
    updateRouteFormWorkflowUi();
    if (shouldShowToast && typeof window.showToast === "function") {
        window.showToast("Route stop order applied.", "success");
    }
    return true;
}

/* ==========================================
   FORM VALIDATION
========================================== */
function validateRouteForm() {
    const form = document.getElementById("routeForm");

    if (!form) {
        return false;
    }
    clearRouteFieldErrors(form);
    let firstInvalid = null;
    const fail = (id, message) => {
        const field = document.getElementById(id);
        showRouteFieldError(field, message);

        if (!firstInvalid && field) {
            firstInvalid = field;
        }
    };
    const data = collectRouteFormData();
    /*
  |--------------------------------------------------------------------------
  | Reservation
  |--------------------------------------------------------------------------
  */
    if (routeFormMode === "add" && !data.reservationId) {
        fail("routeReservation", "Reservation is required.");
    }
    /*
  |--------------------------------------------------------------------------
  | Route Information
  |--------------------------------------------------------------------------
  */
    if (!data.origin) {
        fail("routeOrigin", "Origin is required.");
    }
    if (!data.destination) {
        fail("routeDestination", "Destination is required.");
    }

    if (
        data.origin &&
        data.destination &&
        data.origin.toLowerCase() === data.destination.toLowerCase()
    ) {
        fail("routeDestination", "Destination must differ from origin.");
    }
    if (!data.department) {
        fail("routeDepartment", "Department is required.");
    }
    if (!data.departureDate) {
        fail("routeDepartureDate", "Departure date is required.");
    }
    if (!data.departureTime) {
        fail("routeDepartureTime", "Departure time is required.");
    }
    /*
  |--------------------------------------------------------------------------
  | Edit Status
  |--------------------------------------------------------------------------
  */
    if (routeFormMode === "edit" && !data.status) {
        fail("routeStatus", "Status is required.");
    }
    /*
  |--------------------------------------------------------------------------
  | Ready For Dispatch requires optimization
  |--------------------------------------------------------------------------
  |
  | Backend also validates this.
  |
  */
    if (data.status === "Ready For Dispatch") {
        if (
            data.estimatedDistance === "" ||
            data.estimatedTravelTimeMinutes === ""
        ) {
            fail(
                "routeEstimatedDistance",
                "Run Optimize Route before marking the route Ready For Dispatch.",
            );
        }
    }

    if (firstInvalid) {
        firstInvalid.focus();
    }
    return !firstInvalid;
}

/* ==========================================
   API PAYLOAD BUILDERS
========================================== */
function buildCreateRoutePayload(data) {
    return {
        reservation_id: Number(data.reservationId),
        department: data.department,
        origin_latitude: data.originLatitude,
        origin_longitude: data.originLongitude,
        destination_latitude: data.destinationLatitude,
        destination_longitude: data.destinationLongitude,
        estimated_distance:
            data.estimatedDistance === ""
                ? null
                : Number(data.estimatedDistance),
        estimated_time:
            data.estimatedTravelTimeMinutes === ""
                ? null
                : Number(data.estimatedTravelTimeMinutes),
        optimization_strategy: data.optimizationStrategy || null,
        optimization_score:
            data.optimizationScore === ""
                ? null
                : Number(data.optimizationScore),
        purpose: data.purpose || null,
        notes: data.notes || null,
        stops: data.stopCoordinates,
    };
}

function buildUpdateRoutePayload(data) {
    return {
        origin: data.origin,
        origin_latitude: data.originLatitude,
        origin_longitude: data.originLongitude,
        destination: data.destination,
        destination_latitude: data.destinationLatitude,
        destination_longitude: data.destinationLongitude,
        department: data.department,
        status: data.status,
        optimization_applied: data.optimizationApplied,
        optimization_proof: data.optimizationProof,
        estimated_distance:
            data.estimatedDistance === ""
                ? null
                : Number(data.estimatedDistance),
        estimated_time:
            data.estimatedTravelTimeMinutes === ""
                ? null
                : Number(data.estimatedTravelTimeMinutes),
        optimization_strategy: data.optimizationStrategy || null,
        optimization_score:
            data.optimizationScore === ""
                ? null
                : Number(data.optimizationScore),
        purpose: data.purpose || null,
        notes: data.notes || null,
        stops: data.stopCoordinates,
    };
}

/* ==========================================
   FORM MODAL
========================================== */
async function openRouteFormModal(mode, record = null) {
    if (mode === "add" && !canCreateRoute()) {
        return;
    }
    if (mode === "edit" && !canUpdateRoute()) {
        return;
    }
    const modal = document.getElementById("routeFormModal");
    const title = document.getElementById("routeFormModalTitle");
    const form = document.getElementById("routeForm");
    if (!modal || !form) {
        return;
    }
    invalidateRouteSaveContext();
    routeFormMode = mode;
    routeOptimizationGeneration++;
    routeOptimizationRequestPending = false;
    clearRouteComparison();
    const comparisonOption = document.getElementById("routeComparisonEnabled");
    if (comparisonOption) comparisonOption.checked = false;
    routeEditingId = mode === "edit" && record ? record.id : null;
    routeLastOptimization = null;
    routePendingOptimization = null;
    form.dataset.persistedOptimization = "false";
    const applyOptimizationButton = document.getElementById(
        "applyRouteOptimizationBtn",
    );
    if (applyOptimizationButton) {
        applyOptimizationButton.disabled = true;
    }
    clearRouteFieldErrors(form);
    populateRouteFormOptions();
    const reservationSelect = document.getElementById("routeReservation");
    const statusSelect = document.getElementById("routeStatus");
    const summary = document.getElementById("routeOptimizeSummary");
    if (summary) {
        summary.hidden = true;
        summary.innerHTML = "";
    }

    /*
  |--------------------------------------------------------------------------
  | EDIT
  |--------------------------------------------------------------------------
  */
    if (mode === "edit" && record) {
        if (title) {
            title.textContent = "Edit Route";
        }
        form.reset();
        let fullRoute = record;
        /*
    |--------------------------------------------------------------------------
    | Fetch latest database record before editing.
    |--------------------------------------------------------------------------
    */
        try {
            const fresh = await fetchRoutePlanById(record.id);

            if (fresh) {
                fullRoute = fresh;
            }
        } catch (error) {
            console.warn("Unable to refresh route before editing:", error);
        }
        const existingReservation = {
            id: fullRoute.reservationId,
            reservation_number: fullRoute.reservationNumber,
            patient_name: fullRoute.patientName,
            pickup_location: fullRoute.origin,
            destination: fullRoute.destination,
            priority: fullRoute.priority,
            priority_label: fullRoute.priorityLabel,
            canonical_priority: fullRoute.priority,
            is_emergency: fullRoute.isEmergency,
            schedule_date: fullRoute.departureDate,
            schedule_time: fullRoute.departureTime,
            vehicle: fullRoute.vehicleId
                ? {
                      id: fullRoute.vehicleId,
                      vehicle_name: fullRoute.vehicle,
                  }
                : null,
            driver: fullRoute.driverId
                ? {
                      id: fullRoute.driverId,
                      name: fullRoute.driver,
                  }
                : null,
        };
        await populateRouteReservations(existingReservation);
        if (reservationSelect) {
            reservationSelect.value = String(fullRoute.reservationId || "");
            reservationSelect.disabled = true;
        }
        const routeNumber = document.getElementById("routeNumber");
        if (routeNumber) {
            routeNumber.value = fullRoute.routeNumber || "";
        }
        document.getElementById("routeOrigin").value = fullRoute.origin || "";
        document.getElementById("routeDestination").value =
            fullRoute.destination || "";
        renderRouteStops(fullRoute.stops || []);
        applyReservationResourceDisplay(existingReservation);
        document.getElementById("routePriority").value =
            fullRoute.priorityLabel || getRoutePriorityLabel(fullRoute.priority);
        document.getElementById("routeDepartment").value =
            fullRoute.department || "";
        document.getElementById("routePurpose").value = fullRoute.purpose || "";
        document.getElementById("routeDepartureDate").value =
            fullRoute.departureDate || "";
        document.getElementById("routeDepartureTime").value =
            fullRoute.departureTime || "";
        if (statusSelect) {
            const currentStatus = fullRoute.status || "Draft";
            statusSelect.disabled = false;
            applyRouteStatusTransitions(
                statusSelect,
                currentStatus,
                fullRoute.isEmergency,
            );
        }
        document.getElementById("routeNotes").value = fullRoute.notes || "";

        /*
    |--------------------------------------------------------------------------
    | Optimization
    |--------------------------------------------------------------------------
    */
        const distance = document.getElementById("routeEstimatedDistance");
        const time = document.getElementById("routeEstimatedTime");
        const strategy = document.getElementById("routeOptStrategy");
        const score = document.getElementById("routeOptScore");

        if (distance) {
            distance.value =
                fullRoute.estimatedDistance === null
                    ? ""
                    : formatRouteDistance(fullRoute.estimatedDistance);
            distance.dataset.raw =
                fullRoute.estimatedDistance === null
                    ? ""
                    : String(fullRoute.estimatedDistance);
        }
        if (time) {
            time.value = fullRoute.estimatedTravelTime || "";
            time.dataset.minutes =
                fullRoute.estimatedTravelTimeMinutes === null
                    ? ""
                    : String(fullRoute.estimatedTravelTimeMinutes);
        }
        if (strategy) {
            strategy.value = fullRoute.optimizationStrategy || "";
        }
        if (score) {
            score.value =
                fullRoute.optimizationScore === null
                    ? ""
                    : String(fullRoute.optimizationScore);
            score.dataset.raw =
                fullRoute.optimizationScore === null
                    ? ""
                    : String(fullRoute.optimizationScore);
        }
        /*
        |--------------------------------------------------------------------------
        | Restore persisted route optimization + coordinates
        |--------------------------------------------------------------------------
        */
        const hasOriginCoordinates =
            hasRouteCoordinateValue(fullRoute.originLatitude) &&
            hasRouteCoordinateValue(fullRoute.originLongitude);
        const hasDestinationCoordinates =
            hasRouteCoordinateValue(fullRoute.destinationLatitude) &&
            hasRouteCoordinateValue(fullRoute.destinationLongitude);
        const restoredStopCoordinates = Array.isArray(fullRoute.stopCoordinates)
            ? fullRoute.stopCoordinates.map((stop) => {
                  const hasCoordinates =
                      hasRouteCoordinateValue(stop?.latitude) &&
                      hasRouteCoordinateValue(stop?.longitude);
                  return {
                      location: String(stop?.location || "").trim(),
                      coordinate: hasCoordinates
                          ? {
                                lat: Number(stop.latitude),
                                lng: Number(stop.longitude),
                            }
                          : null,
                  };
              })
            : [];
        routeLastOptimization = {
            estimatedDistance: fullRoute.estimatedDistance,
            estimatedTravelTimeMinutes: fullRoute.estimatedTravelTimeMinutes,
            estimatedTravelTime: fullRoute.estimatedTravelTime,
            optimizationStrategy: fullRoute.optimizationStrategy,
            optimizationScore: fullRoute.optimizationScore,
            optimizedStops: Array.isArray(fullRoute.stops)
                ? fullRoute.stops.slice()
                : [],
            optimizationApplied: false,
            optimizationProof: null,
            routeCoordinates: {
                origin: hasOriginCoordinates
                    ? {
                          lat: Number(fullRoute.originLatitude),
                          lng: Number(fullRoute.originLongitude),
                          displayName: fullRoute.origin,
                      }
                    : null,
                stops: restoredStopCoordinates,
                destination: hasDestinationCoordinates
                    ? {
                          lat: Number(fullRoute.destinationLatitude),
                          lng: Number(fullRoute.destinationLongitude),
                          displayName: fullRoute.destination,
                      }
                    : null,
            },
        };
        form.dataset.persistedOptimization = String(
            fullRoute.estimatedDistance !== null &&
                fullRoute.estimatedDistance !== undefined &&
                String(fullRoute.optimizationStrategy || "").trim() !== "",
        );
    } else {

    /*
  |--------------------------------------------------------------------------
  | ADD
  |--------------------------------------------------------------------------
  */
        if (title) {
            title.textContent = "New Route";
        }

        form.reset();

        resetRouteResourceDisplay();
        resetRouteOptimization();
        renderRouteStops([""]);
        if (statusSelect) {
            statusSelect.value = "Draft";
            statusSelect.disabled = true;
        }
        await populateRouteReservations();
        const routeNumber = document.getElementById("routeNumber");
        if (routeNumber) {
            routeNumber.value = "Loading...";
        }
        try {
            const nextNumber = await fetchNextRouteNumber();
            if (routeNumber) {
                routeNumber.value = nextNumber;
            }
        } catch (error) {
            if (routeNumber) {
                routeNumber.value = "";
            }
            console.error("Unable to load next Route Number:", error);
        }
        const priority = document.getElementById("routePriority");
        if (priority) {
            priority.value = "";
        }

        /*
    |--------------------------------------------------------------------------
    | Reservation-derived fields remain blank
    | until a Reservation is selected.
    |--------------------------------------------------------------------------
    */
        const origin = document.getElementById("routeOrigin");
        const destination = document.getElementById("routeDestination");
        const departureDate = document.getElementById("routeDepartureDate");
        const departureTime = document.getElementById("routeDepartureTime");

        if (origin) {
            origin.value = "";
        }
        if (destination) {
            destination.value = "";
        }
        if (departureDate) {
            departureDate.value = "";
        }
        if (departureTime) {
            departureTime.value = "";
        }
    }

    updateRouteFormWorkflowUi(
        mode === "edit" && record ? getSelectedRouteReservation() : null,
    );

    modal.classList.add("show");
    document.body.style.overflow = "hidden";
    requestAnimationFrame(() => {
        if (routeFormMode === "add") {
            document.getElementById("routeReservation")?.focus();
        } else {
            document.getElementById("routeOrigin")?.focus();
        }
    });
}

function closeRouteFormModal() {
    invalidateRouteSaveContext();
    window.clearTimeout?.(routeTrafficCooldownTimer);
    routeTrafficCooldownTimer = null;
    routeOptimizationGeneration++;
    routeOptimizationRequestPending = false;
    clearRouteComparison();
    const modal = document.getElementById("routeFormModal");

    if (!modal) {
        return;
    }
    modal.classList.remove("show");
    document.body.style.overflow = "";
    routeEditingId = null;
    routeFormMode = "add";
    routeLastOptimization = null;
    routePendingOptimization = null;
    modal.querySelector("form")?.removeAttribute(
        "data-persisted-optimization",
    );
    const applyOptimizationButton = document.getElementById(
        "applyRouteOptimizationBtn",
    );
    if (applyOptimizationButton) {
        applyOptimizationButton.disabled = true;
    }
}

/* ==========================================
   VIEW MODAL
========================================== */

function openViewRouteModal(record) {
    const modal = document.getElementById("viewRouteModal");

    if (!modal || !record) {
        return;
    }

    const set = (id, value) => {
        const element = document.getElementById(id);

        if (element) {
            element.textContent =
                value === null || value === undefined || value === ""
                    ? "—"
                    : value;
        }
    };
    set("viewRouteNumber", record.routeNumber);
    set("viewRouteReservationNumber", record.reservationNumber);
    set("viewRouteStatus", record.status);
    set("viewRoutePriority", record.priorityLabel || getRoutePriorityLabel(record.priority));
    set("viewRouteOrigin", record.origin);
    set("viewRouteDestination", record.destination);
    set(
        "viewRouteStops",
        (record.stops || []).filter(Boolean).join(" → ") || "None",
    );
    set("viewRouteVehicle", record.vehicle);
    set("viewRouteDriver", record.driver);
    set("viewRouteDepartment", record.department);
    set("viewRoutePurpose", record.purpose);
    set(
        "viewRouteDeparture",
        formatRouteDeparture(record.departureDate, record.departureTime),
    );
    set("viewRouteDistance", formatRouteDistance(record.estimatedDistance));
    set("viewRouteTime", record.estimatedTravelTime);
    set("viewRouteStrategy", formatRouteStrategyLabel(record.optimizationStrategy));
    set("viewRouteScore", record.optimizationScore);
    set("viewRouteNotes", record.notes);
    set("viewRouteCreated", formatRouteCreated(record.createdAt));
    set("viewRouteUpdated", formatRouteCreated(record.updatedAt));

    /*
  |--------------------------------------------------------------------------
  | Timeline
  |--------------------------------------------------------------------------
  |
  | Since status history is not yet persisted in MySQL,
  | show basic current-state information.
  |
  */
    const timeline = document.getElementById("viewRouteTimeline");
    if (timeline) {
        const lifecycle = [
            "Draft",
            "Planned",
            "Ready For Dispatch",
            "Completed",
        ];
        let visibleStatuses = [];
        if (record.status === "Archived") {
            visibleStatuses = ["Draft", "Archived"];
        } else {
            const currentIndex = lifecycle.indexOf(record.status);
            if (currentIndex >= 0) {
                visibleStatuses = lifecycle.slice(0, currentIndex + 1);
            } else {
                visibleStatuses = [record.status || "Draft"];
            }
        }

        timeline.innerHTML = visibleStatuses
            .map((status, index) => {
                const isFirst = index === 0;
                const isCurrent = status === record.status;
                let dateLabel = "";
                if (isFirst) {
                    dateLabel = formatRouteDateTime(record.createdAt);
                } else if (isCurrent) {
                    dateLabel = formatRouteDateTime(record.updatedAt);
                } else {
                    dateLabel = "Previously completed";
                }
                return `
            <li class="${isCurrent ? "current" : ""}">
              <strong>
                ${escapeRouteHtml(status)}
              </strong>
              <span>
                ${escapeRouteHtml(dateLabel)}
              </span>
            </li>
          `;
            })
            .join("");
    }

    const archiveButton = document.getElementById("archiveRouteFromViewBtn");
    const restoreButton = document.getElementById("restoreRouteFromViewBtn");
    const editButton = document.getElementById("editRouteFromViewBtn");
    const duplicateButton = document.getElementById(
        "duplicateRouteFromViewBtn",
    );
    if (duplicateButton) {
        duplicateButton.hidden = !canDuplicateRoute();
    }
    if (archiveButton) {
        archiveButton.hidden =
            !canArchiveRoute() ||
            record.status === "Archived" ||
            record.status === "Completed";
    }
    if (restoreButton) {
        restoreButton.hidden =
            !canRestoreRoute() || record.status !== "Archived";
    }
    if (editButton) {
        editButton.hidden = !canUpdateRoute();
        editButton.disabled =
            !canUpdateRoute() ||
            ["Completed", "Archived"].includes(record.status);
    }

    modal.dataset.routeId = record.id;
    modal.classList.add("show");
    document.body.style.overflow = "hidden";
}

function closeViewRouteModal() {
    const modal = document.getElementById("viewRouteModal");
    if (!modal) {
        return;
    }
    modal.classList.remove("show");
    document.body.style.overflow = "";
    delete modal.dataset.routeId;
}

/* ==========================================
   DELETE MODAL
========================================== */
/*
function openDeleteRouteModal(record) {
    if (!canDeleteRoute()) {
        return;
    }
    const modal = document.getElementById("deleteRouteModal");

    if (!modal || !record) {
        return;
    }
    routeDeleteTargetId = record.id;
    const description = document.getElementById("deleteRouteModalDescription");
    if (description) {
        description.innerHTML =
            "Are you sure you want to delete route <strong>" +
            escapeRouteHtml(record.routeNumber) +
            "</strong> (" +
            escapeRouteHtml(record.origin) +
            " → " +
            escapeRouteHtml(record.destination) +
            ")?";
    }
    modal.classList.add("show");
    document.body.style.overflow = "hidden";
    document.getElementById("cancelDeleteRoute")?.focus();
}

function closeDeleteRouteModal() {
    const modal = document.getElementById("deleteRouteModal");

    if (!modal) {
        return;
    }

    modal.classList.remove("show");
    document.body.style.overflow = "";
    routeDeleteTargetId = null;
}
*/ 
async function saveRouteFromForm() {
    if (routeOptimizationRequestPending) {
        window.showToast?.("Wait for the current route calculation before saving.", "warning");
        return;
    }
    if (routeSaveOperation && isRouteSaveCurrent(routeSaveOperation, false)) {
        window.showToast?.("Wait for the current route save before saving again.", "warning");
        return;
    }
    verifyRouteComparisonSnapshot();
    if (routeFormMode === "add" && !canCreateRoute()) {
        return;
    }
    if (routeFormMode === "edit" && !canUpdateRoute()) {
        return;
    }
    const form = document.getElementById("routeForm");
    if (!form) {
        return;
    }
    /*
    |--------------------------------------------------------------------------
    | Prefer optimized routes
    |--------------------------------------------------------------------------
    |
    | If enabled, automatically attempt optimization when the current
    | route has no valid optimization result.
    |
    | This is a preference, not a hard requirement.
    |
    */
    let data = collectRouteFormData();
    if (routePendingOptimization) {
        if (typeof showToast === "function") {
            showToast(
                "Apply the route optimization before saving this route.",
                "warning",
            );
        }
        document.getElementById("applyRouteOptimizationBtn")?.focus();
        return;
    }
    const operation = {
        generation: routeFormGeneration,
        mode: routeFormMode,
        routeId: routeEditingId,
        reservationId: document.getElementById("routeReservation")?.value,
        fingerprint: routeCalculationFingerprint(),
        optimizationGeneration: routeOptimizationGeneration,
        submitButton: form.querySelector('[type="submit"]'),
        ownsSubmitButton: false,
        submitWasDisabled: false,
    };
    routeSaveOperation = operation;
    try {
        const needsOptimization =
            data.origin &&
            data.destination &&
            (data.estimatedDistance === "" ||
                data.estimatedTravelTimeMinutes === "");
        if (routeModuleSettings.preferOptimized && needsOptimization) {
            try {
                const calculation = runRouteOptimization({
                    showSuccessToast: false,
                });
                operation.optimizationGeneration = routeOptimizationGeneration;
                await calculation;
                if (!isRouteSaveCurrent(operation)) return;
                applyPendingRouteOptimization({
                    showToast: false,
                });
                operation.fingerprint = routeCalculationFingerprint();
                /*
                |--------------------------------------------------------------------------
                | Recollect because optimization updated the fields + stop order
                |--------------------------------------------------------------------------
                */
                data = collectRouteFormData();
            } catch (error) {
                if (!isRouteSaveCurrent(operation)) return;
                if (error?.status === 429) {
                    window.showToast?.(error.message, "warning");
                    return;
                }
                console.warn(
                    "Preferred route optimization was unavailable:",
                    error,
                );
                /*
                |--------------------------------------------------------------------------
                | Draft / Planned routes may continue.
                | Ready For Dispatch will still fail normal validation below.
                |--------------------------------------------------------------------------
                */
                if (data.status === "Ready For Dispatch") {
                    if (typeof showToast === "function") {
                        showToast(
                            error.message ||
                                "Route optimization is required before this route can be marked Ready For Dispatch.",
                            "error",
                        );
                    }
                    return;
                }
            }
        }
        if (!isRouteSaveCurrent(operation)) return;
        if (!validateRouteForm()) {
            return;
        }
        /*
        |--------------------------------------------------------------------------
        | Collect again after validation / possible optimization
        |--------------------------------------------------------------------------
        */
        data = collectRouteFormData();
        clearRouteFieldErrors(form);
        const submitButton = operation.submitButton;
        if (submitButton) {
            operation.submitWasDisabled = submitButton.disabled;
            operation.ownsSubmitButton = true;
            submitButton.disabled = true;
        }

        if (!isRouteSaveCurrent(operation)) return;
        if (operation.mode === "edit" && operation.routeId) {
            const payload = buildUpdateRoutePayload(data);
            const currentRecord = getRouteRecordById(operation.routeId);
            const isLifecycleAction =
                String(currentRecord?.status || "Draft") !== data.status ||
                data.optimizationApplied === true;
            const result = isLifecycleAction
                ? await transitionRoutePlanApi(operation.routeId, payload)
                : await updateRoutePlanApi(operation.routeId, payload);
            if (!isRouteSaveCurrent(operation)) return;
            const updated = result.routePlan;
            closeRouteFormModal();
            const completedGeneration = routeFormGeneration;
            await reloadRoutePlanningData({
                resetPage: false,

                focusId: updated?.id || operation.routeId,

                reason: "edit",
            });
            if (completedGeneration !== routeFormGeneration) return;

            if (typeof showToast === "function") {
                showToast(
                    result.message || "Route updated successfully.",
                    "success",
                );
            }

            return;
        }

        const payload = buildCreateRoutePayload(data);
        const result = await createRoutePlanApi(payload);
        if (!isRouteSaveCurrent(operation)) return;
        const created = result.routePlan;
        closeRouteFormModal();
        const completedGeneration = routeFormGeneration;
        await reloadRoutePlanningData({
            resetPage: true,
            focusId: created?.id,
            reason: "add",
        });
        if (completedGeneration !== routeFormGeneration) return;

        if (typeof showToast === "function") {
            showToast(
                result.message || "Route plan created successfully.",
                "success",
            );
        }
    } catch (error) {
        if (!isRouteSaveCurrent(operation)) return;
        console.error("Unable to save Route Plan:", error);

        applyLaravelValidationErrors(error.errors);

        if (typeof showToast === "function") {
            showToast(error.message || "Unable to save Route Plan.", "error");
        }
    } finally {
        if (routeSaveOperation === operation) {
            if (operation.ownsSubmitButton && operation.generation === routeFormGeneration) {
                operation.submitButton.disabled = operation.submitWasDisabled;
            }
            routeSaveOperation = null;
        }
    }
}

/* ==========================================
   DUPLICATE
========================================== */
/**
 * Ask the user which Approved Reservation
 * should own the duplicated RoutePlan.
 *
 * For now this uses a simple prompt so we do not
 * need to redesign the existing UI.
 *
 * Later we can replace this with a proper modal.
 */
async function duplicateRouteFromView(routeId) {
    if (!canDuplicateRoute()) {
        return;
    }
    try {
        const reservations = await fetchAvailableRouteReservations();
        if (reservations.length === 0) {
            if (typeof showToast === "function") {
                showToast(
                    "No Approved reservation is available for duplication.",
                    "warning",
                );
            }
            return;
        }

        const choices = reservations
            .map((reservation, index) => {
                return (
                    `${index + 1}. ` +
                    `${reservation.reservation_number}` +
                    (reservation.patient_name
                        ? ` — ${reservation.patient_name}`
                        : "")
                );
            })
            .join("\n");

        const input = await window.showInputPrompt({
            title: "Duplicate Route Plan",
            message: "Select the target reservation by number:\n\n" + choices,
            label: "Reservation number",
            placeholder: "Enter a reservation number",
            confirmText: "Duplicate Route",
            inputMode: "numeric",
        });

        if (input === null) {
            return;
        }
        const choice = Number.parseInt(input, 10);
        if (
            Number.isNaN(choice) ||
            choice < 1 ||
            choice > reservations.length
        ) {
            if (typeof showToast === "function") {
                showToast("Invalid reservation selection.", "warning");
            }
            return;
        }

        const selected = reservations[choice - 1];
        const result = await duplicateRoutePlanApi(routeId, selected.id);
        closeViewRouteModal();
        await reloadRoutePlanningData({
            resetPage: true,
            focusId: result.routePlan?.id,
            reason: "duplicate",
        });

        if (typeof showToast === "function") {
            showToast(
                result.message || "Route duplicated successfully.",
                "success",
            );
        }
    } catch (error) {
        console.error("Unable to duplicate Route Plan:", error);

        if (typeof showToast === "function") {
            showToast(
                error.message || "Unable to duplicate Route Plan.",
                "error",
            );
        }
    }
}

async function runRouteOptimization({ showSuccessToast = true } = {}) {
    const data = collectRouteFormData();
    data.includeComparison = document.getElementById("routeComparisonEnabled")?.checked === true;
    const generation = ++routeOptimizationGeneration;
    const fingerprint = routeCalculationFingerprint();
    const isCurrent = () => generation === routeOptimizationGeneration && fingerprint === routeCalculationFingerprint();
    clearRouteComparison();
    if (!data.origin || !data.destination) {
        throw new Error(
            "Origin and destination are required before calculating the route.",
        );
    }
    /*
    |--------------------------------------------------------------------------
    | Calculate using Nominatim + OSRM.
    | optimizeRouteWithOsrm() already draws the result.
    |--------------------------------------------------------------------------
    */
    let result;
    routeOptimizationRequestPending = true;
    try {
        result = await optimizeRouteWithOsrm(data, { isCurrent });
        if (!isCurrent()) throw new Error("Route inputs changed during calculation. Run Optimize again.");
    } catch (error) {
        if (error?.status === 429 && isCurrent()) {
            routePendingOptimization = null;
            const applyButton = document.getElementById("applyRouteOptimizationBtn");
            if (applyButton) applyButton.disabled = true;
            if (!routeLastOptimization?.optimizationApplied) {
                const summary = document.getElementById("routeOptimizeSummary");
                if (summary) summary.hidden = true;
            }
            clearRouteComparison();
            updateRouteFormWorkflowUi();
        }
        throw error;
    } finally {
        if (generation === routeOptimizationGeneration) routeOptimizationRequestPending = false;
        refreshRouteTrafficCooldownControls();
    }
    result.sourceFingerprint = fingerprint;
    if (
        Array.isArray(data.stops) &&
        Array.isArray(result.optimizedStops) &&
        data.stops.length !== result.optimizedStops.length
    ) {
        throw new Error(
            "The optimized route returned an incomplete stop sequence.",
        );
    }
    /*
    |--------------------------------------------------------------------------
    | Store latest optimization result including coordinates
    |--------------------------------------------------------------------------
    */
    applyOptimizationToForm(result);

    routePendingOptimization = {
        ...result,
        optimizationApplied: false,
    };
    renderRouteComparison(result);
    const applyButton = document.getElementById("applyRouteOptimizationBtn");
    if (applyButton) {
        applyButton.disabled = false;
    }
    updateRouteFormWorkflowUi();
    /*
    |--------------------------------------------------------------------------
    | Update map labels only.
    | Do NOT call updateRouteMapPanel() here,
    | because route is already drawn.
    |--------------------------------------------------------------------------
    */
    const mapDistance = document.getElementById("mapDistanceLabel");
    const mapEta = document.getElementById("mapEtaLabel");
    const mapStatus = document.getElementById("mapStatusLabel");
    const mapStrategy = document.getElementById("mapStrategyLabel");
    if (mapDistance) {
        mapDistance.textContent = formatRouteDistance(result.estimatedDistance);
    }
    if (mapEta) {
        mapEta.textContent = result.estimatedTravelTime || "—";
    }
    if (mapStatus) {
        mapStatus.textContent = data.status || "Draft";
    }
    if (mapStrategy) {
        mapStrategy.textContent = result.optimizationStrategy
            ? formatRouteStrategyLabel(result.optimizationStrategy) +
              (result.optimizationScore != null
                  ? ` · Assessment: ${result.optimizationScore}/100`
                  : "")
            : "—";
    }
    /*
    |--------------------------------------------------------------------------
    | Optimization summary
    |--------------------------------------------------------------------------
    */
    updateOptimizationSummaryPanel({
        estimatedDistance: result.estimatedDistance,
        estimatedTravelTime: result.estimatedTravelTime,
        optimizationStrategy: result.optimizationStrategy,
        optimizationScore: result.optimizationScore,
        vehicle: result.recommendedVehicle,
        driver: result.recommendedDriver,
    });
    if (showSuccessToast && typeof showToast === "function") {
        showToast(
            "Route ready. Review the result, then apply the stop order.",
            "success",
        );
    }
    return result;
}

function initRoutePlanningModals() {
    if (routeModalsInitialized) {
        return;
    }
    if (!document.getElementById("routePlanningPage")) {
        return;
    }
    routeModalsInitialized = true;
    document.getElementById("routeComparisonEnabled")?.addEventListener("change", resetRouteOptimization);
    const comparisonForm = document.getElementById("routeForm");
    if (comparisonForm && typeof MutationObserver === "function") {
        new MutationObserver(() => invalidateRouteComparisonContext()).observe(comparisonForm, {
            subtree: true,
            attributes: true,
            attributeFilter: ["data-latitude", "data-longitude"],
        });
    }
    populateRouteFormOptions();
    initRouteSectionNavigation();
    updateRouteWorkflowPresentation(null);

    /*
  |--------------------------------------------------------------------------
  | New Route
  |--------------------------------------------------------------------------
  */
    if (canCreateRoute()) {
        document
            .getElementById("newRouteBtn")
            ?.addEventListener("click", async () => {
                await openRouteFormModal("add");
            });
    }
    document
        .getElementById("routeReservation")
        ?.addEventListener("change", () => {
            if (routeFormMode !== "add") {
                return;
            }
            const reservation = getSelectedRouteReservation();

            if (!reservation) {
                resetRouteResourceDisplay();
                resetRouteOptimization();
                updateRouteFormWorkflowUi();
                return;
            }
            applyReservationToRouteForm(reservation);
        });
    document
        .getElementById("closeRouteFormModal")
        ?.addEventListener("click", closeRouteFormModal);
    document
        .getElementById("cancelRouteForm")
        ?.addEventListener("click", closeRouteFormModal);
    document
        .getElementById("routeFormModal")
        ?.addEventListener("click", (event) => {
            if (event.target.id === "routeFormModal") {
                closeRouteFormModal();
            }
        });
  document
        .getElementById("addRouteStopBtn")
        ?.addEventListener("click", () => {
            const stops = getRouteStopsFromForm();
            stops.push("");
            renderRouteStops(stops);
            resetRouteOptimization();
        });
    document
        .getElementById("routeStopsList")
        ?.addEventListener("click", (event) => {
            const button = event.target.closest(".route-remove-stop");
            if (!button) {
                return;
            }
            const row = button.closest(".route-stop-row");
            row?.remove();
            const remaining = getRouteStopsFromForm();
            if (remaining.length === 0) {
                renderRouteStops([""]);
            }
            resetRouteOptimization();
        });
    [
        "routeOrigin",
        "routeDestination",
        "routeDepartureDate",
        "routeDepartureTime",
    ].forEach((id) => {
        const field = document.getElementById(id);
        field?.addEventListener("input", () => {
            /*
        |----------------------------------------------------------------------
        | Origin/Destination text changed manually.
        | Do not keep coordinates from the previous hospital.
        |----------------------------------------------------------------------
        */
            if (id === "routeOrigin" || id === "routeDestination") {
                delete field.dataset.latitude;
                delete field.dataset.longitude;
            }
            resetRouteOptimization();
        });
        field?.addEventListener("change", () => {
            if (id === "routeOrigin" || id === "routeDestination") {
                delete field.dataset.latitude;
                delete field.dataset.longitude;
            }
            resetRouteOptimization();
        });
    });
    document
        .getElementById("routeStopsList")
        ?.addEventListener("input", (event) => {
            if (event.target.matches(".route-stop-input")) {
                resetRouteOptimization();
            }
        });
    document
        .getElementById("optimizeRouteBtn")
        ?.addEventListener("click", async () => {
            const optimizeButton = document.getElementById("optimizeRouteBtn");
            const originalHtml = optimizeButton?.innerHTML;
            try {
                if (optimizeButton) {
                    optimizeButton.disabled = true;

                    optimizeButton.innerHTML = `
                        <i class="ph ph-spinner"></i>
                        Optimizing...
                    `;
                }
                await runRouteOptimization({
                    showSuccessToast: true,
                });
            } catch (error) {
                if (error?.status !== 429) console.error("Route calculation failed:", error);

                if (typeof showToast === "function") {
                    showToast(
                        error.message || "Unable to calculate the route.",
                        error?.status === 429 ? "warning" : "error",
                    );
                }
            } finally {
                if (optimizeButton) {
                    optimizeButton.disabled = false;

                    if (originalHtml !== undefined) {
                        optimizeButton.innerHTML = originalHtml;
                    }
                    refreshRouteTrafficCooldownControls();
                }
            }
        });
    document
        .getElementById("applyRouteOptimizationBtn")
        ?.addEventListener("click", () => {
            applyPendingRouteOptimization({
                showToast: true,
            });
        });
    document
        .getElementById("routeForm")
        ?.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (routeFormMode === "add" && !canCreateRoute()) {
                return;
            }
            if (routeFormMode === "edit" && !canUpdateRoute()) {
                return;
            }
            await saveRouteFromForm();
        });
    document
        .getElementById("routeTableBody")
        ?.addEventListener("click", async (event) => {
            const viewButton = event.target.closest(".view-route");
            const editButton = event.target.closest(".edit-route");
            const deleteButton = event.target.closest(".delete-route");
            const row = event.target.closest("tr[data-route-id]");
            if (!row) {
                return;
            }
            const record = getRouteRecordById(row.dataset.routeId);
            if (!record) {
                return;
            }
            /*
            |--------------------------------------------------------------------------
            | Route History → Map Selection
            |--------------------------------------------------------------------------
            |
            | Only selecting/viewing a route changes the map.
            | Edit/Delete actions remain modal-only actions.
            |
            */
            if (!editButton && !deleteButton) {
                setSelectedRoutePlanningRecord(record.id);
                await updateRouteMapPanel(record);
                updateOptimizationSummaryPanel(record);
            }
            if (viewButton) {
                openViewRouteModal(record);
                return;
            }
            if (editButton && !editButton.disabled && canUpdateRoute()) {
                await openRouteFormModal("edit", record);
                return;
            }
            /*
            if (deleteButton && !deleteButton.disabled && canDeleteRoute()) {
                openDeleteRouteModal(record);
            }
            */ 
        });
    document
        .getElementById("closeViewRouteModal")
        ?.addEventListener("click", closeViewRouteModal);
    document
        .getElementById("closeViewRouteBtn")
        ?.addEventListener("click", closeViewRouteModal);
    document
        .getElementById("viewRouteModal")
        ?.addEventListener("click", (event) => {
            if (event.target.id === "viewRouteModal") {
                closeViewRouteModal();
            }
        });
    document
        .getElementById("editRouteFromViewBtn")
        ?.addEventListener("click", async () => {
            if (!canUpdateRoute()) {
                return;
            }
            const viewModal = document.getElementById("viewRouteModal");
            const editModal = document.getElementById("routeFormModal");
            const id = viewModal?.dataset.routeId;
            const record = getRouteRecordById(id);
            if (!record) {
                return;
            }
            const editButton = document.getElementById("editRouteFromViewBtn");
            if (editButton) {
                editButton.disabled = true;
            }
            try {
                await openRouteFormModal("edit", record);
                if (editModal) {
                    editModal.style.zIndex = "1100";
                }
                await new Promise((resolve) => setTimeout(resolve, 140));
                closeViewRouteModal();
                document.body.style.overflow = "hidden";
                setTimeout(() => {
                    if (editModal) {
                        editModal.style.zIndex = "";
                    }
                }, 200);
            } catch (error) {
                console.error("Unable to open Edit Route:", error);

                if (typeof showToast === "function") {
                    showToast("Unable to open route for editing.", "error");
                }
            } finally {
                if (editButton) {
                    editButton.disabled = false;
                }
            }
        });
    document
        .getElementById("duplicateRouteFromViewBtn")
        ?.addEventListener("click", async () => {
            const modal = document.getElementById("viewRouteModal");
            const id = modal?.dataset.routeId;
            if (!id) {
                return;
            }
            await duplicateRouteFromView(id);
        });
    document
        .getElementById("archiveRouteFromViewBtn")
        ?.addEventListener("click", async () => {
            if (!canArchiveRoute()) {
                return;
            }
            const modal = document.getElementById("viewRouteModal");
            const id = modal?.dataset.routeId;

            if (!id) {
                return;
            }

            try {
                const result = await archiveRoutePlanApi(id);
                closeViewRouteModal();
                await reloadRoutePlanningData({
                    resetPage: false,
                    reason: "archive",
                });

                if (typeof showToast === "function") {
                    showToast(result.message || "Route archived.", "success");
                }
            } catch (error) {
                if (typeof showToast === "function") {
                    showToast(
                        error.message || "Unable to archive route.",
                        "error",
                    );
                }
            }
        });

    document
        .getElementById("restoreRouteFromViewBtn")
        ?.addEventListener("click", async () => {
            if (!canRestoreRoute()) {
                return;
            }
            const modal = document.getElementById("viewRouteModal");
            const id = modal?.dataset.routeId;
            if (!id) {
                return;
            }
            try {
                const result = await restoreRoutePlanApi(id);
                closeViewRouteModal();
                await reloadRoutePlanningData({
                    resetPage: false,
                    focusId: result.routePlan?.id,
                    reason: "restore",
                });
                if (typeof showToast === "function") {
                    showToast(
                        result.message || "Route restored to Draft.",
                        "success",
                    );
                }
            } catch (error) {
                if (typeof showToast === "function") {
                    showToast(
                        error.message || "Unable to restore route.",
                        "error",
                    );
                }
            }
        });
    /*
    document
        .getElementById("closeDeleteRouteModal")
        ?.addEventListener("click", closeDeleteRouteModal);
    document
        .getElementById("cancelDeleteRoute")
        ?.addEventListener("click", closeDeleteRouteModal);
    document
        .getElementById("deleteRouteModal")
        ?.addEventListener("click", (event) => {
            if (event.target.id === "deleteRouteModal") {
                closeDeleteRouteModal();
            }
        });
    document
        .getElementById("confirmDeleteRoute")
        ?.addEventListener("click", async () => {
            if (!canDeleteRoute()) {
                return;
            }
            if (!routeDeleteTargetId) {
                return;
            }
            const id = routeDeleteTargetId;
            try {
                const result = await deleteRoutePlanApi(id);
                closeDeleteRouteModal();
                await reloadRoutePlanningData({
                    resetPage: false,
                    reason: "delete",
                });
                if (typeof showToast === "function") {
                    showToast(
                        result.message || "Route deleted successfully.",
                        "success",
                    );
                }
            } catch (error) {
                closeDeleteRouteModal();

                if (typeof showToast === "function") {
                    showToast(
                        error.message || "Unable to delete route.",
                        "error",
                    );
                }
            }
        });
        */ 

    document.addEventListener("keydown", (event) => {
        if (event.key !== "Escape") {
            return;
        }
        if (
            document
                .getElementById("routeFormModal")
                ?.classList.contains("show")
        ) {
            closeRouteFormModal();
        } else if (
            document
                .getElementById("viewRouteModal")
                ?.classList.contains("show")
        ) {
            closeViewRouteModal();
        } /*else if (
            document
                .getElementById("deleteRouteModal")
                ?.classList.contains("show")
        ) {
            closeDeleteRouteModal();
        }*/
    });
    document
        .getElementById("routeStatus")
        ?.addEventListener("change", (event) => {
            if (routeFormMode !== "edit") {
                return;
            }
            const select = event.currentTarget;
            const currentRecord = getRouteRecordById(routeEditingId);
            if (!currentRecord) {
                return;
            }
            const currentStatus = String(
                currentRecord.status || "Draft",
            ).trim();
            const allowedTransitions =
                ROUTE_STATUS_TRANSITIONS[currentStatus] || [];
            const selectedStatus = String(select.value || "").trim();
            if (
                selectedStatus !== currentStatus &&
                !allowedTransitions.includes(selectedStatus)
            ) {
                select.value = currentStatus;
                if (typeof showToast === "function") {
                    showToast(
                        `Route status cannot transition from "${currentStatus}" to "${selectedStatus}".`,
                        "warning",
                    );
                }
            }
            updateRouteFormWorkflowUi();
        });
}

async function initRoutePlanningPage() {
    const page = document.getElementById("routePlanningPage");
    if (!page) {
        return;
    }
    if (page.dataset.init === "true") {
        return;
    }
    page.dataset.init = "true";
    await loadRouteModuleSettings();
    initRoutePlanningPipeline();
    initRoutePlanningModals();
    if (typeof initRouteTemplates === "function") {
        initRouteTemplates();
    }
    if (typeof initRouteExport === "function") {
        initRouteExport();
    }
    await reloadRoutePlanningData({
        resetPage: true,
        reason: "init",
    });
    startRoutePlanningLiveUpdates();
}

document.addEventListener("DOMContentLoaded", () => {
    initRoutePlanningPage();
});
