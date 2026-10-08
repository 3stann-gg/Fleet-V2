/* ==========================================
   Cross-page pending action intents
   sessionStorage only — one-shot, short-lived
========================================== */

const HIMS_FLEET_PENDING_ACTION_KEY = "himsFleetPendingAction";
const HIMS_FLEET_PENDING_ACTION_MAX_AGE_MS = 60 * 1000;

function setPendingFleetAction(action, payload) {
  if (!action) return false;
  try {
    sessionStorage.setItem(
      HIMS_FLEET_PENDING_ACTION_KEY,
      JSON.stringify({
        action: String(action),
        payload: payload || null,
        at: Date.now(),
      }),
    );
    return true;
  } catch (error) {
    console.error("Unable to store pending action:", error);
    return false;
  }
}

function peekPendingFleetAction() {
  try {
    const raw = sessionStorage.getItem(HIMS_FLEET_PENDING_ACTION_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    if (!parsed || !parsed.action) return null;
    if (
      parsed.at &&
      Date.now() - Number(parsed.at) > HIMS_FLEET_PENDING_ACTION_MAX_AGE_MS
    ) {
      clearPendingFleetAction();
      return null;
    }
    return parsed;
  } catch {
    clearPendingFleetAction();
    return null;
  }
}

function clearPendingFleetAction() {
  try {
    sessionStorage.removeItem(HIMS_FLEET_PENDING_ACTION_KEY);
  } catch {
    /* ignore */
  }
}

/**
 * Consume a pending intent once. handlers: { actionName: fn }
 * Returns true if an action ran.
 */
function consumePendingFleetAction(handlers) {
  const pending = peekPendingFleetAction();
  clearPendingFleetAction();
  if (!pending || !handlers) return false;
  const fn = handlers[pending.action];
  if (typeof fn !== "function") return false;
  try {
    fn(pending.payload);
    return true;
  } catch (error) {
    console.error("Pending action failed:", pending.action, error);
    return false;
  }
}

function navigateWithPendingFleetAction(url, action, payload) {
  if (action) setPendingFleetAction(action, payload);
  window.location.href = url;
}
