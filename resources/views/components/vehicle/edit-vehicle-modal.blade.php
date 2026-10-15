<div
  class="modal-overlay"
  id="editVehicleModal"
  role="dialog"
  aria-modal="true"
  aria-labelledby="editVehicleModalTitle"
  aria-describedby="editVehicleModalDescription"
>
  <div class="custom-modal">
    <div class="modal-header">
      <div>
        <h2 id="editVehicleModalTitle">Edit Vehicle</h2>
        <p id="editVehicleModalDescription">Update vehicle information.</p>
      </div>

      <button
        type="button"
        class="modal-close"
        id="closeEditVehicleModal"
        aria-label="Close edit vehicle dialog"
      >
        <i class="ph ph-x" aria-hidden="true"></i>
      </button>
    </div>

    <form
        class="vehicle-form"
        id="editVehicleForm"
        data-id=""
    >
      <div class="modal-body">
        <p class="form-required-note"><span class="required" aria-hidden="true">*</span> Required field</p>
        <input type="hidden" id="editVehicleId">
        <div class="form-grid">
          <div class="vehicle-form-section-title full-width">
            <span>Vehicle Information</span>
          </div>
          <!--
          <div class="form-group">
              <label for="editVehicleBrand">Brand</label>
              <input
                  type="text"
                  id="editVehicleBrand"
                  name="brand"
                  placeholder="Toyota"
                  required
              >
          </div>
          -->
          <!--
          <div class="form-group">
              <label for="editVehicleModel">Model</label>
              <input
                  type="text"
                  id="editVehicleModel"
                  name="model"
                  placeholder="HiAce"
                  required
              >
          </div>
          -->
          <div class="form-group">
            <label for="editVehiclePlate">
                Plate Number
                <span id="editVehiclePlateRequiredMark" class="required" aria-hidden="true">*</span>
            </label>
            <input
              type="text"
              id="editVehiclePlate"
              name="plate_number"
              placeholder="ABC-1234"

            />
          </div>

          <div class="form-group">
            <label for="editVehicleType">Vehicle Type <span class="required" aria-hidden="true">*</span></label>
            <select id="editVehicleType" name="vehicle_type" required>
              <option value="">Select Vehicle Type</option>
              <option>Ambulance</option>
              <option>Service Vehicle</option>
              <option>SUV</option>
              <option>Motorcycle</option>
            </select>
          </div>

          <div class="form-group">
            <label for="editVehicleLtoCategory">LTO Vehicle Category</label>
            <select id="editVehicleLtoCategory" name="lto_vehicle_category">
              <option value="">Not Recorded</option>
              @foreach(\App\Models\Vehicle::LTO_VEHICLE_CATEGORY_LABELS as $category => $description)
                <option value="{{ $category }}">{{ $category }} — {{ $description }}</option>
              @endforeach
            </select>
            <small class="form-hint">Record the category from the vehicle registration; it is not inferred from Vehicle Type.</small>
          </div>

          <div class="form-group">
              <label for="editVehicleDepartment">
                  Department
                  <span
                      id="editVehicleDepartmentRequiredMark"
                      class="required"
                      aria-hidden="true"
                      hidden
                  >*</span>
              </label>
              <select
                  id="editVehicleDepartment"
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
              <label for="editVehicleCapacity">Capacity <span class="required" aria-hidden="true">*</span></label>

              <input
                  type="number"
                  id="editVehicleCapacity"
                  name="capacity"
                  placeholder="4"
                  required
              >
          </div>

          <div class="form-group">
            <label for="editVehicleDriver">Assigned Driver</label>
            <select id="editVehicleDriver">
        
            </select>
          </div>

          <div class="vehicle-form-section-title full-width">
            <span>Powertrain &amp; Energy</span>
          </div>

          <div class="form-group">
            <label for="editVehiclePowertrain">Powertrain Type <span class="required" aria-hidden="true">*</span></label>
            <select id="editVehiclePowertrain" name="powertrain_type" required>
              <option value="">Select Powertrain Type</option>
              <option value="gasoline">Gasoline</option>
              <option value="diesel">Diesel</option>
              <option value="hybrid">Hybrid (HEV)</option>
              <option value="plug_in_hybrid">Plug-in Hybrid (PHEV)</option>
              <option value="electric">Electric (BEV)</option>
            </select>
          </div>

          <div class="form-group" data-powertrain-field="fuel">
            <label for="editVehicleFuel">Fuel Type</label>
            <select id="editVehicleFuel" name="fuel_type" data-energy-required="true">
              <option value="">Select Fuel Type</option>
              <option value="Diesel">Diesel</option>
              <option value="Gasoline">Gasoline</option>
              <option value="Premium Gasoline">Premium Gasoline</option>
            </select>
          </div>

          <div class="form-group" data-powertrain-field="fuel">
            <label for="editVehicleTankCapacity">Tank Capacity (Liters)</label>
            <input
              type="number"
              id="editVehicleTankCapacity"
              name="tank_capacity"
              data-energy-required="true"
              min="0.01"
              step="0.01"
              placeholder="80"
            />
          </div>

          <div class="form-group" data-powertrain-field="battery">
            <label for="editVehicleBatteryCapacity">Battery Capacity (kWh)</label>
            <input type="number" id="editVehicleBatteryCapacity" min="0" step="0.01" placeholder="60" />
          </div>

          <div class="form-group" data-powertrain-field="battery">
            <label for="editVehicleBatteryLevel">Latest Recorded Battery Level (%)</label>
            <input type="number" id="editVehicleBatteryLevel" min="0" max="100" step="0.01" placeholder="80" />
          </div>

          <div class="form-group" data-powertrain-field="range">
            <label for="editVehicleEstimatedRange">Estimated Range (km)</label>
            <input type="number" id="editVehicleEstimatedRange" min="0" step="0.01" placeholder="240" />
          </div>

          <div class="form-group" data-powertrain-field="charging">
            <label for="editVehicleChargingPort">Charging Port Type</label>
            <input type="text" id="editVehicleChargingPort" maxlength="100" placeholder="Type 2" />
          </div>

          <div class="form-group" data-powertrain-field="charging">
            <label for="editVehicleBatteryHealth">Battery Health (%)</label>
            <input type="number" id="editVehicleBatteryHealth" min="0" max="100" step="0.01" placeholder="Manual inspection value" />
          </div>

          <div class="form-group" data-powertrain-field="charging">
            <label for="editVehicleBatteryInspectionDate">Battery Health Inspection Date</label>
            <input type="date" id="editVehicleBatteryInspectionDate" />
          </div>

          <!--<div class="form-group">
            <label for="editVehicleCurrentFuel">
              Current Fuel (Liters)
            </label>
            <input
              type="number"
              id="editVehicleCurrentFuel"
              name="current_fuel"
              readonly
            />
          </div>-->

          <div class="vehicle-form-section-title full-width">
            <span>Operational Information</span>
          </div>

          <div class="form-group">
            <label for="editVehicleMileage">Current Mileage</label>
            <input
              type="number"
              id="editVehicleMileage"
              name="current_odometer"
              readonly
            />
          </div>

          <div class="form-group">
            <label for="editVehicleStatus">Status <span class="required" aria-hidden="true">*</span></label>
            <select id="editVehicleStatus" name="status" required>
              <option value="">Select Status</option>
              <option>Available</option>
              <option>On Trip</option>
              <option>Maintenance</option>
              <option>Out of Service</option>
            </select>
          </div>

          <div class="form-group">
            <label for="editVehicleReadiness">Operational Readiness</label>
            <select id="editVehicleReadiness" name="readiness_status">
              <option value="">Not Specified (legacy)</option>
              <option value="ready">Ready for Dispatch</option>
              <option value="in_use">In Use</option>
              <option value="maintenance">Maintenance</option>
              <option value="refueling">Refueling</option>
              <option value="charging">Charging</option>
              <option value="cleaning">Cleaning / Sanitation</option>
              <option value="unavailable">Unavailable</option>
            </select>
          </div>

          <div class="form-group">
            <label for="editVehicleTransmissionType">Transmission</label>
            <select id="editVehicleTransmissionType">
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
            <label for="editVehicleEngineDisplacement">Engine Displacement (cc)</label>
            <input type="number" id="editVehicleEngineDisplacement" min="0" step="1" />
          </div>

          <div class="form-group" data-motorcycle-field>
            <label for="editVehicleHelmetAvailable">Helmet Available</label>
            <select id="editVehicleHelmetAvailable">
              <option value="">Not recorded</option>
              <option value="1">Yes</option>
              <option value="0">No</option>
            </select>
          </div>

          <div class="form-group" data-motorcycle-field>
            <label for="editVehicleCargoBoxAvailable">Cargo Box Available</label>
            <select id="editVehicleCargoBoxAvailable">
              <option value="">Not recorded</option>
              <option value="1">Yes</option>
              <option value="0">No</option>
            </select>
          </div>

          <div class="form-group" data-motorcycle-field>
            <label for="editVehicleCargoCapacity">Cargo Capacity (kg)</label>
            <input type="number" id="editVehicleCargoCapacity" min="0" step="0.01" />
          </div>

          <div class="form-group" data-motorcycle-field>
            <label for="editVehicleRegistrationExpiry">Registration Expiry</label>
            <input type="date" id="editVehicleRegistrationExpiry" />
          </div>

          <div class="vehicle-form-section-title full-width">
            <span>Capabilities</span>
          </div>

          <div class="form-group full-width">
            <label>Vehicle Capabilities</label>
            <div id="editVehicleCapabilities" class="checklist-options">
              <span class="form-hint">Loading capabilities...</span>
            </div>
          </div>
        </div>

        <div class="vehicle-form-section-title">
          <span>Notes &amp; Additional Details</span>
        </div>

        <div class="form-group">
          <label for="editVehicleNotes">Notes</label>

          <textarea
            id="editVehicleNotes"
            rows="4"
            name="notes"
            placeholder="Additional notes..."
          ></textarea>
        </div>

      </div>

      <div class="modal-footer">
          <button type="button" class="btn-outline" id="cancelEditVehicle">
            Cancel
          </button>

          <button type="submit" id="updateVehicleBtn" class="btn-primary">
            <i class="ph ph-floppy-disk" aria-hidden="true"></i>
            Update Vehicle
          </button>
      </div>
    </form>
  </div>
</div>
