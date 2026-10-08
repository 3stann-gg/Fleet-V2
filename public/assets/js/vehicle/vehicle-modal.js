/* ==========================================
   Vehicle Modal
========================================== */

function openVehicleModal(modal) {
  if (!modal) return;

  if (!modal.classList.contains("show")) {
    modal.dataset.previousBodyOverflow = document.body.style.overflow;
  }

  modal.classList.add("show");
  document.body.style.overflow = "hidden";
  loadAvailableDrivers();
}

function closeVehicleModal(modal) {
  if (!modal || !modal.classList.contains("show")) return;

  modal.classList.remove("show");
  document.body.style.overflow = modal.dataset.previousBodyOverflow || "";
  delete modal.dataset.previousBodyOverflow;
}

function initVehicleModal() {
  const modal = document.getElementById("vehicleModal");
  const openButton = document.getElementById("addVehicleBtn");
  const closeButton = document.getElementById("closeVehicleModal");
  const cancelButton = document.getElementById("cancelVehicleModal");

  if (!modal || modal.dataset.vehicleModalInitialized === "true") return;

  modal.dataset.vehicleModalInitialized = "true";

  openButton?.addEventListener("click", () => openVehicleModal(modal));
  closeButton?.addEventListener("click", () => closeVehicleModal(modal));
  cancelButton?.addEventListener("click", () => closeVehicleModal(modal));

  modal.addEventListener("click", (event) => {
    if (event.target === modal) {
      closeVehicleModal(modal);
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && modal.classList.contains("show")) {
      closeVehicleModal(modal);
    }
  });
}

document.addEventListener("DOMContentLoaded", () => {
    initVehicleModal();
});
