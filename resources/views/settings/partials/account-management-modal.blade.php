<div
    id="accountManagementModal"
    class="modal-overlay"
    role="dialog"
    aria-modal="true"
    aria-labelledby="accountManagementModalTitle"
    aria-hidden="true"
>
    <div class="custom-modal">

        <div class="modal-header">
            <div>
                <h2 id="accountManagementModalTitle">
                    Account Management
                </h2>

                <p>
                    Manage Fleet system user accounts.
                </p>
            </div>

            <button
                type="button"
                class="modal-close"
                id="closeAccountManagementModal"
                aria-label="Close modal"
            >
                <i class="ph ph-x"></i>
            </button>
        </div>

        <div class="modal-body">

            <div class="form-grid">
                <div class="form-group full-width">
                    <label for="accountManagementAction">
                        Account Action
                    </label>

                    <select id="accountManagementAction">
                        <option value="">
                            Select an action
                        </option>

                        <option value="create">
                            Create Account
                        </option>

                        <option value="update">
                            Update Account
                        </option>

                        <option value="reset_password">
                            Reset Password
                        </option>

                        <option value="delete">
                            Delete Account
                        </option>
                    </select>
                </div>
            </div>

            <div id="accountManagementContent">
                <div class="settings-empty-state">
                    <i
                        class="ph ph-user-gear"
                        aria-hidden="true"
                    ></i>

                    <p>
                        Select an account action to continue.
                    </p>
                </div>
            </div>

        </div>

    </div>
</div>