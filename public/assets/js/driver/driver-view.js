/* ==========================================
   View Driver Modal 
========================================== */

function setViewDriverText(modal, id, value) {
  const element = modal.querySelector(`#${id}`);

  if (element) {
    element.textContent = value || "Not provided";
  }
}

function getViewDriverStatusClass(status) {
  const statusClasses = {
    Available: "available",
    "On Duty": "trip",
    "On Leave": "maintenance",
    Inactive: "out",
  };

  return statusClasses[status] || "out";
}

function setViewDriverPhoto(image, photoSource) {
  if (!image) return;

  if (!image.dataset.placeholderSrc) {
    image.dataset.placeholderSrc = image.getAttribute("src") || image.src;
  }

  image.onerror = null;
  image.src = image.dataset.placeholderSrc;
  image.alt = "Driver Photo";

  if (!photoSource) return;

  image.onerror = () => {
    image.onerror = null;
    image.src = image.dataset.placeholderSrc;
  };
  image.src = photoSource;
}

function populateViewDriverModal(modal, row) {
    const name =
      `${row.dataset.firstName || ""} ${row.dataset.lastName || ""}`.trim() || "Not provided";
    const employeeId =
        "DRV-" + String(row.dataset.id).padStart(3, "0");
    const status = row.dataset.status || "Not provided";
    const image = modal.querySelector("#viewDriverImage");
    const statusBadge = modal.querySelector("#viewDriverStatus");
    setViewDriverPhoto(image, row.dataset.photoUrl || "");

    setViewDriverText(modal, "viewDriverName", name);
    setViewDriverText(
        modal,
        "viewDriverSubtitle",
        "Fleet Driver"
    );
    setViewDriverText(
        modal,
        "viewDriverEmployeeId",
        employeeId
    );
    setViewDriverText(
        modal,
        "viewDriverLicenseNumber",
        row.dataset.licenseNumber
    );
    setViewDriverText(
        modal,
        "viewDriverLicenseClass",
        row.dataset.licenseClass
    );

    setViewDriverText(
        modal,
        "viewDriverLicenseExpiry",
        row.dataset.licenseExpiry
    );
    setViewDriverText(
        modal,
        "viewDriverDlCodes",
        (row.dataset.dlCodes || "").split("|").filter(Boolean).join(", ") ||
            "Not Recorded",
    );
    setViewDriverText(
        modal,
        "viewDriverClutchAuthorization",
        row.dataset.clutchAuthorization || "Not Recorded",
    );
    setViewDriverText(
        modal,
        "viewDriverPhone",
        row.dataset.contactNumber
    );
    setViewDriverText(
        modal,
        "viewDriverEmail",
        row.dataset.email
    );
    setViewDriverText(
        modal,
        "viewDriverAssignedVehicle",
        row.dataset.vehicle
    );
    setViewDriverText(
        modal,
        "viewDriverExperience",
        row.dataset.experience
    );
    setViewDriverText(
        modal,
        "viewDriverAddress",
        row.dataset.address
    );
    setViewDriverText(
        modal,
        "viewDriverEmergencyContact",
        row.dataset.emergencyContact
    );
    setViewDriverText(
        modal,
        "viewDriverQualifications",
        (row.dataset.qualifications || "").split("|").filter(Boolean).join(", ") ||
            "Qualification information unavailable",
    );
    setViewDriverText(
        modal,
        "viewDriverNotes",
        row.dataset.notes
    );

    if (statusBadge) {

        statusBadge.className = "status-badge";
        statusBadge.classList.add(
            getViewDriverStatusClass(status)
        );
        statusBadge.textContent = status;

        const summary =
            modal.querySelector("#viewDriverStatusSummary");

        if (summary) {

            summary.className = "status-badge";
            summary.classList.add(
                getViewDriverStatusClass(status)
            );
            summary.textContent = status;

        }

    }

}

function populateDriverTrafficViolations(modal, violations) {
  const container = modal.querySelector("#viewDriverTrafficViolations");
  if (!container) return;

  if (!Array.isArray(violations) || violations.length === 0) {
    container.textContent = "No traffic violation records";
    return;
  }

  container.replaceChildren(...violations.map((violation) => {
    const item = document.createElement("p");
    const vehicle = violation.vehicle?.plate_number || "No vehicle linked";
    item.textContent = `${violation.violation_date || "Date not recorded"} · ${violation.violation_type_label || violation.violation_type} · ${vehicle} · ${violation.responsibility_label || violation.responsibility} · ${violation.status_label || violation.status}`;
    return item;
  }));
}

function openDriverDetailsModal(modal) {
  if (!modal.classList.contains("show")) {
    modal.dataset.previousBodyOverflow = document.body.style.overflow;
  }

  modal.classList.add("show");
  document.body.style.overflow = "hidden";
}

function closeDriverDetailsModal(modal) {
  if (!modal.classList.contains("show")) return;

  modal.classList.remove("show");
  document.body.style.overflow = modal.dataset.previousBodyOverflow || "";
  delete modal.dataset.previousBodyOverflow;
  modal.currentRow = null;
}

function initViewDriverModal() {
  const modal = document.getElementById("viewDriverModal");

  if (!modal || modal.dataset.viewDriverModalInitialized === "true") return;

  const closeButton = document.getElementById("closeViewDriverModal");
  const footerCloseButton = document.getElementById("closeViewDriverBtn");
  const editFromViewButton = document.getElementById("editDriverFromViewBtn");

  modal.dataset.viewDriverModalInitialized = "true";

  document.addEventListener("click", async (event) => {
    if (!event.target || typeof event.target.closest !== "function") return;

    const viewButton = event.target.closest(".action-btn.view");

    if (!viewButton) return;

    const row = viewButton.closest("tr");

    if (!row) return;

    modal.currentRow = row;
    populateViewDriverModal(modal, row);
    openDriverDetailsModal(modal);

    const violationContainer = modal.querySelector("#viewDriverTrafficViolations");
    if (violationContainer) {
      violationContainer.textContent = "Loading traffic violation history...";
      try {
        const response = await fetch(`/drivers/${row.dataset.id}`, {
          headers: { Accept: "application/json" },
          credentials: "same-origin",
        });
        if (!response.ok) throw new Error("Unable to load driver history.");
        const driver = await response.json();
        populateDriverTrafficViolations(modal, driver.traffic_violations || []);
      } catch (error) {
        violationContainer.textContent = "Traffic violation history is unavailable.";
      }
    }
  });

  if (closeButton) {
    closeButton.addEventListener("click", () => closeDriverDetailsModal(modal));
  }

  if (footerCloseButton) {
    footerCloseButton.addEventListener("click", () =>
      closeDriverDetailsModal(modal),
    );
  }

  if (editFromViewButton) {
    editFromViewButton.addEventListener("click", () => {
      const row = modal.currentRow;
      const editModal = document.getElementById("editDriverModal");

      if (
        !row ||
        !editModal ||
        typeof populateEditDriverModal !== "function" ||
        typeof openEditDriverModal !== "function"
      ) {
        return;
      }

      closeDriverDetailsModal(modal);
      populateEditDriverModal(editModal, row);
      openEditDriverModal(editModal);
    });
  }

  modal.addEventListener("click", (event) => {
    if (event.target === modal) {
      closeDriverDetailsModal(modal);
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && modal.classList.contains("show")) {
      closeDriverDetailsModal(modal);
    }
  });
}

document.addEventListener("DOMContentLoaded", () => {
    initViewDriverModal();
});
