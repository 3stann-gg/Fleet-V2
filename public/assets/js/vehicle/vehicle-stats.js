/* ==========================================
   Dashboard Statistics
========================================== */

function updateVehicleStats() {
    fetch("/fleet/stats")

        .then(response => response.json())
        .then(stats => {

            document.getElementById("totalVehicles").textContent =
                stats.total;

            document.getElementById("availableVehicles").textContent =
                stats.available;

            document.getElementById("onTripVehicles").textContent =
                stats.on_trip;

            document.getElementById("maintenanceVehicles").textContent =
                stats.maintenance;

        })

        .catch(error => {
            console.error(error);

        });

}

document.addEventListener("DOMContentLoaded", updateVehicleStats);
