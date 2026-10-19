document.addEventListener("DOMContentLoaded", () => {
    const statusFilter = document.getElementById("sanitationStatusFilter");
    statusFilter?.addEventListener("change", () => {
        const url = new URL(window.location.href);
        if (statusFilter.value === "all") {
            url.searchParams.delete("status");
        } else {
            url.searchParams.set("status", statusFilter.value);
        }
        window.location.href = url.toString();
    });

    document.querySelectorAll(".sanitation-transition").forEach((button) => {
        button.addEventListener("click", async () => {
            const row = button.closest("tr");
            const targetStatus = button.dataset.status;
            const confirmed = await window.showFleetConfirm({
                title:
                    targetStatus === "completed"
                        ? "Complete Cleaning?"
                        : "Start Cleaning?",
                message: "Confirm this sanitation lifecycle action.",
                confirmLabel:
                    targetStatus === "completed"
                        ? "Complete Cleaning"
                        : "Start Cleaning",
                confirmIcon:
                    targetStatus === "completed"
                        ? "ph ph-check-circle"
                        : "ph ph-play",
            });
            if (!confirmed) {
                return;
            }

            button.disabled = true;
            const response = await fetch(`/sanitation/${row.dataset.id}/transition`, {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute("content"),
                },
                credentials: "same-origin",
                body: JSON.stringify({ status: targetStatus }),
            });
            const data = await response.json();
            if (!response.ok) {
                window.showToast?.(data.message || "Unable to update sanitation status.", "error");
                button.disabled = false;
                return;
            }
            window.showToast?.(data.message, "success");
            window.location.reload();
        });
    });
});
