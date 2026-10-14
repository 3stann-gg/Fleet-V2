@extends('layouts.app')

@section('title', 'Fuel & Energy Management | HIMS Fleet')

@section('content')

        <section class="page-wrapper">
          <div class="vehicle-page fuel-page">
            <div class="page-header">
              <div>
                <h1>Fuel & Energy Management</h1>
                <p>
                  Track fuel usage, charging activity, costs, and vehicle energy consumption.
                </p>
              </div>

              @if($fuelPermissions['canCreate'] ?? false)
                  <button
                      type="button"
                      id="addFuelBtn"
                      class="btn-primary"
                  >
                      <i class="ph ph-plus"></i>
                      Request Fuel Record
                  </button>
              @endif

              @if(auth()->user()?->hasPermission('charging.create'))
                  <button type="button" id="addChargingBtn" class="btn-primary" hidden>
                      <i class="ph ph-plus"></i>
                      Add Charging Transaction
                  </button>
              @endif
            </div>

            <div class="energy-tabs" role="tablist" aria-label="Fuel and energy transactions">
              <button type="button" class="energy-tab is-active" id="fuelTransactionsTab" role="tab" aria-selected="true" aria-controls="fuelTransactionsPanel">
                <i class="ph ph-gas-pump"></i> Fuel Transactions
              </button>
              <button type="button" class="energy-tab" id="chargingTransactionsTab" role="tab" aria-selected="false" aria-controls="chargingTransactionsPanel">
                <i class="ph ph-lightning"></i> Charging Transactions
              </button>
            </div>

            <div id="fuelTransactionsPanel" role="tabpanel" aria-labelledby="fuelTransactionsTab">

            <div class="stats-grid">
              <div class="stat-card">
                <div class="stat-icon">
                  <i class="ph-fill ph-gas-pump"></i>
                </div>
                <div class="stat-content">
                  <h3 id="totalFuelRecords">0</h3>
                  <p>Total Fuel Records</p>
                </div>
              </div>

              <div class="stat-card">
                <div class="stat-icon success">
                  <i class="ph-fill ph-drop"></i>
                </div>
                <div class="stat-content">
                  <h3 id="totalFuelConsumed">0.00 L</h3>
                  <p>Total Fuel Consumed</p>
                </div>
              </div>

              <div class="stat-card">
                <div class="stat-icon warning">
                  <i class="ph-fill ph-currency-circle-dollar"></i>
                </div>
                <div class="stat-content">
                  <h3 id="totalFuelCost">₱0.00</h3>
                  <p>Total Fuel Cost</p>
                </div>
              </div>

              <div class="stat-card">
                <div class="stat-icon">
                  <i class="ph-fill ph-chart-line-up"></i>
                </div>
                <div class="stat-content">
                  <h3 id="averageCostPerLiter">₱0.00/L</h3>
                  <p>Average Cost per Liter</p>
                </div>
              </div>
            </div>

            <div class="toolbar">
              <div class="toolbar-left">
                <div class="search-box">
                  <i class="ph ph-magnifying-glass"></i>
                  <input
                    type="text"
                    id="fuelSearch"
                    placeholder="Search by record number, vehicle, plate, driver, station, or receipt"
                    aria-label="Search fuel records"
                  />
                </div>

                <select
                  class="filter-select"
                  id="fuelVehicleFilter"
                  aria-label="Filter fuel records by vehicle"
                >
                  <option value="all">All Vehicles</option>
                </select>

                <select
                  class="filter-select"
                  id="fuelTypeFilter"
                  aria-label="Filter fuel records by fuel type"
                >
                  <option value="all">All Fuel Types</option>
                  <option value="Diesel">Diesel</option>
                  <option value="Gasoline">Gasoline</option>
                  <option value="Premium Gasoline">Premium Gasoline</option>
                </select>

                <input
                  type="date"
                  class="filter-select"
                  id="fuelDateFilter"
                  aria-label="Filter fuel records by refueling date"
                />
              </div>

              <div class="toolbar-right">
                <button type="button" class="btn-outline" id="refreshFuel">
                  <i class="ph ph-arrows-clockwise"></i>
                  Refresh
                </button>
              </div>
            </div>

            {{-- Fuel records are intentionally non-deletable --}}
            <!--
            <div class="bulk-toolbar" id="fuelBulkToolbar">
              <span id="fuelSelectedCount">0 fuel records selected</span>
              <div>
                <button type="button" class="btn-outline" id="clearFuelSelection">
                  Clear
                </button>
                <button
                  type="button"
                  id="deleteSelectedFuel"
                  class="btn-danger"
                  aria-label="Delete selected fuel records"
                >
                  <i class="ph ph-trash"></i>
                  Delete Selected
                </button>
              </div>
            </div>
            -->

            <div class="card">
              <div class="card-header">
                <div>
                  <h3>Fuel Records</h3>
                  <p class="card-subtitle">
                    View and manage all hospital fleet fuel transactions.
                  </p>
                </div>
                <div class="card-actions">
                  <div class="export-dropdown">
                    <button
                      type="button"
                      class="btn-outline export-menu-toggle"
                      id="fuelExportMenuToggle"
                      aria-haspopup="menu"
                      aria-expanded="false"
                      aria-controls="fuelExportMenu"
                    >
                      <i class="ph ph-export" aria-hidden="true"></i>
                      Export
                      <i class="ph ph-caret-down export-menu-chevron" aria-hidden="true"></i>
                    </button>
                    <div
                      class="export-menu"
                      id="fuelExportMenu"
                      role="menu"
                      hidden
                      aria-label="Export fuel records"
                    >
                      <button
                        type="button"
                        class="export-menu-item"
                        role="menuitem"
                        id="printFuel"
                      >
                        <i class="ph ph-printer" aria-hidden="true"></i>
                        Print
                      </button>
                      <button
                        type="button"
                        class="export-menu-item"
                        role="menuitem"
                        id="exportFuelPDF"
                      >
                        <i class="ph ph-file-pdf" aria-hidden="true"></i>
                        Export PDF
                      </button>
                      <button
                        type="button"
                        class="export-menu-item"
                        role="menuitem"
                        id="exportFuel"
                      >
                        <i class="ph ph-file-xls" aria-hidden="true"></i>
                        Export Excel
                      </button>
                    </div>
                  </div>
                </div>
              </div>

              <div class="table-responsive">
                <table class="fleet-table">
                  <thead>
                    <tr>
                      <th>
                        <!--
                        <input
                          type="checkbox"
                          id="selectAllFuel"
                          aria-label="Select all visible fuel records"
                        />
                        -->
                      </th>
                      <th class="sortable" data-column="1">
                        Fuel Record No.
                        <i class="ph ph-caret-up-down sort-icon"></i>
                      </th>
                      <th class="sortable" data-column="2">
                        Date
                        <i class="ph ph-caret-up-down sort-icon"></i>
                      </th>
                      <th class="sortable" data-column="3">
                        Vehicle
                        <i class="ph ph-caret-up-down sort-icon"></i>
                      </th>
                      <th>Plate No.</th>
                      <th>Driver</th>
                      <th class="sortable" data-column="6">
                        Fuel Type
                        <i class="ph ph-caret-up-down sort-icon"></i>
                      </th>
                      <th class="sortable numeric-cell" data-column="7">
                        Quantity
                        <i class="ph ph-caret-up-down sort-icon"></i>
                      </th>
                      <th class="numeric-cell">Cost / L</th>
                      <th class="sortable numeric-cell" data-column="9">
                        Total Cost
                        <i class="ph ph-caret-up-down sort-icon"></i>
                      </th>
                      <th class="sortable numeric-cell" data-column="10">
                        Odometer
                        <i class="ph ph-caret-up-down sort-icon"></i>
                      </th>
                      <th>Fuel Station</th>
                      <th>Actions</th>
                    </tr>
                  </thead>

                  <tbody id="fuelTableBody">
                    
                  </tbody>
                </table>
              </div>
            </div>

            <div class="table-footer">
              <div id="fuelPaginationInfo">
                Showing <strong>0–0</strong> of <strong>0</strong> fuel records
              </div>
              <div class="pagination" id="fuelPagination">
                <button type="button" aria-label="Previous page">
                  <i class="ph ph-caret-left"></i>
                </button>

                <button type="button" aria-label="Next page">
                  <i class="ph ph-caret-right"></i>
                </button>
              </div>
            </div>
            </div>

            <div id="chargingTransactionsPanel" role="tabpanel" aria-labelledby="chargingTransactionsTab" hidden>
              <div class="stats-grid">
                <div class="stat-card">
                  <div class="stat-icon"><i class="ph-fill ph-lightning"></i></div>
                  <div class="stat-content"><h3 id="totalChargingRecords">0</h3><p>Charging Transactions</p></div>
                </div>
                <div class="stat-card">
                  <div class="stat-icon success"><i class="ph-fill ph-battery-charging"></i></div>
                  <div class="stat-content"><h3 id="totalElectricityAdded">0.00 kWh</h3><p>Electricity Added</p></div>
                </div>
                <div class="stat-card">
                  <div class="stat-icon warning"><i class="ph-fill ph-currency-circle-dollar"></i></div>
                  <div class="stat-content"><h3 id="totalChargingCost">₱0.00</h3><p>Charging Cost</p></div>
                </div>
                <div class="stat-card">
                  <div class="stat-icon info"><i class="ph-fill ph-battery-full"></i></div>
                  <div class="stat-content"><h3 id="averageEnergyAdded">0.00 kWh</h3><p>Average Energy Added</p></div>
                </div>
              </div>

              <div class="toolbar">
                <div class="toolbar-left">
                  <div class="search-box"><i class="ph ph-magnifying-glass"></i><input type="search" id="chargingSearch" placeholder="Search vehicle, driver, location" aria-label="Search charging transactions"></div>
                  <select class="filter-select" id="chargingVehicleFilter" aria-label="Filter charging transactions by vehicle"><option value="all">All Vehicles</option></select>
                  <select class="filter-select" id="chargingPowertrainFilter" aria-label="Filter charging transactions by powertrain"><option value="all">All Powertrains</option><option value="plug_in_hybrid">Plug-in Hybrid (PHEV)</option><option value="electric">Electric (BEV)</option></select>
                  <input type="date" class="filter-select" id="chargingDateFilter" aria-label="Filter charging transactions by date">
                </div>
                <div class="toolbar-right"><button type="button" class="btn-outline" id="refreshCharging"><i class="ph ph-arrows-clockwise"></i> Refresh</button></div>
              </div>

              <div class="card">
                <div class="card-header"><div><h3>Charging Transactions</h3><p class="card-subtitle">View recorded charging activity for plug-in hybrid and electric vehicles.</p></div></div>
                <div class="table-responsive">
                  <table class="fleet-table">
                    <thead><tr><th>Vehicle</th><th>Powertrain</th><th>Driver</th><th>Date / Time</th><th class="numeric-cell">Battery Before</th><th class="numeric-cell">Battery After</th><th class="numeric-cell">Energy Added</th><th>Charging Location</th><th class="numeric-cell">Cost</th><th>Actions</th></tr></thead>
                    <tbody id="chargingTableBody"><tr class="helper-row"><td colspan="10">Loading charging transactions...</td></tr></tbody>
                  </table>
                </div>
              </div>
              <div class="table-footer"><div id="chargingPaginationInfo">Showing <strong>0–0</strong> of <strong>0</strong> charging transactions</div><div class="pagination" id="chargingPagination"></div></div>
            </div>
          </div>
        </section>

    @if($fuelPermissions['canCreate'] ?? false)
        @include('components.fuel.add-fuel-modal')
    @endif

    @include('components.fuel.view-fuel-modal')

    @if(auth()->user()?->hasPermission('charging.create'))
        @include('components.fuel.add-charging-modal')
    @endif

    @include('components.fuel.view-charging-modal')

    @if($fuelPermissions['canUpdate'] ?? false)
        @include('components.fuel.edit-fuel-modal')
    @endif

    {{-- @include('components.fuel.delete-fuel-modal') --}}

    <script>
        window.FLEET_RBAC = window.FLEET_RBAC || {};
        window.FLEET_RBAC.role =
            @json(auth()->user()?->role);
        window.FLEET_RBAC.fuel =
            @json($fuelPermissions ?? []);
    </script>

  <script src="{{ asset('assets/js/helpers/rbac.js') }}"></script>

    <script src="https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>

    <!-- Main JS -->
     @push('scripts')

    <script src="{{ asset('assets/js/components/dropdown.js') }}"></script>
    <script src="{{ asset('assets/js/fuel/fuel-modal.js') }}"></script>
    <script src="{{ asset('assets/js/fuel/fuel-add.js') }}"></script>
    <script src="{{ asset('assets/js/fuel/fuel-view.js') }}"></script>
    <script src="{{ asset('assets/js/fuel/fuel-edit.js') }}"></script>
    <script src="{{ asset('assets/js/fuel/fuel-delete.js') }}"></script>
    <script src="{{ asset('assets/js/fuel/fuel-statistics.js') }}"></script>
    <script src="{{ asset('assets/js/fuel/fuel-sort.js') }}"></script>
    <script src="{{ asset('assets/js/fuel/fuel-pagination.js') }}"></script>
    <script src="{{ asset('assets/js/fuel/fuel-search.js') }}"></script>
    <script src="{{ asset('assets/js/fuel/fuel-table.js') }}"></script>
    <script src="{{ asset('assets/js/fuel/fuel-bulk.js') }}"></script>
    <script src="{{ asset('assets/js/fuel/fuel-export.js') }}"></script>
    <script src="{{ asset('assets/js/fuel/fuel-print.js') }}"></script>
    <script src="{{ asset('assets/js/fuel/charging.js') }}"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

    @endpush

@endsection
