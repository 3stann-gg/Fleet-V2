/* ==========================================
   Vehicle Image Upload
========================================== */

const vehiclePlaceholderSource = "../assets/images/vehicle-placeholder.svg";

function getVehiclePlaceholderSource(image) {
  if (!image) return vehiclePlaceholderSource;

  if (!image.dataset.placeholderSrc) {
    image.dataset.placeholderSrc =
      image.getAttribute("src") || vehiclePlaceholderSource;
  }

  return image.dataset.placeholderSrc;
}

function resetVehicleImagePreview() {
  const input = document.getElementById("vehicleImage");
  const preview = document.getElementById("vehiclePreview");

  if (preview) {
    preview.src = getVehiclePlaceholderSource(preview);
  }

  if (input) {
    input.value = "";
  }
}

function initVehicleImageUpload() {
  const input = document.getElementById("vehicleImage");
  const preview = document.getElementById("vehiclePreview");

  if (!input || !preview || input.dataset.vehicleImageInitialized === "true") {
    return;
  }

  input.dataset.vehicleImageInitialized = "true";
  getVehiclePlaceholderSource(preview);

  input.addEventListener("change", () => {
    const file = input.files?.[0];

    if (!file || !file.type.startsWith("image/")) {
      input.value = "";
      return;
    }

    const reader = new FileReader();

    reader.addEventListener("load", () => {
      if (typeof reader.result === "string") {
        preview.src = reader.result;
      }
    });

    reader.readAsDataURL(file);
  });
}
