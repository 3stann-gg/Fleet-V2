<div
  id="viewMaintenanceModal"
  class="modal-overlay"
  role="dialog"
  aria-modal="true"
  aria-labelledby="viewMaintenanceModalTitle"
  aria-describedby="viewMaintenanceModalDescription"
>
  <div class="custom-modal">
    <div class="modal-header">
      <div>
        <h2 id="viewMaintenanceModalTitle">Maintenance Details</h2>

        <p id="viewMaintenanceModalDescription">
          View complete vehicle maintenance record information.
        </p>
      </div>

      <button
        type="button"
        class="modal-close"
        id="closeViewMaintenanceModal"
        aria-label="Close maintenance details"
      >
        <i class="ph ph-x" aria-hidden="true"></i>
      </button>
    </div>

    <div class="modal-body">
      <div class="view-maintenance-summary">
        <div class="view-maintenance-summary-item">
          <span class="view-maintenance-summary-label">Maintenance Number</span>
          <span class="view-maintenance-summary-value" id="viewMaintenanceNumber"></span>
        </div>

        <div class="view-maintenance-summary-item">
          <span class="view-maintenance-summary-label">Vehicle</span>
          <span class="view-maintenance-summary-value" id="viewMaintenanceVehicle"></span>
        </div>

        <div class="view-maintenance-summary-item">
          <span class="view-maintenance-summary-label">Status</span>
          <span id="viewMaintenanceStatus"></span>
        </div>

        <div class="view-maintenance-summary-item">
          <span class="view-maintenance-summary-label">Priority</span>
          <span class="view-maintenance-summary-value" id="viewMaintenancePriority"></span>
        </div>
      </div>

      <div class="view-maintenance-details">
        <div class="detail-section-heading">
          <h3>Service Details</h3>
        </div>

        <div class="view-maintenance-detail-item">
          <label>Service Type</label>
          <p id="viewMaintenanceServiceType"></p>
        </div>

        <div class="view-maintenance-detail-item">
          <label>Maintenance Category</label>
          <p id="viewMaintenanceCategory"></p>
        </div>

        <div class="view-maintenance-detail-item">
          <label>Request Type</label>
          <p id="viewMaintenanceRequestType"></p>
        </div>

        <div class="view-maintenance-detail-item">
          <label>Technician / Workshop</label>
          <p id="viewMaintenanceTechnician"></p>
        </div>

        <div class="detail-section-heading">
          <h3>Schedule, Cost &amp; Odometer</h3>
        </div>

        <div class="view-maintenance-detail-item">
          <label>Current Maintenance Date</label>
          <p id="viewMaintenanceScheduledDate"></p>
        </div>

        <div class="view-maintenance-detail-item">
          <label>Completion Date</label>
          <p id="viewMaintenanceCompletionDate"></p>
        </div>

        <div class="view-maintenance-detail-item">
          <label>Cost</label>
          <p id="viewMaintenanceCost"></p>
        </div>

        <div class="view-maintenance-detail-item">
          <label>Odometer Reading</label>
          <p id="viewMaintenanceOdometer"></p>
        </div>

        <div class="view-maintenance-detail-item">
          <label>Current Vehicle Odometer</label>
          <p id="viewMaintenanceVehicleOdometer"></p>
        </div>

        <div class="view-maintenance-detail-item">
          <label>Vehicle Purchase Date</label>
          <p id="viewMaintenancePurchaseDate"></p>
        </div>

        <div class="view-maintenance-detail-item">
          <label>Schedule Context</label>
          <p id="viewMaintenanceDueStatus"></p>
        </div>

        <div class="view-maintenance-detail-item">
          <label>Due Basis</label>
          <p id="viewMaintenanceDueBasis"></p>
        </div>

        <div class="view-maintenance-detail-item">
          <label>Context Date</label>
          <p id="viewMaintenanceDueDate"></p>
        </div>

        <div class="view-maintenance-detail-item">
          <label>Next Service Due</label>
          <p id="viewMaintenanceNextSchedule"></p>
        </div>

        <div class="view-maintenance-detail-item">
          <label>Scheduling Mode</label>
          <p id="viewMaintenanceSchedulingMode"></p>
        </div>

        <div class="view-maintenance-detail-item">
          <label>Recurrence Frequency</label>
          <p id="viewMaintenanceRecurrenceFrequency"></p>
        </div>

        <div class="view-maintenance-detail-item">
          <label>Recurrence Interval</label>
          <p id="viewMaintenanceRecurrenceInterval"></p>
        </div>

        <div class="view-maintenance-detail-item">
          <label>Preserve Month End</label>
          <p id="viewMaintenancePreserveMonthEnd"></p>
        </div>

        <div class="view-maintenance-detail-item">
          <label>Calculation Basis</label>
          <p id="viewMaintenanceRecurrenceBasis"></p>
        </div>
      </div>

      <div class="detail-section-heading detail-section-heading--standalone">
        <h3>Service Information &amp; Notes</h3>
      </div>

      <div class="view-maintenance-notes">
        <label>Description</label>
        <p id="viewMaintenanceDescription"></p>

        <label>Parts Used</label>
        <p id="viewMaintenancePartsUsed"></p>

        <label>Notes</label>
        <p id="viewMaintenanceNotes"></p>
      </div>

      <div class="maintenance-classification-note maintenance-classification-note--modal" role="note">
        <i class="ph ph-info" aria-hidden="true"></i>
        <p id="viewMaintenanceBasis"></p>
      </div>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn-outline" id="closeViewMaintenanceBtn">
        Close
      </button>

      @if($maintenancePermissions['canUpdate'] ?? false)
          <button
              type="button"
              class="btn-primary"
              id="editMaintenanceFromViewBtn"
          >
              <i class="ph ph-pencil-simple" aria-hidden="true"></i>
              Edit Maintenance
          </button>
      @endif
    </div>
  </div>
</div>
