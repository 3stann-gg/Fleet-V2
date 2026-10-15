<div id="vehicleSanitationModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="vehicleSanitationTitle" aria-describedby="vehicleSanitationDescription">
  <div class="custom-modal">
    <div class="modal-header">
      <div>
        <h2 id="vehicleSanitationTitle">Create Sanitation Record</h2>
        <p id="vehicleSanitationDescription">Record an intentional vehicle cleaning transaction.</p>
      </div>
      <button type="button" class="modal-close" id="closeVehicleSanitationModal" aria-label="Close sanitation record dialog">
        <i class="ph ph-x" aria-hidden="true"></i>
      </button>
    </div>
    <form id="vehicleSanitationForm" class="operational-modal-form">
      @csrf
      <input type="hidden" id="sanitationVehicleId" />
      <input type="hidden" id="sanitationDispatchId" />
      <div class="modal-body form-grid">
        <div class="form-group">
          <label for="sanitationReason">Reason *</label>
          <select id="sanitationReason" required>
            <option value="post_patient_transport">Post Patient Transport</option>
            <option value="routine_end_of_trip">Routine End of Trip</option>
            <option value="contamination_concern">Contamination Concern</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="form-group">
          <label for="sanitationCleaningType">Cleaning Type *</label>
          <select id="sanitationCleaningType" required>
            <option value="routine_cleaning">Routine Cleaning</option>
            <option value="disinfection">Disinfection</option>
            <option value="deep_cleaning">Deep Cleaning</option>
          </select>
        </div>
        <div class="form-group">
          <label for="sanitationStatus">Status *</label>
          <select id="sanitationStatus" required>
            <option value="pending">Pending</option>
            <option value="in_progress">In Progress</option>
            <option value="completed">Completed</option>
          </select>
        </div>
        <div class="form-group full-width">
          <label for="sanitationRemarks">Remarks</label>
          <textarea id="sanitationRemarks" rows="3"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-outline" id="cancelVehicleSanitation">Cancel</button>
        <button type="submit" class="btn-primary">Save Sanitation Record</button>
      </div>
    </form>
  </div>
</div>
