/* ==========================================
   Driver Dashboard Statistics
========================================== */

function updateDriverStats() {
  const totalDrivers = document.getElementById("totalDrivers");
  const availableDrivers = document.getElementById("availableDrivers");
  const onDutyDrivers = document.getElementById("onDutyDrivers");
  const onLeaveDrivers = document.getElementById("onLeaveDrivers");
  const tableBody = document.getElementById("driverTableBody");

  if (
    !totalDrivers ||
    !availableDrivers ||
    !onDutyDrivers ||
    !onLeaveDrivers ||
    !tableBody
  ) {
    return;
  }

  let total = 0;
  let available = 0;
  let onDuty = 0;
  let onLeave = 0;

  tableBody.querySelectorAll("tr").forEach((row) => {
    const isHelperRow =
      row.classList.contains("driver-no-results") ||
      row.classList.contains("helper-row") ||
      row.classList.contains("empty-state") ||
      row.classList.contains("temporary-row") ||
      row.dataset.helperRow === "true" ||
      row.dataset.temporary === "true";
    const isDriverRow = Boolean(
      row.querySelector(".driver-name") ||
        row.querySelector(".driver-checkbox"),
    );

    if (isHelperRow || !isDriverRow) return;

    total += 1;

    const status = (
      row.querySelector(".status-badge")?.textContent || ""
    )
      .trim()
      .toLowerCase()
      .replace(/\s+/g, " ");

    if (status === "available") {
      available += 1;
    } else if (
      status === "on duty" ||
      status === "on-duty" ||
      status === "onduty"
    ) {
      onDuty += 1;
    } else if (status === "on leave" || status === "onleave") {
      onLeave += 1;
    }
  });

  totalDrivers.textContent = total;
  availableDrivers.textContent = available;
  onDutyDrivers.textContent = onDuty;
  onLeaveDrivers.textContent = onLeave;
}
