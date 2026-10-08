<div
    id="viewDriverPerformanceModal"
    class="modal-overlay"
    role="dialog"
    aria-modal="true"
    aria-labelledby="viewDriverPerformanceModalTitle"
    aria-describedby="viewDriverPerformanceModalDescription"
>
    <div class="custom-modal driver-performance-modal">
        <div class="modal-header">
            <div>
                <h2 id="viewDriverPerformanceModalTitle">Driver Performance Details</h2>
                <p id="viewDriverPerformanceModalDescription">
                    Review driver identity, license information, and operational activity.
                </p>
            </div>

            <button
                type="button"
                class="modal-close"
                id="closeDriverPerformanceModal"
                aria-label="Close driver performance details"
            >
                <i class="ph ph-x"></i>
            </button>
        </div>

        <div class="vehicle-profile driver-profile driver-performance-profile">
            <div class="driver-performance-photo-wrap">
                <img id="performanceDetailPhoto" alt="">
                <span id="performanceDetailInitials" aria-hidden="true"></span>
            </div>

            <h3 id="performanceDetailName">—</h3>
            <p id="performanceDetailNumber">—</p>
            <span id="performanceDetailStatus" class="status-badge inactive">—</span>
        </div>

        <div class="modal-body">
            <section class="driver-performance-detail-section" aria-labelledby="performanceIdentityHeading">
                <h3 id="performanceIdentityHeading">Driver Identity</h3>
                <div class="view-driver-details">
                    <div class="view-driver-detail-item">
                        <label>Driver ID</label>
                        <p id="performanceIdentityNumber">—</p>
                    </div>
                    <div class="view-driver-detail-item">
                        <label>Assigned Vehicle</label>
                        <p id="performanceDetailVehicle">—</p>
                    </div>
                </div>
            </section>

            <section class="driver-performance-detail-section" aria-labelledby="performanceLicenseHeading">
                <h3 id="performanceLicenseHeading">License Information</h3>
                <div class="view-driver-details">
                    <div class="view-driver-detail-item">
                        <label>License Number</label>
                        <p id="performanceDetailLicenseNumber">—</p>
                    </div>
                    <div class="view-driver-detail-item">
                        <label>License Class</label>
                        <p id="performanceDetailLicenseClass">—</p>
                    </div>
                    <div class="view-driver-detail-item">
                        <label>License Expiration</label>
                        <p id="performanceDetailLicenseExpiry">—</p>
                    </div>
                </div>
            </section>

            <section class="driver-performance-detail-section" aria-labelledby="performanceSummaryHeading">
                <h3 id="performanceSummaryHeading">Performance Summary</h3>
                <div class="view-driver-summary driver-performance-summary">
                    <div class="view-driver-summary-item">
                        <span class="view-driver-summary-label">Completed Trips</span>
                        <strong id="performanceDetailCompleted">0</strong>
                    </div>
                    <div class="view-driver-summary-item">
                        <span class="view-driver-summary-label">Cancelled Trips</span>
                        <strong id="performanceDetailCancelled">0</strong>
                    </div>
                    <div class="view-driver-summary-item">
                        <span class="view-driver-summary-label">Total Distance</span>
                        <strong id="performanceDetailDistance">0 km</strong>
                    </div>
                    <div class="view-driver-summary-item">
                        <span class="view-driver-summary-label">Fuel Used</span>
                        <strong id="performanceDetailFuel">0 L</strong>
                    </div>
                    <div class="view-driver-summary-item">
                        <span class="view-driver-summary-label">Electricity Used</span>
                        <strong id="performanceDetailElectricity">0 kWh</strong>
                    </div>
                </div>
            </section>

            <section class="driver-performance-detail-section" aria-labelledby="performanceRecentTripsHeading">
                <h3 id="performanceRecentTripsHeading">Recent Trips</h3>
                <div class="table-responsive">
                    <table class="fleet-table driver-performance-recent-table">
                        <thead>
                            <tr>
                                <th>Dispatch</th>
                                <th>Date</th>
                                <th>Route</th>
                                <th>Vehicle</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="performanceRecentTripsBody"></tbody>
                    </table>
                </div>
            </section>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn-outline" id="closeDriverPerformanceModalFooter">
                Close
            </button>
        </div>
    </div>
</div>
