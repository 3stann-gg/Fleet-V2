<div
  id="editReservationModal"
  class="modal-overlay"
  role="dialog"
  aria-modal="true"
  aria-labelledby="editReservationModalTitle"
  aria-describedby="editReservationModalDescription"
>
  <div class="custom-modal">
    <!-- Header -->
    <div class="modal-header">
      <div>
        <h2 id="editReservationModalTitle">Edit Reservation</h2>

        <p id="editReservationModalDescription">
          Update transport reservation information.
        </p>
      </div>

      <button
        type="button"
        class="modal-close"
        id="closeEditReservationModal"
        aria-label="Close edit reservation modal"
      >
        <i class="ph ph-x" aria-hidden="true"></i>
      </button>
    </div>

    <form id="editReservationForm" class="reservation-form operational-modal-form">
      <!-- Body -->
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-section-heading">
            <h3>Request Information</h3>
            <p>Review the request reference, subject, and transport type.</p>
          </div>
          <div class="form-group">
            <label for="editReservationNumber">Reservation Number *</label>
            <input
              type="text"
              id="editReservationNumber"
              required
            />
          </div>

          <div class="form-group">
            <label
                for="editReservationPatient"
                id="editReservationPatientLabel"
            >
                Patient Name
            </label>
            <input
                type="text"
                id="editReservationPatient"
            />
          </div>

          <div class="form-group">
            <label for="editReservationType">Transport Request Type *</label>
            <select id="editReservationType" required>
              <option value="">Select Request Type</option>
              <option value="emergency_response">Emergency Response</option>
              <option value="patient_transfer">Patient Transfer</option>
              <option value="staff_transport">Staff Transport</option>
              <option value="medical_supply_delivery">Medical Supply Delivery</option>
              <option value="laboratory_specimen_transport">Laboratory Specimen Transport</option>
              <option value="equipment_transfer">Equipment Transfer</option>
              <option value="administrative_trip">Administrative Trip</option>
              <optgroup label="Legacy records">
                <option value="Patient Transport">Patient Transport</option>
                <option value="Emergency Transfer">Emergency Transfer</option>
                <option value="Medical Appointment">Medical Appointment</option>
                <option value="Laboratory Transport">Laboratory Transport</option>
                <option value="Staff Transport">Staff Transport</option>
                <option value="Supply Delivery">Supply Delivery</option>
              </optgroup>
            </select>

            <small
                class="form-hint"
                id="reservationTypeHint"
            >
                Select the type of transportation request.
            </small>
          </div>

          <div class="form-section-heading">
            <h3>Assignment</h3>
            <p>Update the vehicle and driver assigned to this request.</p>
          </div>

          <div class="form-group">
            <label for="editReservationVehicle">Vehicle *</label>
            <select id="editReservationVehicle">
              
            </select>
          </div>

          <div class="form-group">
            <label for="editReservationDriver">Driver *</label>
            <select id="editReservationDriver">
              <option value="">Select Vehicle First</option>
            </select>
            <small class="form-hint">
                Driver is based on the selected vehicle.
            </small>
          </div>

          <div class="form-section-heading">
            <h3>Origin &amp; Destination</h3>
            <p>Confirm the pickup and destination locations.</p>
          </div>

          <!-- Pickup Location -->
          <div class="form-group">
              <label for="editReservationPickup">
                  Pickup Location *
              </label>

              <div class="facility-autocomplete">
                  <input
                      type="text"
                      id="editReservationPickup"
                      class="form-control"
                      placeholder="Search hospital or facility..."
                      autocomplete="off"
                      required
                  />

                  <div
                      id="editReservationPickupSuggestions"
                      class="facility-suggestions"
                      hidden
                  ></div>
              </div>

              <small class="form-hint">
                  Hospital, facility, address, or other pickup location.
              </small>
          </div>

          <!-- Destination -->
          <div class="form-group">
              <label for="editReservationDestination">
                  Destination *
              </label>

              <div class="facility-autocomplete">
                  <input
                      type="text"
                      id="editReservationDestination"
                      class="form-control"
                      placeholder="Search hospital or facility..."
                      autocomplete="off"
                      required
                  />

                  <div
                      id="editReservationDestinationSuggestions"
                      class="facility-suggestions"
                      hidden
                  ></div>
              </div>

              <small class="form-hint">
                  Hospital, facility, address, or other destination location.
              </small>
          </div>

          <!-- Logistics Integration -->
          <div
              class="form-group full-width"
              id="editReservationLogisticsSection"
              hidden
          >
              <div class="section-header">
                  <h3>Logistics Information</h3>
                  <p>
                      Shipment details from the external Logistics system.
                  </p>
              </div>

              <div class="form-group">
                  <label for="editReservationShipment">
                      Logistics Shipment
                  </label>

                  <input
                      type="text"
                      id="editReservationShipment"
                      class="form-control"
                      placeholder="Waiting for Logistics integration"
                      readonly
                      disabled
                  />

                  <small class="form-hint">
                      Select an active shipment from the centralized Logistics system.
                  </small>
              </div>

              <div class="form-grid">
                  <div class="form-group">
                      <label for="editLogisticsExternalId">
                          Logistics Reference
                      </label>
                      <input
                          type="text"
                          id="editLogisticsExternalId"
                          placeholder="Waiting for Logistics integration"
                          readonly
                      />
                  </div>

                  <div class="form-group">
                      <label for="editLogisticsPoNumber">
                          PO Number
                      </label>
                      <input
                          type="text"
                          id="editLogisticsPoNumber"
                          placeholder="Waiting for Logistics integration"
                          readonly
                      />
                  </div>

                  <div class="form-group">
                      <label for="editLogisticsSupplier">
                          Supplier
                      </label>
                      <input
                          type="text"
                          id="editLogisticsSupplier"
                          placeholder="Waiting for Logistics integration"
                          readonly
                      />
                  </div>

                  <div class="form-group">
                      <label for="editLogisticsTrackingNumber">
                          Tracking / AWB Number
                      </label>
                      <input
                          type="text"
                          id="editLogisticsTrackingNumber"
                          placeholder="Waiting for Logistics integration"
                          readonly
                      />
                  </div>

                  <div class="form-group">
                      <label for="editLogisticsStatus">
                          Logistics Status
                      </label>
                      <input
                          type="text"
                          id="editLogisticsStatus"
                          value="Not Synced"
                          readonly
                      />
                  </div>

                  <div class="form-group">
                      <label for="editLogisticsEta">
                          External ETA
                      </label>
                      <input
                          type="text"
                          id="editLogisticsEta"
                          placeholder="Waiting for Logistics integration"
                          readonly
                      />
                  </div>
              </div>

              <small class="form-hint">
                  Logistics information will be populated automatically
                  once the external Logistics system is integrated.
              </small>
          </div>

          <div class="form-section-heading">
            <h3>Schedule &amp; Priority</h3>
            <p>Update the requested transport time and operational priority.</p>
          </div>

          <div class="form-group">
            <label for="editReservationDate">Schedule Date *</label>
            <input
              type="date"
              id="editReservationDate"
              required
            />
            <small class="form-hint" id="editReservationDateHint">Select a date allowed by Reservation Settings.</small>
          </div>

          <div class="form-group">
            <label for="editReservationTime">Schedule Time *</label>
            <input
              type="time"
              id="editReservationTime"
              required
            />
            <small class="form-hint" id="editReservationTimeHint">Enter the scheduled pickup time.</small>
          </div>

          <div class="form-group">
            <label for="editReservationPriority">Priority *</label>
            <select id="editReservationPriority" required>
              <option value="">Select Priority</option>
              <option value="emergency">Emergency</option>
              <option value="urgent">Urgent</option>
              <option value="routine">Routine</option>
              <option value="scheduled">Scheduled</option>
              <optgroup label="Legacy records">
                <option value="Emergency">Emergency (legacy)</option>
                <option value="High">Urgent (legacy)</option>
                <option value="Normal">Routine (legacy)</option>
                <option value="Low">Scheduled (legacy)</option>
              </optgroup>
            </select>

            <small
                class="form-hint"
                id="editReservationPriorityHint"
            >
                Choose priority independently from the transport request type.
            </small>
          </div>

          <div class="form-group">
            <label for="editReservationStatus">Status *</label>
            <input type="text" id="editReservationStatus" readonly>
          </div>

          <div class="form-section-heading">
            <h3>Transport Requirements</h3>
            <p>Review required capabilities and the current workflow status.</p>
          </div>

          <div class="form-group full-width">
            <label>Required Vehicle Capabilities</label>
            <div id="editReservationRequiredCapabilities" class="checklist-options">
              <span class="form-hint">Loading capabilities...</span>
            </div>
            <small class="form-hint">Leave blank when no special capability is required.</small>
          </div>

          <div class="form-section-heading">
            <h3>Contact &amp; Notes</h3>
            <p>Maintain coordination details and additional instructions.</p>
          </div>

          <div class="form-group">
            <label for="editReservationContact">Contact Number</label>
            <input
              type="tel"
              id="editReservationContact"
            />
          </div>

          <div class="form-group full-width">
            <label for="editReservationNotes">Notes</label>
            <textarea
              id="editReservationNotes"
              rows="4"
            ></textarea>
          </div>
        </div>
      </div>

      <div class="modal-footer">
          <button type="button" class="btn-outline" id="cancelEditReservation">
            Cancel
          </button>

          <button type="submit" class="btn-primary" id="updateReservationBtn">
            Update Reservation
          </button>
      </div>
    </form>
  </div>
</div>
