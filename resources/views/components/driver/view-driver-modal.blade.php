<div
  id="viewDriverModal"
  class="modal-overlay"
  role="dialog"
  aria-modal="true"
  aria-labelledby="viewDriverModalTitle"
  aria-describedby="viewDriverModalDescription"
>
  <div class="custom-modal">
    <div class="modal-header">
      <div>
        <h2 id="viewDriverModalTitle">Driver Details</h2>

        <p id="viewDriverModalDescription">
          View complete driver information.
        </p>
      </div>

      <button
        type="button"
        class="modal-close"
        id="closeViewDriverModal"
        aria-label="Close driver details"
      >
        <i class="ph ph-x"></i>
      </button>
    </div>

    <div class="vehicle-profile driver-profile">
      <img
        id="viewDriverImage"
        src="data:image/svg+xml,%3Csvg%20xmlns%3D%27http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%27%20viewBox%3D%270%200%20170%20170%27%3E%3Crect%20width%3D%27170%27%20height%3D%27170%27%20fill%3D%27%23f8fafc%27%2F%3E%3Ccircle%20cx%3D%2785%27%20cy%3D%2762%27%20r%3D%2730%27%20fill%3D%27%23cbd5e1%27%2F%3E%3Cpath%20d%3D%27M30%20155c10-35%2035-50%2055-50s45%2015%2055%2050%27%20fill%3D%27%23cbd5e1%27%2F%3E%3C%2Fsvg%3E"
        alt="Driver photo"
      />

      <h3 id="viewDriverName"></h3>

      <p id="viewDriverSubtitle"></p>

      <span id="viewDriverStatus" class="status-badge available"></span>
    </div>

    <div class="modal-body">
      <div class="view-driver-summary">
        <div class="view-driver-summary-item">
          <span class="view-driver-summary-label">Employee ID</span>
          <span class="view-driver-summary-value" id="viewDriverEmployeeId"></span>
        </div>

        <div class="view-driver-summary-item">
          <span class="view-driver-summary-label">License Class</span>
          <span class="view-driver-summary-value" id="viewDriverLicenseClass"></span>
        </div>

        <div class="view-driver-summary-item">
          <span class="view-driver-summary-label">Status</span>
          <span id="viewDriverStatusSummary"></span>
        </div>

        <div class="view-driver-summary-item">
          <span class="view-driver-summary-label">License Status</span>
          <span class="view-driver-summary-value" id="viewDriverLicenseExpiry"></span>
        </div>
      </div>

      <div class="view-driver-details">
        <div class="view-driver-detail-item">
          <label>License Number</label>
          <p id="viewDriverLicenseNumber"></p>
        </div>

        <div class="view-driver-detail-item">
          <label>Contact Number</label>
          <p id="viewDriverPhone"></p>
        </div>

        <div class="view-driver-detail-item">
          <label>Email</label>
          <p id="viewDriverEmail"></p>
        </div>

        <div class="view-driver-detail-item">
          <label>Assigned Vehicle</label>
          <p id="viewDriverAssignedVehicle"></p>
        </div>

        <div class="view-driver-detail-item">
          <label>Experience</label>
          <p id="viewDriverExperience"></p>
        </div>

        <div class="view-driver-detail-item">
          <label>Address</label>
          <p id="viewDriverAddress"></p>
        </div>

        <div class="view-driver-detail-item">
          <label>Emergency Contact</label>
          <p id="viewDriverEmergencyContact"></p>
        </div>

        <div class="view-driver-detail-item">
          <label>Authorized Vehicle Types</label>
          <p id="viewDriverQualifications"></p>
        </div>
      </div>

      <div class="view-driver-notes">
        <label>Notes</label>
        <p id="viewDriverNotes"></p>
      </div>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn-outline" id="closeViewDriverBtn">
        Close
      </button>

      <button type="button" class="btn-primary" id="editDriverFromViewBtn">
        <i class="ph ph-pencil-simple"></i>
        Edit Driver
      </button>
    </div>
  </div>
</div>
