/* ==========================================
   Vehicle Form Dropdowns
========================================== */

function getVehicleStatusClass(status) {
  const normalizedStatus = (status || "")
    .trim()
    .toLowerCase()
    .replace(/[\s-]+/g, "");

  if (normalizedStatus === "available") return "available";
  if (normalizedStatus === "ontrip") return "trip";
  if (normalizedStatus === "maintenance") return "maintenance";

  return "out";
}

function initVehicleForm() {
  const form = document.getElementById("vehicleForm");
  const vehicleType = document.getElementById("vehicleType");
  const vehicleDriver = document.getElementById("vehicleDriver");
  const vehicleFuel = document.getElementById("vehicleFuel");
  const vehicleStatus = document.getElementById("vehicleStatus");

  if (form?.dataset.vehicleFormInitialized === "true") return;

  if (vehicleType) {
    vehicleType.innerHTML = `
      <option value="">Select Vehicle Type</option>
      <option>Ambulance</option>
      <option>Patient Van</option>
      <option>Service Vehicle</option>
      <option>SUV</option>
      <option>Motorcycle</option>
    `;
  }

  if (vehicleDriver) {
    vehicleDriver.innerHTML = `
      <option value="">Select Driver</option>
      <option>Juan Dela Cruz</option>
      <option>Pedro Santos</option>
      <option>Maria Reyes</option>
      <option>Carlos Mendoza</option>
    `;
  }

  if (vehicleFuel) {
    vehicleFuel.innerHTML = `
      <option value="">Select Fuel Type</option>
      <option>Diesel</option>
      <option>Gasoline</option>
      <option>Premium Gasoline</option>
    `;
  }

  if (vehicleStatus) {
    vehicleStatus.innerHTML = `
      <option value="">Select Status</option>
      <option>Available</option>
      <option>On Trip</option>
      <option>Maintenance</option>
      <option>Out of Service</option>
    `;
  }

  if (form) {
    form.dataset.vehicleFormInitialized = "true";
  }
}
