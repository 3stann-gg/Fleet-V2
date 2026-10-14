document.addEventListener("DOMContentLoaded", () => {
    const baseUrl = window.TRAFFIC_VIOLATION_ROUTES?.base || "/traffic-violations";
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const formModal = document.getElementById("trafficViolationFormModal");
    const recordForm = document.getElementById("trafficViolationForm");

    function openModal(modal) {
        if (!modal) return;
        const scrollableBody = modal.querySelector(".modal-body");
        if (scrollableBody) scrollableBody.scrollTop = 0;
        modal.classList.add("show");
        document.body.style.overflow = "hidden";
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove("show");
        if (!document.querySelector(".modal-overlay.show")) document.body.style.overflow = "";
    }

    document.querySelectorAll("[data-close-modal]").forEach((button) => {
        button.addEventListener("click", () => closeModal(button.closest(".modal-overlay")));
    });
    document.querySelectorAll(".modal-overlay").forEach((modal) => {
        modal.addEventListener("click", (event) => {
            if (event.target === modal) closeModal(modal);
        });
    });
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") document.querySelectorAll(".modal-overlay.show").forEach(closeModal);
    });

    async function request(url, options = {}) {
        const response = await fetch(url, {
            ...options,
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": csrf,
                ...(options.headers || {}),
            },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const validation = data.errors ? Object.values(data.errors).flat().join(" ") : "";
            throw new Error(validation || data.message || "Unable to complete the request.");
        }
        return data;
    }

    function formPayload(form) {
        const payload = {};
        new FormData(form).forEach((value, key) => {
            payload[key] = typeof value === "string" && value.trim() === "" ? null : value;
        });
        return payload;
    }

    function value(value) {
        return value === null || value === undefined || value === "" ? "Not recorded" : value;
    }

    function setFormValues(form, record, prefix = "") {
        if (!form) return;
        form.querySelectorAll("[name]").forEach((field) => {
            const key = field.name;
            let fieldValue = record[key] ?? "";
            if (key === "violation_time" && typeof fieldValue === "string") fieldValue = fieldValue.slice(0, 5);
            field.value = fieldValue;
        });
        if (prefix) document.getElementById(prefix).value = record.id;
    }

    async function loadRecord(id) {
        const data = await request(`${baseUrl}/${id}`);
        return data.traffic_violation;
    }

    function displayError(elementId, error) {
        const element = document.getElementById(elementId);
        if (!element) return;
        element.textContent = error.message;
        element.hidden = false;
    }

    function clearError(elementId) {
        const element = document.getElementById(elementId);
        if (element) element.hidden = true;
    }

    document.getElementById("addTrafficViolationBtn")?.addEventListener("click", () => {
        recordForm.reset();
        document.getElementById("trafficViolationId").value = "";
        document.getElementById("trafficViolationFormTitle").textContent = "Add Traffic Violation";
        document.getElementById("violation_date").value = new Date().toISOString().slice(0, 10);
        clearError("trafficViolationFormError");
        updateDispatchContext();
        updateSelectionContext();
        openModal(formModal);
    });

    const dispatchSelect = document.getElementById("dispatch_id");
    const driverSelect = document.getElementById("driver_id");
    const vehicleSelect = document.getElementById("vehicle_id");

    function updateDispatchContext() {
        if (!dispatchSelect) return;
        const selected = dispatchSelect.selectedOptions[0];
        const warning = document.getElementById("dispatchMismatchWarning");
        const tripInput = document.getElementById("trip_log_id");
        const reservationInput = document.getElementById("reservation_id");
        tripInput.value = selected?.dataset.trip || "";
        reservationInput.value = selected?.dataset.reservation || "";
        const mismatches = [];
        if (selected?.value && driverSelect?.value && selected.dataset.driver && driverSelect.value !== selected.dataset.driver) mismatches.push("driver");
        if (selected?.value && vehicleSelect?.value && selected.dataset.vehicle && vehicleSelect.value !== selected.dataset.vehicle) mismatches.push("vehicle");
        if (warning) {
            warning.hidden = mismatches.length === 0;
            warning.textContent = mismatches.length
                ? `Warning: the selected ${mismatches.join(" and ")} differs from the related dispatch. Your selections will not be overwritten.`
                : "";
        }
    }

    function updateSelectionContext() {
        const driverContext = document.getElementById("driverContext");
        const vehicleContext = document.getElementById("vehicleContext");
        if (driverContext) driverContext.textContent = driverSelect?.selectedOptions[0]?.dataset.context || "";
        if (vehicleContext) vehicleContext.textContent = vehicleSelect?.selectedOptions[0]?.dataset.context || "";
    }

    [dispatchSelect, driverSelect, vehicleSelect].forEach((field) => field?.addEventListener("change", () => {
        updateDispatchContext();
        updateSelectionContext();
    }));

    recordForm?.addEventListener("submit", async (event) => {
        event.preventDefault();
        clearError("trafficViolationFormError");
        const id = document.getElementById("trafficViolationId").value;
        try {
            const data = await request(id ? `${baseUrl}/${id}` : baseUrl, {
                method: id ? "PUT" : "POST",
                body: JSON.stringify(formPayload(recordForm)),
            });
            window.showToast?.(data.message, "success");
            window.location.reload();
        } catch (error) {
            displayError("trafficViolationFormError", error);
        }
    });

    document.querySelectorAll(".traffic-action").forEach((button) => {
        button.addEventListener("click", async () => {
            const { action, id } = button.dataset;
            try {
                if (action === "archive" || action === "restore") {
                    if (!window.confirm(`${action === "archive" ? "Archive" : "Restore"} this traffic violation record?`)) return;
                    const data = await request(`${baseUrl}/${id}/${action}`, { method: "POST", body: "{}" });
                    window.showToast?.(data.message, "success");
                    window.location.reload();
                    return;
                }

                const record = await loadRecord(id);
                if (action === "view") {
                    const details = document.getElementById("trafficViolationDetails");
                    const driverName = record.driver ? `${record.driver.first_name} ${record.driver.last_name}` : "Not identified";
                    const rows = {
                        "Citation Number": value(record.citation_number), Date: value(record.violation_date), Time: value(record.violation_time),
                        "Issuing Agency": value(record.issuing_agency), Location: value(record.location), Vehicle: value(record.vehicle?.plate_number),
                        Driver: driverName, Violation: value(record.violation_type_label), Responsibility: value(record.responsibility_label),
                        Status: value(record.status_label), Dispatch: value(record.dispatch?.dispatch_number), Description: value(record.description),
                        Notes: value(record.notes), "Responsibility Notes": value(record.responsibility_notes),
                    };
                    if (Object.hasOwn(record, "fine_amount")) {
                        rows["Fine Amount"] = record.fine_amount === null ? "Not recorded" : `₱${Number(record.fine_amount).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
                        rows["Payment Status"] = value(record.payment_status_label);
                        rows["Payment Date"] = value(record.payment_date);
                        rows["Payment Reference"] = value(record.payment_reference);
                        rows["Settlement Notes"] = value(record.settlement_notes);
                    }
                    details.replaceChildren(...Object.entries(rows).map(([label, detail]) => {
                        const wrapper = document.createElement("div");
                        const term = document.createElement("dt");
                        const description = document.createElement("dd");
                        term.textContent = label;
                        description.textContent = detail;
                        wrapper.append(term, description);
                        return wrapper;
                    }));
                    openModal(document.getElementById("viewTrafficViolationModal"));
                } else if (action === "edit") {
                    recordForm.reset();
                    setFormValues(recordForm, record, "trafficViolationId");
                    document.getElementById("trafficViolationFormTitle").textContent = "Edit Traffic Violation";
                    updateDispatchContext();
                    updateSelectionContext();
                    openModal(formModal);
                } else if (action === "review") {
                    const form = document.getElementById("reviewTrafficViolationForm");
                    setFormValues(form, record, "reviewViolationId");
                    openModal(document.getElementById("reviewTrafficViolationModal"));
                } else if (action === "settlement") {
                    const form = document.getElementById("settlementTrafficViolationForm");
                    setFormValues(form, record, "settlementViolationId");
                    openModal(document.getElementById("settlementTrafficViolationModal"));
                }
            } catch (error) {
                window.showToast?.(error.message, "error");
            }
        });
    });

    document.getElementById("reviewTrafficViolationForm")?.addEventListener("submit", async (event) => {
        event.preventDefault();
        clearError("reviewTrafficViolationError");
        try {
            const id = document.getElementById("reviewViolationId").value;
            const data = await request(`${baseUrl}/${id}/review`, { method: "PUT", body: JSON.stringify(formPayload(event.currentTarget)) });
            window.showToast?.(data.message, "success");
            window.location.reload();
        } catch (error) { displayError("reviewTrafficViolationError", error); }
    });

    document.getElementById("settlementTrafficViolationForm")?.addEventListener("submit", async (event) => {
        event.preventDefault();
        clearError("settlementTrafficViolationError");
        try {
            const id = document.getElementById("settlementViolationId").value;
            const data = await request(`${baseUrl}/${id}/settlement`, { method: "PUT", body: JSON.stringify(formPayload(event.currentTarget)) });
            window.showToast?.(data.message, "success");
            window.location.reload();
        } catch (error) { displayError("settlementTrafficViolationError", error); }
    });
});
