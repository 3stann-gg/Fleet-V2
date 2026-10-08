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

    document.querySelectorAll(".sanitation-status-update").forEach((select) => {
        select.addEventListener("change", async () => {
            const row = select.closest("tr");
            const response = await fetch(`/sanitation/${row.dataset.id}`, {
                method: "PUT",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute("content"),
                },
                credentials: "same-origin",
                body: JSON.stringify({ status: select.value }),
            });
            const data = await response.json();
            if (!response.ok) {
                window.showToast?.(data.message || "Unable to update sanitation status.", "error");
                return;
            }
            window.showToast?.(data.message, "success");
            window.location.reload();
        });
    });
});
