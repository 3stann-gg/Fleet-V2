/* ==========================================
   Fuel Delete
   Backend-controlled deletion
========================================== */

let deleteFuelInitialized = false;

const deleteFuelModalState = {
    currentRow: null,
    opener: null,
    mode: "single",
    bulkIds: [],
};

function populateDeleteFuel(row) {
    if (!row) {
        return;
    }

    const number =
        row.querySelector(".fuel-number")?.textContent?.trim() ||
        "this fuel record";
    const vehicle =
        row.querySelector(".fuel-vehicle")?.textContent?.trim() ||
        "this vehicle";
    const title = document.getElementById("deleteFuelModalTitle");
    const description = document.getElementById("deleteFuelModalDescription");
    const confirmButton = document.getElementById("confirmDeleteFuel");

    if (title) {
        title.textContent = "Delete Fuel Record";
    }

    if (description) {
        description.innerHTML =
            "Are you sure you want to delete " +
            "<strong>" +
            number +
            "</strong> for <strong>" +
            vehicle +
            "</strong>?";
    }

    if (confirmButton) {
        confirmButton.innerHTML =
            '<i class="ph ph-trash"></i> Delete Fuel Record';
    }
}

function populateBulkDeleteFuel(count) {
    const title = document.getElementById("deleteFuelModalTitle");
    const description = document.getElementById("deleteFuelModalDescription");
    const confirmButton = document.getElementById("confirmDeleteFuel");
    const safeCount = Number(count) || 0;

    if (title) {
        title.textContent = "Delete Selected Fuel Records";
    }

    if (description) {
        description.textContent =
            "Delete " +
            safeCount +
            " selected fuel record" +
            (safeCount === 1 ? "" : "s") +
            "?";
    }

    if (confirmButton) {
        confirmButton.innerHTML = '<i class="ph ph-trash"></i> Delete Selected';
    }
}


async function deleteFuelRecord(rowOrId) {
    const tableBody = document.getElementById("fuelTableBody");

    if (!tableBody) {
        throw new Error("Fuel table was not found.");
    }

    let row = null;
    let id = "";

    if (typeof rowOrId === "string") {
        id = rowOrId.trim();

        if (!id) {
            throw new Error("Invalid fuel record ID.");
        }

        row =
            typeof resolveFuelRowById === "function"
                ? resolveFuelRowById(id)
                : null;
    } else if (rowOrId && rowOrId.nodeType === Node.ELEMENT_NODE) {
        row = rowOrId;

        id = (row.dataset.id || row.dataset.fuelId || "").trim();
    }

    if (!id) {
        throw new Error("Invalid fuel record ID.");
    }

    /*
    |--------------------------------------------------------------------------
    | Backend Delete
    |--------------------------------------------------------------------------
    */

    const response = await fetch(`/fuel-records/${id}`, {
        method: "DELETE",

        headers: {
            Accept: "application/json",

            "X-CSRF-TOKEN": document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute("content"),
        },
    });

    const data = await response.json();

    if (!response.ok) {
        throw new Error(data.message || "Fuel record cannot be deleted.");
    }

    return {
        success: true,
        row,
        id,
        data,
    };
}

function openDeleteFuelModal(row, opener) {
    const modal = document.getElementById("deleteFuelModal");

    if (!modal || !row) {
        return;
    }

    deleteFuelModalState.mode = "single";
    deleteFuelModalState.bulkIds = [];
    deleteFuelModalState.currentRow = row;
    deleteFuelModalState.opener = opener || null;

    populateDeleteFuel(row);

    modal.classList.add("show");
    document.body.style.overflow = "hidden";
    document.getElementById("cancelDeleteFuel")?.focus();
}

function openBulkDeleteFuelModal(ids, opener) {
    const modal = document.getElementById("deleteFuelModal");

    if (!modal) {
        return;
    }

    const bulkIds = Array.isArray(ids)
        ? ids.map((id) => String(id).trim()).filter(Boolean)
        : [];

    if (bulkIds.length === 0) {
        return;
    }

    deleteFuelModalState.mode = "bulk";
    deleteFuelModalState.bulkIds = bulkIds;
    deleteFuelModalState.currentRow = null;
    deleteFuelModalState.opener = opener || null;

    populateBulkDeleteFuel(bulkIds.length);

    modal.classList.add("show");
    document.body.style.overflow = "hidden";
    document.getElementById("cancelDeleteFuel")?.focus();
}


