/* ==========================================
   Edit Driver Modal
========================================== */
/*
function getEditDriverRowText(row, columnIndex, selector) {
  const selectedElement = selector ? row.querySelector(selector) : null;
  const cell = row.children && row.children[columnIndex];
  const value = selectedElement ? selectedElement.textContent : cell?.textContent;

  return value && value.trim() ? value.trim() : "";
}

function getEditDriverData(row, key) {
  const value = row.dataset && row.dataset[key];

  return value && value.trim() ? value.trim() : "";
}
*/
/* ==========================================
   Driver Settings - Edit
========================================== */

async function getEditDriverSettings() {
  if (
    typeof window.getDriverModuleSettings ===
    "function"
  ) {
    return await window.getDriverModuleSettings();
  }

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
      throw new Error();
    }

    const data =
      await response.json();

    const settings =
      data?.settings?.drivers || {};

    return {
      requireLicenseExpiry:
        settings.requireLicenseExpiry !== false,

      warnLicenseDays:
        Math.max(
          1,
          Math.min(
            180,
            Number(
              settings.warnLicenseDays ?? 30
            )
          )
        ),
    };
  } catch {
    return {
      requireLicenseExpiry: true,
      warnLicenseDays: 30,
    };
  }
}

function applyEditDriverSettings(
  settings
) {
  const expiry =
    document.getElementById(
      "editDriverLicenseExpiry"
    );
  const mark =
    document.getElementById(
      "editDriverLicenseExpiryRequiredMark"
    );
  const hint =
    document.getElementById(
      "editDriverLicenseExpiryHint"
    );
  const required =
    settings?.requireLicenseExpiry !== false;

  if (expiry) {
    expiry.required =
      required;
  }
  if (mark) {
    mark.hidden =
      !required;
  }
  if (hint) {
    hint.textContent =
      required
        ? "License expiry is required."
        : `License expiry is optional. Warning threshold: ${settings.warnLicenseDays} days.`;
  }
}

//   RBAC
function canEditDriverRecords() {
    return window.FleetRBAC?.hasPermission?.("drivers", "canUpdate") === true;
}

function setEditDriverFieldValue(id, value) {
  const field = document.getElementById(id);

  if (field) {
    field.value = value;
  }
}

function setEditDriverSelectValue(id, value) {
  const select = document.getElementById(id);

  if (!select) return;

  if (
    value &&
    !Array.from(select.options).some((option) => option.value === value)
  ) {
    const option = document.createElement("option");

    option.value = value;
    option.textContent = value;
    select.appendChild(option);
  }

  select.value = value;
}

function getEditDriverPlaceholderSource(preview) {
  if (!preview) return "";

  if (!preview.dataset.placeholderSrc) {
    preview.dataset.placeholderSrc = preview.getAttribute("src") || preview.src;
  }

  return preview.dataset.placeholderSrc;
}

function setEditDriverPreviewSource(preview, photoSource) {
  if (!preview) return;

  const placeholderSource = getEditDriverPlaceholderSource(preview);

  preview.onerror = null;
  preview.src = placeholderSource;

  if (!photoSource) return;

  preview.onerror = () => {
    preview.onerror = null;
    preview.src = placeholderSource;
  };
  preview.src = photoSource;
}

function openEditDriverModal(modal) {
  if (!modal.classList.contains("show")) {
    modal.dataset.previousBodyOverflow = document.body.style.overflow;
  }

  modal.classList.add("show");
  document.body.style.overflow = "hidden";
}

function closeEditDriverModal(modal) {
  if (!modal.classList.contains("show")) return;

  modal.classList.remove("show");
  document.body.style.overflow = modal.dataset.previousBodyOverflow || "";
  delete modal.dataset.previousBodyOverflow;
  modal.currentRow = null;
  modal.dataset.photoChanged = "false";
}

