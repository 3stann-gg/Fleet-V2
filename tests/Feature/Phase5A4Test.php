<?php

use App\Models\User;

it('renders the route planning workflow and accessible section navigation', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);

    $response = $this->actingAs($user)->get(route('route-planning'));

    $response->assertSuccessful()
        ->assertSeeInOrder([
            'Planning',
            'Map',
            'Optimization',
            'Route History',
        ])
        ->assertSeeInOrder([
            'Plan',
            'Optimize',
            'Review',
            'Ready for Dispatch',
        ])
        ->assertSee('aria-label="Route Planning sections"', false)
        ->assertSee('aria-current="location"', false)
        ->assertSee('data-route-section="routePlanningSection"', false)
        ->assertSee('data-route-section="routeMapSection"', false)
        ->assertSee('data-route-section="routeOptimizationSection"', false)
        ->assertSee('data-route-section="routeHistorySection"', false);
});

it('keeps reservation priority readonly and exposes emergency and optimization states', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);

    $response = $this->actingAs($user)->get(route('route-planning'));

    $response->assertSuccessful()
        ->assertSee('id="routePriority" readonly', false)
        ->assertSee('Inherited from the approved reservation', false)
        ->assertSee('id="routeEmergencyIndicator"', false)
        ->assertSee('id="routeFormEmergencyIndicator"', false)
        ->assertSee('id="routeWorkflowStatus"', false)
        ->assertSee('id="routeWorkflowOptimization"', false)
        ->assertSee('id="routeOptimizationStateLabel"', false)
        ->assertSee('Route / Reservation')
        ->assertSee('id="optimizeRouteBtn"', false)
        ->assertSee('id="applyRouteOptimizationBtn"', false)
        ->assertSee('id="saveRouteBtn"', false);
});

it('retains the existing route planning scripts and server proof handoff', function () {
    $view = file_get_contents(resource_path('views/route-planning/index.blade.php'));
    $modalScript = file_get_contents(public_path('assets/js/route-planning/route-modal.js'));

    expect($view)
        ->toContain('route-store.js')
        ->toContain('route-pipeline.js')
        ->toContain('route-full-map.js')
        ->toContain('route-modal.js')
        ->and($modalScript)
        ->toContain('optimization_proof: data.optimizationProof')
        ->toContain('optimizationProof: result.optimizationProof || null')
        ->toContain('routePendingOptimization')
        ->toContain('applyPendingRouteOptimization');
});

it('prioritizes a newly generated optimization over a previously persisted result', function () {
    $modalScript = file_get_contents(public_path('assets/js/route-planning/route-modal.js'));
    $generatedBranch = strpos($modalScript, '} else if (hasGeneratedOptimization) {');
    $appliedBranch = strpos($modalScript, '} else if (hasAppliedOptimization) {');

    expect($generatedBranch)->not->toBeFalse()
        ->and($appliedBranch)->not->toBeFalse();
    expect($generatedBranch)->toBeLessThan($appliedBranch);
});
