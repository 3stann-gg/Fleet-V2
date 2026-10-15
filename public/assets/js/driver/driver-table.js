/* ==========================================
   Driver Table
========================================== */

let driverLiveUpdateInterval = null;
let driverLiveUpdateRunning = false;

document.addEventListener("DOMContentLoaded", async () => {
    await loadDrivers();
    startDriverLiveUpdates();
});

document.addEventListener("DOMContentLoaded", () => {
    const showArchived = document.getElementById("showArchivedDrivers");

    showArchived?.addEventListener("change", async () => {
        if (typeof clearDriverSelection === "function") {
            clearDriverSelection();
        }

        await loadDrivers();

        updateDriverBulkSelectionVisibility();
    });
});

async function loadDrivers() {
    try {
        const showArchived =
            document.getElementById("showArchivedDrivers")?.checked === true;

        const url = showArchived ? "/drivers?show_archived=1" : "/drivers";

        const response = await fetch(url, {
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
            cache: "no-store",
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.message || "Failed to load drivers.");
        }

        const drivers = Array.isArray(data)
            ? data
            : Array.isArray(data.drivers)
              ? data.drivers
              : [];

        renderDriverTable(drivers);

        updateDriverBulkSelectionVisibility();

        if (typeof updateDriverStats === "function") {
            updateDriverStats();
        }

        return drivers;
    } catch (error) {
        console.error("DRIVER LOAD ERROR:", error);

        return [];
    }
}

function startDriverLiveUpdates() {
    if (driverLiveUpdateInterval) {
        return;
    }
    driverLiveUpdateInterval = window.setInterval(async () => {
        if (document.hidden) {
            return;
        }
        if (driverLiveUpdateRunning) {
            return;
        }

        driverLiveUpdateRunning = true;

        try {
            await loadDrivers();
        } catch (error) {
            console.error("Driver live update failed:", error);
        } finally {
            driverLiveUpdateRunning = false;
        }
    }, 10000);
}

function getDriverStatusClass(status) {
    const value = (status || "")
        .trim()
        .toLowerCase()
        .replace(/[\s-]+/g, "");

    if (value === "available") {
        return "available";
    }

    if (value === "onduty") {
        return "trip";
    }

    if (value === "onleave") {
        return "maintenance";
    }

    if (value === "inactive") {
        return "out";
    }

    return "out";
}

function getDriverInitials(firstName, lastName) {
    return (
        (firstName?.charAt(0) || "") + (lastName?.charAt(0) || "")
    ).toUpperCase();
}

