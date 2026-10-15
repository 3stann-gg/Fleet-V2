@props([
    'prefix',
    'allowSelection' => true,
])

<section
    id="{{ $prefix }}CandidateComparison"
    class="dispatch-candidate-comparison"
    data-candidate-comparison="{{ $prefix }}"
    aria-labelledby="{{ $prefix }}CandidateComparisonTitle"
    aria-describedby="{{ $prefix }}CandidateComparisonDescription {{ $prefix }}CandidateComparisonNotice"
    @if($allowSelection) data-allow-selection="true" @endif
    hidden
>
    <div class="dispatch-candidate-header">
        <div>
            <h3 id="{{ $prefix }}CandidateComparisonTitle">Candidate Vehicle Comparison</h3>
            <p id="{{ $prefix }}CandidateComparisonDescription">Current operational advisory. The dispatcher retains final control.</p>
        </div>
        <span id="{{ $prefix }}CandidatePriority" class="status-chip status-chip--info"></span>
    </div>

    <input type="hidden" id="{{ $prefix }}SelectedVehicleId" />

    <div
        id="{{ $prefix }}SelectedVehicleSummary"
        class="dispatch-selected-candidate"
        aria-live="polite"
        hidden
    ></div>

    <div class="table-responsive">
        <table class="fleet-table dispatch-candidate-table" aria-label="Candidate vehicle suitability comparison">
            <thead>
                <tr>
                    <th>Vehicle</th>
                    <th>Readiness</th>
                    <th>Capabilities</th>
                    <th>Hospital Qualification</th>
                    <th>Driver License</th>
                    <th>Energy</th>
                    <th>Operational Health</th>
                    <th>Result</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="{{ $prefix }}CandidateTableBody"></tbody>
        </table>
    </div>

    <div id="{{ $prefix }}CandidateEmpty" class="dispatch-candidate-empty" role="status" aria-live="polite" hidden>
        No fleet vehicles are available for comparison.
    </div>

    <p id="{{ $prefix }}CandidateComparisonNotice" class="dispatch-candidate-notice">
        Suitability is calculated from current records and does not automatically assign a vehicle.
    </p>
</section>