function closeDeleteFuelModal(opener) {
    const modal = document.getElementById("deleteFuelModal");

    if (!modal || !modal.classList.contains("show")) {
        return;
    }

    modal.classList.remove("show");
    document.body.style.overflow = "";

    const focusTarget = opener || deleteFuelModalState.opener;

    deleteFuelModalState.currentRow = null;
    deleteFuelModalState.bulkIds = [];
    deleteFuelModalState.mode = "single";
    deleteFuelModalState.opener = null;

    if (focusTarget && focusTarget.isConnected) {
        focusTarget.focus();
    }
}

async function confirmSingleFuelDelete() {
    const row = deleteFuelModalState.currentRow;

    const opener = deleteFuelModalState.opener;

    if (!row || !row.isConnected) {
        closeDeleteFuelModal(opener);
        return;
    }

    const confirmButton = document.getElementById("confirmDeleteFuel");

    if (confirmButton) {
        confirmButton.disabled = true;
        confirmButton.dataset.processing = "true";
        confirmButton.textContent = "Deleting...";
    }

    try {
        await deleteFuelRecord(row);

        const id = (row.dataset.id || row.dataset.fuelId || "").trim();

        if (id && typeof removeFuelSelectionId === "function") {
            removeFuelSelectionId(id);
        }

        row.remove();

        closeDeleteFuelModal(opener);

        if (typeof refreshFuelTable === "function") {
            refreshFuelTable({
                resetPage: false,
                refreshStatistics: true,
                reason: "delete",
            });
        }

        if (typeof loadFuelRecords === "function") {
            await loadFuelRecords();
        }

        if (typeof showToast === "function") {
            showToast("Fuel record deleted successfully.", "success");
        }
    } catch (error) {
        console.error("FUEL DELETE ERROR:", error);

        /*
        |--------------------------------------------------------------------------
        | Important:
        | Current backend intentionally rejects deletion.
        |--------------------------------------------------------------------------
        */

        closeDeleteFuelModal(opener);

        if (typeof showToast === "function") {
            showToast(
                error.message || "Fuel records cannot be deleted.",
                "warning",
            );
        }
    } finally {
        if (confirmButton) {
            confirmButton.disabled = false;

            confirmButton.dataset.processing = "false";

            confirmButton.innerHTML =
                '<i class="ph ph-trash"></i> Delete Fuel Record';
        }
    }
}

async function confirmBulkFuelDelete() {
    const opener = deleteFuelModalState.opener;

    const ids = Array.from(deleteFuelModalState.bulkIds || []);

    if (ids.length === 0) {
        closeDeleteFuelModal(opener);
        return;
    }

    closeDeleteFuelModal(opener);

    await executeBulkFuelDelete(ids, opener);
}

function initDeleteFuelModal() {
    if (deleteFuelInitialized) {
        return;
    }

    const modal = document.getElementById("deleteFuelModal");

    if (!modal) {
        return;
    }

    deleteFuelInitialized = true;

    document.addEventListener("click", (event) => {
        const deleteBtn = event.target.closest(".action-btn.delete-fuel");

        if (deleteBtn) {
            const row = deleteBtn.closest("tr");

            if (row) {
                openDeleteFuelModal(row, deleteBtn);
            }

            return;
        }

        if (
            event.target.closest("#closeDeleteFuelModal") ||
            event.target.closest("#cancelDeleteFuel") ||
            event.target === modal
        ) {
            closeDeleteFuelModal();
            return;
        }

        if (event.target.closest("#confirmDeleteFuel")) {
            if (deleteFuelModalState.mode === "bulk") {
                confirmBulkFuelDelete();
            } else {
                confirmSingleFuelDelete();
            }
        }
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && modal.classList.contains("show")) {
            closeDeleteFuelModal();
        }
    });
}

document.addEventListener("DOMContentLoaded", () => {
    initDeleteFuelModal();
});
