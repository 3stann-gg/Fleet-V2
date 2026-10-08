<div
  id="dispatchChecklistModal"
  class="modal-overlay"
  role="dialog"
  aria-modal="true"
  aria-labelledby="dispatchChecklistModalTitle"
>
  <div class="custom-modal">
    <div class="modal-header">
      <div>
        <h2 id="dispatchChecklistModalTitle">Trip Checklist</h2>
        <p id="dispatchChecklistModalDescription">Record the operational checklist for this dispatch.</p>
      </div>
      <button type="button" class="modal-close" id="closeDispatchChecklistModal" aria-label="Close checklist">
        <i class="ph ph-x"></i>
      </button>
    </div>

    <div class="modal-body">
      <form id="dispatchChecklistForm" class="vehicle-form">
        <input type="hidden" id="checklistDispatchId">

        <div id="preTripChecklistFields">
          <div class="form-group">
            <label for="preTripResult">Result *</label>
            <select id="preTripResult">
              <option value="passed">Passed</option>
              <option value="passed_with_remarks">Passed with Remarks</option>
              <option value="failed">Failed</option>
            </select>
          </div>
          <div class="form-group full-width">
            <label>Vehicle Checks</label>
            <div class="checklist-options">
              @foreach([
                'tires' => 'Tires',
                'brakes' => 'Brakes',
                'horn' => 'Horn',
                'mirrors' => 'Mirrors',
                'energy_level_checked' => 'Energy Level Checked',
                'sufficient_energy' => 'Sufficient Energy',
                'documents_available' => 'Documents Available',
                'communication_equipment_operational' => 'Communication Equipment Operational',
              ] as $key => $label)
                <label data-pre-trip-vehicle="shared"><input type="checkbox" data-pre-check="{{ $key }}"> <span>{{ $label }}</span></label>
              @endforeach
              @foreach([
                'lights' => 'Lights',
                'vehicle_clean' => 'Vehicle Clean',
                'required_equipment_present' => 'Required Equipment Present',
                'stretcher' => 'Stretcher (When Required)',
                'wheelchair_support' => 'Wheelchair Support (When Required)',
                'oxygen_equipment' => 'Oxygen Equipment (When Required)',
                'emergency_kit' => 'Emergency Kit (When Required)',
              ] as $key => $label)
                <label data-pre-trip-vehicle="standard"><input type="checkbox" data-pre-check="{{ $key }}"> <span>{{ $label }}</span></label>
              @endforeach
              @foreach([
                'headlight' => 'Headlight',
                'tail_light' => 'Tail Light',
                'turn_signals' => 'Turn Signals',
                'helmet_available' => 'Helmet Available',
                'cargo_box_secure' => 'Cargo Box Secure',
              ] as $key => $label)
                <label data-pre-trip-vehicle="motorcycle"><input type="checkbox" data-pre-check="{{ $key }}"> <span>{{ $label }}</span></label>
              @endforeach
              <label data-pre-trip-vehicle="motorcycle" data-required-capability="lab_specimen_transport"><input type="checkbox" data-pre-check="specimen_storage_available"> <span>Specimen Storage Available</span></label>
              <label data-pre-trip-vehicle="motorcycle" data-required-capability="medical_supply_transport"><input type="checkbox" data-pre-check="medical_supply_storage_available"> <span>Medical Supply Storage Available</span></label>
              <label data-pre-trip-vehicle="motorcycle" data-required-capability="cargo_transport"><input type="checkbox" data-pre-check="cargo_secured"> <span>Cargo Secured</span></label>
            </div>
          </div>
        </div>

        <div id="postTripChecklistFields" hidden>
          <div class="form-grid">
            <div class="form-group">
              <label for="postTripOdometer">Final Odometer</label>
              <input type="number" min="0" step="0.01" id="postTripOdometer">
            </div>
            <div class="form-group" data-post-trip-energy="fuel">
              <label for="postTripFuel">Fuel Remaining (%)</label>
              <input type="number" min="0" max="100" step="0.01" id="postTripFuel">
            </div>
            <div class="form-group" data-post-trip-energy="battery">
              <label for="postTripBattery">Battery Remaining (%)</label>
              <input type="number" min="0" max="100" step="0.01" id="postTripBattery">
            </div>
            <div class="form-group">
              <label for="postTripTires">Tire Condition</label>
              <select id="postTripTires"><option value="good">Good</option><option value="attention_required">Attention Required</option></select>
            </div>
            <div class="form-group">
              <label for="postTripLights">Lights Condition</label>
              <select id="postTripLights"><option value="good">Good</option><option value="attention_required">Attention Required</option></select>
            </div>
            <div class="form-group">
              <label for="postTripBrakes">Brake Condition</label>
              <select id="postTripBrakes"><option value="good">Good</option><option value="attention_required">Attention Required</option></select>
            </div>
          </div>
          <div class="checklist-options">
            <label><input type="checkbox" id="postTripVisibleDamage"> <span>Visible Damage</span></label>
            <label><input type="checkbox" id="postTripIssueReported"> <span>Issue Reported</span></label>
            <label><input type="checkbox" id="postTripMaintenanceRequired"> <span>Maintenance Required</span></label>
            <label><input type="checkbox" id="postTripCleaningRequired"> <span>Cleaning Required</span></label>
          </div>
        </div>

        <div class="form-group full-width">
          <label for="tripChecklistRemarks">Remarks</label>
          <textarea id="tripChecklistRemarks" rows="4"></textarea>
        </div>

        <div id="dispatchChecklistReadOnly" class="view-dispatch-details" hidden></div>

        <div id="postTripContextActions" class="form-actions" hidden>
          <button type="button" class="btn-primary" id="createChecklistSanitation">
            <i class="ph ph-sparkle"></i> Create Sanitation Record
          </button>
          <a class="btn-outline" id="createChecklistMaintenance" href="{{ route('maintenance') }}">
            <i class="ph ph-wrench"></i> Create Maintenance Request
          </a>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn-outline" id="cancelDispatchChecklist">Close</button>
          <button type="submit" class="btn-primary" id="saveDispatchChecklist">
            <i class="ph ph-check"></i> Save Checklist
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
