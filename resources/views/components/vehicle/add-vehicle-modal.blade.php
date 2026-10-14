<div
  class="modal-overlay"
  id="vehicleModal"
  role="dialog"
  aria-modal="true"
  aria-labelledby="vehicleModalTitle"
>
  <div class="custom-modal">
    <!-- Header -->
    <div class="modal-header">
      <div>
        <h2 id="vehicleModalTitle">Request New Vehicle</h2>
        <p>Register a new hospital fleet vehicle.</p>
      </div>

      <button
        type="button"
        class="modal-close"
        id="closeVehicleModal"
        aria-label="Close add vehicle dialog"
      >
        <i class="ph ph-x" aria-hidden="true"></i>
      </button>
    </div>

    <form
        class="vehicle-form"
        id="vehicleForm"
        action="{{ route('vehicles.store') }}"
        method="POST"
    >
      @csrf
      <div class="modal-body">
        <p class="form-required-note"><span class="required" aria-hidden="true">*</span> Required field</p>
        <!-- Vehicle Image -->
        <div class="vehicle-image-section">
          <label class="vehicle-image-label" for="vehicleImage">Vehicle Image</label>

          <div class="vehicle-image-upload">
            <img
              id="vehiclePreview"
              src="../assets/images/vehicle-placeholder.svg"
              alt="Vehicle Preview"
            />

            <input type="file" id="vehicleImage" accept="image/*" hidden />

            <label for="vehicleImage" class="btn-outline upload-btn">
              <i class="ph ph-image" aria-hidden="true"></i>
              Choose Image
            </label>
          </div>
        </div>
        <div class="form-grid">
          <div class="vehicle-form-section-title full-width">
            <span>Vehicle Information</span>
          </div>
          <div class="form-group">
            <label for="vehiclePlate">
                Plate Number
                <span id="vehiclePlateRequiredMark" class="required" aria-hidden="true">*</span>
            </label>
            <input
                type="text"
                id="vehiclePlate"
                name="plate_number"
                placeholder="ABC-1234"
  
            />
          </div>

          <div class="form-group">
            <label for="vehicleType">Vehicle Type <span class="required" aria-hidden="true">*</span></label>
            <select
                id="vehicleType"
                name="vehicle_type"
                required
            >
                <option value="">Select Vechicle Type</option>
                <option value="Ambulance">Ambulance</option>
                <option value="Patient Van">Patient Van</option>
                <option value="Service Vehicle">Service Vehicle</option>
                <option value="SUV">SUV</option>
                <option value="Motorcycle">Motorcycle</option>
            </select>
          </div>

          <div class="form-group">
            <label for="vehicleLtoCategory">LTO Vehicle Category</label>
            <select id="vehicleLtoCategory" name="lto_vehicle_category">
              <option value="">Not Recorded</option>
              @foreach(\App\Models\Vehicle::LTO_VEHICLE_CATEGORY_LABELS as $category => $description)
                <option value="{{ $category }}">{{ $category }} — {{ $description }}</option>
              @endforeach
            </select>
            <small class="form-hint">Record the category from the vehicle registration; it is not inferred from Vehicle Type.</small>
          </div>

          <div class="form-group">
            <label for="vehicleBrand">Brand <span class="required" aria-hidden="true">*</span></label>

            <input
                type="text"
                id="vehicleBrand"
                name="brand"
                placeholder="Toyota"
                required
            >
        </div>

        <div class="form-group">
            <label for="vehicleModel">Model <span class="required" aria-hidden="true">*</span></label>

            <input
                type="text"
                id="vehicleModel"
                name="model"
                placeholder="Hiace"
                required
            >
        </div>

        <div class="form-group">
          <label for="vehicleDepartment">
              Department
              <span
                  id="vehicleDepartmentRequiredMark"
                  class="required"
                  aria-hidden="true"
                  hidden
              >*</span>
          </label>

          <select
              id="vehicleDepartment"
              name="department"
          >
              <option value="">Select Department</option>
              <option value="Emergency">Emergency</option>
              <option value="Outpatient">Outpatient</option>
              <option value="Laboratory">Laboratory</option>
              <option value="Facilities">Facilities</option>
              <option value="Admin">Admin</option>
              <option value="Logistics">Logistics</option>
          </select>
      </div>

          <div class="form-group">
            <label for="vehicleCapacity">Capacity <span class="required" aria-hidden="true">*</span></label>
            <input
                type="number"
                id="vehicleCapacity"
                name="capacity"
                placeholder="4"
            />
          </div>

          <div class="form-group">
            <label for="vehicleDriver">Assigned Driver</label>
            <select id="vehicleDriver"></select>
          </div>

          <div class="vehicle-form-section-title full-width">
            <span>Powertrain &amp; Energy</span>
          </div>

          <div class="form-group">
            <label for="vehiclePowertrain">Powertrain Type <span class="required" aria-hidden="true">*</span></label>
            <select id="vehiclePowertrain" name="powertrain_type" required>
              <option value="">Select Powertrain Type</option>
              <option value="gasoline">Gasoline</option>
              <option value="diesel">Diesel</option>
              <option value="hybrid">Hybrid (HEV)</option>
              <option value="plug_in_hybrid">Plug-in Hybrid (PHEV)</option>
              <option value="electric">Electric (BEV)</option>
            </select>
          </div>

          <div class="form-group" data-powertrain-field="fuel">
            <label for="vehicleFuel">Fuel Type</label>
            <select
                id="vehicleFuel"
                name="fuel_type"
                data-energy-required="true"
            >
                <option value="">Select Fuel Type</option>
                <option value="Diesel">Diesel</option>
                <option value="Gasoline">Gasoline</option>
                <option value="Premium Gasoline">Premium Gasoline</option>
            </select>
          </div>

          <div class="form-group">
            <label for="vehicleMileage">Current Mileage <span class="required" aria-hidden="true">*</span></label>
            <input
              type="number"
              id="vehicleMileage"
              name="current_odometer"
              min="0"
              step="0.01"
              placeholder="58240"
            />
          </div>

          <div class="form-group" data-powertrain-field="fuel">
            <label for="vehicleTankCapacity">Tank Capacity (Liters)</label>
            <input
              type="number"
              id="vehicleTankCapacity"
              name="tank_capacity"
              data-energy-required="true"
              min="0.01"
              step="0.01"
              placeholder="80"
            />
          </div>

          <div class="form-group" data-powertrain-field="battery">
            <label for="vehicleBatteryCapacity">Battery Capacity (kWh)</label>
            <input type="number" id="vehicleBatteryCapacity" min="0" step="0.01" placeholder="60" />
          </div>

          <div class="form-group" data-powertrain-field="battery">
            <label for="vehicleBatteryLevel">Latest Recorded Battery Level (%)</label>
            <input type="number" id="vehicleBatteryLevel" min="0" max="100" step="0.01" placeholder="80" />
          </div>

          <div class="form-group" data-powertrain-field="range">
            <label for="vehicleEstimatedRange">Estimated Range (km)</label>
            <input type="number" id="vehicleEstimatedRange" min="0" step="0.01" placeholder="240" />
          </div>

          <div class="form-group" data-powertrain-field="charging">
            <label for="vehicleChargingPort">Charging Port Type</label>
            <input type="text" id="vehicleChargingPort" maxlength="100" placeholder="Type 2" />
          </div>

          <div class="form-group" data-powertrain-field="charging">
            <label for="vehicleBatteryHealth">Battery Health (%)</label>
            <input type="number" id="vehicleBatteryHealth" min="0" max="100" step="0.01" placeholder="Manual inspection value" />
          </div>

          <div class="form-group" data-powertrain-field="charging">
            <label for="vehicleBatteryInspectionDate">Battery Health Inspection Date</label>
            <input type="date" id="vehicleBatteryInspectionDate" />
          </div>

          <div class="form-group" data-powertrain-field="fuel">
            <label for="vehicleCurrentFuel">Current Fuel (Liters)</label>
            <input
              type="number"
              id="vehicleCurrentFuel"
              name="current_fuel"
              data-energy-required="true"
              min="0"
              step="0.01"
              placeholder="40"
            />
          </div>

          <div class="vehicle-form-section-title full-width">
            <span>Operational Information</span>
          </div>

          <div class="form-group">
            <label for="vehicleStatus">Status <span class="required" aria-hidden="true">*</span></label>
            <select
                id="vehicleStatus"
                name="status"
                required
            >   
                <option value="">Select Status Type</option>
                <option value="Available">Available</option>
                <option value="On Trip">On Trip</option>
                <option value="Maintenance">Maintenance</option>
                <option value="Out of Service">Out of Service</option>
            </select>
          </div>

          <div class="form-group">
            <label for="vehicleReadiness">Operational Readiness <span class="required" aria-hidden="true">*</span></label>
            <select id="vehicleReadiness" name="readiness_status" required>
              <option value="ready" selected>Ready for Dispatch</option>
              <option value="in_use">In Use</option>
              <option value="maintenance">Maintenance</option>
              <option value="refueling">Refueling</option>
              <option value="charging">Charging</option>
              <option value="cleaning">Cleaning / Sanitation</option>
              <option value="unavailable">Unavailable</option>
            </select>
          </div>

          <div class="form-group">
            <label for="vehiclePurchaseDate">Purchase Date</label>
            <input type="date" id="vehiclePurchaseDate" />
          </div>

          <div class="form-group">
            <label for="vehicleInsuranceExpiry">Insurance Expiry</label>
            <input type="date" id="vehicleInsuranceExpiry" />
          </div>

          <div class="form-group">
            <label for="vehicleTransmissionType">Transmission</label>
            <select id="vehicleTransmissionType">
              <option value="">Not recorded</option>
              <option value="automatic">Automatic</option>
              <option value="manual">Manual</option>
              <option value="semi_automatic">Semi-Automatic</option>
            </select>
          </div>

          <div class="vehicle-form-section-title full-width" data-motorcycle-field>
            <span>Motorcycle Details</span>
          </div>

          <div class="form-group" data-motorcycle-field data-motorcycle-engine-field>
            <label for="vehicleEngineDisplacement">Engine Displacement (cc)</label>
            <input type="number" id="vehicleEngineDisplacement" min="0" step="1" />
          </div>

          <div class="form-group" data-motorcycle-field>
            <label for="vehicleHelmetAvailable">Helmet Available</label>
            <select id="vehicleHelmetAvailable">
              <option value="">Not recorded</option>
              <option value="1">Yes</option>
              <option value="0">No</option>
            </select>
          </div>

          <div class="form-group" data-motorcycle-field>
            <label for="vehicleCargoBoxAvailable">Cargo Box Available</label>
            <select id="vehicleCargoBoxAvailable">
              <option value="">Not recorded</option>
              <option value="1">Yes</option>
              <option value="0">No</option>
            </select>
          </div>

          <div class="form-group" data-motorcycle-field>
            <label for="vehicleCargoCapacity">Cargo Capacity (kg)</label>
            <input type="number" id="vehicleCargoCapacity" min="0" step="0.01" />
          </div>

          <div class="form-group" data-motorcycle-field>
            <label for="vehicleRegistrationExpiry">Registration Expiry</label>
            <input type="date" id="vehicleRegistrationExpiry" />
          </div>

          <div class="vehicle-form-section-title full-width">
            <span>Capabilities</span>
          </div>

          <div class="form-group full-width">
            <label>Vehicle Capabilities</label>
            <div id="vehicleCapabilities" class="checklist-options">
              <span class="form-hint">Loading capabilities...</span>
            </div>
          </div>
        </div>

        <div class="vehicle-form-section-title">
          <span>Notes &amp; Additional Details</span>
        </div>

        <div class="form-group">
          <label for="vehicleNotes">Notes</label>

          <textarea
            id="vehicleNotes"
            rows="4"
            placeholder="Additional vehicle information..."
          ></textarea>
        </div>

      </div>

      <div class="modal-footer">
          <button type="button" class="btn-outline" id="cancelVehicleModal">
            Cancel
          </button>

          <button type="submit" class="btn-primary" id="saveVehicleBtn">
            <i class="ph ph-floppy-disk" aria-hidden="true"></i>
            Save Vehicle
          </button>
      </div>
    </form>
  </div>
</div>
