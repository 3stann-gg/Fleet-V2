<div id="addChargingModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="addChargingModalTitle" aria-describedby="addChargingModalDescription">
  <div class="custom-modal">
    <div class="modal-header">
      <div>
        <h2 id="addChargingModalTitle">Add Charging Transaction</h2>
        <p id="addChargingModalDescription">Record a completed vehicle charging session.</p>
      </div>
      <button type="button" class="modal-close" id="closeAddChargingModal" aria-label="Close add charging modal"><i class="ph ph-x"></i></button>
    </div>
    <div class="modal-body">
      <form id="chargingForm">
        <div class="form-grid">
          <div class="form-group"><label for="chargingVehicle">Vehicle *</label><select id="chargingVehicle" required><option value="">Select Vehicle</option></select></div>
          <div class="form-group"><label for="chargingDriver">Driver</label><select id="chargingDriver"><option value="">No driver</option></select></div>
          <div class="form-group"><label for="chargingDate">Charging Date *</label><input type="date" id="chargingDate" required></div>
          <div class="form-group"><label for="chargingTime">Charging Time</label><input type="time" id="chargingTime"></div>
          <div class="form-group"><label for="chargingBatteryBefore">Battery Before (%) *</label><input type="number" id="chargingBatteryBefore" min="0" max="100" step="0.01" required></div>
          <div class="form-group"><label for="chargingBatteryAfter">Battery After (%) *</label><input type="number" id="chargingBatteryAfter" min="0" max="100" step="0.01" required></div>
          <div class="form-group"><label for="chargingEnergyAdded">Energy Added (kWh) *</label><input type="number" id="chargingEnergyAdded" min="0" step="0.01" required></div>
          <div class="form-group"><label for="chargingLocation">Charging Location *</label><input type="text" id="chargingLocation" maxlength="255" required></div>
          <div class="form-group"><label for="chargingCost">Charging Cost *</label><input type="number" id="chargingCost" min="0" step="0.01" required></div>
          <div class="form-group"><label for="chargingOdometer">Odometer (km)</label><input type="number" id="chargingOdometer" min="0" step="0.01"></div>
          <div class="form-group full-width"><label for="chargingNotes">Notes</label><textarea id="chargingNotes" rows="3"></textarea></div>
        </div>
      </form>
    </div>
    <div class="modal-footer"><button type="button" class="btn-outline" id="cancelCharging">Cancel</button><button type="submit" form="chargingForm" class="btn-primary" id="saveChargingBtn"><i class="ph ph-floppy-disk"></i> Save Transaction</button></div>
  </div>
</div>
