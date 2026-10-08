<div
  id="secureExportModal"
  class="modal-overlay"
  role="dialog"
  aria-modal="true"
  aria-labelledby="secureExportModalTitle"
  aria-describedby="secureExportModalDescription"
>
  <div class="custom-modal secure-export-modal">
    <div class="modal-header">
      <div>
        <h2 id="secureExportModalTitle">Secure Export</h2>
        <p id="secureExportModalDescription">
          For security, confirm your account password before exporting sensitive records.
        </p>
      </div>
      <button type="button" class="modal-close" id="closeSecureExportModal" aria-label="Close secure export">
        <i class="ph ph-x" aria-hidden="true"></i>
      </button>
    </div>

    <form id="secureExportForm" novalidate>
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group full-width">
            <label for="secureExportFormat">Export Format</label>
            <input type="text" id="secureExportFormat" readonly />
          </div>

          <div class="form-group full-width">
            <label for="secureExportCurrentPassword">Current Account Password</label>
            <input
              type="password"
              id="secureExportCurrentPassword"
              name="current_password"
              autocomplete="current-password"
              required
            />
            <small class="field-error" id="secureExportCurrentPasswordError"></small>
          </div>

          <div class="form-group full-width secure-export-protection-toggle">
            <label class="secure-export-checkbox" for="secureExportProtectedFile">
              <input type="checkbox" id="secureExportProtectedFile" />
              <span>Protect Downloaded File</span>
            </label>
            <small id="secureExportProtectionHint">
              PDF protection uses the separate file password. Protected CSV requires encrypted ZIP support.
            </small>
          </div>

          <div id="secureExportPasswordFields" class="secure-export-password-fields" hidden>
            <div class="form-group">
              <label for="secureExportFilePassword">Export File Password</label>
              <input type="password" id="secureExportFilePassword" autocomplete="new-password" minlength="8" />
            </div>
            <div class="form-group">
              <label for="secureExportFilePasswordConfirmation">Confirm Export File Password</label>
              <input type="password" id="secureExportFilePasswordConfirmation" autocomplete="new-password" minlength="8" />
            </div>
          </div>

          <div class="form-group full-width">
            <small class="field-error" id="secureExportError" role="alert"></small>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-outline" id="cancelSecureExport">Cancel</button>
        <button type="submit" class="btn-primary" id="confirmSecureExport">
          <i class="ph ph-shield-check" aria-hidden="true"></i>
          Verify &amp; Export
        </button>
      </div>
    </form>
  </div>
</div>
