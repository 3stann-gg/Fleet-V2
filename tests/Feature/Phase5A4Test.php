<?php

use App\Models\User;
use Symfony\Component\Process\Process;

function p54RunRouteJavaScript(string $scenario): array
{
    $harness = <<<'JS'
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const plain = value => JSON.parse(JSON.stringify(value));
class Element {
    constructor(tag = 'div') { this.tag = tag; this.children = []; this.value = ''; this.dataset = {}; this.style = {}; this.hidden = false; this.classList = {add(){}, remove(){}, toggle(){}}; }
    set innerHTML(value) { this.html = value; this.children = []; }
    get innerHTML() { return this.html || ''; }
    appendChild(child) { this.children.push(child); }
    setAttribute() {}
    focus() {}
    querySelector() { return null; }
    querySelectorAll(selector) {
        if (selector !== 'input.route-stop-input') return [];
        return this.children.flatMap(child => child.tag === 'input' && child.className === 'route-stop-input' ? [child] : child.querySelectorAll(selector));
    }
}
const nodes = Object.fromEntries(['routeForm','routeStopsList','routeEstimatedDistance','routeEstimatedTime','routeOptStrategy','routeOptScore','routeStrategyPresentation','routeOptimizeSummary','routeReservation','routeOrigin','routeDestination','routeDepartment','routeDepartureDate','routeDepartureTime','routeStatus'].map(id => [id, new Element()]));
Object.entries({routeReservation:'1',routeOrigin:'Synthetic origin',routeDestination:'Synthetic destination',routeDepartment:'Operations',routeDepartureDate:'2026-11-20',routeDepartureTime:'09:00',routeStatus:'Planned'}).forEach(([id,value]) => nodes[id].value = value);
const context = vm.createContext({
    console: {warn(){}, error(){}},
    document: {getElementById: id => nodes[id] || null, createElement: tag => new Element(tag), querySelector: () => null, querySelectorAll: () => [], addEventListener(){}},
    window: {FleetRBAC:{hasPermission:()=>true}, showToast(){}},
    fetch: () => { throw Error('Unexpected network access in isolated test'); },
});
for (const name of ['route-store.js','route-pipeline.js','route-modal.js']) {
    vm.runInContext(fs.readFileSync(process.argv[1] + '/' + name, 'utf8'), context);
}
const stops = [
    {location:'Repeated stop', coordinate:{lat:14.61,lng:121.01}},
    {location:'Repeated stop', coordinate:{lat:14.62,lng:121.02}},
    {location:'Third stop', coordinate:{lat:14.63,lng:121.03}},
];
const coordinates = {origin:{lat:14.6,lng:121},stops,destination:{lat:14.7,lng:121.1}};
const geometry = {type:'LineString',coordinates:[[121,14.6],[121.1,14.7]]};
let fallbackCalls = 0;
context.initRouteLeafletMap = async () => ({});
context.geocodeRouteRecord = async () => coordinates;
context.drawRouteOnLeaflet = () => {};
context.requestOsrmRoute = async () => { fallbackCalls++; return {distance:12000,duration:900,geometry}; };
const traffic = mapping => ({provider:'TomTom',distanceKm:10,durationMinutes:12,geometry,optimizedWaypoints:mapping,optimizationProof:'synthetic-proof'});
(async () => {
JS;
    $script = $harness."\n".$scenario."\n})().catch(error => { console.error(error.stack); process.exitCode = 1; });";
    $process = new Process(['node', '-e', $script, public_path('assets/js/route-planning')]);
    $process->setTimeout(30)->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

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

it('labels new OSRM fallback results without claiming waypoint optimization', function () {
    $script = file_get_contents(public_path('assets/js/route-planning/route-modal.js'));

    expect($script)
        ->toContain('optimizationStrategy = "OSRM Route — Existing Stop Order (Fallback)";')
        ->toContain('Traffic-aware routing is unavailable. Stops remain in the supplied order.')
        ->not->toContain('optimizationStrategy = "OSRM Optimized Waypoints (Fallback)";')
        ->toContain('optimizationStrategy = "TomTom Traffic-Aware Optimized";')
        ->toContain('optimizationStrategy = "TomTom Traffic-Aware Multi-stop";')
        ->toContain('optimizationStrategy = "TomTom Traffic-Aware Fastest";');
});

it('keeps legacy strategy formatting separate from raw route state and submission', function () {
    $store = file_get_contents(public_path('assets/js/route-planning/route-store.js'));
    $modal = file_get_contents(public_path('assets/js/route-planning/route-modal.js'));
    $pipeline = file_get_contents(public_path('assets/js/route-planning/route-pipeline.js'));
    $view = file_get_contents(resource_path('views/route-planning/index.blade.php'));

    expect($store)
        ->toContain('return strategy === "OSRM Optimized Waypoints (Fallback)"')
        ->toContain('? "OSRM Route (Legacy Fallback; stop-order optimization unverified)"')
        ->toContain(': strategy;')
        ->toContain('optimizationStrategy: String(raw.optimization_strategy || "").trim()')
        ->and($modal)
        ->toContain('const label = formatRouteStrategyLabel(strategy.value);')
        ->toContain('presentation.textContent = label;')
        ->toContain('strategy.value = fullRoute.optimizationStrategy || "";')
        ->toContain('optimizationStrategy: get("routeOptStrategy")')
        ->toContain('optimization_strategy: data.optimizationStrategy || null')
        ->toContain('optimization_proof: data.optimizationProof')
        ->not->toContain('strategy.value = formatRouteStrategyLabel(')
        ->and($pipeline)
        ->toContain('formatRouteStrategyLabel(record.optimizationStrategy)')
        ->and($view)
        ->toContain('id="routeOptStrategy" readonly')
        ->toContain('id="routeStrategyPresentation"');
});

it('describes the assessment score while preserving score and export contracts', function () {
    $view = file_get_contents(resource_path('views/route-planning/index.blade.php'));
    $modal = file_get_contents(public_path('assets/js/route-planning/route-modal.js'));
    $pipeline = file_get_contents(public_path('assets/js/route-planning/route-pipeline.js'));
    $exports = file_get_contents(public_path('assets/js/route-planning/route-export.js'));
    $pdfStart = strpos($exports, 'pdf.autoTable({');

    expect($pdfStart)->not->toBeFalse();

    $pdfEnd = strpos($exports, 'startY: y,', $pdfStart);

    expect($pdfEnd)->not->toBeFalse();

    $pdfTable = substr($exports, $pdfStart, $pdfEnd - $pdfStart);
    $explanation = 'Application-defined score out of 100 based on traffic and routing availability, with waypoint metadata considered where available. It is not a percentage saved, AI confidence, or a guarantee of the best route.';

    expect($view)
        ->toContain('Route Assessment Score')
        ->toContain($explanation)
        ->not->toContain('>Optimization Score<')
        ->and($modal)
        ->toContain('function calculateRouteOptimizationScore(')
        ->toContain('optimization_score:')
        ->toContain('result.optimizationScore != null')
        ->and($pipeline)
        ->toContain('record.optimizationScore != null')
        ->and($exports)
        ->toContain('Route Assessment Score')
        ->toContain($explanation)
        ->toContain('formatRouteStrategyLabel(r.optimizationStrategy)')
        ->toContain('r.optimizationScore,')
        ->and($pdfTable)
        ->not->toContain('optimizationScore')
        ->not->toContain('Assessment Score');

    expect(substr_count($exports, 'formatRouteStrategyLabel(r.optimizationStrategy)'))->toBe(3);
});

it('validates complete frontend permutations and uses original order on malformed TomTom results', function () {
    $result = p54RunRouteJavaScript(<<<'JS'
const cycle = [{providedIndex:1,optimizedIndex:2},{providedIndex:2,optimizedIndex:0},{providedIndex:0,optimizedIndex:1}];
assert.deepEqual(plain(context.orderRouteWaypoints(stops, cycle)), [stops[2],stops[0],stops[1]]);
assert.equal(context.orderRouteWaypoints(stops, cycle)[0], stops[2]);
assert.deepEqual(plain(context.orderRouteWaypoints(stops, stops.map((_,i)=>({providedIndex:i,optimizedIndex:i})))), stops);
assert.deepEqual(plain(context.orderRouteWaypoints([], [])), []);
assert.deepEqual(plain(context.orderRouteWaypoints(stops.slice(0,1), [])), stops.slice(0,1));
assert.deepEqual(plain(context.orderRouteWaypoints(stops.slice(0,1), [{providedIndex:0,optimizedIndex:0}])), stops.slice(0,1));
const repeated = {...stops[1], coordinate:stops[0].coordinate};
assert.deepEqual(plain(context.orderRouteWaypoints([stops[0],repeated], [{providedIndex:0,optimizedIndex:1},{providedIndex:1,optimizedIndex:0}])), [repeated,stops[0]]);
const identity = [{providedIndex:0,optimizedIndex:0},{providedIndex:1,optimizedIndex:1},{providedIndex:2,optimizedIndex:2}];
const invalid = [[], identity.slice(0,2), null, {}, [null,...identity.slice(1)]];
for (const field of ['providedIndex','optimizedIndex']) {
    for (const value of [undefined,null,false,'0',0.5,-1,3]) invalid.push([{...identity[0],[field]:value},...identity.slice(1)]);
    invalid.push([identity[0],{...identity[1],[field]:0},identity[2]]);
}
for (const mapping of invalid) {
    assert.throws(()=>context.orderRouteWaypoints(stops,mapping), /invalid waypoint permutation/);
    context.requestTomTomRoute = async () => traffic(mapping);
    const fallback = await context.calculateRouteWithTraffic({origin:'O',destination:'D'}, {draw:false,silent:true});
    assert.equal(fallback.provider, 'OSRM');
    assert.equal(fallback.fallback, true);
    assert.deepEqual(plain(fallback.routeCoordinates), coordinates);
    assert.equal(fallback.optimizationProof, undefined);
}
assert.throws(()=>context.orderRouteWaypoints([], [{providedIndex:0,optimizedIndex:0}]));
assert.throws(()=>context.orderRouteWaypoints(stops.slice(0,1), [{providedIndex:0,optimizedIndex:1}]));
context.requestTomTomRoute = async () => { throw Error('synthetic provider unavailable'); };
const fallback = await context.optimizeRouteWithOsrm({origin:'O',destination:'D',stops:stops.map(s=>s.location)});
assert.equal(fallback.optimizationStrategy, 'OSRM Route — Existing Stop Order (Fallback)');
assert.equal(fallback.optimizationScore, 85);
assert.deepEqual(plain(fallback.optimizedStops), stops.map(s=>s.location));
process.stdout.write(JSON.stringify({invalidMappings:invalid.length,fallbackCalls,originalOrderPreserved:true}));
JS);

    expect($result['invalidMappings'])->toBe(21)
        ->and($result['fallbackCalls'])->toBe(22)
        ->and($result['originalOrderPreserved'])->toBeTrue();
});

it('shows a neutral success toast after preparing TomTom and fallback route results', function () {
    $result = p54RunRouteJavaScript(<<<'JS'
const message = 'Route ready. Review the result, then apply the stop order.';
const notifications = [];
context.showToast = (text, type) => {
    assert.equal(vm.runInContext('routePendingOptimization !== null', context), true);
    assert.equal(nodes.routeOptimizeSummary.hidden, false);
    notifications.push({message:text,type});
};
context.window.showToast = context.showToast;
context.renderRouteStops(stops.map(stop=>stop.location));
const cases = [
    {provider:'OSRM',mapping:[],fallback:true,order:stops},
    {provider:'TomTom',mapping:[{providedIndex:0,optimizedIndex:1},{providedIndex:1,optimizedIndex:2},{providedIndex:2,optimizedIndex:0}],fallback:false,order:[stops[2],stops[0],stops[1]]},
];
for (const scenario of cases) {
    context.resetRouteOptimization();
    notifications.length = 0;
    context.requestTomTomRoute = async () => traffic(scenario.mapping);
    const route = await context.runRouteOptimization({showSuccessToast:true});
    assert.equal(route.provider, scenario.provider);
    assert.equal(route.fallback, scenario.fallback);
    assert.deepEqual(plain(route.routeCoordinates.stops), scenario.order);
    assert.deepEqual(plain(route.optimizedStops), scenario.order.map(stop=>stop.location));
    assert.deepEqual(plain(context.getRouteStopsFromForm()), stops.map(stop=>stop.location));
    assert.deepEqual(notifications, [{message,type:'success'}]);
    assert.doesNotMatch(notifications[0].message, /optimi[sz]ed/i);
    if (scenario.fallback) {
        assert.equal(route.optimizationProof, null);
        assert.equal(route.optimizationScore, 85);
    } else {
        assert.equal(route.optimizationProof, 'synthetic-proof');
    }
    notifications.length = 0;
    context.resetRouteOptimization();
    await context.runRouteOptimization({showSuccessToast:false});
    assert.deepEqual(notifications, []);
}
process.stdout.write(JSON.stringify({providers:cases.map(scenario=>scenario.provider),notificationTimingPreserved:true,disabledToastSuppressed:true}));
JS);

    expect($result)->toBe([
        'providers' => ['OSRM', 'TomTom'],
        'notificationTimingPreserved' => true,
        'disabledToastSuppressed' => true,
    ]);
});

it('applies TomTom and fallback stop orders with neutral success notifications', function () {
    $result = p54RunRouteJavaScript(<<<'JS'
const notifications = [];
context.showToast = (message, type) => notifications.push({
    message,type,
    pending:vm.runInContext('routePendingOptimization !== null',context),
    applied:vm.runInContext('routeLastOptimization?.optimizationApplied === true',context),
    stops:plain(context.getRouteStopsFromForm()),
});
context.window.showToast = context.showToast;
const originalOrder = stops.map(stop=>stop.location);
const cases = [
    {provider:'OSRM',mapping:[],order:stops},
    {provider:'TomTom',mapping:[{providedIndex:0,optimizedIndex:1},{providedIndex:1,optimizedIndex:2},{providedIndex:2,optimizedIndex:0}],order:[stops[2],stops[0],stops[1]]},
];
for (const scenario of cases) {
    context.resetRouteOptimization();
    context.renderRouteStops(originalOrder);
    notifications.length = 0;
    context.requestTomTomRoute = async () => traffic(scenario.mapping);
    const route = await context.runRouteOptimization({showSuccessToast:true});
    assert.equal(route.provider, scenario.provider);
    assert.equal(context.applyPendingRouteOptimization({showToast:true}), true);
    assert.deepEqual(notifications, [
        {message:'Route ready. Review the result, then apply the stop order.',type:'success',pending:true,applied:false,stops:originalOrder},
        {message:'Route stop order applied.',type:'success',pending:false,applied:true,stops:scenario.order.map(stop=>stop.location)},
    ]);
    for (const notification of notifications) assert.doesNotMatch(notification.message, /optimi[sz]/i);
    assert.deepEqual(plain(context.collectRouteFormData().stopCoordinates), scenario.order.map(stop=>({location:stop.location,latitude:stop.coordinate.lat,longitude:stop.coordinate.lng})));
    assert.equal(context.applyPendingRouteOptimization({showToast:true}), false);
    assert.equal(notifications.length, 2);
    context.resetRouteOptimization();
    context.renderRouteStops(originalOrder);
    notifications.length = 0;
    await context.runRouteOptimization({showSuccessToast:false});
    assert.equal(context.applyPendingRouteOptimization({showToast:false}), true);
    assert.deepEqual(notifications, []);
}
process.stdout.write(JSON.stringify({providers:cases.map(scenario=>scenario.provider),applyTimingPreserved:true,notificationsRemainOptional:true}));
JS);

    expect($result)->toBe([
        'providers' => ['OSRM', 'TomTom'],
        'applyTimingPreserved' => true,
        'notificationsRemainOptional' => true,
    ]);
});

it('keeps repeated stop labels paired with coordinates through preview apply and mocked save', function () {
    $result = p54RunRouteJavaScript(<<<'JS'
const cycle = [{providedIndex:0,optimizedIndex:1},{providedIndex:1,optimizedIndex:2},{providedIndex:2,optimizedIndex:0}];
context.requestTomTomRoute = async () => traffic(cycle);
context.renderRouteStops(stops.map(s=>s.location));
let savedPayload;
context.createRoutePlanApi = async payload => { savedPayload = payload; return {routePlan:{id:99}}; };
context.reloadRoutePlanningData = async () => {};
await context.runRouteOptimization({showSuccessToast:false});
assert.deepEqual(plain(context.getRouteStopsFromForm()), stops.map(s=>s.location));
await context.saveRouteFromForm();
assert.equal(savedPayload, undefined, 'Pending optimization must block Save');
vm.runInContext("routePendingOptimization.optimizedStops[0] = 'Wrong label'",context);
assert.throws(()=>context.applyPendingRouteOptimization({showToast:false}), /labels do not match/);
assert.deepEqual(plain(context.getRouteStopsFromForm()), stops.map(s=>s.location));
vm.runInContext("routePendingOptimization.optimizedStops[0] = 'Third stop'",context);
assert.equal(context.applyPendingRouteOptimization({showToast:false}), true);
const expected = [stops[2],stops[0],stops[1]].map(s=>({location:s.location,latitude:s.coordinate.lat,longitude:s.coordinate.lng}));
assert.deepEqual(plain(context.collectRouteFormData().stopCoordinates), expected);
assert.equal(context.buildUpdateRoutePayload(context.collectRouteFormData()).optimization_proof, 'synthetic-proof');
await context.saveRouteFromForm();
assert.deepEqual(plain(savedPayload.stops), expected);
assert.equal(savedPayload.optimization_strategy, 'TomTom Traffic-Aware Optimized');
const normalized = context.normalizeRouteApiRecord({id:99,stops:expected.map((s,i)=>({...s,stop_order:i+1})).reverse()});
assert.deepEqual(plain(normalized.stops), expected.map(s=>s.location));
assert.deepEqual(plain(normalized.stopCoordinates), expected);
nodes.routeStopsList.querySelectorAll('input.route-stop-input')[0].value = 'Changed without input event';
assert.throws(()=>context.collectRouteFormData(), /stops changed after optimization/);
assert.equal(fallbackCalls, 0);
process.stdout.write(JSON.stringify({pendingSaveBlocked:true,pairedSave:true,reopenedOrder:true,changedLabelsRejected:true}));
JS);

    expect($result)->toBe([
        'pendingSaveBlocked' => true,
        'pairedSave' => true,
        'reopenedOrder' => true,
        'changedLabelsRejected' => true,
    ]);
});