async function loadEditDriverVehicleOptions(currentVehicleId = "") {
    const select = document.getElementById("editDriverAssignedVehicle");
    if (!select) return;

    try {
        const response = await fetch("/fleet/available", {
            headers: {
                "Accept": "application/json"
            }
        });
        if (!response.ok) {
            throw new Error(
                `Failed to load vehicles: ${response.status}`
            );
        }

        const data = await response.json();
        const vehicles = data.vehicles ?? data;

        const options = [
            {
                label: "Select Assigned Vehicle",
                value: ""
            }
        ];

        vehicles.forEach(vehicle => {

            const vehicleId = String(vehicle.id);
            /*
             * Skip vehicles that already have a driver,
             * except the driver's current assigned vehicle.
             */
            if (
                vehicle.drivers &&
                vehicle.drivers.length > 0 &&
                vehicleId !== String(currentVehicleId)
            ) {
                return;
            }
            const brand = vehicle.brand ?? "";
            const model = vehicle.model ?? "";
            const type = vehicle.vehicle_type ?? "";
            const vehicleName = `${brand} ${model}`.trim();

            options.push({
                label: `${vehicleName} - ${type}`,
                value: vehicleId
            });
        });

        /*
         * If the current vehicle is not included in /fleet/available
         * (for example, because its status is no longer Available),
         * fetch it separately and add it.
         */
        if (
            currentVehicleId &&
            !options.some(
                option => option.value === String(currentVehicleId)
            )
        ) {
            const currentResponse =
                await fetch(`/fleet/${currentVehicleId}`, {
                    headers: {
                        "Accept": "application/json"
                    }
                });

            if (currentResponse.ok) {
                const currentData =await currentResponse.json();
                const vehicle = currentData.vehicle;
                if (vehicle) {
                    const brand = vehicle.brand ?? "";
                    const model = vehicle.model ?? "";
                    const type = vehicle.vehicle_type ?? "";
                    const vehicleName = `${brand} ${model}`.trim();

                    options.push({
                        label: `${vehicleName} - ${type}`,
                        value: String(vehicle.id)
                    });
                }
            }
        }

        setDriverSelectOptions(select, options);
        select.value = currentVehicleId
                ? String(currentVehicleId)
                : "";

    } catch (error) {
        console.error(
            "EDIT DRIVER VEHICLE DROPDOWN ERROR:",
            error
        );
        setDriverSelectOptions(select, [
            {
                label: "Unable to load vehicles",
                value: ""
            }
        ]);
    }
}

function populateEditDriverModal(modal, row) {
  const preview = document.getElementById("editDriverPreview");
  const imageInput = document.getElementById("editDriverImage");
  const photoSource = row.dataset.photoUrl || "";
  const driverId = row.dataset.id;

  modal.currentRow = row;
  modal.dataset.photoChanged = "false";

  setEditDriverPreviewSource(preview, photoSource);

  if (imageInput) {
    imageInput.value = "";
  }

  setEditDriverFieldValue(
      "editDriverFirstName",
      row.dataset.firstName || ""
  );
  setEditDriverFieldValue(
      "editDriverLastName",
      row.dataset.lastName || ""
  );
  setEditDriverFieldValue(
      "editDriverEmployeeId",
      row.dataset.driverNumber || "",
  );
  setEditDriverFieldValue(
      "editDriverLicenseNumber",
      row.dataset.licenseNumber
  );
  setEditDriverSelectValue(
      "editDriverLicenseClass",
      row.dataset.licenseClass
  );
  setEditDriverFieldValue(
      "editDriverLicenseExpiry",
      row.dataset.licenseExpiry
  );
  setEditDriverFieldValue(
      "editDriverPhone",
      row.dataset.contactNumber
  );
  setEditDriverFieldValue(
      "editDriverEmail",
      row.dataset.email
  );
  setEditDriverFieldValue(
      "editDriverExperience",
      row.dataset.experience
  );
  setEditDriverFieldValue(
      "editDriverAddress",
      row.dataset.address
  );
  setEditDriverFieldValue(
      "editDriverEmergencyContact",
      row.dataset.emergencyContact
  );
  setEditDriverFieldValue(
      "editDriverNotes",
      row.dataset.notes
  );
  const statusSelect = document.getElementById("editDriverStatus");

  const currentStatus = row.dataset.status || "";

  if (statusSelect) {
      /*
       * Remove stale temporary On Duty option.
       */
      Array.from(statusSelect.options).forEach((option) => {
          if (option.dataset.systemStatus === "true") {
              option.remove();
          }
      });

      if (currentStatus === "On Duty") {
          const option = document.createElement("option");
          option.value = "On Duty";
          option.textContent = "On Duty (Active Trip)";
          option.dataset.systemStatus = "true";
          statusSelect.appendChild(option);
          statusSelect.value = "On Duty";
          statusSelect.disabled = true;
          statusSelect.title =
              "On Duty is controlled by the Dispatch lifecycle.";
      } else {
          statusSelect.disabled = false;
          statusSelect.removeAttribute("title");
          statusSelect.value = currentStatus;
      }
  }
  setEditDriverSelectValue(
      "editDriverAssignedVehicle",
      row.dataset.assignedVehicleId
  );

  const qualifications = new Set(
      (row.dataset.qualifications || "").split("|").filter(Boolean),
  );
  document
      .querySelectorAll('#editDriverQualificationOptions input[type="checkbox"]')
      .forEach((checkbox) => {
          checkbox.checked = qualifications.has(checkbox.value);
      });
}