function escapeDriverHtml(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

/* ==========================================
   Bulk Selection Visibility
========================================== */

function updateDriverBulkSelectionVisibility() {
    const selectAll = document.getElementById("selectAllDrivers");

    const toolbar = document.getElementById("driverBulkToolbar");

    const showArchived =
        document.getElementById("showArchivedDrivers")?.checked === true;

    if (selectAll) {
        selectAll.hidden = showArchived;
        selectAll.checked = false;
        selectAll.indeterminate = false;
    }

    if (showArchived) {
        if (typeof clearDriverSelection === "function") {
            clearDriverSelection();
        }

        if (toolbar) {
            toolbar.classList.remove("show");
        }
    }
}

/* ==========================================
   Render Driver Table
========================================== */

function renderDriverTable(drivers) {
    const tableBody = document.getElementById("driverTableBody");
    if (!tableBody) {
        return;
    }
    const canUpdate =
        window.FleetRBAC?.hasPermission?.("drivers", "canUpdate") === true;
    const canArchive =
        window.FleetRBAC?.hasPermission?.("drivers", "canArchive") === true;
    const canBulkArchive =
        window.FleetRBAC?.hasPermission?.("drivers", "canBulkArchive") === true;
    const canRestore =
        window.FleetRBAC?.hasPermission?.("drivers", "canRestore") === true;
    let html = "";

    drivers.forEach((driver) => {
        const isArchived = Boolean(driver.archived_at);
        const badgeClass = getDriverStatusClass(driver.status);
        const fullName = `${driver.first_name ?? ""} ${
            driver.last_name ?? ""
        }`.trim();
        const photoUrl = driver.photo_url || "";
        const qualifications = Array.isArray(driver.qualifications)
            ? driver.qualifications.map((qualification) => qualification.vehicle_type)
            : [];
        const dlCodes = Array.isArray(driver.dl_codes)
            ? driver.dl_codes
            : Array.isArray(driver.license_codes)
              ? driver.license_codes.map((licenseCode) => licenseCode.code)
              : [];
        const licenseExpiry = escapeDriverHtml(
            driver.license_expiry || "Not recorded",
        );
        const dlCodeSummary = escapeDriverHtml(
            dlCodes.length > 0 ? dlCodes.join(", ") : "Not recorded",
        );
        const qualificationSummary = escapeDriverHtml(
            qualifications.length > 0
                ? qualifications.join(", ")
                : "Not recorded",
        );

        const vehicleText = driver.vehicle
            ? `${driver.vehicle.brand ?? ""} ${driver.vehicle.model ?? ""} — ${
                  driver.vehicle.vehicle_type ?? ""
              }`.trim()
            : "Unassigned";

        html += `
            <tr
                data-id="${driver.id ?? ""}"
                data-driver-number="${driver.driver_number ?? ""}"
                data-first-name="${driver.first_name ?? ""}"
                data-last-name="${driver.last_name ?? ""}"
                data-license-number="${driver.license_number ?? ""}"
                data-license-class="${driver.license_class ?? ""}"
                data-license-expiry="${driver.license_expiry ?? ""}"
                data-dl-codes="${escapeDriverHtml(dlCodes.join("|"))}"
                data-clutch-authorization="${escapeDriverHtml(driver.clutch_authorization ?? "")}"
                data-contact-number="${driver.contact_number ?? ""}"
                data-email="${driver.email ?? ""}"
                data-experience="${driver.experience ?? ""}"
                data-address="${driver.address ?? ""}"
                data-emergency-contact="${driver.emergency_contact ?? ""}"
                data-notes="${driver.notes ?? ""}"
                data-status="${driver.status ?? ""}"
                data-assigned-vehicle-id="${driver.assigned_vehicle_id ?? ""}"
                data-vehicle="${vehicleText}"
                data-photo="${driver.photo ?? ""}"
                data-photo-url="${escapeDriverHtml(photoUrl)}"
                data-qualifications="${escapeDriverHtml(qualifications.join("|"))}"
                data-archived-at="${driver.archived_at ?? ""}"
            >

                <!-- Checkbox -->
                <td>
                    ${
                        canBulkArchive && !isArchived
                            ? `
                                <input
                                    type="checkbox"
                                    class="driver-checkbox"
                                    data-id="${driver.id ?? ""}"
                                    aria-label="Select ${fullName || driver.driver_number || "driver"}"
                                >
                            `
                            : ""
                    }
                </td>

                <!-- Driver -->
                <td>
                    <div class="driver-info">

                        <div class="driver-avatar">
                            ${getDriverInitials(
                                driver.first_name,
                                driver.last_name,
                            )}
                            ${
                                photoUrl
                                    ? `
                                        <img
                                            class="driver-photo"
                                            data-driver-photo="${escapeDriverHtml(photoUrl)}"
                                            alt="${escapeDriverHtml(fullName || "Driver")}"
                                            hidden
                                        >
                                    `
                                    : ""
                            }
                        </div>

                        <div>
                            <div class="driver-name">
                                ${fullName || "Unnamed Driver"}
                            </div>

                            <small>
                                Fleet Driver
                            </small>
                        </div>

                    </div>
                </td>

                <!-- Driver ID -->
                <td>
                    ${driver.driver_number ?? "—"}
                </td>

                <!-- License Number -->
                <td>
                    ${driver.license_number ?? "—"}
                    <small class="driver-table-meta">
                        Expires: ${licenseExpiry}
                    </small>
                </td>

                <!-- License Class -->
                <td>
                    ${driver.license_class ?? "—"}
                    <small class="driver-table-meta">
                        DL Codes: ${dlCodeSummary}
                    </small>
                </td>

                <!-- Assigned Vehicle -->
                <td>
                    ${
                        driver.vehicle
                            ? `
                                <span class="fuel-vehicle">

                                    <span class="fuel-vehicle-name">
                                        ${driver.vehicle.brand ?? ""}
                                        ${driver.vehicle.model ?? ""}
                                    </span>

                                    <small class="fuel-vehicle-type">
                                        ${driver.vehicle.vehicle_type ?? ""}
                                    </small>

                                </span>
                            `
                            : "Unassigned"
                    }
                    <small class="driver-table-meta">
                        Hospital qualified: ${qualificationSummary}
                    </small>
                </td>

                <!-- Status -->
                <td>
                    <span
                        class="status-badge ${badgeClass}"
                    >
                        ${driver.status ?? ""}
                    </span>
                </td>

                <!-- Contact -->
                <td>
                    ${driver.contact_number ?? "—"}
                </td>

                <!-- Actions -->
                <td>
                    <div class="action-buttons">

                        <!-- View -->
                        <button
                            type="button"
                            class="action-btn view"
                            data-id="${driver.id ?? ""}"
                            title="View"
                            aria-label="View ${escapeDriverHtml(fullName || driver.driver_number || "driver")}"
                        >
                            <i class="ph ph-eye" aria-hidden="true"></i>
                        </button>

                        ${
                            canUpdate && !isArchived
                                ? `
                                    <!-- Edit -->
                                    <button
                                        type="button"
                                        class="action-btn edit"
                                        data-id="${driver.id ?? ""}"
                                        title="Edit"
                                        aria-label="Edit ${escapeDriverHtml(fullName || driver.driver_number || "driver")}"
                                    >
                                        <i class="ph ph-pencil-simple" aria-hidden="true"></i>
                                    </button>
                                `
                                : ""
                        }

                        ${
                            canArchive && !isArchived
                                ? `
                                    <!-- Archive -->
                                    <button
                                        type="button"
                                        class="action-btn archive"
                                        data-id="${driver.id ?? ""}"
                                        title="Archive"
                                        aria-label="Archive ${escapeDriverHtml(fullName || driver.driver_number || "driver")}"
                                    >
                                        <i class="ph ph-archive" aria-hidden="true"></i>
                                    </button>
                                `
                                : ""
                        }

                        ${
                            canRestore && isArchived
                                ? `
                                    <!-- Restore -->
                                    <button
                                        type="button"
                                        class="action-btn restore"
                                        data-id="${driver.id ?? ""}"
                                        title="Restore"
                                        aria-label="Restore ${escapeDriverHtml(fullName || driver.driver_number || "driver")}"
                                    >
                                        <i class="ph ph-arrow-counter-clockwise" aria-hidden="true"></i>
                                    </button>
                                `
                                : ""
                        }

                    </div>
                </td>

            </tr>
        `;
    });

    tableBody.innerHTML = html;

    tableBody.querySelectorAll("[data-driver-photo]").forEach((image) => {
        image.addEventListener(
            "load",
            () => {
                image.hidden = false;
            },
            { once: true },
        );

        image.addEventListener(
            "error",
            () => {
                image.remove();
            },
            { once: true },
        );

        image.src = image.dataset.driverPhoto;
    });

   
    if (typeof applyDriverFilters === "function") {
        applyDriverFilters();
    }
    if (typeof refreshDriverBulkState === "function") {
        refreshDriverBulkState();
    }
    if (typeof refreshDriverPagination === "function") {
        refreshDriverPagination();
    }
    if (typeof initDriverPagination === "function") {
        initDriverPagination();
    }
    updateDriverBulkSelectionVisibility();
}
