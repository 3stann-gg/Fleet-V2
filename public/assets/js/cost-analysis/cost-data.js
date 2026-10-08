/* ==========================================
   Cost Analysis Data Adapters

   Real source:
   - Laravel / MySQL
   - Vehicles
   - Fuel Logs
   - Maintenance
   - Dispatch

   No fake trip monetary costs.
========================================== */

function getCostVehicleLabel(vehicle) {
    if (!vehicle) {
        return "";
    }

    const brandModel = [vehicle.brand, vehicle.model]
        .filter(Boolean)
        .map((value) => String(value).trim())
        .filter(Boolean)
        .join(" ")
        .trim();

    const vehicleType = String(
        vehicle.vehicle_type || vehicle.type || "",
    ).trim();

    if (brandModel && vehicleType) {
        return `${brandModel} - ${vehicleType}`;
    }

    return (
        brandModel || vehicleType || vehicle.vehicle_name || vehicle.name || ""
    );
}

function getCostDriverLabel(driver) {
    if (!driver) {
        return "";
    }

    return [driver.first_name, driver.last_name]
        .filter(Boolean)
        .map((value) => String(value).trim())
        .filter(Boolean)
        .join(" ")
        .trim();
}

function costNormalizeDate(value) {
    if (!value) {
        return "";
    }

    return String(value).slice(0, 10);
}

async function fetchCostJson(url) {
    const response = await fetch(url, {
        headers: {
            Accept: "application/json",
        },
        credentials: "same-origin",
    });

    let data = null;

    try {
        data = await response.json();
    } catch {
        data = null;
    }

    if (!response.ok) {
        throw new Error(data?.message || `Unable to load ${url}.`);
    }

    return data;
}

/* ==========================================
   REAL DATABASE SOURCES
========================================== */
/*
async function getCostVehicleData() {
    const data = await fetchCostJson("/fleet");
    if (Array.isArray(data?.vehicles)) {
        return data.vehicles;
    }
    if (Array.isArray(data)) {
        return data;
    }
    return [];
}

async function getCostFuelData() {
    const data = await fetchCostJson("/fuel-records");
    return Array.isArray(data?.fuelLogs) ? data.fuelLogs : [];
}

async function getCostMaintenanceData() {
    const data = await fetchCostJson("/maintenance");
    return Array.isArray(data?.maintenances) ? data.maintenances : [];
}

async function getCostTripData() {
    const data = await fetchCostJson("/dispatch");
    return Array.isArray(data?.dispatches) ? data.dispatches : [];
}
*/

function vehicleLookupMap(vehicles) {
    const map = new Map();

    (vehicles || []).forEach((vehicle) => {
        const label = getCostVehicleLabel(vehicle);

        if (vehicle.id != null) {
            map.set(String(vehicle.id), vehicle);
        }

        if (label) {
            map.set(label, vehicle);
        }
    });

    return map;
}

/**
 * Normalize all supported monetary cost records.
 * Fuel + Maintenance only (valid totalCost/cost fields).
 * Trip Operations / Reservation Operations omitted unless monetary fields exist.
 */
