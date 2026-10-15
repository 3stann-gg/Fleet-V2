<div
  class="modal-overlay"
  id="fleetConfirmationModal"
  role="alertdialog"
  aria-modal="true"
  aria-labelledby="fleetConfirmationTitle"
  aria-describedby="fleetConfirmationMessage"
  hidden
>
  <div class="custom-modal delete-modal fleet-confirm-modal" tabindex="-1">
    <button
      type="button"
      class="modal-close fleet-confirm-close"
      id="closeFleetConfirmation"
      aria-label="Close confirmation"
    >
      <i class="ph ph-x" aria-hidden="true"></i>
    </button>

    <div class="delete-icon fleet-confirm-icon" aria-hidden="true">
      <i class="ph-fill ph-warning-circle" id="fleetConfirmationIcon"></i>
    </div>

    <h2 id="fleetConfirmationTitle">Confirm action</h2>
    <p id="fleetConfirmationMessage">Are you sure you want to continue?</p>

    <div class="modal-footer">
      <button type="button" class="btn-outline" id="cancelFleetConfirmation">
        Cancel
      </button>
      <button type="button" class="btn-danger" id="confirmFleetConfirmation">
        <i class="ph ph-check" aria-hidden="true"></i>
        <span>Confirm</span>
      </button>
    </div>
  </div>
</div>

<div
  class="modal-overlay"
  id="fleetInputPromptModal"
  role="dialog"
  aria-modal="true"
  aria-labelledby="fleetInputPromptTitle"
  aria-describedby="fleetInputPromptMessage"
  hidden
>
  <div class="custom-modal fleet-input-modal" tabindex="-1">
    <div class="modal-header">
      <div>
        <h2 id="fleetInputPromptTitle">Enter a value</h2>
      </div>
      <button
        type="button"
        class="modal-close"
        id="closeFleetInputPrompt"
        aria-label="Close input dialog"
      >
        <i class="ph ph-x" aria-hidden="true"></i>
      </button>
    </div>

    <form id="fleetInputPromptForm" novalidate>
      <div class="modal-body">
        <p class="fleet-input-message" id="fleetInputPromptMessage">
          Provide the requested information.
        </p>
        <div class="form-group">
          <label for="fleetInputPromptField" id="fleetInputPromptLabel">Value</label>
          <input type="text" id="fleetInputPromptField" autocomplete="off">
          <small class="field-error" id="fleetInputPromptError" role="alert"></small>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-outline" id="cancelFleetInputPrompt">
          Cancel
        </button>
        <button type="submit" class="btn-primary" id="confirmFleetInputPrompt">
          <i class="ph ph-check" aria-hidden="true"></i>
          <span>Confirm</span>
        </button>
      </div>
    </form>
  </div>
</div>
