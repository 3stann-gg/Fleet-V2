@extends('layouts.app')

@section('title', 'Driver Performance | HIMS Fleet')

@section('content')
    <section class="page-wrapper">
        <div
            class="vehicle-page driver-performance-page"
            id="driverPerformancePage"
            data-data-url="{{ route('driver-performance.data') }}"
        >
            <div class="page-header">
                <div>
                    <h1>Driver Performance</h1>
                    <p>Monitor and evaluate driver operational performance.</p>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon success">
                        <i class="ph-fill ph-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3 id="performanceCompletedTrips">0</h3>
                        <p>Completed Trips</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon danger">
                        <i class="ph-fill ph-x-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3 id="performanceCancelledTrips">0</h3>
                        <p>Cancelled Trips</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon info">
                        <i class="ph-fill ph-path"></i>
                    </div>
                    <div class="stat-content">
                        <h3 id="performanceTotalDistance">0 km</h3>
                        <p>Total Distance</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon warning">
                        <i class="ph-fill ph-gas-pump"></i>
                    </div>
                    <div class="stat-content">
                        <h3 id="performanceFuelUsed">0 L</h3>
                        <p>Fuel Used</p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon info">
                        <i class="ph-fill ph-lightning"></i>
                    </div>
                    <div class="stat-content">
                        <h3 id="performanceElectricityUsed">0 kWh</h3>
                        <p>Electricity Used</p>
                    </div>
                </div>
            </div>

            <div class="report-charts-grid driver-performance-charts">
                <div class="report-chart-card" id="topPerformingDriversChart">
                    <h4>Top Performing Drivers</h4>
                    <p class="card-subtitle">Completed trips in the selected period.</p>
                    <p class="report-chart-empty" hidden>No completed trip data available.</p>
                    <div class="report-chart-body">
                        <div class="report-bars is-horizontal"></div>
                    </div>
                </div>

                <div class="report-chart-card" id="driverStatusDistributionChart">
                    <h4>Driver Status Distribution</h4>
                    <p class="card-subtitle">Current status of the displayed drivers.</p>
                    <p class="report-chart-empty" hidden>No driver status data available.</p>
                    <div class="report-chart-body report-donut-wrap">
                        <div class="report-donut" aria-hidden="true"></div>
                        <ul class="report-legend"></ul>
                    </div>
                </div>
            </div>

            <div class="card driver-performance-records">
                <div class="card-header">
                    <div>
                        <h3>Driver Performance Records</h3>
                        <p class="card-subtitle">
                            Review trip outcomes and logged operational activity by driver.
                        </p>
                    </div>
                </div>

                <div class="toolbar">
                    <div class="toolbar-left">
                        <div class="search-box">
                            <i class="ph ph-magnifying-glass" aria-hidden="true"></i>
                            <input
                                type="search"
                                id="performanceDriverSearch"
                                placeholder="Search driver name or ID"
                                aria-label="Search driver performance records"
                            >
                        </div>

                        <select
                            class="filter-select"
                            id="performanceStatusFilter"
                            aria-label="Filter by driver status"
                        >
                            <option value="all">All Statuses</option>
                        </select>

                        <select
                            class="filter-select"
                            id="performanceVehicleFilter"
                            aria-label="Filter by assigned vehicle"
                        >
                            <option value="all">All Vehicles</option>
                        </select>

                        <select
                            class="filter-select"
                            id="performanceDateRange"
                            aria-label="Driver performance date range"
                        >
                            <option value="today">Today</option>
                            <option value="last7">Last 7 Days</option>
                            <option value="last30" selected>Last 30 Days</option>
                            <option value="thisMonth">This Month</option>
                            <option value="thisYear">This Year</option>
                            <option value="all">All Time</option>
                        </select>
                    </div>

                    <div class="toolbar-right">
                        <button type="button" class="btn-outline" id="refreshDriverPerformance">
                            <i class="ph ph-arrows-clockwise" aria-hidden="true"></i>
                            Refresh
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="fleet-table">
                        <thead>
                            <tr>
                                <th>Driver</th>
                                <th>Driver ID</th>
                                <th>Assigned Vehicle</th>
                                <th class="numeric-cell">Completed Trips</th>
                                <th class="numeric-cell">Cancelled Trips</th>
                                <th class="numeric-cell">Total Distance</th>
                                <th class="numeric-cell">Fuel Used</th>
                                <th class="numeric-cell">Electricity Used</th>
                                <th>Performance Level</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="driverPerformanceTableBody">
                            <tr class="helper-row">
                                <td colspan="10">Loading driver performance records...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="table-footer">
                    <span id="driverPerformanceTableSummary">Showing 0 records</span>
                    <div class="pagination" id="driverPerformancePagination"></div>
                </div>
            </div>

            @include('components.driver-performance.view-performance-modal')
        </div>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/driver-performance/driver-performance.js') }}"></script>
@endpush
