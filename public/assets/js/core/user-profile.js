/* ==========================================
   Shared Authenticated User Profile UI

   Laravel / MySQL backed.
   No localStorage profile persistence.
========================================== */

/* ==========================================
   HELPERS
========================================== */

function getUserInitials(profile = {}) {
    const firstName = String(profile.firstName || "").trim();

    const lastName = String(profile.lastName || "").trim();

    const displayName = String(profile.displayName || "").trim();

    const initials = (firstName.charAt(0) + lastName.charAt(0)).toUpperCase();

    if (initials) {
        return initials;
    }

    const parts = displayName.split(/\s+/).filter(Boolean);

    if (parts.length >= 2) {
        return (
            parts[0].charAt(0) + parts[parts.length - 1].charAt(0)
        ).toUpperCase();
    }

    return displayName.slice(0, 2).toUpperCase() || "U";
}

/* ==========================================
   READ CURRENT PROFILE FROM PAGE
========================================== */

function getUserProfileFromPage() {
    const firstName = document.getElementById("profileFirstName")?.value || "";

    const lastName = document.getElementById("profileLastName")?.value || "";

    const displayName =
        document.getElementById("profileDisplayName")?.value ||
        document.getElementById("profileOverviewName")?.textContent ||
        "User";

    const role =
        document.getElementById("profileOverviewRole")?.textContent ||
        document.getElementById("profileAccountRole")?.textContent ||
        "Staff";

    const image = document.getElementById("profileAvatarImage");

    return {
        firstName: String(firstName).trim(),

        lastName: String(lastName).trim(),

        displayName: String(displayName).trim(),

        role: String(role).trim(),

        avatar: image && !image.hidden && image.src ? image.src : null,
    };
}

/* ==========================================
   SYNC NAVBAR / SIDEBAR PROFILE UI
========================================== */

function syncUserProfileUI(profile = null) {
    const p = profile || getUserProfileFromPage();

    const name = p.displayName || "User";

    const role = p.role || "Staff";

    const initials = getUserInitials(p);

    document.querySelectorAll(".profile-name").forEach((element) => {
        element.textContent = name;
    });

    document.querySelectorAll(".profile-role").forEach((element) => {
        element.textContent = role;
    });

    document.querySelectorAll(".profile-avatar").forEach((element) => {
        if (p.avatar) {
            element.classList.add("has-photo");

            element.style.backgroundImage = `url("${String(p.avatar).replace(
                /"/g,
                "",
            )}")`;

            element.style.backgroundSize = "cover";

            element.style.backgroundPosition = "center";

            element.textContent = "";
        } else {
            element.classList.remove("has-photo");

            element.style.backgroundImage = "";

            element.textContent = initials;
        }

        element.setAttribute("aria-label", name);
    });

    const toggle = document.getElementById("sidebarProfileToggle");

    if (toggle) {
        toggle.setAttribute("aria-label", `${name}, ${role}`);
    }
}
