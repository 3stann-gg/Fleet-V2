<div
    id="accountManagementModal"
    class="modal-overlay"
    role="dialog"
    aria-modal="true"
    aria-labelledby="accountManagementModalTitle"
    aria-describedby="accountManagementModalDescription"
    aria-hidden="true"
>
    <div class="custom-modal">

        <div class="modal-header">
            <div>
                <h2 id="accountManagementModalTitle">
                    Account Management
                </h2>

                <p id="accountManagementModalDescription">
                    Manage Fleet system user accounts.
                </p>
            </div>

            <button
                type="button"
                class="modal-close"
                id="closeAccountManagementModal"
                aria-label="Close modal"
            >
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>
        </div>

        <div class="modal-body">

            <section class="account-action-section" aria-labelledby="accountManagementActionHeading">
                <div class="account-section-heading">
                    <span>Account administration</span>
                    <h3 id="accountManagementActionHeading">Choose an action</h3>
                    <p>Creation and editing are separate from password and deactivation actions.</p>
                </div>

                <div class="form-grid">
                <div class="form-group full-width">
                    <label for="accountManagementAction">
                        Account Action
                    </label>

                    <select id="accountManagementAction" aria-describedby="accountManagementActionHint">
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
                            Deactivate Account
                        </option>
                    </select>

                    <p class="settings-inline-hint" id="accountManagementActionHint">
                        Select one action; existing authorization and security checks still apply.
                    </p>
                </div>
            </div>
            </section>

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
