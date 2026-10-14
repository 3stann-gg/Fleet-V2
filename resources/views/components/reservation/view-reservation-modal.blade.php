<div
  id="viewReservationModal"
  class="modal-overlay"
  role="dialog"
  aria-modal="true"
  aria-labelledby="viewReservationModalTitle"
  aria-describedby="viewReservationModalDescription"
>
  <div class="custom-modal">
    <div class="modal-header">
      <div>
        <h2 id="viewReservationModalTitle">Reservation Details</h2>

        <p id="viewReservationModalDescription">
          View complete transport reservation information.
        </p>
      </div>

      <button
        type="button"
        class="modal-close"
        id="closeViewReservationModal"
        aria-label="Close reservation details"
      >
        <i class="ph ph-x" aria-hidden="true"></i>
      </button>
    </div>

    <div class="modal-body">
      <div class="view-reservation-summary">
        <div class="view-reservation-summary-item">
          <span class="view-reservation-summary-label">Reservation Number</span>
          <span class="view-reservation-summary-value" id="viewReservationNumber"></span>
        </div>

        <div class="view-reservation-summary-item">
          <span class="view-reservation-summary-label">Transport Request Type</span>
          <span class="view-reservation-summary-value" id="viewReservationType"></span>
        </div>

        <div class="view-reservation-summary-item">
          <span class="view-reservation-summary-label">Status</span>
          <span id="viewReservationStatusSummary"></span>
        </div>

        <div class="view-reservation-summary-item">
          <span class="view-reservation-summary-label">Priority</span>
          <span class="view-reservation-summary-value" id="viewReservationPriority"></span>
        </div>
      </div>

      <div class="view-reservation-details">
        <div class="detail-section-heading">
          <h3>Request Details</h3>
        </div>

        <div class="view-reservation-detail-item">
          <label>Patient Name</label>
          <p id="viewReservationPatient"></p>
        </div>

        <div class="detail-section-heading">
          <h3>Vehicle &amp; Driver</h3>
        </div>

        <div class="view-reservation-detail-item">
          <label>Assigned Vehicle</label>
          <p id="viewReservationVehicle"></p>
        </div>

        <div class="view-reservation-detail-item">
          <label>Assigned Driver</label>
          <p id="viewReservationDriver"></p>
        </div>

        <div class="detail-section-heading">
          <h3>Schedule &amp; Route</h3>
        </div>

        <div class="view-reservation-detail-item">
          <label>Pickup Location</label>
          <p id="viewReservationPickup"></p>
        </div>

        <div class="view-reservation-detail-item">
          <label>Destination</label>
          <p id="viewReservationDestination"></p>
        </div>

        <div class="view-reservation-detail-item">
          <label>Schedule</label>
          <p id="viewReservationSchedule"></p>
        </div>

        <div class="detail-section-heading">
          <h3>Requester Contact</h3>
        </div>

        <div class="view-reservation-detail-item">
          <label>Contact Number</label>
          <p id="viewReservationContact"></p>
        </div>
      </div>

      <div class="detail-section-heading detail-section-heading--standalone">
        <h3>Transport Requirements</h3>
      </div>

      <div class="view-reservation-notes">
        <label>Required Vehicle Capabilities</label>
        <p id="viewReservationCapabilities">No special capability requirements</p>
      </div>

      <div class="detail-section-heading detail-section-heading--standalone">
        <h3>Additional Information</h3>
      </div>

      <div class="view-reservation-notes">
        <label>Notes</label>
        <p id="viewReservationNotes"></p>
      </div>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn-outline" id="closeViewReservationBtn">
        Close
      </button>

      <button type="button" class="btn-primary" id="editReservationFromViewBtn">
        <i class="ph ph-pencil-simple" aria-hidden="true"></i>
        Edit Reservation
      </button>
    </div>
  </div>
</div>
