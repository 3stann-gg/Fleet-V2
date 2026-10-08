/* ==========================================
   Dispatch Modal
========================================== */

let dispatchModalInitialized = false;

function validateDispatchForm(form) {
    let isValid = true;
    const firstInvalid = [];

    const requiredFields = [
        {
            id: "dispatchNumber",
            validate: (value) => {
                const number = value.trim();
                return (
                    number !== "" &&
                    number !== "Generating..." &&
                    number !== "Unable to generate" &&
                    number.length >= 5
                );
            },
        },
        {
            id: "dispatchReservation",
            validate: (value) => value.trim() !== "",
        },
        {
            id: "dispatchPatient",
            validate: (value) => value.trim() !== "",
        },
        {
            id: "dispatchRequestType",
            validate: (value) => value.trim() !== "",
        },
        {
            id: "dispatchVehicle",
            validate: (value) => value.trim() !== "",
        },
        {
            id: "dispatchDriver",
            validate: (value) => value.trim() !== "",
        },
        {
            id: "dispatchPickup",
            validate: (value) => value.trim() !== "",
        },
        {
            id: "dispatchDestination",
            validate: (value) => value.trim() !== "",
        },
        {
            id: "dispatchDate",
            validate: (value) => {
                if (value.trim() === "") {
                    return false;
                }

                const selectedDate = new Date(value + "T00:00:00");
                const today = new Date();

                today.setHours(0, 0, 0, 0);

                return selectedDate >= today;
            },
        },
        {
            id: "dispatchTime",
            validate: (value) => value.trim() !== "",
        },
        {
            id: "dispatchPriority",
            validate: (value) => value.trim() !== "",
        },
        {
            id: "dispatchStatus",
            validate: (value) =>
                ["Pending", "Assigned", "En Route"].includes(value.trim()),
        },
    ];

    requiredFields.forEach((field) => {
        const element = form.querySelector("#" + field.id);

        if (!element) {
            return;
        }

        const value = element.value || "";
        const valid = field.validate(value);

        if (!valid) {
            isValid = false;
            element.classList.add("is-invalid");
            firstInvalid.push(element);
        } else {
            element.classList.remove("is-invalid");
        }
    });


    const contactEl = form.querySelector("#dispatchContact");
    if (contactEl && contactEl.value.trim() !== "") {
        const contactValid = /^[0-9+\-() ]+$/.test(contactEl.value.trim());

        if (!contactValid) {
            isValid = false;
            contactEl.classList.add("is-invalid");
            firstInvalid.push(contactEl);
        } else {
            contactEl.classList.remove("is-invalid");
        }
    }

    if (firstInvalid.length > 0) {
        firstInvalid[0].focus();
    }

    return isValid;
}


function initDispatchModal() {
    if (dispatchModalInitialized) {
        return;
    }
    const modal = document.getElementById("addDispatchModal");
    const openBtn = document.getElementById("createDispatchBtn");
    if (!modal || !openBtn) {
        return;
    }

    dispatchModalInitialized = true;
    openBtn.addEventListener("click", async () => {
        modal.classList.add("show");
        document.body.style.overflow = "hidden";
        if (typeof loadNextDispatchNumber === "function") {
            await loadNextDispatchNumber();
        }
        if (typeof loadAvailableReservations === "function") {
            await loadAvailableReservations();
        }
    });
    const closeModal = () => {
        modal.classList.remove("show");
        document.body.style.overflow = "";
        const numberInput = document.getElementById("dispatchNumber");
        if (numberInput) {
            numberInput.value = "Generating...";
        }
    };

    const closeBtn = document.getElementById("closeAddDispatchModal");

    if (closeBtn) {
        closeBtn.addEventListener("click", closeModal);
    }

    const cancelBtn = document.getElementById("cancelAddDispatch");

    if (cancelBtn) {
        cancelBtn.addEventListener("click", closeModal);
    }

    modal.addEventListener("click", (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && modal.classList.contains("show")) {
            closeModal();
        }
    });

    const form = document.getElementById("dispatchForm");

    if (form) {
        const inputs = form.querySelectorAll("input, select, textarea");
        inputs.forEach((input) => {
            input.addEventListener("input", () => {
                input.classList.remove("is-invalid");
            });
            input.addEventListener("change", () => {
                input.classList.remove("is-invalid");
            });
        });
    }
}

document.addEventListener("DOMContentLoaded", () => {
    initDispatchModal();
});
