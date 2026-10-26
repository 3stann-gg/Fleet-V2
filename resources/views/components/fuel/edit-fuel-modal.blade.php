<div
  id="editFuelModal"
  class="modal-overlay"
  role="dialog"
  aria-modal="true"
  aria-labelledby="editFuelModalTitle"
  aria-describedby="editFuelModalDescription"
>
  <div class="custom-modal">
    <div class="modal-header">
      <div>
        <h2 id="editFuelModalTitle">Edit Fuel Record</h2>
        <p id="editFuelModalDescription">
          Update vehicle fuel transaction information.
        </p>
      </div>
      <button
        type="button"
        class="modal-close"
        id="closeEditFuelModal"
        aria-label="Close edit fuel modal"
      >
        <i class="ph ph-x" aria-hidden="true"></i>
      </button>
    </div>

    <form id="editFuelForm" class="operational-modal-form">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-section-heading">
            <h3>Transaction &amp; Vehicle</h3>
            <p>Review the refueling event, fleet vehicle, and assigned driver.</p>
          </div>

          <div class="form-group">
            <label for="editFuelNumber">Fuel Record Number *</label>
            <input type="text" id="editFuelNumber" readonly />
          </div>

          <div class="form-group">
            <label for="editFuelRefuelDate">Refueling Date *</label>
            <input type="date" id="editFuelRefuelDate" required />
          </div>

          <div class="form-group">
            <label for="editFuelRefuelTime">Refueling Time *</label>
            <input type="time" id="editFuelRefuelTime" required />
          </div>

          <div class="form-group">
            <label for="editFuelVehicle">
              Vehicle
            </label>

            <select
              id="editFuelVehicle"
              disabled
            >
              <option value="">
                Vehicle
              </option>
            </select>
          </div>

          <div class="form-group">
            <label for="editFuelPlate">
              Plate Number
            </label>

            <input
              type="text"
              id="editFuelPlate"
              readonly
              placeholder="Auto-filled from vehicle"
            />
          </div>

          <div class="form-group">
            <label for="editFuelDriver">
              Driver
            </label>
            <input
              type="text"
              id="editFuelDriver"
              readonly
              placeholder="Assigned driver"
            />
            <input
              type="hidden"
              id="editFuelDriverId"
            />
          </div>

          <div class="form-section-heading">
            <h3>Fuel Details</h3>
            <p>Review the liquid-fuel type and recorded quantity in liters.</p>
          </div>

          <div class="form-group">
            <label for="editFuelType">Fuel Type *</label>
            <select id="editFuelType" disabled required>
              <option value="">Select Fuel Type</option>
              <option value="Diesel">Diesel</option>
              <option value="Gasoline">Gasoline</option>
              <option value="Premium Gasoline">Premium Gasoline</option>
            </select>
          </div>

          <div class="form-group">
            <label for="editFuelQuantity">Quantity (Liters) *</label>
            <input type="number" id="editFuelQuantity" min="0.01" step="0.01" readonly />
          </div>

          <div class="form-section-heading">
            <h3>Cost &amp; Odometer</h3>
            <p>Maintain unit cost while preserving calculated and mileage values.</p>
          </div>

          <div class="form-group">
            <label for="editFuelCostPerLiter">Cost per Liter *</label>
            <input type="number" id="editFuelCostPerLiter" min="0.01" step="0.01" required />
          </div>

          <div class="form-group">
            <label for="editFuelTotalCost">Total Cost *</label>
            <input type="number" id="editFuelTotalCost" min="0" step="0.01" readonly />
            <small
                id="editFuelHighCostWarning"
                class="form-hint"
                hidden
            ></small>
          </div>

          <div class="form-group">
            <label for="editFuelOdometer">Odometer Reading *</label>
            <input type="number" id="editFuelOdometer" min="0" step="1" readonly />
          </div>

          <div class="form-section-heading">
            <h3>Station &amp; Transaction Reference</h3>
            <p>Update the source, receipt, and payment information.</p>
          </div>

          <div class="form-group">
            <label for="editFuelStation">
                Fuel Station
                <span id="editFuelStationRequiredMark">*</span>
            </label>
            <select id="editFuelStation">
              <option value="">Select Fuel Station</option>
            </select>
          </div>

          <div class="form-group">
            <label for="editFuelReceipt">Receipt / Reference Number</label>
            <input type="text" id="editFuelReceipt" maxlength="40" />
          </div>

          <div class="form-group">
            <label for="editFuelPayment">Payment Method</label>
            <select id="editFuelPayment">
              <option value="">Select payment method</option>
              <option value="Fleet Card">Fleet Card</option>
              <option value="Cash">Cash</option>
              <option value="Company Account">Company Account</option>
              <option value="Other">Other</option>
            </select>
          </div>

          <div class="form-section-heading">
            <h3>Additional Information</h3>
          </div>

          <div class="form-group full-width">
            <label for="editFuelNotes">Notes</label>
            <textarea id="editFuelNotes" rows="3"></textarea>
          </div>
        </div>
      </div>

      <div class="modal-footer">
          <button type="button" class="btn-outline" id="cancelEditFuel">Cancel</button>
          <button type="submit" class="btn-primary" id="updateFuelBtn">Update Fuel Record</button>
      </div>
    </form>
  </div>
</div>
