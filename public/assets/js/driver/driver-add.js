/* ==========================================
   Add Driver
========================================== */

/* ==========================================
   Driver Module Settings
========================================== */

window.getDriverModuleSettings =
  window.getDriverModuleSettings ||
  async function () {
    const defaults = {
      requireLicenseExpiry: true,
      warnLicenseDays: 30,
      defaultStatus: "Available",
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
          "Unable to load Driver settings."
        );
      }

      const data = await response.json();

      const settings =
        data?.settings?.drivers;

      if (
        !settings ||
        typeof settings !== "object"
      ) {
        return defaults;
      }

      const allowedStatuses = [
        "Available",
        "On Leave",
        "Inactive",
      ];

      return {
        requireLicenseExpiry:
          settings.requireLicenseExpiry !== false,

        warnLicenseDays: Math.max(
          1,
          Math.min(
            180,
            Number(
              settings.warnLicenseDays ?? 30
            )
          )
        ),

        defaultStatus:
          allowedStatuses.includes(
            settings.defaultStatus
          )
            ? settings.defaultStatus
            : defaults.defaultStatus,
      };
    } catch (error) {
      console.error(
        "Driver settings load error:",
        error
      );

      return defaults;
    }
  };

function applyDriverAddSettings(settings) {
  const expiry =
    document.getElementById(
      "driverLicenseExpiry"
    );

  const expiryMark =
    document.getElementById(
      "driverLicenseExpiryRequiredMark"
    );
  const expiryHint =
    document.getElementById(
      "driverLicenseExpiryHint"
    );
  const status =
    document.getElementById(
      "driverStatus"
    );
  const expiryRequired =
    settings?.requireLicenseExpiry !== false;

  if (expiry) {
    expiry.required = expiryRequired;
  }
  if (expiryMark) {
    expiryMark.hidden =
      !expiryRequired;
  }
  if (expiryHint) {
    expiryHint.textContent =
      expiryRequired
        ? "License expiry is required."
        : `License expiry is optional. Expiry warnings use the configured ${settings.warnLicenseDays}-day threshold.`;
  }
  if (status && !status.value) {
    status.value =
      settings?.defaultStatus ||
      "Available";
  }
}

//   RBAC
function canCreateDriver() {
    return window.FleetRBAC?.hasPermission?.("drivers", "canCreate") === true;
}

function setDriverFieldValidationMessage(field) {
  field.setCustomValidity("Please complete this required field.");

  const clearValidationMessage = () => {
    field.setCustomValidity("");
  };

  field.addEventListener("input", clearValidationMessage, { once: true });
  field.addEventListener("change", clearValidationMessage, { once: true });
  field.reportValidity();
  field.focus();
}

async function initDriverAdd() {
  if (!canCreateDriver()) {
      return;
  }
  const form = document.getElementById("driverForm");

  if (!form || form.dataset.driverAddInitialized === "true") {
      return;
  }

  /*
|--------------------------------------------------------------------------
| Lock initialization immediately
|--------------------------------------------------------------------------
*/
  form.dataset.driverAddInitialized = "true";

  const driverSettings = await window.getDriverModuleSettings();

  applyDriverAddSettings(driverSettings);

  const alwaysRequiredFieldIds = [
      "driverFirstName",
      "driverLastName",
      "driverLicenseNumber",
      "driverLicenseClass",
      "driverPhone",
      "driverEmail",
      "driverStatus",
  ];

  const alwaysRequiredFields = alwaysRequiredFieldIds
      .map((id) => document.getElementById(id))
      .filter(Boolean);

  alwaysRequiredFields.forEach((field) => {
      field.required = true;
  });

  function getRequiredDriverFields() {
      const fields = [...alwaysRequiredFields];
      const expiry = document.getElementById("driverLicenseExpiry");
      if (expiry && expiry.required) {
          fields.push(expiry);
      }

      return fields;
  }

  form.addEventListener("submit", async (event) => {
      event.preventDefault();

      if (form.dataset.driverSubmitting === "true") {
          return;
      }

      const requiredFields = getRequiredDriverFields();
      const emptyField = requiredFields.find(
          (field) => !String(field.value || "").trim(),
      );
      if (emptyField) {
          setDriverFieldValidationMessage(emptyField);

          return;
      }
      if (!form.checkValidity()) {
          form.reportValidity();

          return;
      }

      form.dataset.driverSubmitting = "true";

      const formData = new FormData();
      const vehicle = document.getElementById("driverAssignedVehicle").value;

      formData.append(
          "first_name",
          document.getElementById("driverFirstName").value.trim(),
      );
      formData.append(
          "last_name",
          document.getElementById("driverLastName").value.trim(),
      );
      formData.append(
          "license_number",
          document.getElementById("driverLicenseNumber").value.trim(),
      );
      formData.append(
          "license_class",
          document.getElementById("driverLicenseClass").value,
      );
      formData.append(
          "license_expiry",
          document.getElementById("driverLicenseExpiry").value,
      );
      formData.append(
          "clutch_authorization",
          document.getElementById("driverClutchAuthorization")?.value || "",
      );
      document
          .querySelectorAll('#driverDlCodeOptions input[type="checkbox"]:checked')
          .forEach((checkbox) => {
              formData.append("dl_codes[]", checkbox.value);
          });
      formData.append(
          "contact_number",
          document.getElementById("driverPhone").value,
      );
      formData.append(
          "email",
          document.getElementById("driverEmail").value.trim(),
      );
      formData.append("assigned_vehicle_id", vehicle || "");
      formData.append(
          "experience",
          document.getElementById("driverExperience").value,
      );
      formData.append("status", document.getElementById("driverStatus").value);
      formData.append(
          "address",
          document.getElementById("driverAddress").value,
      );
      formData.append(
          "emergency_contact",
          document.getElementById("driverEmergencyContact").value,
      );
      formData.append("notes", document.getElementById("driverNotes").value);

      document
          .querySelectorAll('#driverQualificationOptions input[type="checkbox"]:checked')
          .forEach((checkbox) => {
              formData.append("qualifications[]", checkbox.value);
          });

      const image = document.getElementById("driverImage").files[0];

      if (image) {
          formData.append("photo", image);
      }

      try {
          console.log([...formData.entries()]);
          const response = await fetch("/drivers", {
              method: "POST",
              headers: {
                  "X-CSRF-TOKEN": document.querySelector(
                      'meta[name="csrf-token"]',
                  ).content,
                  Accept: "application/json",
              },
              body: formData,
          });

          const data = await response.json();

          if (response.status === 422) {
              const firstError = data?.errors
                  ? Object.values(data.errors).flat()[0]
                  : null;

              window.showToast(
                  firstError ||
                      data?.message ||
                      "Please check the driver information.",
                  "error",
              );

              return;
          }

          if (!response.ok) {
              throw new Error(data?.message || "Failed to add driver.");
          }

          if (data.success) {
              loadDrivers();

              if (typeof loadDriverVehicleOptions === "function") {
                  await loadDriverVehicleOptions();
              }

              form.reset();

              applyDriverAddSettings(driverSettings);

              if (typeof resetDriverImagePreview === "function") {
                  resetDriverImagePreview();
              }
              if (typeof closeDriverModal === "function") {
                  closeDriverModal();
              }
              window.showToast(data.message, "success");
          }
      } catch (error) {
          console.error("Driver creation error:", error);

          window.showToast(error?.message || "Failed to add driver.", "error");
      } finally {
          form.dataset.driverSubmitting = "false";
      }
  });
}

document.addEventListener("DOMContentLoaded", async () => {
    await initDriverAdd();
});
