/* ==========================================
   Toast Notification
========================================== */

function ensureToastContainer() {
  let container = document.getElementById("toastContainer");
  if (container) return container;

  const host = document.getElementById("toast");
  if (host) {
    container = document.createElement("div");
    container.id = "toastContainer";
    container.className = "toast-container";
    host.appendChild(container);
    return container;
  }

  container = document.createElement("div");
  container.id = "toastContainer";
  container.className = "toast-container";
  document.body.appendChild(container);
  return container;
}

function initToast() {
  window.showToast = function (message, type = "success") {
    const container = ensureToastContainer();
    if (!container) return;

    const toast = document.createElement("div");
    const safeType = type || "success";
    toast.className = "toast-message " + safeType;

    let icon = "ph-check-circle";
    if (safeType === "error") icon = "ph-x-circle";
    else if (safeType === "warning") icon = "ph-warning-circle";
    else if (safeType === "info") icon = "ph-info";

    const iconEl = document.createElement("i");
    iconEl.className = "ph-fill " + icon;
    iconEl.setAttribute("aria-hidden", "true");

    const textEl = document.createElement("span");
    textEl.textContent = String(message == null ? "" : message);

    toast.appendChild(iconEl);
    toast.appendChild(textEl);
    container.appendChild(toast);

    setTimeout(() => {
      toast.classList.add("show");
    }, 100);

    setTimeout(() => {
      toast.classList.remove("show");
      setTimeout(() => {
        toast.remove();
      }, 300);
    }, 3000);
  };
}

document.addEventListener("DOMContentLoaded", () => {
    initToast();
});