<section
    class="card profile-card profile-danger-card"
    aria-labelledby="deleteAccountHeading"
>
    <div class="card-header">
        <div>
            <h3 id="deleteAccountHeading">
                Delete Account
            </h3>

            <p class="card-subtitle">
                Permanently remove your account and profile information.
            </p>
        </div>
    </div>

    <div class="profile-danger-content">
        <div>
            <h4>
                Permanently delete this account
            </h4>

            <p>
                Once your account is deleted, this action cannot be undone.
                You will need to enter your current password before deletion
                can continue.
            </p>
        </div>

        <button
            type="button"
            class="btn-danger"
            id="openDeleteAccountModal"
        >
            <i
                class="ph ph-trash"
                aria-hidden="true"
            ></i>

            Delete Account
        </button>
    </div>
</section>

{{-- Delete Account Confirmation Modal --}}
<div
    class="profile-modal-backdrop"
    id="deleteAccountModal"
    hidden
>
    <div
        class="profile-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="deleteAccountModalTitle"
    >
        <div class="profile-modal-header">
            <div>
                <h3 id="deleteAccountModalTitle">
                    Delete Account?
                </h3>

                <p>
                    This action is permanent and cannot be reversed.
                </p>
            </div>

            <button
                type="button"
                class="profile-modal-close"
                id="closeDeleteAccountModal"
                aria-label="Close"
            >
                <i
                    class="ph ph-x"
                    aria-hidden="true"
                ></i>
            </button>
        </div>

        <form
            method="POST"
            action="{{ route('profile.destroy') }}"
            id="deleteAccountForm"
        >
            @csrf
            @method('delete')

            <div class="profile-modal-body">
                <div class="profile-delete-warning">
                    <i
                        class="ph ph-warning"
                        aria-hidden="true"
                    ></i>

                    <div>
                        <strong>
                            This will permanently delete your account.
                        </strong>

                        <p>
                            Enter your current password below to confirm.
                        </p>
                    </div>
                </div>

                <div class="form-group">
                    <label for="delete_account_password">
                        Current Password *
                    </label>

                    <div class="password-input-wrapper">
                        <input
                            id="delete_account_password"
                            name="password"
                            type="password"
                            autocomplete="current-password"
                            required
                            autofocus
                            class="@if($errors->userDeletion->has('password')) is-invalid @endif"
                        >

                        <button
                            type="button"
                            class="password-toggle-btn"
                            data-password-toggle="delete_account_password"
                            aria-label="Show password"
                        >
                            <i class="ph ph-eye"></i>
                        </button>
                    </div>

                    @if ($errors->userDeletion->has('password'))
                        <p
                            class="profile-field-error"
                            id="deleteAccountPasswordError"
                        >
                            {{ $errors->userDeletion->first('password') }}
                        </p>
                    @endif
                </div>
            </div>

            <div class="profile-modal-footer">
                <button
                    type="button"
                    class="btn-outline"
                    id="cancelDeleteAccountBtn"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn-danger"
                    id="confirmDeleteAccountBtn"
                >
                    <i
                        class="ph ph-trash"
                        aria-hidden="true"
                    ></i>

                    Permanently Delete
                </button>
            </div>
        </form>
    </div>
</div>