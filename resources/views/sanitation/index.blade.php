@extends('layouts.app')

@section('title', 'Sanitation Records | HIMS Fleet')

@section('content')
<section class="page-wrapper">
  <div class="vehicle-page">
    <div class="page-header">
      <div>
        <h1>Vehicle Sanitation Records</h1>
        <p>Track pending, active, and completed cleaning transactions.</p>
      </div>
    </div>

    <div class="toolbar">
      <div class="toolbar-left">
        <select class="filter-select" id="sanitationStatusFilter">
          <option value="all">All Statuses</option>
          <option value="pending">Pending</option>
          <option value="in_progress">In Progress</option>
          <option value="completed">Completed</option>
        </select>
      </div>
    </div>

    <div class="card">
      <div class="table-responsive">
        <table class="fleet-table" aria-label="Vehicle sanitation requests">
          <thead>
            <tr>
              <th>Vehicle</th>
              <th>Dispatch</th>
              <th>Reason</th>
              <th>Cleaning Type</th>
              <th>Status</th>
              <th>Performer</th>
              <th>Started</th>
              <th>Completed</th>
              <th>Remarks</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="sanitationTableBody">
            @forelse($sanitationLogs as $record)
              <tr data-id="{{ $record->id }}" data-status="{{ $record->status }}">
                <td>{{ $record->vehicle?->display_label ?? 'Vehicle unavailable' }}</td>
                <td>{{ $record->dispatch?->dispatch_number ?? 'Not linked' }}</td>
                <td>{{ $record->reason_label }}</td>
                <td>{{ $record->cleaning_type_label }}</td>
                <td><span class="status-badge {{ $record->status === 'completed' ? 'completed' : 'maintenance' }}">{{ $record->status_label }}</span></td>
                <td>{{ $record->performedBy?->name ?? 'Not assigned' }}</td>
                <td>{{ $record->started_at?->format('Y-m-d H:i') ?? 'Not started' }}</td>
                <td>{{ $record->completed_at?->format('Y-m-d H:i') ?? 'Not completed' }}</td>
                <td>{{ $record->remarks ?? 'None' }}</td>
                <td>
                  @if(auth()->user()->hasPermission('sanitation.update'))
                    @if($record->status === 'pending')
                      <button type="button" class="btn-outline sanitation-transition" data-status="in_progress">
                        Start Cleaning
                      </button>
                    @elseif($record->status === 'in_progress')
                      <button type="button" class="btn-primary sanitation-transition" data-status="completed">
                        Complete Cleaning
                      </button>
                    @else
                      <span aria-label="No lifecycle actions available">—</span>
                    @endif
                  @else
                    <span aria-label="No lifecycle actions available">—</span>
                  @endif
                </td>
              </tr>
            @empty
              <tr><td colspan="10" class="text-center">No sanitation records.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    <div class="table-footer">{{ $sanitationLogs->links() }}</div>
  </div>
</section>
@push('scripts')
<script src="{{ asset('assets/js/sanitation/sanitation.js') }}"></script>
@endpush
@endsection