async function initEditDriverModal() {
  if (!canEditDriverRecords()) {
      return;
  }
  const modal = document.getElementById("editDriverModal");
  const form = document.getElementById("editDriverForm");
  const closeButton = document.getElementById("closeEditDriverModal");
  const cancelButton = document.getElementById("cancelEditDriver");
  const imageInput = document.getElementById("editDriverImage");
  const preview = document.getElementById("editDriverPreview");

  if (!modal || !form || modal.dataset.editDriverModalInitialized === "true") {
    return;
  }

  const driverSettings = await getEditDriverSettings();

  applyEditDriverSettings(driverSettings);

  modal.dataset.editDriverModalInitialized = "true";

  if (imageInput && preview) {
    getEditDriverPlaceholderSource(preview);

    imageInput.addEventListener("change", () => {
      const file = imageInput.files && imageInput.files[0];

      if (!file) return;

      if (!file.type.startsWith("image/")) {
        imageInput.value = "";
        return;
      }

      const reader = new FileReader();

      reader.addEventListener("load", () => {
        setEditDriverPreviewSource(preview, reader.result);
        modal.dataset.photoChanged = "true";
      });

      reader.readAsDataURL(file);
    });
  }

  document.addEventListener("click", async (event) => {
    if (!event.target || typeof event.target.closest !== "function") {
        return;
    }
    const editButton = event.target.closest(".action-btn.edit");

    if (!editButton) return;

    const row = editButton.closest("tr");

    if (!row) return;

    const currentVehicleId = row.dataset.assignedVehicleId || "";
    await loadEditDriverVehicleOptions(currentVehicleId);
    populateEditDriverModal(modal, row);

    openEditDriverModal(modal);
});

  closeButton?.addEventListener("click", () => closeEditDriverModal(modal));
  cancelButton?.addEventListener("click", () => closeEditDriverModal(modal));

  modal.addEventListener("click", (event) => {
    if (event.target === modal) {
      closeEditDriverModal(modal);
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && modal.classList.contains("show")) {
      closeEditDriverModal(modal);
    }
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();

    const requiredFieldIds = [
        "editDriverFirstName",
        "editDriverLastName",
        "editDriverLicenseNumber",
        "editDriverLicenseClass",
        "editDriverPhone",
        "editDriverStatus",
    ];

    const requiredFields = requiredFieldIds
        .map((id) => document.getElementById(id))
        .filter(Boolean);

    const expiryField = document.getElementById("editDriverLicenseExpiry");

    if (expiryField && expiryField.required) {
        requiredFields.push(expiryField);
    }

    const emptyField = requiredFields.find(
        (field) => !String(field.value || "").trim(),
    );

    if (emptyField) {
      if (typeof setDriverFieldValidationMessage === "function") {
        setDriverFieldValidationMessage(emptyField);
      } else {
        emptyField.focus();
      }

      return;
    }

    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    const row = modal.currentRow;
    if (!row) return;
    const driverId = row.dataset.id;

    const formData = new FormData();

    formData.append(
        "first_name",
        document.getElementById("editDriverFirstName").value.trim()
    );
    formData.append(
        "last_name",
        document.getElementById("editDriverLastName").value.trim()
    );
    formData.append(
        "license_number",
        document.getElementById("editDriverLicenseNumber").value
    );
    formData.append(
        "license_class",
        document.getElementById("editDriverLicenseClass").value
    );
    formData.append(
        "license_expiry",
        document.getElementById("editDriverLicenseExpiry").value
    );
    formData.append(
        "contact_number",
        document.getElementById("editDriverPhone").value
    );
    formData.append(
        "email",
        document.getElementById("editDriverEmail").value
    );
    formData.append(
        "experience",
        document.getElementById("editDriverExperience").value
    );
    formData.append(
        "address",
        document.getElementById("editDriverAddress").value
    );
    formData.append(
        "emergency_contact",
        document.getElementById("editDriverEmergencyContact").value
    );
    formData.append(
        "notes",
        document.getElementById("editDriverNotes").value
    );
    formData.append(
        "status",
        document.getElementById("editDriverStatus").value
    );
    formData.append(
        "assigned_vehicle_id",
        document.getElementById("editDriverAssignedVehicle").value
    );

    document
        .querySelectorAll('#editDriverQualificationOptions input[type="checkbox"]:checked')
        .forEach((checkbox) => {
            formData.append("qualifications[]", checkbox.value);
        });

    const image = document.getElementById("editDriverImage").files[0];

    if (image) {
        formData.append("photo", image);
    }

    try {
        const response = await fetch(`/drivers/${driverId}`, {
            method: "POST",
            headers: {
                "X-CSRF-TOKEN": document
                    .querySelector('meta[name="csrf-token"]').content,
                "Accept": "application/json"
            },
            body: (() => {
                formData.append("_method", "PUT");
                return formData;
            })()
        });

        const data = await response.json();

        if (response.status === 422) {
            console.log(data.errors);

            const firstError = data.errors
                ? Object.values(data.errors).flat()[0]
                : null;

            window.showToast(
                firstError ||
                    data.message ||
                    "Please check the driver information.",
                "error",
            );

            return;
        }
        if (data.success) {
            loadDrivers();

            if (typeof loadDriverVehicleOptions === "function") {
                await loadDriverVehicleOptions();
            }

            form.reset();
            closeEditDriverModal(modal);

            window.showToast(data.message, "success");
        }
    }
    catch (error) {
        console.error(error);
        window.showToast("Failed to update driver.", "error");
    }

  });
}

document.addEventListener("DOMContentLoaded", async () => {
    await initEditDriverModal();
});
