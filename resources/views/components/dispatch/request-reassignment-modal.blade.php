<div
    id="requestReassignmentModal"
    class="modal-overlay"
    role="dialog"
    aria-modal="true"
    aria-labelledby="requestReassignmentModalTitle"
    aria-describedby="requestReassignmentModalDescription"
    hidden
>
    <div class="custom-modal">

        <div class="modal-header">
            <div>
                <h2 id="requestReassignmentModalTitle">
                    Request Reassignment
                </h2>

                <p id="requestReassignmentModalDescription">
                    Provide a reason for requesting a different driver.
                </p>
            </div>

            <button
                type="button"
                class="modal-close"
                id="closeRequestReassignmentModal"
                aria-label="Close modal"
            >
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>

        <form id="requestReassignmentForm" class="operational-modal-form">
            <div class="modal-body">

                <input
                    type="hidden"
                    id="reassignmentDispatchId"
                />

                <div class="form-group">
                    <label for="reassignmentReason">
                        Reason
                    </label>

                    <textarea
                        id="reassignmentReason"
                        rows="5"
                        required
                        minlength="10"
                        maxlength="2000"
                        placeholder="Explain why this dispatch should be reassigned."
                    ></textarea>

                    <small class="field-hint">
                        Minimum 10 characters.
                    </small>
                </div>

            </div>

            <div class="modal-footer">

                    <button
                        type="button"
                        class="btn-outline"
                        id="cancelRequestReassignment"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn-primary"
                        id="submitRequestReassignment"
                    >
                        <i class="ph ph-paper-plane-tilt"></i>
                        Submit Request
                    </button>

            </div>
        </form>
    </div>
</div>


<div
    id="acceptDispatchModal"
    class="modal-overlay"
    role="dialog"
    aria-modal="true"
    aria-labelledby="acceptDispatchModalTitle"
    aria-describedby="acceptDispatchModalDescription"
    hidden
>
    <div class="custom-modal delete-modal accept-dispatch-modal">
        <div class="delete-icon">
            <i class="ph-fill ph-check-circle"></i>
        </div>
        <h2 id="acceptDispatchModalTitle">
            Accept Dispatch
        </h2>
        <p id="acceptDispatchModalDescription">
            Are you sure you want to accept
            <strong id="acceptDispatchName">this dispatch</strong>?
        </p>
        <p class="delete-note" id="acceptDispatchMessageText">
            Once accepted, the dispatch status will be updated to Assigned.
        </p>
        <div class="modal-footer">
            <button
                type="button"
                class="btn-outline"
                id="cancelAcceptDispatch"
            >
                Cancel
            </button>
            <button
                type="button"
                class="btn-success"
                id="confirmAcceptDispatch"
            >
                <i class="ph ph-check"></i>
                Accept Dispatch
            </button>
        </div>
    </div>
</div>
