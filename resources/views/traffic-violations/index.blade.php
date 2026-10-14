@extends('layouts.app')

@section('title', 'Traffic Violations | HIMS Fleet')

@php
    $canCreate = auth()->user()->hasPermission('traffic_violations.create');
    $canUpdate = auth()->user()->hasPermission('traffic_violations.update');
    $canReview = auth()->user()->hasPermission('traffic_violations.review');
    $canSettle = auth()->user()->hasPermission('traffic_violations.settlement');
    $canArchive = auth()->user()->hasPermission('traffic_violations.archive');
    $canViewFinancial = $canReview || $canSettle;
@endphp

@section('content')
<section class="page-wrapper">
    <div class="vehicle-page traffic-violations-page">
        <div class="page-header">
            <div>
                <h1>Traffic Violation Management</h1>
                <p>Record, review, and track citations involving hospital fleet operations.</p>
            </div>
            @if($canCreate)
                <button type="button" class="btn-primary" id="addTrafficViolationBtn">
                    <i class="ph ph-plus"></i> Add Violation
                </button>
            @endif
        </div>

        <p class="traffic-overview-label">Current Overview</p>
        <div class="stats-grid traffic-stats-grid">
            @foreach([
                ['total', 'Total Records', 'ph-files', ''],
                ['under_review', 'Requiring Review', 'ph-magnifying-glass', 'warning'],
                ['for_settlement', 'For Settlement', 'ph-receipt', 'warning'],
                ['unpaid', 'Unpaid', 'ph-currency-circle-dollar', 'warning'],
                ['closed_settled', 'Closed / Settled', 'ph-check-circle', 'success'],
            ] as [$key, $label, $icon, $iconClass])
                <div class="stat-card">
                    <div class="stat-icon {{ $iconClass }}"><i class="ph-fill {{ $icon }}"></i></div>
                    <div class="stat-content"><h3>{{ $overview[$key] }}</h3><p>{{ $label }}</p></div>
                </div>
            @endforeach
        </div>

        <form method="GET" action="{{ route('traffic-violations.index') }}" class="toolbar traffic-filter-form">
            <div class="toolbar-left">
                <div class="search-box">
                    <i class="ph ph-magnifying-glass"></i>
                    <input name="search" value="{{ request('search') }}" placeholder="Search citation, agency, location, driver, or vehicle">
                </div>
                <select name="violation_type" class="filter-select">
                    <option value="all">All Violation Types</option>
                    @foreach($typeLabels as $value => $label)<option value="{{ $value }}" @selected(request('violation_type') === $value)>{{ $label }}</option>@endforeach
                </select>
                <select name="responsibility" class="filter-select">
                    <option value="all">All Responsibility</option>
                    @foreach($responsibilityLabels as $value => $label)<option value="{{ $value }}" @selected(request('responsibility') === $value)>{{ $label }}</option>@endforeach
                </select>
                <select name="status" class="filter-select">
                    <option value="all">All Statuses</option>
                    @foreach($statusLabels as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach
                </select>
                @if($canViewFinancial)
                    <select name="payment_status" class="filter-select">
                        <option value="all">All Payment Statuses</option>
                        @foreach($paymentStatusLabels as $value => $label)<option value="{{ $value }}" @selected(request('payment_status') === $value)>{{ $label }}</option>@endforeach
                    </select>
                @endif
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="filter-select" aria-label="Violation date from">
                <input type="date" name="date_to" value="{{ request('date_to') }}" class="filter-select" aria-label="Violation date to">
                @if($canArchive)
                    <label class="traffic-archived-toggle"><input type="checkbox" name="show_archived" value="1" @checked(request()->boolean('show_archived'))> Show Archived</label>
                @endif
            </div>
            <div class="toolbar-right">
                <button type="submit" class="btn-primary"><i class="ph ph-funnel"></i> Apply</button>
                <a href="{{ route('traffic-violations.index') }}" class="btn-outline">Clear</a>
            </div>
        </form>

        <div class="card">
            <div class="card-header"><div><h3>Traffic Violation Records</h3><p>Responsibility is recorded only after authorized human review.</p></div></div>
            <div class="table-responsive">
                <table class="fleet-table">
                    <thead><tr>
                        <th>Citation #</th><th>Date</th><th>Vehicle</th><th>Driver</th><th>Violation</th>
                        <th>Responsibility</th><th>Status</th>
                        @if($canViewFinancial)<th>Fine</th><th>Payment</th>@endif
                        <th>Actions</th>
                    </tr></thead>
                    <tbody>
                    @forelse($trafficViolations as $violation)
                        <tr class="{{ $violation->archived_at ? 'traffic-row-archived' : '' }}">
                            <td>{{ $violation->citation_number ?: 'Not provided' }}</td>
                            <td>{{ $violation->violation_date?->format('M d, Y') }}</td>
                            <td>{{ $violation->vehicle?->plate_number ?: 'Not linked' }}</td>
                            <td>{{ $violation->driver ? trim($violation->driver->first_name.' '.$violation->driver->last_name) : 'Not identified' }}</td>
                            <td>{{ $violation->violation_type_label }}</td>
                            <td><span class="status-badge {{ $violation->responsibility === 'under_review' ? 'maintenance' : 'active' }}">{{ $violation->responsibility_label }}</span></td>
                            <td><span class="status-badge {{ in_array($violation->status, ['settled', 'closed'], true) ? 'completed' : 'maintenance' }}">{{ $violation->status_label }}</span></td>
                            @if($canViewFinancial)
                                <td>{{ $violation->fine_amount !== null ? '₱'.number_format((float) $violation->fine_amount, 2) : 'Not recorded' }}</td>
                                <td>{{ $violation->payment_status_label }}</td>
                            @endif
                            <td>
                                <div class="traffic-actions">
                                    <button type="button" class="action-btn traffic-action" data-action="view" data-id="{{ $violation->id }}" title="View"><i class="ph ph-eye"></i></button>
                                    @if($canUpdate && !$violation->archived_at)<button type="button" class="action-btn traffic-action" data-action="edit" data-id="{{ $violation->id }}" title="Edit"><i class="ph ph-pencil-simple"></i></button>@endif
                                    @if($canReview && !$violation->archived_at)<button type="button" class="action-btn traffic-action" data-action="review" data-id="{{ $violation->id }}" title="Review"><i class="ph ph-clipboard-text"></i></button>@endif
                                    @if($canSettle && !$violation->archived_at)<button type="button" class="action-btn traffic-action" data-action="settlement" data-id="{{ $violation->id }}" title="Settlement"><i class="ph ph-receipt"></i></button>@endif
                                    @if($canArchive)
                                        <button type="button" class="action-btn traffic-action" data-action="{{ $violation->archived_at ? 'restore' : 'archive' }}" data-id="{{ $violation->id }}" title="{{ $violation->archived_at ? 'Restore' : 'Archive' }}"><i class="ph ph-{{ $violation->archived_at ? 'arrow-counter-clockwise' : 'archive' }}"></i></button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canViewFinancial ? 10 : 8 }}" class="text-center">No traffic violation records match the current filters.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="table-footer">{{ $trafficViolations->links() }}</div>
    </div>
