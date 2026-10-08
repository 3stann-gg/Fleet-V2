<div
  id="deleteFuelModal"
  class="modal-overlay"
  role="dialog"
  aria-modal="true"
  aria-labelledby="deleteFuelModalTitle"
  aria-describedby="deleteFuelModalDescription"
>
  <div class="custom-modal delete-modal">
    <button
      type="button"
      class="modal-close"
      id="closeDeleteFuelModal"
      aria-label="Close delete fuel modal"
    >
      <i class="ph ph-x"></i>
    </button>

    <div class="delete-icon">
      <i class="ph-fill ph-warning-circle"></i>
    </div>

    <h2 id="deleteFuelModalTitle">Delete Fuel Record</h2>

    <p id="deleteFuelModalDescription">
      Are you sure you want to delete
      <strong id="deleteFuelNumber">this fuel record</strong>
      for <strong id="deleteFuelVehicle">this vehicle</strong>?
    </p>

    <p class="delete-note">This action cannot be undone.</p>

    <div class="modal-footer">
      <button type="button" class="btn-outline" id="cancelDeleteFuel">Cancel</button>
      <button type="button" class="btn-danger" id="confirmDeleteFuel">
        <i class="ph ph-trash"></i>
        Delete Fuel Record
      </button>
    </div>
  </div>
</div>
