<div
  class="modal-overlay"
  id="viewVehicleModal"
  role="dialog"
  aria-modal="true"
  aria-labelledby="viewVehicleName"
>
  <div class="custom-modal">
    <div class="modal-header">
      <div>
        <h2>Vehicle Details</h2>
        <p>View complete vehicle information.</p>
      </div>

      <button
        type="button"
        class="modal-close"
        id="closeViewVehicleModal"
        aria-label="Close vehicle details dialog"
      >
        <i class="ph ph-x"></i>
      </button>
    </div>
    <div class="vehicle-profile">
      <img
        id="viewVehicleImage"
        src="../assets/images/vehicle-placeholder.svg"
        alt="Vehicle"
      />

      <h3 id="viewVehicleName"></h3>

      <p id="viewVehicleSubtitle">Emergency Response Unit</p>
      <span
        id="viewVehicleStatus"
        class="status-badge available">
    </span>
    </div>

    <div class="modal-body">
      <div class="view-vehicle-summary">
        <div class="view-vehicle-summary-item">
          <span class="view-vehicle-summary-label">Plate Number</span>
          <span class="view-vehicle-summary-value" id="viewPlateNumber"></span>
        </div>

        <div class="view-vehicle-summary-item">
          <span class="view-vehicle-summary-label">Vehicle Type</span>
          <span class="view-vehicle-summary-value" id="viewVehicleType"></span>
        </div>

        <div class="view-vehicle-summary-item">
          <span class="view-vehicle-summary-label">Status</span>
          <span id="viewVehicleStatusSummary"></span>
        </div>

        <div class="view-vehicle-summary-item">
          <span class="view-vehicle-summary-label">Operational Readiness</span>
          <span id="viewVehicleReadiness"></span>
        </div>

        <div class="view-vehicle-summary-item">
          <span class="view-vehicle-summary-label">Assigned Driver</span>
          <span class="view-vehicle-summary-value" id="viewDriver"></span>
        </div>
      </div>

      <div class="view-vehicle-details">
        <!--
        <div class="view-vehicle-detail-item">
          <label>Model / Subtitle</label>
          <p id=""viewVehicleModelSubtitle""></p>
        </div>
        -->
        <div class="view-vehicle-detail-item">
          <label>Department</label>
          <p id="viewVehicleDepartment"></p>
        </div>

        <div class="view-vehicle-detail-item full-width">
          <label>Vehicle Capabilities</label>
          <p id="viewVehicleCapabilities">No capabilities recorded</p>
        </div>

        <div class="view-vehicle-detail-item full-width">
          <label>Sanitation Records</label>
          <p id="viewVehicleSanitation">No sanitation records</p>
          <button type="button" class="btn-outline" id="createVehicleSanitationRecord">
            <i class="ph ph-sparkle"></i>
            Create Sanitation Record
          </button>
        </div>

        <div class="vehicle-energy-section-title full-width">
          <span>Power / Energy Information</span>
        </div>

        <div class="view-vehicle-detail-item">
          <label>Powertrain</label>
          <p id="viewPowertrainType"></p>
        </div>

        <div class="view-vehicle-detail-item" data-view-energy-field="fuel">
          <label>Fuel Type</label>
          <p id="viewFuelType"></p>
        </div>

        <div class="view-vehicle-detail-item" data-view-energy-field="fuel">
          <label>Fuel Level</label>
          <p id="viewFuelLevel"></p>
        </div>

        <div class="view-vehicle-detail-item">
          <label>Mileage</label>
          <p id="viewMileage"></p>
        </div>

        <div class="view-vehicle-detail-item" data-view-energy-field="fuel">
          <label>Tank Capacity</label>
          <p id="viewTankCapacity"></p>
        </div>

        <div class="view-vehicle-detail-item" data-view-energy-field="fuel">
          <label>Current Fuel</label>
          <p id="viewCurrentFuel"></p>
        </div>

        <div class="view-vehicle-detail-item" data-view-energy-field="battery">
          <label>Battery Capacity</label>
          <p id="viewBatteryCapacity"></p>
        </div>

        <div class="view-vehicle-detail-item" data-view-energy-field="battery">
          <label>Latest Recorded Battery Level</label>
          <p id="viewBatteryLevel"></p>
        </div>

        <div class="view-vehicle-detail-item" data-view-energy-field="range">
          <label>Estimated Range</label>
          <p id="viewEstimatedRange"></p>
        </div>

        <div class="view-vehicle-detail-item" data-view-energy-field="charging">
          <label>Charging Port</label>
          <p id="viewChargingPort"></p>
        </div>

        <div class="view-vehicle-detail-item" data-view-energy-field="charging">
          <label>Battery Health</label>
          <p id="viewBatteryHealth"></p>
        </div>

        <div class="view-vehicle-detail-item" data-view-energy-field="charging">
          <label>Last Health Update</label>
          <p id="viewBatteryHealthUpdated"></p>
        </div>

        <div class="view-vehicle-detail-item" data-view-energy-field="charging">
          <label>Battery Health Status</label>
          <p id="viewBatteryHealthStatus"></p>
        </div>

        <div class="view-vehicle-detail-item" data-view-energy-field="charging">
          <label>Last Battery Inspection</label>
          <p id="viewBatteryInspectionDate"></p>
        </div>

        <div class="view-vehicle-detail-item">
          <label>Purchase Date</label>
          <p id="viewPurchaseDate"></p>
        </div>

        <div class="view-vehicle-detail-item">
          <label>Insurance Expiry</label>
          <p id="viewInsuranceExpiry"></p>
        </div>

        <div class="vehicle-energy-section-title full-width" data-view-motorcycle-field>
          <span>Motorcycle Information</span>
        </div>

        <div class="view-vehicle-detail-item" data-view-motorcycle-field data-view-motorcycle-engine-field>
          <label>Engine Displacement</label>
          <p id="viewEngineDisplacement"></p>
        </div>

        <div class="view-vehicle-detail-item" data-view-motorcycle-field>
          <label>Transmission</label>
          <p id="viewTransmissionType"></p>
        </div>

        <div class="view-vehicle-detail-item" data-view-motorcycle-field>
          <label>Helmet Available</label>
          <p id="viewHelmetAvailable"></p>
        </div>

        <div class="view-vehicle-detail-item" data-view-motorcycle-field>
          <label>Cargo Box Available</label>
          <p id="viewCargoBoxAvailable"></p>
        </div>

        <div class="view-vehicle-detail-item" data-view-motorcycle-field>
          <label>Cargo Capacity</label>
          <p id="viewCargoCapacity"></p>
        </div>

        <div class="view-vehicle-detail-item" data-view-motorcycle-field>
          <label>Registration Expiry</label>
          <p id="viewRegistrationExpiry"></p>
        </div>

        <div class="vehicle-energy-section-title full-width">
          <span>Vehicle Operational Health</span>
        </div>

        <div class="view-vehicle-detail-item">
          <label>Overall</label>
          <p id="viewOperationalHealth"></p>
        </div>

        <div class="view-vehicle-detail-item">
          <label>Maintenance</label>
          <p id="viewMaintenanceAdvisory"></p>
        </div>

        <div class="view-vehicle-detail-item">
          <label>Registration</label>
          <p id="viewRegistrationCompliance"></p>
        </div>

        <div class="view-vehicle-detail-item">
          <label>Insurance</label>
          <p id="viewInsuranceCompliance"></p>
        </div>

        <div class="view-vehicle-detail-item full-width">
          <label>Transparent Reasons</label>
          <p id="viewOperationalHealthReasons"></p>
        </div>
      </div>

      <div class="view-vehicle-notes">
        <label>Additional Information</label>
        <p id="viewVehicleNotes"></p>
      </div>
    </div>

      <div class="modal-footer">
        <button type="button" class="btn-outline" id="closeViewBtn">Close</button>

        <button type="button" class="btn-primary" id="editVehicleBtn">
          <i class="ph ph-pencil-simple"></i>
          Edit Vehicle
        </button>
      </div>
    </div>
</div>