</section>

@if($canCreate || $canUpdate)
<div class="modal-overlay" id="trafficViolationFormModal" role="dialog" aria-modal="true" aria-labelledby="trafficViolationFormTitle">
    <div class="custom-modal traffic-modal-wide">
        <div class="modal-header"><div><h2 id="trafficViolationFormTitle">Add Traffic Violation</h2><p>Record citation facts without assigning responsibility automatically.</p></div><button type="button" class="modal-close" data-close-modal><i class="ph ph-x"></i></button></div>
        <form id="trafficViolationForm"><div class="modal-body"><input type="hidden" id="trafficViolationId">
            <div class="traffic-form-grid">
                <div class="form-group"><label for="citation_number">Citation Number</label><input id="citation_number" name="citation_number" maxlength="100"></div>
                <div class="form-group"><label for="violation_date">Violation Date *</label><input type="date" id="violation_date" name="violation_date" required></div>
                <div class="form-group"><label for="violation_time">Violation Time</label><input type="time" id="violation_time" name="violation_time"></div>
                <div class="form-group"><label for="issuing_agency">Issuing Agency</label><input id="issuing_agency" name="issuing_agency" maxlength="150"></div>
                <div class="form-group traffic-span-2"><label for="location">Location</label><input id="location" name="location" maxlength="255"></div>
                <div class="form-group"><label for="vehicle_id">Vehicle</label><select id="vehicle_id" name="vehicle_id"><option value="">Not identified</option>@foreach($vehicles as $vehicle)<option value="{{ $vehicle->id }}" data-context="{{ $vehicle->vehicle_type }} · LTO {{ $vehicle->lto_vehicle_category ?: 'not recorded' }} · Registration {{ $vehicle->registration_expiry?->format('Y-m-d') ?: 'not recorded' }} · {{ $vehicle->transmission_type ?: 'transmission not recorded' }}">{{ $vehicle->plate_number }} — {{ $vehicle->vehicle_type }}</option>@endforeach</select><small id="vehicleContext"></small></div>
                <div class="form-group"><label for="driver_id">Driver</label><select id="driver_id" name="driver_id"><option value="">Not identified</option>@foreach($drivers as $driver)<option value="{{ $driver->id }}" data-context="License {{ $driver->license_status }} · DL {{ implode(', ', $driver->dl_codes) ?: 'not recorded' }} · {{ $driver->clutch_authorization ?: 'clutch authorization not recorded' }} · Qualified: {{ $driver->qualifications->pluck('vehicle_type')->join(', ') ?: 'not recorded' }}">{{ $driver->last_name }}, {{ $driver->first_name }}</option>@endforeach</select><small id="driverContext"></small></div>
                <div class="form-group"><label for="dispatch_id">Related Dispatch</label><select id="dispatch_id" name="dispatch_id"><option value="">Not linked</option>@foreach($dispatches as $dispatch)<option value="{{ $dispatch->id }}" data-driver="{{ $dispatch->reservation?->driver_id }}" data-vehicle="{{ $dispatch->reservation?->vehicle_id }}" data-trip="{{ $dispatch->tripLog?->id }}" data-reservation="{{ $dispatch->reservation_id }}">{{ $dispatch->dispatch_number }} — {{ $dispatch->dispatch_date }}</option>@endforeach</select></div>
                <div class="form-group"><label for="violation_type">Violation Type *</label><select id="violation_type" name="violation_type" required><option value="">Select type</option>@foreach($typeLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                <div class="traffic-dispatch-warning traffic-span-2" id="dispatchMismatchWarning" hidden></div>
                <input type="hidden" id="trip_log_id" name="trip_log_id"><input type="hidden" id="reservation_id" name="reservation_id">
                <div class="form-group"><label for="fine_amount">Fine Amount</label><input type="number" id="fine_amount" name="fine_amount" min="0" step="0.01"></div>
                <div class="form-group"><label for="due_date">Due Date</label><input type="date" id="due_date" name="due_date"></div>
                <div class="form-group traffic-span-2"><label for="description">Description</label><textarea id="description" name="description" rows="3" maxlength="5000"></textarea></div>
                <div class="form-group traffic-span-2"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="2" maxlength="5000"></textarea></div>
            </div><div class="form-error" id="trafficViolationFormError" hidden></div>
        </div><div class="modal-footer"><button type="button" class="btn-outline" data-close-modal>Cancel</button><button type="submit" class="btn-primary">Save Violation</button></div></form>
    </div>
</div>
@endif

<div class="modal-overlay" id="viewTrafficViolationModal" role="dialog" aria-modal="true" aria-labelledby="viewTrafficViolationTitle">
    <div class="custom-modal traffic-modal-wide"><div class="modal-header"><div><h2 id="viewTrafficViolationTitle">Traffic Violation Details</h2><p>Administrative citation record and review history.</p></div><button type="button" class="modal-close" data-close-modal><i class="ph ph-x"></i></button></div><div class="modal-body"><dl class="traffic-detail-grid" id="trafficViolationDetails"></dl></div><div class="modal-footer"><button type="button" class="btn-outline" data-close-modal>Close</button></div></div>
</div>

@if($canReview)
<div class="modal-overlay" id="reviewTrafficViolationModal" role="dialog" aria-modal="true" aria-labelledby="reviewTrafficViolationTitle">
    <div class="custom-modal"><div class="modal-header"><div><h2 id="reviewTrafficViolationTitle">Review Traffic Violation</h2><p>Responsibility remains a manual human determination.</p></div><button type="button" class="modal-close" data-close-modal><i class="ph ph-x"></i></button></div>
        <form id="reviewTrafficViolationForm"><div class="modal-body"><input type="hidden" id="reviewViolationId">
            <div class="traffic-form-grid">
                <div class="form-group"><label for="reviewResponsibility">Responsibility</label><select id="reviewResponsibility" name="responsibility" required>@foreach($responsibilityLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                <div class="form-group"><label for="reviewStatus">Status</label><select id="reviewStatus" name="status" required>@foreach($statusLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                <div class="form-group"><label for="reviewFineAmount">Fine Amount</label><input type="number" id="reviewFineAmount" name="fine_amount" min="0" step="0.01"></div>
                <div class="form-group"><label for="reviewDueDate">Due Date</label><input type="date" id="reviewDueDate" name="due_date"></div>
                <div class="form-group"><label for="reviewPaymentStatus">Payment Status</label><select id="reviewPaymentStatus" name="payment_status">@foreach($paymentStatusLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                <div class="form-group"><label for="reviewPaymentDate">Payment Date</label><input type="date" id="reviewPaymentDate" name="payment_date"></div>
                <div class="form-group"><label for="reviewPaymentReference">Payment Reference</label><input id="reviewPaymentReference" name="payment_reference" maxlength="150"></div>
                <div class="form-group traffic-span-2"><label for="reviewResponsibilityNotes">Responsibility Notes</label><textarea id="reviewResponsibilityNotes" name="responsibility_notes" rows="3"></textarea></div>
                <div class="form-group traffic-span-2"><label for="reviewSettlementNotes">Settlement Notes</label><textarea id="reviewSettlementNotes" name="settlement_notes" rows="3"></textarea></div>
            </div><div class="form-error" id="reviewTrafficViolationError" hidden></div>
        </div><div class="modal-footer"><button type="button" class="btn-outline" data-close-modal>Cancel</button><button type="submit" class="btn-primary">Save Review</button></div></form>
    </div>
</div>
@endif

@if($canSettle)
<div class="modal-overlay" id="settlementTrafficViolationModal" role="dialog" aria-modal="true" aria-labelledby="settlementTrafficViolationTitle">
    <div class="custom-modal"><div class="modal-header"><div><h2 id="settlementTrafficViolationTitle">Settlement Information</h2><p>Payment tracking is informational and is not a payroll deduction.</p></div><button type="button" class="modal-close" data-close-modal><i class="ph ph-x"></i></button></div>
        <form id="settlementTrafficViolationForm"><div class="modal-body"><input type="hidden" id="settlementViolationId"><div class="traffic-form-grid">
            <div class="form-group"><label for="settlementFineAmount">Fine Amount</label><input type="number" id="settlementFineAmount" name="fine_amount" min="0" step="0.01"></div>
            <div class="form-group"><label for="settlementDueDate">Due Date</label><input type="date" id="settlementDueDate" name="due_date"></div>
            <div class="form-group"><label for="settlementPaymentStatus">Payment Status</label><select id="settlementPaymentStatus" name="payment_status">@foreach($paymentStatusLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
            <div class="form-group"><label for="settlementPaymentDate">Payment Date</label><input type="date" id="settlementPaymentDate" name="payment_date"></div>
            <div class="form-group"><label for="settlementPaymentReference">Payment Reference</label><input id="settlementPaymentReference" name="payment_reference" maxlength="150"></div>
            <div class="form-group traffic-span-2"><label for="settlementNotes">Settlement Notes</label><textarea id="settlementNotes" name="settlement_notes" rows="3"></textarea></div>
        </div><div class="form-error" id="settlementTrafficViolationError" hidden></div></div><div class="modal-footer"><button type="button" class="btn-outline" data-close-modal>Cancel</button><button type="submit" class="btn-primary">Save Settlement</button></div></form>
    </div>
</div>
@endif

@push('scripts')
<script>window.TRAFFIC_VIOLATION_ROUTES = @json(['base' => url('/traffic-violations')]);</script>
<script src="{{ asset('assets/js/traffic-violations/traffic-violations.js') }}"></script>
@endpush
@endsection
