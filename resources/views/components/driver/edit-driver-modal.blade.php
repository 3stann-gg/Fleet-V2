<div
  id="editDriverModal"
  class="modal-overlay"
  role="dialog"
  aria-modal="true"
  aria-labelledby="editDriverModalTitle"
  aria-describedby="editDriverModalDescription"
>
  <div class="custom-modal">
    <div class="modal-header">
      <div>
        <h2 id="editDriverModalTitle">Edit Driver</h2>

        <p id="editDriverModalDescription">Update driver information.</p>
      </div>

      <button
        type="button"
        class="modal-close"
        id="closeEditDriverModal"
        aria-label="Close Edit Driver modal"
      >
        <i class="ph ph-x" aria-hidden="true"></i>
      </button>
    </div>

    <form id="editDriverForm" class="driver-form vehicle-form operational-modal-form">
      <div class="modal-body">
        @csrf
        <div class="form-section-heading form-section-heading--outside-grid">
          <h3>Formal Photo</h3>
          <p>Keep the current photo or choose a replacement.</p>
        </div>

        <div class="vehicle-image-section">
          <label class="vehicle-image-label" for="editDriverImage">
            Driver Photo
          </label>

          <div class="vehicle-image-upload">
            <img
              id="editDriverPreview"
              src="data:image/svg+xml,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%20viewBox%3D%270%200%20170%20170%27%3E%3Crect%20width%3D%27170%27%20height%3D%27170%27%20fill%3D%27%23f8fafc%27%2F%3E%3Ccircle%20cx%3D%2785%27%20cy%3D%2762%27%20r%3D%2730%27%20fill%3D%27%23cbd5e1%27%2F%3E%3Cpath%20d%3D%27M30%20155c10-35%2035-50%2055-50s45%2015%2055%2050%27%20fill%3D%27%23cbd5e1%27%2F%3E%3C%2Fsvg%3E"
              alt="Driver photo preview"
            />

            <input
              type="file"
              id="editDriverImage"
              name="photo"
              accept="image/*"
              hidden
            />

            <label for="editDriverImage" class="btn-outline upload-btn">
              <i class="ph ph-image"></i>
              Choose Photo
            </label>
          </div>
        </div>

        <div class="form-grid">
          <div class="form-section-heading">
            <h3>Basic Information</h3>
            <p>Review the driver's identity and employment record.</p>
          </div>

          <div class="form-group">
            <label for="editDriverFirstName">First Name *</label>
            <input
                type="text"
                id="editDriverFirstName"
                name="first_name"
                required
            >
        </div>

        <div class="form-group">
            <label for="editDriverLastName">Last Name *</label>
            <input
                type="text"
                id="editDriverLastName"
                name="last_name"
                required
            >
        </div>

          <div class="form-group">
            <label for="editDriverEmployeeId">Driver ID *</label>
            <input
                type="text"
                id="editDriverEmployeeId"
                readonly
            >
          </div>

          <div class="form-section-heading">
            <h3>License &amp; LTO</h3>
            <p>Maintain legal license details, DL codes, and clutch authorization.</p>
          </div>

          <div class="form-group">
            <label for="editDriverLicenseNumber">License Number *</label>
            <input type="text" id="editDriverLicenseNumber" name="license_number" required />
          </div>

          <div class="form-group">
            <label for="editDriverLicenseClass">License Class *</label>
            <select
                id="editDriverLicenseClass"
                name="license_class"
                required
            >
              <option value="">Select License Class</option>
              <option value="Professional">Professional</option>
              <option value="Non-Professional">Non-Professional</option>
            </select>
          </div>

          <div class="form-group">
            <label for="editDriverLicenseExpiry">
                License Expiry
                <span id="editDriverLicenseExpiryRequiredMark">*</span>
            </label>
            <input
                type="date"
                id="editDriverLicenseExpiry"
                name="license_expiry"
                required
            >
            
          </div>

          <div class="form-group full-width">
            <label>Driver's License Codes</label>
            <div class="checklist-options" id="editDriverDlCodeOptions">
              @foreach(\App\Models\Driver::DL_CODE_LABELS as $code => $description)
                <label title="{{ $description }}">
                  <input type="checkbox" name="dl_codes[]" value="{{ $code }}">
                  <span>{{ $code }} — {{ $description }}</span>
                </label>
              @endforeach
            </div>
            <small class="form-hint">Modern LTO Driver's License Codes. Leave blank when not recorded.</small>
          </div>

          <div class="form-group">
            <label for="editDriverClutchAuthorization">Clutch Authorization</label>
            <select id="editDriverClutchAuthorization" name="clutch_authorization">
              <option value="">Not Recorded</option>
              <option value="MT">Manual Transmission (MT) — permits MT and AT</option>
              <option value="AT">Automatic Transmission (AT) only</option>
            </select>
          </div>

          <div class="form-section-heading">
            <h3>Contact Information</h3>
            <p>Maintain current contact details for operational coordination.</p>
          </div>

          <div class="form-group">
            <label for="editDriverPhone">Phone Number *</label>
            <input
                type="tel"
                id="editDriverPhone"
                name="contact_number"
            >
            <small
                class="form-hint"
                id="editDriverLicenseExpiryHint"
            >
                License expiry is required.
            </small>
          </div>

          <div class="form-group">
            <label for="editDriverEmail">Email *</label>
            <input type="email" id="editDriverEmail" />
          </div>

          <div class="form-section-heading">
            <h3>Status &amp; Availability</h3>
            <p>Update the current assignment, experience, and availability.</p>
          </div>

          <div class="form-group">
            <label for="editDriverAssignedVehicle">Assigned Vehicle</label>
            <select
                id="editDriverAssignedVehicle"
                name="assigned_vehicle_id"
            >
              <option value="">Select Assigned Vehicle</option>
            </select>
          </div>

          <div class="form-group">
            <label for="editDriverExperience">Experience (Years)</label>
            <input type="number" id="editDriverExperience" name="experience" min="0" />
          </div>

          <div class="form-group">
            <label for="editDriverStatus">Status *</label>
            <select
                id="editDriverStatus"
                name="status"
                required
            >
              <option value="">Select Status</option>
              <option value="Available">Available</option>
              <option value="On Leave">On Leave</option>
              <option value="Inactive">Inactive</option>
            </select>
          </div>

          <div class="form-section-heading">
            <h3>Hospital Qualifications</h3>
            <p>Maintain internal vehicle-type authorization separately from LTO credentials.</p>
          </div>

          <div class="form-group full-width">
            <label>Authorized Vehicle Types</label>
            <div class="checklist-options" id="editDriverQualificationOptions">
              @foreach(\App\Models\Driver::VEHICLE_TYPE_OPTIONS as $vehicleType)
                <label>
                  <input type="checkbox" name="qualifications[]" value="{{ $vehicleType }}">
                  <span>{{ $vehicleType }}</span>
                </label>
              @endforeach
            </div>
            <small class="form-hint">Leave blank when qualification information is unavailable.</small>
          </div>

          <div class="form-section-heading">
            <h3>Additional Information</h3>
            <p>Update address, emergency contact, and relevant notes.</p>
          </div>

          <div class="form-group full-width">
            <label for="editDriverAddress">Address</label>
            <input type="text" id="editDriverAddress" name="address" />
          </div>

          <div class="form-group">
            <label for="editDriverEmergencyContact">Emergency Contact</label>
            <input type="tel" id="editDriverEmergencyContact" name="emergency_contact" />
          </div>

          <div class="form-group full-width">
            <label for="editDriverNotes">Notes</label>
            <textarea id="editDriverNotes" name="notes" rows="4"></textarea>
          </div>
        </div>
      </div>

      <div class="modal-footer">
          <button type="button" class="btn-outline" id="cancelEditDriver">
            Cancel
          </button>

          <button type="submit" class="btn-primary" id="updateDriverBtn">
            <i class="ph ph-floppy-disk"></i>
            Update Driver
          </button>
      </div>
    </form>
  </div>
</div>
