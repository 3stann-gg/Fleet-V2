@extends('layouts.app')

@section('title', 'User Management | HIMS Fleet')

@section('content')

<section class="page-wrapper">
    <div class="vehicle-page user-management-page" id="userManagementPage">
        <div class="page-header">
            <div>
                <h1>User Management</h1>
                <p>Manage system user accounts, roles, and account access.</p>
            </div>

            <button
                type="button"
                class="btn-primary"
                id="openAccountManagementModal"
            >
                <i class="ph ph-user-gear" aria-hidden="true"></i>
                Manage User Accounts
            </button>
        </div>

        <section
            class="card user-management-directory"
            aria-labelledby="userManagementDirectoryHeading"
        >
            <div class="card-header">
                <div>
                    <h2 id="userManagementDirectoryHeading">User Accounts</h2>
                    <p class="card-subtitle">
                        Review identity, role, and current account status.
                    </p>
                </div>
            </div>

            <div class="table-responsive">
                <table
                    class="fleet-table user-management-table"
                    aria-labelledby="userManagementDirectoryHeading"
                >
                    <thead>
                        <tr>
                            <th scope="col">User</th>
                            <th scope="col">Role</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>

                    <tbody id="userManagementTableBody">
                        <tr>
                            <td colspan="4" class="settings-empty-state">
                                Loading user accounts...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p
                class="settings-inline-hint"
                id="userManagementTableStatus"
                role="status"
                aria-live="polite"
            >
                Loading account directory.
            </p>
        </section>
    </div>
</section>

@include('user-management.partials.account-management-modal')

@push('scripts')
    <script src="{{ asset('assets/js/user-management/account-management.js') }}"></script>
@endpush

@endsection
