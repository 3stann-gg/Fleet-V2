/**
 * Pre-paint theme bootstrap.
 * Load in <head> before styles so dark mode avoids a light flash.
 * Supports light | dark | system (resolved via prefers-color-scheme).
 */
(function applyHimsFleetThemeBoot() {
  try {
    const preference = localStorage.getItem("himsFleetTheme");
    let resolved = "light";
    if (preference === "dark") {
      resolved = "dark";
    } else if (preference === "system") {
      resolved =
        window.matchMedia &&
        window.matchMedia("(prefers-color-scheme: dark)").matches
          ? "dark"
          : "light";
    }
    document.documentElement.setAttribute("data-theme", resolved);
  } catch {
    document.documentElement.setAttribute("data-theme", "light");
  }
})();
