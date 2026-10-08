<div
    id="editDispatchModal"
    class="modal-overlay"
    role="dialog"
    aria-modal="true"
    aria-labelledby="editDispatchModalTitle"
    aria-describedby="editDispatchModalDescription"
>
    <div class="custom-modal">
        <div class="modal-header">
            <div>
                <h2 id="editDispatchModalTitle">Edit Dispatch</h2>

                <p id="editDispatchModalDescription">
                    Update hospital transport dispatch information.
                </p>
            </div>

            <button
                type="button"
                class="modal-close"
                id="closeEditDispatchModal"
                aria-label="Close edit dispatch modal"
            >
                <i class="ph ph-x"></i>
            </button>
        </div>

        <div class="modal-body">
            <form id="editDispatchForm">
                <div class="form-grid">

                    <!-- Dispatch Number -->
                    <div class="form-group">
                        <label for="editDispatchNumber">
                            Dispatch Number
                        </label>

                        <input
                            type="text"
                            id="editDispatchNumber"
                            name="editDispatchNumber"
                            readonly
                        />
                    </div>

                    <!-- Reservation -->
                    <div class="form-group">
                        <label for="editDispatchReservation">
                            Reservation
                        </label>

                        <input
                            type="text"
                            id="editDispatchReservation"
                            name="editDispatchReservation"
                            readonly
                        />
                    </div>

                    <!-- Patient -->
                    <div class="form-group">
                        <label for="editDispatchPatient">
                            Patient Name
                        </label>

                        <input
                            type="text"
                            id="editDispatchPatient"
                            name="editDispatchPatient"
                            readonly
                        />
                    </div>

                    <!-- Request Type -->
                    <div class="form-group">
                        <label for="editDispatchRequestType">
                            Request Type
                        </label>

                        <input
                            type="text"
                            id="editDispatchRequestType"
                            name="editDispatchRequestType"
                            readonly
                        />
                    </div>

                    <!-- Vehicle -->
                    <div class="form-group">
                        <label for="editDispatchVehicle">
                            Vehicle
                        </label>

                        <input
                            type="text"
                            id="editDispatchVehicle"
                            name="editDispatchVehicle"
                            readonly
                        />
                    </div>

                    <!-- Driver -->
                    <div class="form-group">
                        <label for="editDispatchDriver">
                            Driver
                        </label>

                        <input
                            type="text"
                            id="editDispatchDriver"
                            name="editDispatchDriver"
                            readonly
                        />
                    </div>

                    <div class="form-group full-width">
                        <x-dispatch.candidate-vehicle-comparison prefix="editDispatch" />
                    </div>

                    <!-- Pickup -->
                    <div class="form-group">
                        <label for="editDispatchPickup">
                            Pickup Location
                        </label>

                        <input
                            type="text"
                            id="editDispatchPickup"
                            name="editDispatchPickup"
                            readonly
                        />
                    </div>

                    <!-- Destination -->
                    <div class="form-group">
                        <label for="editDispatchDestination">
                            Destination
                        </label>

                        <input
                            type="text"
                            id="editDispatchDestination"
                            name="editDispatchDestination"
                            readonly
                        />
                    </div>

                    <!-- Schedule Date -->
                    <div class="form-group">
                        <label for="editDispatchDate">
                            Schedule Date
                        </label>

                        <input
                            type="date"
                            id="editDispatchDate"
                            name="editDispatchDate"
                            readonly
                        />
                    </div>

                    <!-- Schedule Time -->
                    <div class="form-group">
                        <label for="editDispatchTime">
                            Schedule Time
                        </label>

                        <input
                            type="time"
                            id="editDispatchTime"
                            name="editDispatchTime"
                            readonly
                        />
                    </div>

                    <!-- Priority -->
                    <div class="form-group">
                        <label for="editDispatchPriority">
                            Priority
                        </label>

                        <input
                            type="text"
                            id="editDispatchPriority"
                            name="editDispatchPriority"
                            readonly
                        />
                    </div>

                    <!-- Status -->
                    <div class="form-group">
                        <label for="editDispatchStatus">
                            Status
                        </label>
                        <select
                            id="editDispatchStatus"
                            name="editDispatchStatus"
                            required
                        >
                            <option value="">Select status</option>
                            <option value="Pending">Pending</option>
                            <option value="Assigned">Assigned</option>
                            <option value="En Route">En Route</option>
                            <option value="Arrived">Arrived</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>

                    <!-- Contact -->
                    <div class="form-group">
                        <label for="editDispatchContact">
                            Contact Number
                        </label>

                        <input
                            type="tel"
                            id="editDispatchContact"
                            name="editDispatchContact"
                            readonly
                        />
                    </div>

                    <!-- Notes -->
                    <div class="form-group full-width">
                        <label for="editDispatchNotes">
                            Notes
                        </label>

                        <textarea
                            id="editDispatchNotes"
                            name="editDispatchNotes"
                            rows="3"
                            readonly
                        ></textarea>
                    </div>

                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn-outline"
                        id="cancelEditDispatch"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn-primary"
                        id="updateDispatchBtn"
                    >
                        Update Dispatch
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
