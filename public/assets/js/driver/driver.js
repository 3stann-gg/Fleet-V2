/* ==========================================
   Driver Add Modal
========================================== */

function getDriverModal() {
  return document.getElementById("addDriverModal");
}

function openDriverModal() {
  const modal = getDriverModal();

  if (!modal) return;

  if (!modal.classList.contains("show")) {
    modal.dataset.previousBodyOverflow = document.body.style.overflow;
  }

  modal.classList.add("show");
  document.body.style.overflow = "hidden";
}

function closeDriverModal() {
  const modal = getDriverModal();

  if (!modal || !modal.classList.contains("show")) return;

  modal.classList.remove("show");
  document.body.style.overflow = modal.dataset.previousBodyOverflow || "";
  delete modal.dataset.previousBodyOverflow;
}

function initDriverModal() {
  const modal = getDriverModal();

  if (!modal || modal.dataset.driverModalInitialized === "true") return;

  const openButton = document.getElementById("addDriverBtn");
  const closeButton = document.getElementById("closeAddDriverModal");
  const cancelButton = document.getElementById("cancelAddDriver");

  modal.dataset.driverModalInitialized = "true";

  if (openButton) {
    openButton.addEventListener("click", openDriverModal);
  }

  if (closeButton) {
    closeButton.addEventListener("click", closeDriverModal);
  }

  if (cancelButton) {
    cancelButton.addEventListener("click", closeDriverModal);
  }

  modal.addEventListener("click", (event) => {
    if (event.target === modal) {
      closeDriverModal();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && modal.classList.contains("show")) {
      closeDriverModal();
    }
  });
}

function setDriverSelectOptions(select, options) {
  if (!select) return;

  const optionElements = options.map(({ label, value }) =>
    new Option(label, value),
  );

  select.replaceChildren(...optionElements);
}

async function loadDriverVehicleOptions() {
    const select = document.getElementById("driverAssignedVehicle");

    if (!select) return;

    try {
        const response = await fetch("/fleet/available", {
            headers: {
                "Accept": "application/json"
            }
        });

        if (!response.ok) {
            throw new Error(`Failed to load vehicles: ${response.status}`);
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

            // Skip vehicles that already have a driver
            if (vehicle.drivers && vehicle.drivers.length > 0) {
                return;
            }

            const brand = vehicle.brand ?? "";
            const model = vehicle.model ?? "";
            const type = vehicle.vehicle_type ?? "";
            const vehicleName = `${brand} ${model}`.trim();
            options.push({
                label: `${vehicleName} - ${type}`,
                value: vehicle.id
            });
        });

        setDriverSelectOptions(select, options);

    } catch (error) {
        console.error("DRIVER VEHICLE DROPDOWN ERROR:", error);
        setDriverSelectOptions(select, [
            {
                label: "Unable to load vehicles",
                value: ""
            }
        ]);
    }
}

function initDriverForm() {
  const form = document.getElementById("driverForm");

  if (!form || form.dataset.driverFormInitialized === "true") return;

  const licenseClass = document.getElementById("driverLicenseClass");
  const assignedVehicle = document.getElementById("driverAssignedVehicle");
  const status = document.getElementById("driverStatus");

  setDriverSelectOptions(licenseClass, [
    { label: "Select License Class", value: "" },
    { label: "Professional", value: "Professional" },
    { label: "Non-Professional", value: "Non-Professional" },
  ]);

  loadDriverVehicleOptions();

  setDriverSelectOptions(status, [
    { label: "Select Status", value: "" },
    { label: "Available", value: "Available" },
    //{ label: "On Duty", value: "On Duty" },
    { label: "On Leave", value: "On Leave" },
    { label: "Inactive", value: "Inactive" },
  ]);

  form.dataset.driverFormInitialized = "true";
}

function getDriverPlaceholderSource(preview) {
  if (!preview) return "";

  return preview.dataset.placeholderSrc || preview.getAttribute("src") || "";
}

function resetDriverImagePreview() {
  const imageInput = document.getElementById("driverImage");
  const preview = document.getElementById("driverPreview");

  if (imageInput) {
    imageInput.value = "";
  }

  if (!preview) return;

  const placeholderSource = getDriverPlaceholderSource(preview);

  if (placeholderSource) {
    preview.src = placeholderSource;
  }
}

function initDriverImageUpload() {
  const imageInput = document.getElementById("driverImage");
  const preview = document.getElementById("driverPreview");

  if (!imageInput || !preview) return;

  if (!preview.dataset.placeholderSrc) {
    preview.dataset.placeholderSrc = preview.getAttribute("src") || preview.src;
  }

  if (imageInput.dataset.driverImageInitialized === "true") return;

  imageInput.dataset.driverImageInitialized = "true";

  imageInput.addEventListener("change", () => {
    const file = imageInput.files && imageInput.files[0];

    if (!file) return;

    if (!file.type.startsWith("image/")) {
      imageInput.value = "";
      return;
    }

    const reader = new FileReader();

    reader.addEventListener("load", () => {
      preview.src = reader.result;
    });

    reader.readAsDataURL(file);
  });
}

document.addEventListener("DOMContentLoaded", () => {
    initDriverModal();
    initDriverForm();
    initDriverImageUpload();
});
