/* ==========================================
   Reports Data Adapters

   Real Laravel / MySQL sources:
   - Vehicles
   - Drivers
   - Reservations
   - Dispatch
   - Maintenance
   - Fuel Logs

   No sample operational data.
========================================== */

/* ==========================================
   COMMON HELPERS
========================================== */

function getReportVehicleLabel(vehicle) {
    if (!vehicle) {
        return "";
    }

    const brandModel = [vehicle.brand, vehicle.model]
        .filter(Boolean)
        .map((value) => String(value).trim())
        .filter(Boolean)
        .join(" ")
        .trim();

    const type = String(vehicle.vehicle_type || vehicle.type || "").trim();

    if (brandModel && type) {
        return `${brandModel} - ${type}`;
    }

    return brandModel || type || vehicle.vehicle_name || vehicle.name || "";
}

function getReportDriverLabel(driver) {
    if (!driver) {
        return "";
    }

    const fullName = [driver.first_name, driver.last_name]
        .filter(Boolean)
        .map((value) => String(value).trim())
        .filter(Boolean)
        .join(" ")
        .trim();

    return fullName || driver.name || "";
}

function normalizeReportDate(value) {
    if (!value) {
        return "";
    }
    return String(value).slice(0, 10);
}

async function getReportsSourceData() {
    return await fetchReportsJson("/reports/data");
}

async function fetchReportsJson(url) {
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
   LOAD ALL REPORT SOURCES
========================================== */

async function getAllReportsSourceData() {
    const data = await fetchReportsJson("/reports/data");
    const vehiclesRaw = Array.isArray(data?.vehicles) ? data.vehicles : [];
    const driversRaw = Array.isArray(data?.drivers) ? data.drivers : [];
    const reservationsRaw = Array.isArray(data?.reservations)
        ? data.reservations
        : [];
    const dispatchesRaw = Array.isArray(data?.dispatches)
        ? data.dispatches
        : [];
    const maintenanceRaw = Array.isArray(data?.maintenance)
        ? data.maintenance
        : [];
    const fuelRaw = Array.isArray(data?.fuel) ? data.fuel : [];

    const vehicles = vehiclesRaw.map((vehicle) => ({
        id: String(vehicle.id ?? ""),
        name: getReportVehicleLabel(vehicle),
        plateNumber: vehicle.plate_number || "",
        type: vehicle.vehicle_type || vehicle.type || "",
        status: vehicle.status || "",
        department: vehicle.department || "",
        capacity: Number(vehicle.capacity) || 0,
        raw: vehicle,
    }));

    const drivers = driversRaw.map((driver) => {
        const assignedVehicle =
            driver.vehicle || driver.assigned_vehicle || null;
        return {
            id: String(driver.id ?? ""),
            name: getReportDriverLabel(driver),
            status: driver.status || "",
            assignedVehicle: assignedVehicle
                ? getReportVehicleLabel(assignedVehicle)
                : driver.assigned_vehicle_name || "",
            department: assignedVehicle?.department || null,
            raw: driver,
        };
    });

    const reservations = reservationsRaw.map((reservation) => {
        const vehicle = reservation.vehicle || null;
        const routePlan =
            reservation.route_plan || reservation.routePlan || null;
        return {
            id: String(reservation.id ?? ""),
            reservationNumber:
                reservation.reservation_number || String(reservation.id || ""),
            date: normalizeReportDate(reservation.schedule_date),
            vehicleId:
                reservation.vehicle_id != null
                    ? String(reservation.vehicle_id)
                    : "",
            vehicleName: getReportVehicleLabel(vehicle),
            requester:
                reservation.requester_name ||
                reservation.patient_name ||
                reservation.requested_by ||
                "—",
            department:
                reservation.department ||
                routePlan?.department ||
                vehicle?.department ||
                null,
            purpose: reservation.purpose || routePlan?.purpose || "",
            status: reservation.status || "",
            raw: reservation,
        };
    });

    const dispatches = dispatchesRaw.map((dispatch) => {
        const reservation = dispatch.reservation || null;
        const vehicle = reservation?.vehicle || dispatch.vehicle || null;
        const driver = reservation?.driver || dispatch.driver || null;
        const routePlan =
            reservation?.route_plan ||
            reservation?.routePlan ||
            dispatch.route_plan ||
            dispatch.routePlan ||
            null;
        const distance =
            routePlan?.estimated_distance ?? dispatch.distance ?? null;
        const duration = routePlan?.estimated_time ?? dispatch.duration ?? null;
        return {
            id: String(dispatch.id ?? ""),
            tripNumber: dispatch.dispatch_number || String(dispatch.id || ""),
            date: normalizeReportDate(dispatch.dispatch_date),
            vehicleId: vehicle?.id != null ? String(vehicle.id) : "",
            vehicleName: getReportVehicleLabel(vehicle),
            driverId: driver?.id != null ? String(driver.id) : "",
            driverName: getReportDriverLabel(driver),
            origin: routePlan?.origin || reservation?.pickup_location || "",
            destination:
                routePlan?.destination || reservation?.destination || "",
            status: dispatch.trip_status || "",
            distance: distance != null ? Number(distance) : 0,
            duration: duration != null ? Number(duration) : 0,
            department:
                routePlan?.department ||
                reservation?.department ||
                vehicle?.department ||
                null,
            raw: dispatch,
        };
    });

    const maintenance = maintenanceRaw.map((record) => ({
        id: String(record.id ?? ""),
        maintenanceNumber: record.maintenance_number || String(record.id || ""),
        date: normalizeReportDate(record.maintenance_date),
        completionDate: normalizeReportDate(record.completion_date),
        vehicleId: record.vehicle_id != null ? String(record.vehicle_id) : "",
        vehicleName: getReportVehicleLabel(record.vehicle),
        type: record.maintenance_type || "",
        status: record.status || "",
        cost: record.cost != null ? Number(record.cost) : null,
        serviceProvider: record.technician || "",
        department: record.vehicle?.department || null,
        raw: record,
    }));

    const fuel = fuelRaw.map((record) => ({
        id: String(record.id ?? ""),
        fuelRecordNumber: record.fuel_number || String(record.id || ""),
        date: normalizeReportDate(record.date),
        vehicleId: record.vehicle_id != null ? String(record.vehicle_id) : "",
        vehicleName: getReportVehicleLabel(record.vehicle),
        driverId: record.driver_id != null ? String(record.driver_id) : "",
        driverName: getReportDriverLabel(record.driver),
        fuelType: record.fuel_type || "",
        quantity: Number(record.fuel_amount) || 0,
        costPerLiter: Number(record.cost_per_liter) || 0,
        totalCost: Number(record.cost) || 0,
        odometer: Number(record.odometer) || 0,
        station: record.fuel_station || "",
        department: record.vehicle?.department || null,
        raw: record,
    }));

    return {
        vehicles,
        drivers,
        reservations,
        dispatches,
        maintenance,
        fuel,

        scope: data?.scope || {},
    };
}
