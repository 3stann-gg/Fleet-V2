(function () {
    "use strict";

    const modal = document.getElementById("fleetConfirmationModal");

    if (!modal) return;

    const dialog = modal.querySelector(".fleet-confirm-modal");
    const title = document.getElementById("fleetConfirmationTitle");
    const message = document.getElementById("fleetConfirmationMessage");
    const icon = document.getElementById("fleetConfirmationIcon");
    const closeButton = document.getElementById("closeFleetConfirmation");
    const cancelButton = document.getElementById("cancelFleetConfirmation");
    const confirmButton = document.getElementById("confirmFleetConfirmation");
    const confirmButtonIcon = confirmButton?.querySelector("i");
    const confirmButtonLabel = confirmButton?.querySelector("span");
    let resolveConfirmation = null;
    let previousFocus = null;
    let previousBodyOverflow = "";

    function finish(confirmed) {
        if (!resolveConfirmation) return;

        const resolve = resolveConfirmation;
        resolveConfirmation = null;
        modal.classList.remove("show");
        modal.hidden = true;
        document.body.style.overflow = previousBodyOverflow;
        confirmButton.disabled = false;
        resolve(Boolean(confirmed));

        if (previousFocus instanceof HTMLElement) {
            previousFocus.focus();
        }

        previousFocus = null;
    }

    function focusableElements() {
        return Array.from(
            modal.querySelectorAll(
                'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            ),
        ).filter((element) => !element.hidden);
    }

    window.showFleetConfirm = function (options = {}) {
        if (resolveConfirmation) {
            finish(false);
        }

        const tone = options.tone === "danger" ? "danger" : "primary";
        const isDanger = tone === "danger";

        title.textContent = options.title || "Confirm action";
        message.textContent = options.message || "Are you sure you want to continue?";
        cancelButton.textContent = options.cancelLabel || "Cancel";
        confirmButtonLabel.textContent = options.confirmLabel || "Confirm";
        confirmButton.className = isDanger ? "btn-danger" : "btn-primary";
        confirmButtonIcon.className = options.confirmIcon || (isDanger ? "ph ph-warning" : "ph ph-check");
        confirmButtonIcon.setAttribute("aria-hidden", "true");
        icon.className = options.icon || (isDanger ? "ph-fill ph-warning-circle" : "ph-fill ph-check-circle");
        dialog.classList.toggle("fleet-confirm-modal--primary", !isDanger);

        previousFocus = document.activeElement;
        previousBodyOverflow = document.body.style.overflow;
        modal.hidden = false;
        modal.classList.add("show");
        document.body.style.overflow = "hidden";

        window.requestAnimationFrame(() => cancelButton.focus());

        return new Promise((resolve) => {
            resolveConfirmation = resolve;
        });
    };

    closeButton?.addEventListener("click", () => finish(false));
    cancelButton?.addEventListener("click", () => finish(false));
    confirmButton?.addEventListener("click", () => {
        if (confirmButton.disabled) return;

        confirmButton.disabled = true;
        finish(true);
    });

    modal.addEventListener("click", (event) => {
        if (event.target === modal) finish(false);
    });

    modal.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            event.preventDefault();
            finish(false);
            return;
        }

        if (event.key !== "Tab") return;

        const focusable = focusableElements();

        if (focusable.length === 0) {
            event.preventDefault();
            dialog?.focus();
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    const inputModal = document.getElementById("fleetInputPromptModal");

    if (!inputModal) return;

    const inputDialog = inputModal.querySelector(".fleet-input-modal");
    const inputForm = document.getElementById("fleetInputPromptForm");
    const inputTitle = document.getElementById("fleetInputPromptTitle");
    const inputMessage = document.getElementById("fleetInputPromptMessage");
    const inputLabel = document.getElementById("fleetInputPromptLabel");
    const inputField = document.getElementById("fleetInputPromptField");
    const inputError = document.getElementById("fleetInputPromptError");
    const inputCloseButton = document.getElementById("closeFleetInputPrompt");
    const inputCancelButton = document.getElementById("cancelFleetInputPrompt");
    const inputConfirmButton = document.getElementById("confirmFleetInputPrompt");
    const inputConfirmLabel = inputConfirmButton?.querySelector("span");
    let resolveInputPrompt = null;
    let inputPreviousFocus = null;
    let inputPreviousBodyOverflow = "";
    let inputRequired = false;
    let inputRequiredMessage = "This field is required.";

    function finishInputPrompt(value) {
        if (!resolveInputPrompt) return;

        const resolve = resolveInputPrompt;
        resolveInputPrompt = null;
        inputModal.classList.remove("show");
        inputModal.hidden = true;
        document.body.style.overflow = inputPreviousBodyOverflow;
        inputError.textContent = "";
        resolve(value);

        if (inputPreviousFocus instanceof HTMLElement) {
            inputPreviousFocus.focus();
        }

        inputPreviousFocus = null;
    }

    function inputFocusableElements() {
        return Array.from(
            inputModal.querySelectorAll(
                'button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])',
            ),
        ).filter((element) => !element.hidden);
    }

    window.showInputPrompt = function (options = {}) {
        if (resolveInputPrompt) {
            finishInputPrompt(null);
        }

        inputTitle.textContent = options.title || "Enter a value";
        inputMessage.textContent = options.message || "Provide the requested information.";
        inputLabel.textContent = options.label || "Value";
        inputField.value = options.value == null ? "" : String(options.value);
        inputField.placeholder = options.placeholder || "";
        inputField.inputMode = options.inputMode || "text";
        inputField.setAttribute("aria-required", String(options.required === true));
        inputCancelButton.textContent = options.cancelText || "Cancel";
        inputConfirmLabel.textContent = options.confirmText || "Confirm";
        inputRequired = options.required === true;
        inputRequiredMessage = options.requiredMessage || "This field is required.";
        inputError.textContent = "";

        inputPreviousFocus = document.activeElement;
        inputPreviousBodyOverflow = document.body.style.overflow;
        inputModal.hidden = false;
        inputModal.classList.add("show");
        document.body.style.overflow = "hidden";

        window.requestAnimationFrame(() => {
            inputField.focus();
            inputField.select();
        });

        return new Promise((resolve) => {
            resolveInputPrompt = resolve;
        });
    };

    inputForm?.addEventListener("submit", (event) => {
        event.preventDefault();

        if (inputRequired && inputField.value.trim() === "") {
            inputError.textContent = inputRequiredMessage;
            inputField.focus();
            return;
        }

        finishInputPrompt(inputField.value);
    });

    inputField?.addEventListener("input", () => {
        if (inputError.textContent) {
            inputError.textContent = "";
        }
    });

    inputCloseButton?.addEventListener("click", () => finishInputPrompt(null));
    inputCancelButton?.addEventListener("click", () => finishInputPrompt(null));

    inputModal.addEventListener("click", (event) => {
        if (event.target === inputModal) finishInputPrompt(null);
    });

    inputModal.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            event.preventDefault();
            finishInputPrompt(null);
            return;
        }

        if (event.key !== "Tab") return;

        const focusable = inputFocusableElements();
        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (!first || !last) {
            event.preventDefault();
            inputDialog?.focus();
        } else if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
})();
