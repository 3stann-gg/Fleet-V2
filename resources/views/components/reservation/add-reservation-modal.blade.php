<div
    id="addReservationModal"
    class="modal-overlay"
    role="dialog"
    aria-modal="true"
    aria-labelledby="addReservationModalTitle"
    aria-describedby="addReservationModalDescription"
>
    <div class="custom-modal">

        <!-- Header -->
        <div class="modal-header">
            <div>
                <h2 id="addReservationModalTitle">
                    Request New Reservation
                </h2>
                <p id="addReservationModalDescription">
                    Create a hospital transport reservation request.
                </p>
            </div>

            <button
                type="button"
                class="modal-close"
                id="closeAddReservationModal"
                aria-label="Close add reservation modal"
            >
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>

        <form
            id="reservationForm"
            class="reservation-form operational-modal-form"
        >
            <!-- Body -->
            <div class="modal-body">
                <div class="form-grid">

                    <div class="form-section-heading">
                        <h3>Request Information</h3>
                        <p>Identify the request and the patient or transport subject.</p>
                    </div>

                    <!-- Reservation Number -->
                    <div class="form-group">
                        <label for="reservationNumber">
                            Reservation Number *
                        </label>

                        <input
                            type="text"
                            id="reservationNumber"
                            readonly
                        />
                    </div>

                    <!-- Patient Name -->
                    <div class="form-group">
                        <label for="reservationPatient" id="reservationPatientLabel">
                            Patient Name
                        </label>

                        <input
                            type="text"
                            id="reservationPatient"
                            placeholder="Enter patient name"
                        />
                    </div>

                    <!-- Request Type -->
                    <div class="form-group">
                        <label for="reservationType">
                            Transport Request Type *
                        </label>

                        <select
                            id="reservationType"
                            required
                        >
                            <option value="">
                                Select Request Type
                            </option>

                            <option value="emergency_response">
                                Emergency Response
                            </option>

                            <option value="patient_transfer">
                                Patient Transfer
                            </option>

                            <option value="staff_transport">
                                Staff Transport
                            </option>

                            <option value="medical_supply_delivery">
                                Medical Supply Delivery
                            </option>

                            <option value="laboratory_specimen_transport">
                                Laboratory Specimen Transport
                            </option>

                            <option value="equipment_transfer">
                                Equipment Transfer
                            </option>

                            <option value="administrative_trip">
                                Administrative Trip
                            </option>
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
                        <p>Select the available vehicle and its assigned driver.</p>
                    </div>

                    <!-- Vehicle -->
                    <div class="form-group">
                        <label for="reservationVehicle">
                            Vehicle *
                        </label>

                        <select
                            id="reservationVehicle"
                            required
                        >
                            <option value="">
                                Loading vehicles...
                            </option>
                        </select>

                        <small class="form-hint">
                            Available vehicles are loaded automatically.
                        </small>
                    </div>

                    <!-- Driver -->
                    <div class="form-group">
                        <label for="reservationDriver">
                            Driver *
                        </label>

                        <select
                            id="reservationDriver"
                            required
                            disabled
                        >
                            <option value="">
                                Select Vehicle First
                            </option>
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
                        <label for="reservationPickup">Pickup Location *</label>

                        <div class="facility-autocomplete">
                            <input
                                type="text"
                                id="reservationPickup"
                                class="form-control"
                                placeholder="Search hospital or facility..."
                                autocomplete="off"
                                required
                            />

                            <div
                                id="reservationPickupSuggestions"
                                class="facility-suggestions"
                                hidden
                            ></div>
                        </div>
                        <small
                            class="form-hint"
                            id="reservationPickupHint"
                        >
                            Hospital, facility, address, or other pickup location.
                        </small>
                    </div>
                    

                    <!-- Destination -->
                    <div class="form-group">
                        <label for="reservationDestination">Destination *</label>

                        <div class="facility-autocomplete">
                            <input
                                type="text"
                                id="reservationDestination"
                                class="form-control"
                                placeholder="Search hospital or facility..."
                                autocomplete="off"
                                required
                            />

                            <div
                                id="reservationDestinationSuggestions"
                                class="facility-suggestions"
                                hidden
                            ></div>
                        </div>
                        <small
                            class="form-hint"
                            id="reservationDestinationHint"
                        >
                            Hospital, facility, address, or other destination location.
                        </small>
                    </div>

                        <!-- Logistics Integration -->
                        <div
                            class="form-group full-width"
                            id="reservationLogisticsSection"
                            hidden
                        >
                            <div class="section-header">
                                <h3>Logistics Information</h3>
                                <p>
                                    Shipment details from the external Logistics system.
                                </p>
                            </div>

                            <div class="form-group full-width">
                            <label for="reservationShipment">Logistics Shipment</label>

                            <select
                                id="reservationShipment"
                                name="shipment_id"
                                class="form-control"
                                disabled
                            >
                                <option value="">Select Logistics Shipment</option>
                            </select>

                            <small class="form-hint">
                                Select an active shipment from the centralized Logistics system.
                            </small>
                        </div>

                        <div class="form-grid">

                            <!-- External Logistics ID -->
                            <div class="form-group">
                                <label for="logisticsExternalId">
                                    Logistics Reference
                                </label>

                                <input
                                    type="text"
                                    id="logisticsExternalId"
                                    placeholder="Waiting for Logistics integration"
                                    readonly
                                />
                            </div>

                            <!-- PO Number -->
                            <div class="form-group">
                                <label for="logisticsPoNumber">
                                    PO Number
                                </label>

                                <input
                                    type="text"
                                    id="logisticsPoNumber"
                                    placeholder="Waiting for Logistics integration"
                                    readonly
                                />
                            </div>

                            <!-- Supplier -->
                            <div class="form-group">
                                <label for="logisticsSupplier">
                                    Supplier
                                </label>

                                <input
                                    type="text"
                                    id="logisticsSupplier"
                                    placeholder="Waiting for Logistics integration"
                                    readonly
                                />
                            </div>

                            <!-- Tracking -->
                            <div class="form-group">
                                <label for="logisticsTrackingNumber">
                                    Tracking / AWB Number
                                </label>

                                <input
                                    type="text"
                                    id="logisticsTrackingNumber"
                                    placeholder="Waiting for Logistics integration"
                                    readonly
                                />
                            </div>

                            <!-- External Status -->
                            <div class="form-group">
                                <label for="logisticsStatus">
                                    Logistics Status
                                </label>

                                <input
                                    type="text"
                                    id="logisticsStatus"
                                    value="Not Synced"
                                    readonly
                                />
                            </div>

                            <!-- ETA -->
                            <div class="form-group">
                                <label for="logisticsEta">
                                    External ETA
                                </label>

                                <input
                                    type="text"
                                    id="logisticsEta"
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
                        <p>Set the requested transport time and operational priority.</p>
                    </div>

                    <!-- Schedule Date -->
                    <div class="form-group">
                        <label for="reservationDate">
                            Schedule Date *
                        </label>

                        <input
                            type="date"
                            id="reservationDate"
                            required
                        />

                        <small
                            class="form-hint"
                            id="reservationDateHint"
                        >
                            Date is controlled by Reservation Settings.
                        </small>
                    </div>

                    <!-- Schedule Time -->
                    <div class="form-group">
                        <label for="reservationTime">
                            Schedule Time *
                        </label>

                        <input
                            type="time"
                            id="reservationTime"
                            required
                        />

                        <small
                            class="form-hint"
                            id="reservationTimeHint"
                        >
                            Enter the scheduled pickup time.
                        </small>
                    </div>

                    <!-- Priority -->
                    <div class="form-group">
                        <label for="reservationPriority">
                            Priority *
                        </label>

                        <select
                            id="reservationPriority"
                            required
                        >
                            <option value="">
                                Select Priority
                            </option>

                            <option value="emergency">
                                Emergency
                            </option>

                            <option value="urgent">
                                Urgent
                            </option>

                            <option value="routine" selected>
                                Routine
                            </option>

                            <option value="scheduled">
                                Scheduled
                            </option>
                        </select>

                        <small
                            class="form-hint"
                            id="reservationPriorityHint"
                        >
                            Choose priority independently from the transport request type.
                        </small>
                    </div>

                    <div class="form-section-heading">
                        <h3>Transport Requirements</h3>
                        <p>Record required capabilities and the request status.</p>
                    </div>

                    <!-- Status -->
                    <div class="form-group full-width">
                        <label>Required Vehicle Capabilities</label>
                        <div id="reservationRequiredCapabilities" class="checklist-options">
                            <span class="form-hint">Loading capabilities...</span>
                        </div>
                        <small class="form-hint">Leave blank when no special capability is required.</small>
                    </div>

                    <!-- Status -->
                    <div class="form-group">
                        <label for="reservationStatus">
                            Status *
                        </label>

                        <select
                            id="reservationStatus"
                            disabled
                        >
                            <option value="Pending">
                                Pending
                            </option>

                            <option value="Approved">
                                Approved
                            </option>
                        </select>

                        <small
                            class="form-hint"
                            id="reservationStatusHint"
                        >
                            Initial status is controlled by Reservation Settings.
                        </small>
                    </div>

                    <div class="form-section-heading">
                        <h3>Contact &amp; Notes</h3>
                        <p>Add coordination details and any additional instructions.</p>
                    </div>

                    <!-- Contact Number -->
                    <div class="form-group">
                        <label for="reservationContact">
                            Contact Number
                        </label>

                        <input
                            type="tel"
                            id="reservationContact"
                            placeholder="Enter contact number"
                            autocomplete="tel"
                        />
                    </div>

                    <!-- Notes -->
                    <div class="form-group full-width">
                        <label for="reservationNotes">
                            Notes
                        </label>

                        <textarea
                            id="reservationNotes"
                            rows="4"
                            placeholder="Enter additional instructions or remarks..."
                        ></textarea>
                    </div>

                </div>
            </div>

            <!-- Footer -->
            <div class="modal-footer">

                    <button
                        type="button"
                        class="btn-outline"
                        id="cancelAddReservation"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn-primary"
                        id="saveReservationBtn"
                    >
                        Save Reservation
                    </button>

            </div>
        </form>
    </div>
</div>
