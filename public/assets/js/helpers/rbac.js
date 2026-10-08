/* ==========================================
   Fleet Frontend RBAC Helpers 
========================================== */

window.FleetRBAC = window.FleetRBAC || {};

window.FleetRBAC.getModulePermissions = function (moduleName) {
    const permissions = window.FLEET_RBAC?.[moduleName] || {};

    return permissions;
};

window.FleetRBAC.hasPermission = function (moduleName, permission) {
    const permissions = window.FleetRBAC.getModulePermissions(moduleName);

    return permissions?.[permission] === true;
};

window.FleetRBAC.getRole = function () {
    return window.FLEET_RBAC?.role || "";
};

window.FleetRBAC.hasRole = function (...roles) {
    return roles.includes(window.FleetRBAC.getRole());
};