function normalizeCostRecords(sources) {
    const vehicles = sources.vehicles || [];
    const vmap = vehicleLookupMap(vehicles);
    const records = [];

    (sources.fuel || []).forEach((fuel) => {
        const total = Number(fuel.cost);
        if (!Number.isFinite(total)) {
            return;
        }
        const vehicle =
            fuel.vehicle || vmap.get(String(fuel.vehicle_id || "")) || {};
        const driver = fuel.driver || null;
        records.push({
            id: "fuel-" + (fuel.id || fuel.fuel_number),
            referenceNumber: fuel.fuel_number || String(fuel.id || ""),
            date: costNormalizeDate(fuel.date),
            category: "Fuel",
            subcategory: fuel.fuel_type || "",
            vehicleId: fuel.vehicle_id || vehicle.id || "",
            vehicleName: getCostVehicleLabel(vehicle),
            plateNumber: vehicle.plate_number || "",
            vehicleType: vehicle.vehicle_type || "",
            powertrainType: vehicle.powertrain_type || "",
            driverId: fuel.driver_id || driver?.id || "",
            driverName: getCostDriverLabel(driver),
            department: vehicle.department || vehicle.department_name || null,
            sourceModule: "Fuel",
            sourceRecordId: fuel.id || "",
            description:
                (fuel.fuel_type || "Fuel") +
                (fuel.fuel_station ? " @ " + fuel.fuel_station : ""),
            quantity: Number(fuel.fuel_amount) || 0,
            unitCost: Number(fuel.cost_per_liter) || 0,
            totalCost: total,
            distance: null,
            /*
             * Existing FuelLog represents
             * an already executed refueling.
             */
            status: "Completed",
        });
    });

    (sources.maintenance || []).forEach((maintenance) => {
        /*
        |--------------------------------------------------------------------------
        | Actual Cost Only
        |--------------------------------------------------------------------------
        */
        if (maintenance.status !== "Completed") {
            return;
        }
        if (
            maintenance.cost === null ||
            maintenance.cost === undefined ||
            maintenance.cost === ""
        ) {
            return;
        }
        const total = Number(maintenance.cost);
        if (!Number.isFinite(total)) {
            return;
        }
        const vehicle =
            maintenance.vehicle ||
            vmap.get(String(maintenance.vehicle_id || "")) ||
            {};
        records.push({
            id: "mnt-" + (maintenance.id || maintenance.maintenance_number),
            referenceNumber:
                maintenance.maintenance_number || String(maintenance.id || ""),
            date: costNormalizeDate(
                maintenance.completion_date || maintenance.maintenance_date,
            ),
            category: "Maintenance",
            subcategory: maintenance.maintenance_type || "",
            vehicleId: maintenance.vehicle_id || vehicle.id || "",
            vehicleName: getCostVehicleLabel(vehicle),
            plateNumber: vehicle.plate_number || "",
            vehicleType: vehicle.vehicle_type || "",
            powertrainType: vehicle.powertrain_type || "",
            driverId: "",
            driverName: "",
            department: vehicle.department || vehicle.department_name || null,
            sourceModule: "Maintenance",
            sourceRecordId: maintenance.id || "",
            description:
                maintenance.description ||
                maintenance.maintenance_type ||
                "Maintenance",
            quantity: 1,
            unitCost: total,
            totalCost: total,
            distance: null,
            status: maintenance.status,
        });
    });

    /* Trip Operations: only if a real cost field exists on the record */
    (sources.dispatches || []).forEach((dispatch) => {
        const rawTotal =
            dispatch.total_cost ??
            dispatch.totalCost ??
            dispatch.trip_cost ??
            dispatch.tripCost ??
            dispatch.cost;
        /*
        |--------------------------------------------------------------------------
        | Dispatch currently has no monetary cost.
        |--------------------------------------------------------------------------
        */
        if (rawTotal === null || rawTotal === undefined || rawTotal === "") {
            return;
        }
        const total = Number(rawTotal);
        if (!Number.isFinite(total)) {
            return;
        }
        const reservation = dispatch.reservation || null;
        const routePlan =
            reservation?.route_plan || reservation?.routePlan || null;
        const vehicle = reservation?.vehicle || null;
        const driver = reservation?.driver || null;
        const origin = routePlan?.origin || reservation?.pickup_location || "";
        const destination =
            routePlan?.destination || reservation?.destination || "";
        records.push({
            id: "trip-" + (dispatch.id || dispatch.dispatch_number),
            referenceNumber:
                dispatch.dispatch_number || String(dispatch.id || ""),
            date: costNormalizeDate(dispatch.dispatch_date),
            category: "Trip Operations",
            subcategory: dispatch.trip_status || "",
            vehicleId: vehicle?.id || "",
            vehicleName: getCostVehicleLabel(vehicle),
            plateNumber: vehicle?.plate_number || "",
            vehicleType: vehicle?.vehicle_type || "",
            powertrainType: vehicle?.powertrain_type || "",
            driverId: driver?.id || "",
            driverName: getCostDriverLabel(driver),
            department: routePlan?.department || null,
            sourceModule: "Dispatch",
            sourceRecordId: dispatch.id || "",
            description: origin + (destination ? " → " + destination : ""),
            quantity: 1,
            unitCost: total,
            totalCost: total,
            distance:
                routePlan?.estimated_distance != null
                    ? Number(routePlan.estimated_distance)
                    : null,
            status: dispatch.trip_status || "",
        });
    });

    return records;
}

async function loadCostAnalysisSources() {
    const data = await fetchCostJson("/cost-analysis/data");
    return {
        vehicles: Array.isArray(data?.vehicles) ? data.vehicles : [],
        fuel: Array.isArray(data?.fuel) ? data.fuel : [],
        maintenance: Array.isArray(data?.maintenance) ? data.maintenance : [],
        dispatches: Array.isArray(data?.dispatches) ? data.dispatches : [],
        /*
        |--------------------------------------------------------------------------
        | Reserved for future dedicated Cost Analysis sources
        |--------------------------------------------------------------------------
        */
        reservations: [],
        routes: [],
    };
}
