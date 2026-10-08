function updateReservationStatistics() {
  const tableBody = document.getElementById("reservationTableBody");
  const totalEl = document.getElementById("totalReservations");
  const pendingEl = document.getElementById("pendingReservations");
  const approvedEl = document.getElementById("approvedReservations");
  const completedEl = document.getElementById("completedReservations");

  let total = 0;
  let pending = 0;
  let approved = 0;
  let completed = 0;

  if (tableBody) {
    const rows = tableBody.querySelectorAll("tr");
    rows.forEach((row) => {
      if (
        row.id === "reservation-no-results" ||
        row.classList.contains("reservation-no-results")
      ) {
        return;
      }

      const badge = row.querySelector(".status-badge");
      if (!badge) {
        return;
      }

      const status = badge.textContent.trim();

      total += 1;
      if (status === "Pending") {
        pending += 1;
      } else if (status === "Approved") {
        approved += 1;
      } else if (status === "Completed") {
        completed += 1;
      }
    });
  }

  if (totalEl) totalEl.textContent = total;
  if (pendingEl) pendingEl.textContent = pending;
  if (approvedEl) approvedEl.textContent = approved;
  if (completedEl) completedEl.textContent = completed;
}

(function initReservationStatistics() {
  if (window.__reservationStatsInitialized === "true") {
    return;
  }
  window.__reservationStatsInitialized = "true";

  updateReservationStatistics();

  const tableBody = document.getElementById("reservationTableBody");
  if (tableBody && tableBody.dataset.statsObserverInitialized !== "true") {
    tableBody.dataset.statsObserverInitialized = "true";
    const observer = new MutationObserver(updateReservationStatistics);
    observer.observe(tableBody, {
      childList: true,
      subtree: true,
      characterData: true,
    });
  }

  const refreshBtn = document.getElementById("refreshReservations");
  if (refreshBtn && refreshBtn.dataset.statsRefreshInitialized !== "true") {
    refreshBtn.dataset.statsRefreshInitialized = "true";
    refreshBtn.addEventListener("click", updateReservationStatistics);
  }
})();
