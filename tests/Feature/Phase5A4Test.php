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
for (const name of ['route-store.js','route-pipeline.js','route-modal.js','route-export.js']) {
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
const actualGeocodeRouteRecord = context.geocodeRouteRecord;
const actualRequestOsrmRoute = context.requestOsrmRoute;
context.geocodeRouteRecord = async () => coordinates;
context.drawRouteOnLeaflet = () => {};
context.requestOsrmRoute = async () => { fallbackCalls++; return {distance:12000,duration:900,geometry}; };
const traffic = mapping => ({provider:'TomTom',distanceKm:10,durationMinutes:12,geometry,optimizedWaypoints:mapping,optimizationProof:'synthetic-proof'});
(async () => {
JS;
    $script = $harness."\n".$scenario."\n})().catch(error => { console.error(error.stack); process.exitCode = 1; });";
    $process = new Process(['node', '-e', 'eval(require("node:fs").readFileSync(0, "utf8"))', public_path('assets/js/route-planning')]);
    $process->setInput($script)->setTimeout(30)->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

function p54RunRouteSaveJavaScript(string $scenario): array
{
    $setup = <<<'JS'
for(const id of ['routeFormModal','routeFormModalTitle','routeNumber','routePriority','routePurpose','routeNotes','routeVehicle','routeDriver','routeComparisonEnabled','routeComparisonSummary','applyRouteOptimizationBtn','saveRouteBtn'])nodes[id]??=new Element();
let modalOpen=false;
nodes.routeFormModal.classList={add:()=>modalOpen=true,remove:()=>modalOpen=false};
nodes.saveRouteBtn.disabled=false;
nodes.routeForm.querySelector=selector=>selector==='[type="submit"]'?nodes.saveRouteBtn:null;
nodes.routeForm.reset=()=>{};nodes.routeForm.removeAttribute=()=>{};
context.document.body={style:{}};context.requestAnimationFrame=callback=>callback();
context.populateRouteFormOptions=()=>{};context.clearRouteFieldErrors=()=>{};
context.applyRouteFormLifecycleRules=()=>{};context.applyReservationResourceDisplay=()=>{};
context.resetRouteResourceDisplay=()=>{};context.applyRouteStatusTransitions=(field,status)=>field.value=status;
context.populateRouteReservations=async()=>[];context.fetchNextRouteNumber=async()=>'SYNTHETIC-NEXT';
context.validateRouteForm=()=>true;context.reloadRoutePlanningData=async()=>{};
const record=id=>({id,reservationId:id,routeNumber:'SYNTHETIC-'+id,origin:'Synthetic origin '+id,destination:'Synthetic destination '+id,department:'Operations',purpose:'Synthetic Save test',notes:'',priority:'routine',priorityLabel:'Routine',isEmergency:false,status:'Planned',departureDate:'2026-11-20',departureTime:'09:00',stops:stops.map(s=>s.location),stopCoordinates:stops.map(s=>({location:s.location,latitude:s.coordinate.lat,longitude:s.coordinate.lng})),estimatedDistance:null,estimatedTravelTimeMinutes:null,optimizationScore:null});
context.fetchRoutePlanById=async id=>record(id);context.getRouteRecordById=id=>record(id);
const notices=[],saveCalls=[],validationErrors=[];
context.showToast=context.window.showToast=(message,type)=>notices.push({message,type});
context.applyLaravelValidationErrors=errors=>validationErrors.push(errors);
const saved=(id=11)=>({routePlan:{id},message:'Synthetic save complete'});
context.createRoutePlanApi=async payload=>{saveCalls.push({mode:'add',payload});return saved(33);};
context.updateRoutePlanApi=async(id,payload)=>{saveCalls.push({mode:'edit',id,payload});return saved(id);};
context.transitionRoutePlanApi=async(id,payload)=>{saveCalls.push({mode:'transition',id,payload});return saved(id);};
const deferred=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return{promise,resolve,reject};};
const open=async(mode='edit',id=11)=>{
    await context.openRouteFormModal(mode,mode==='edit'?record(id):null);
    if(mode==='add'){nodes.routeReservation.value=String(id);nodes.routeOrigin.value='Synthetic origin '+id;nodes.routeDestination.value='Synthetic destination '+id;nodes.routeDepartment.value='Operations';nodes.routeDepartureDate.value='2026-11-20';nodes.routeDepartureTime.value='09:00';context.renderRouteStops(stops.map(s=>s.location));}
};
const validResult=()=>({...traffic([{providedIndex:0,optimizedIndex:0},{providedIndex:1,optimizedIndex:1},{providedIndex:2,optimizedIndex:2}]),routeCoordinates:coordinates,originalRouteCoordinates:coordinates,optimizedStops:stops.map(s=>s.location),estimatedDistance:10,estimatedTravelTimeMinutes:12,optimizationScore:85});
const state=()=>plain({data:context.collectRouteFormData(),pending:vm.runInContext('routePendingOptimization',context),last:vm.runInContext('routeLastOptimization',context),modalOpen,saveDisabled:nodes.saveRouteBtn.disabled,comparison:nodes.routeComparisonSummary.innerHTML});
vm.runInContext('routeModuleSettings.preferOptimized=true',context);
JS;

    return p54RunRouteJavaScript($setup."\n".$scenario);
}

it('abandons stale automatic Save calculations without touching the current form', function (string $outcome, string $change) {
    $scenario = <<<'JS'
const outcome=OUTCOME,change=CHANGE;
await open(change==='add_to_edit'||change.startsWith('reservation')?'add':'edit',11);
const calculation=deferred();context.optimizeRouteWithOsrm=()=>calculation.promise;
const older=context.saveRouteFromForm();
if(change==='reservation'){nodes.routeReservation.value='77';context.resetRouteOptimization();}
else if(change==='reservation_roundtrip'){nodes.routeReservation.value='77';context.resetRouteOptimization();nodes.routeReservation.value='11';context.resetRouteOptimization();}
else if(change==='stop_roundtrip'){const input=nodes.routeStopsList.querySelectorAll('input.route-stop-input')[0];const previous=input.value;input.value='Temporary synthetic edit';context.resetRouteOptimization();input.value=previous;context.resetRouteOptimization();}
else {context.closeRouteFormModal();if(change!=='close_only')await open(change==='edit_to_add'?'add':'edit',change==='same'?11:22);}
const current=state(),noticeCount=notices.length;
if(outcome==='success')calculation.resolve(validResult());
else calculation.reject(Object.assign(new Error('Synthetic older calculation failed'),outcome==='429'?{status:429}:{}));
await older;
assert.equal(saveCalls.length,0);assert.deepEqual(state(),current);
assert.equal(notices.length,noticeCount);assert.equal(validationErrors.length,0);
process.stdout.write(JSON.stringify({noSave:true,formUntouched:true,noStaleMessages:true}));
JS;
    $scenario = str_replace(['OUTCOME', 'CHANGE'], [json_encode($outcome), json_encode($change)], $scenario);
    expect(p54RunRouteSaveJavaScript($scenario))->toBe(['noSave' => true, 'formUntouched' => true, 'noStaleMessages' => true]);
})->with([
    'failed calculation after switching routes' => ['failure', 'different'],
    'successful calculation after switching routes' => ['success', 'different'],
    'reopened same route has a new generation' => ['success', 'same'],
    'Add to Edit' => ['failure', 'add_to_edit'],
    'Edit to Add' => ['failure', 'edit_to_add'],
    'different Add reservation' => ['success', 'reservation'],
    'reservation changed and restored' => ['failure', 'reservation_roundtrip'],
    'stop changed and restored' => ['failure', 'stop_roundtrip'],
    'closed without reopening' => ['failure', 'close_only'],
    'older rate limit after switching routes' => ['429', 'different'],
]);

it('ignores stale Save responses without resetting a newer pending Save', function (string $outcome) {
    $scenario = <<<'JS'
await open();vm.runInContext('routeModuleSettings.preferOptimized=false',context);
const oldResponse=deferred(),newResponse=deferred();let calls=0;
context.updateRoutePlanApi=(id,payload)=>{saveCalls.push({id,payload});return++calls===1?oldResponse.promise:newResponse.promise;};
const oldSave=context.saveRouteFromForm();assert.equal(nodes.saveRouteBtn.disabled,true);
context.closeRouteFormModal();await open('edit',22);
assert.equal(nodes.saveRouteBtn.disabled,false);
const newSave=context.saveRouteFromForm();const current=state(),noticeCount=notices.length;
if(OUTCOME==='success')oldResponse.resolve(saved(11));
else oldResponse.reject(Object.assign(new Error('Synthetic older Save failure'),OUTCOME==='429'?{status:429}:{errors:{origin:['Old error']}}));
await oldSave;
assert.deepEqual(state(),current);assert.equal(nodes.saveRouteBtn.disabled,true);
assert.equal(notices.length,noticeCount);assert.equal(validationErrors.length,0);
assert.deepEqual(saveCalls.map(c=>c.id),[11,22]);
newResponse.resolve(saved(22));await newSave;
assert.equal(modalOpen,false);assert.equal(nodes.saveRouteBtn.disabled,false);
assert.equal(notices.filter(n=>n.type==='success').length,1);
process.stdout.write(JSON.stringify({newerPendingPreserved:true,noStaleMessages:true,intendedRoutesOnly:true}));
JS;
    expect(p54RunRouteSaveJavaScript(str_replace('OUTCOME', json_encode($outcome), $scenario)))->toBe([
        'newerPendingPreserved' => true, 'noStaleMessages' => true, 'intendedRoutesOnly' => true,
    ]);
})->with(['success', 'failure', '429']);

it('does not release a newer Save after changing reservation in the same form', function () {
    expect(p54RunRouteSaveJavaScript(<<<'JS'
await open('add',11);
const older=deferred(),newer=deferred();let calculations=0;
context.optimizeRouteWithOsrm=()=>++calculations===1?older.promise:newer.promise;
const oldSave=context.saveRouteFromForm();nodes.routeReservation.value='77';context.resetRouteOptimization();
const newSave=context.saveRouteFromForm();const current=state();
older.resolve(validResult());await oldSave;
assert.deepEqual(state(),current);assert.equal(saveCalls.length,0);
assert.equal(vm.runInContext('routeOptimizationRequestPending',context),true);
newer.resolve(validResult());await newSave;
assert.equal(saveCalls.length,1);assert.equal(saveCalls[0].mode,'add');assert.equal(saveCalls[0].payload.reservation_id,77);
assert.equal(notices.filter(n=>n.type==='success').length,1);
process.stdout.write(JSON.stringify({newerCalculationPreserved:true,newReservationOnly:true}));
JS))->toBe(['newerCalculationPreserved' => true, 'newReservationOnly' => true]);
});

it('releases its own submit lock when reservation changes during an already submitted Save', function () {
    expect(p54RunRouteSaveJavaScript(<<<'JS'
await open('add',11);vm.runInContext('routeModuleSettings.preferOptimized=false',context);
const response=deferred();context.createRoutePlanApi=payload=>{saveCalls.push({payload});return response.promise;};
const older=context.saveRouteFromForm();assert.equal(nodes.saveRouteBtn.disabled,true);
nodes.routeReservation.value='77';context.resetRouteOptimization();const current=plain(context.collectRouteFormData());
response.resolve(saved(33));await older;
assert.deepEqual(plain(context.collectRouteFormData()),current);assert.equal(modalOpen,true);
assert.equal(nodes.saveRouteBtn.disabled,false);assert.equal(notices.length,0);
assert.equal(saveCalls.length,1);assert.equal(saveCalls[0].payload.reservation_id,11);
process.stdout.write(JSON.stringify({currentInputsPreserved:true,ownLockReleased:true,noStaleSuccess:true}));
JS))->toBe(['currentInputsPreserved' => true, 'ownLockReleased' => true, 'noStaleSuccess' => true]);
});

it('prevents duplicate Saves while calculation or submission is pending', function (bool $calculate) {
    $scenario = <<<'JS'
await open();vm.runInContext('routeModuleSettings.preferOptimized=CALCULATE',context);
const pending=deferred();
if(CALCULATE)context.optimizeRouteWithOsrm=()=>pending.promise;
else context.updateRoutePlanApi=(id,payload)=>{saveCalls.push({id,payload});return pending.promise;};
const first=context.saveRouteFromForm();await context.saveRouteFromForm();
assert.equal(saveCalls.length,CALCULATE?0:1);
pending.resolve(CALCULATE?validResult():saved(11));await first;
assert.equal(saveCalls.length,1);assert.equal(modalOpen,false);
assert.equal(notices.filter(n=>n.type==='success').length,1);
process.stdout.write(JSON.stringify({oneSave:true,oneSuccess:true}));
JS;
    expect(p54RunRouteSaveJavaScript(str_replace('CALCULATE', $calculate ? 'true' : 'false', $scenario)))->toBe(['oneSave' => true, 'oneSuccess' => true]);
})->with([true, false]);

it('preserves valid same-form Saves and the existing optional calculation error policy', function (string $calculation, string $mode) {
    $scenario = <<<'JS'
await open(MODE);
if(CALCULATION==='success')context.optimizeRouteWithOsrm=async()=>validResult();
else context.optimizeRouteWithOsrm=async()=>{throw new Error('Synthetic optional calculation failure');};
if(CALCULATION==='ready_failure')nodes.routeStatus.value='Ready For Dispatch';
await context.saveRouteFromForm();
assert.equal(saveCalls.length,CALCULATION==='ready_failure'?0:1);
assert.equal(modalOpen,CALCULATION==='ready_failure');
assert.equal(nodes.saveRouteBtn.disabled,false);
if(saveCalls.length){if(MODE==='edit')assert.equal(saveCalls[0].id,11);else assert.equal(saveCalls[0].mode,'add');}
process.stdout.write(JSON.stringify({sameFormPolicyPreserved:true,buttonRecovered:true}));
JS;
    expect(p54RunRouteSaveJavaScript(str_replace(['CALCULATION', 'MODE'], [json_encode($calculation), json_encode($mode)], $scenario)))->toBe(['sameFormPolicyPreserved' => true, 'buttonRecovered' => true]);
})->with([
    'Edit calculated Save' => ['success', 'edit'],
    'Edit optional calculation failure' => ['failure', 'edit'],
    'Ready For Dispatch calculation failure' => ['ready_failure', 'edit'],
    'Add calculated Save' => ['success', 'add'],
    'Add optional calculation failure' => ['failure', 'add'],
]);

it('retains shared cooldowns from a stale real 429 without notifying or saving the newer form', function () {
    expect(p54RunRouteSaveJavaScript(<<<'JS'
await open();context.FormData=class {};const response=deferred(),entered=deferred();let requests=0;
context.__saveClock=Date.parse('2026-11-12T00:00:00Z');vm.runInContext('Date.now=()=>__saveClock',context);
context.fetch=async()=>{requests++;entered.resolve();return response.promise;};
const oldSave=context.saveRouteFromForm();await entered.promise;
context.closeRouteFormModal();await open('edit',22);const current=state(),noticeCount=notices.length;
response.resolve({ok:false,status:429,headers:{get:n=>n.toLowerCase()==='content-type'?'application/json':'42'},json:async()=>({code:'ROUTE_RATE_LIMITED',scope:'all',retry_after:42})});
await oldSave;
assert.deepEqual(state(),current);assert.equal(saveCalls.length,0);assert.equal(notices.length,noticeCount);
const cooldown=context.getRouteTrafficRateLimitError(false);assert.equal(cooldown.status,429);assert.equal(cooldown.retryAfter,42);
assert.equal(requests,1);assert.equal(fallbackCalls,0);
process.stdout.write(JSON.stringify({cooldownPreserved:true,newFormUntouched:true,noFallbackOrSave:true}));
JS))->toBe(['cooldownPreserved' => true, 'newFormUntouched' => true, 'noFallbackOrSave' => true]);
});

it('does not announce an older Save after another form opens during refresh', function () {
    expect(p54RunRouteSaveJavaScript(<<<'JS'
await open();vm.runInContext('routeModuleSettings.preferOptimized=false',context);
const refresh=deferred(),entered=deferred();context.reloadRoutePlanningData=async()=>{entered.resolve();await refresh.promise;};
const save=context.saveRouteFromForm();await entered.promise;await open('edit',22);const current=state();
refresh.resolve();await save;assert.deepEqual(state(),current);assert.equal(notices.filter(n=>n.type==='success').length,0);
assert.equal(saveCalls.length,1);assert.equal(saveCalls[0].id,11);
process.stdout.write(JSON.stringify({newFormPreserved:true,noStaleSuccess:true}));
JS))->toBe(['newFormPreserved' => true, 'noStaleSuccess' => true]);
});

it('honors retry headers with independent cooldowns and never retries on timer expiry', function (string $header, int $seconds) {
    $scenario = <<<'JS'
context.FormData=class {};
context.__now=Date.parse('2026-11-11T00:00:00Z');
vm.runInContext('Date.now=()=>__now',context);
const timers=new Map();let timerId=0,calls=0;
context.window.setTimeout=(fn,delay)=>{timers.set(++timerId,{fn,at:context.__now+delay});return timerId;};
context.window.clearTimeout=id=>timers.delete(id);
nodes.optimizeRouteBtn=new Element();nodes.routeComparisonEnabled=new Element();nodes.routeComparisonEnabled.checked=true;
const header=HEADER_VALUE,seconds=EXPECTED_SECONDS;
context.fetch=async()=>{calls++;return calls===1
    ?{ok:false,status:429,headers:{get:n=>n.toLowerCase()==='content-type'?'application/json':header},json:async()=>({success:false,code:'ROUTE_RATE_LIMITED',retry_after:9,scope:'comparison'})}
    :{ok:true,status:200,headers:{get:()=> 'application/json'},json:async()=>({success:true})};};
const request=include=>context.routeApiRequest('/route-planning/traffic-route',{method:'POST',body:JSON.stringify({include_comparison:include})});
await assert.rejects(request(true),e=>e.status===429&&e.scope==='comparison'&&e.retryAfter===seconds&&/Too many requests/.test(e.message));
assert.equal(calls,1);assert.equal(nodes.optimizeRouteBtn.disabled,true);
await assert.rejects(request(true),e=>e.status===429);assert.equal(calls,1,'Cooldown must not issue requests');
nodes.routeComparisonEnabled.checked=false;context.refreshRouteTrafficCooldownControls();assert.equal(nodes.optimizeRouteBtn.disabled,false);
await request(false);assert.equal(calls,2,'Comparison limit must not prohibit normal routing');
nodes.routeComparisonEnabled.checked=true;context.refreshRouteTrafficCooldownControls();assert.equal(nodes.optimizeRouteBtn.disabled,true);
context.__now+=seconds*1000+11;
for(const [id,timer]of [...timers])if(timer.at<=context.__now){timers.delete(id);timer.fn();}
assert.equal(nodes.optimizeRouteBtn.disabled,false);assert.equal(calls,2,'Timer must only re-enable controls');
await request(true);assert.equal(calls,3);
const invalid=context.routeTrafficRetryDeadline('invalid',undefined);
assert.equal(invalid-context.__now,60000);
process.stdout.write(JSON.stringify({headerHonored:true,scopesIndependent:true,noAutomaticRetries:true,recovered:true}));
JS;
    $scenario = str_replace(['HEADER_VALUE', 'EXPECTED_SECONDS'], [json_encode($header), (string) $seconds], $scenario);
    expect(p54RunRouteJavaScript($scenario))->toBe([
        'headerHonored' => true, 'scopesIndependent' => true, 'noAutomaticRetries' => true, 'recovered' => true,
    ]);
})->with([
    'seconds' => ['42', 42],
    'http date' => ['Wed, 11 Nov 2026 00:00:42 GMT', 42],
    'invalid header uses body' => ['invalid', 9],
]);

it('blocks both routing modes on a shared cooldown without contacting a provider', function () {
    expect(p54RunRouteJavaScript(<<<'JS'
context.FormData=class {};let calls=0;
context.fetch=async()=>{calls++;return{ok:false,status:429,headers:{get:n=>n.toLowerCase()==='content-type'?'application/json':'30'},json:async()=>({scope:'all',retry_after:30})};};
await assert.rejects(context.routeApiRequest('/route-planning/traffic-route',{method:'POST',body:'{}'}),e=>e.status===429);
await assert.rejects(context.routeApiRequest('/route-planning/traffic-route',{method:'POST',body:'{"include_comparison":true}'}),e=>e.status===429);
await assert.rejects(context.calculateRouteWithTraffic({origin:'O',destination:'D'},{draw:false,silent:true}),e=>e.status===429);
assert.equal(calls,1);assert.equal(fallbackCalls,0);
process.stdout.write(JSON.stringify({sharedCooldown:true,noProviderRequests:true}));
JS))->toBe(['sharedCooldown' => true, 'noProviderRequests' => true]);
});

it('propagates application 429 through both routing paths while preserving genuine provider fallback', function () {
    expect(p54RunRouteJavaScript(<<<'JS'
const limited=Object.assign(new Error('Too many requests.'),{status:429});
context.requestTomTomRoute=async()=>{throw limited;};
await assert.rejects(context.calculateRouteWithTraffic({origin:'O',destination:'D'},{draw:false,silent:true}),e=>e===limited);
await assert.rejects(context.calculateTrafficRouteFromCoordinates(coordinates),e=>e===limited);
assert.equal(fallbackCalls,0);
context.requestTomTomRoute=async()=>{throw Object.assign(new Error('Provider unavailable'),{status:502});};
assert.equal((await context.calculateRouteWithTraffic({origin:'O',destination:'D'},{draw:false,silent:true})).fallback,true);
assert.equal((await context.calculateTrafficRouteFromCoordinates(coordinates)).fallback,true);
assert.equal(fallbackCalls,2);
process.stdout.write(JSON.stringify({noFallbackOn429:true,genuineFailureFallback:true}));
JS))->toBe(['noFallbackOn429' => true, 'genuineFailureFallback' => true]);
});

it('does not save automatically or publish a stale optimization after a rate limit', function () {
    expect(p54RunRouteJavaScript(<<<'JS'
nodes.applyRouteOptimizationBtn=new Element();nodes.routeComparisonSummary=new Element();
context.renderRouteStops(stops.map(s=>s.location));
const limited=Object.assign(new Error('Too many requests. Please try again in 30 seconds.'),{status:429});
const before=plain(context.collectRouteFormData());let writes=0;const messages=[];
context.createRoutePlanApi=async()=>{writes++;};context.updateRoutePlanApi=async()=>{writes++;};
context.transitionRoutePlanApi=async()=>{writes++;};context.window.showToast=(message,type)=>messages.push({message,type});
vm.runInContext('routeModuleSettings.preferOptimized=true',context);
context.optimizeRouteWithOsrm=async()=>{throw limited;};
await context.saveRouteFromForm();assert.equal(writes,0);assert.deepEqual(plain(context.collectRouteFormData()),before);
assert.equal(messages.length,1);assert.match(messages[0].message,/Too many requests/);
vm.runInContext('routePendingOptimization={old:true}',context);
await assert.rejects(context.runRouteOptimization(),e=>e.status===429);
assert.equal(vm.runInContext('routePendingOptimization',context),null);assert.equal(nodes.applyRouteOptimizationBtn.disabled,true);
assert.equal(nodes.routeComparisonSummary.hidden,true);assert.deepEqual(plain(context.collectRouteFormData()),before);
let rejectOld;const older=new Promise((resolve,reject)=>rejectOld=reject);let count=0;
const valid={...traffic([{providedIndex:0,optimizedIndex:0},{providedIndex:1,optimizedIndex:1},{providedIndex:2,optimizedIndex:2}]),routeCoordinates:coordinates,originalRouteCoordinates:coordinates,optimizedStops:stops.map(s=>s.location),estimatedDistance:10,estimatedTravelTimeMinutes:12,optimizationScore:85};
context.optimizeRouteWithOsrm=async()=>++count===1?older:valid;
const old=context.runRouteOptimization({showSuccessToast:false}).catch(e=>e.status);
await context.runRouteOptimization({showSuccessToast:false});const current=plain(vm.runInContext('routePendingOptimization',context));
rejectOld(limited);assert.equal(await old,429);
assert.deepEqual(plain(vm.runInContext('routePendingOptimization',context)),current,'An older 429 cannot erase a newer valid result');
process.stdout.write(JSON.stringify({noAutomaticSave:true,formPreserved:true,noStaleApply:true,newerResultPreserved:true}));
JS))->toBe(['noAutomaticSave' => true, 'formPreserved' => true, 'noStaleApply' => true, 'newerResultPreserved' => true]);
});

function p54RunDriverRerouteJavaScript(string $scenario): array
{
    $setup = <<<'JS'
const messages=[],providerRequests=[],osrmRequests=[],draws=[];
let providerMode='identity';
context.window.showToast=(message,type)=>messages.push({message,type});
context.window.FLEET_RBAC={role:'driver'};context.document.readyState='loading';
context.__now=100000;vm.runInContext('Date.now=()=>__now',context);
const marker='    window.initFullRouteMap = init;';
const fullMapSource=fs.readFileSync(process.argv[1]+'/route-full-map.js','utf8');assert.equal(fullMapSource.split(marker).length,2);
const hooks=`window.__driverTest={
 setup(route,record){currentFullMapRoute=route;currentFullMapRecord=record;currentFullMapLiveVehicle={location:{latitude:14.59,longitude:121.02}};lastRerouteAt=0;},
 reroute:recalculateRouteFromCurrentLocation,returnHome:handleReturnToHospital,
 remainingTime:calculateNavigationRemainingTimeMinutes,renderMetrics:updateFullMapInfo,
 prepareMetricDisplay(remainingDistance){
  calculatePolylineRemainingDistanceKm=()=>remainingDistance;
  updateStatusAwareNavigationUI=()=>{};updateFullRouteProgress=()=>{};
  updateFullMapGpsIndicator=()=>{};updateFullMapGpsAccuracyCircle=()=>{};
 },
 state(){return{route:currentFullMapRoute,record:currentFullMapRecord,isRerouting,isReturningToHospital};}
};
drawFullRoute=(route,record)=>window.__driverDraws.push({route,record});
updateFullMapInfo=()=>{};updateCurrentNavigationInstruction=()=>{};
`;
context.window.__driverDraws=draws;
vm.runInContext(fullMapSource.replace(marker,hooks+marker),context);
const driverStops=stops.map((stop,index)=>({...plain(stop),id:41+index}));
const driverCoordinates={origin:{lat:14.6,lng:121},stops:driverStops,destination:{lat:14.7,lng:121.1}};
const savedRoute={...traffic([]),routeCoordinates:driverCoordinates,instructions:[],geometry:{type:'LineString',coordinates:[driverCoordinates.origin,...driverStops.map(s=>s.coordinate),driverCoordinates.destination].map(p=>[p.lng,p.lat])}};
const driverRecord={id:99,dispatchId:7,tripStatus:'En Route',destination:'Synthetic destination'};
const initial=()=>context.window.__driverTest.setup(plain(savedRoute),plain(driverRecord));
initial();
context.routeApiRequest=async(url,options)=>{
 const body=JSON.parse(options.body);providerRequests.push({url,body});
 if(providerMode==='limited')throw Object.assign(new Error('Too many requests. Please try again later.'),{status:429});
 if(providerMode==='unavailable')throw Object.assign(new Error('Synthetic provider unavailable'),{status:503});
 const input=body.coordinates,count=input.length-2;
 const order=Array.from({length:count},(_,i)=>i);
 if(providerMode==='reordered'&&count>1)order.unshift(order.pop());
 let mapping=order.map((providedIndex,optimizedIndex)=>({providedIndex,optimizedIndex}));
 if(providerMode==='bad_mapping')mapping=mapping.map(m=>({...m,optimizedIndex:0}));
 const points=[input[0],...order.map(i=>input[i+1]),input.at(-1)];
 return {success:true,provider:'TomTom',distance_km:10,travel_time_minutes:12,points,optimized_waypoints:mapping};
};
context.requestOsrmRoute=actualRequestOsrmRoute;
context.fetch=async(url)=>{
 assert.ok(url.startsWith('https://router.project-osrm.org/route/v1/driving/'),'Unexpected external request in isolated regression');
 osrmRequests.push(url);
 const pairs=decodeURIComponent(new URL(url).pathname.split('/').at(-1)).split(';').map(p=>p.split(',').map(Number));
 return {ok:true,json:async()=>({code:'Ok',routes:[{distance:12000,duration:900,geometry:{type:'LineString',coordinates:pairs}}]})};
};
context.window.calculateTrafficRouteFromCoordinates=context.calculateTrafficRouteFromCoordinates;
const state=()=>plain(context.window.__driverTest.state());
JS;

    return p54RunRouteJavaScript($setup."\n".$scenario);
}

it('normalizes OSRM navigation metrics without inventing unavailable estimates', function (string $metrics, ?float $distance, ?float $duration): void {
    $script = <<<'JS'
providerMode='unavailable';
const response=METRICS;
context.fetch=async url=>{osrmRequests.push(url);return{ok:true,json:async()=>({code:'Ok',routes:[{...response,geometry:savedRoute.geometry}]})};};
const route=await context.calculateTrafficRouteFromCoordinates(driverCoordinates,driverRecord);
assert.equal(route.distanceKm,DISTANCE);assert.equal(route.durationMinutes,DURATION);
assert.equal(route.provider,'OSRM');assert.equal(route.fallback,true);
assert.equal(route.trafficDelayMinutes,0);assert.deepEqual(plain(route.optimizedWaypoints),[]);
assert.deepEqual(plain(route.routeCoordinates),driverCoordinates);
assert.deepEqual(plain(route.geometry),savedRoute.geometry);
assert.equal(providerRequests.length,1);assert.equal(osrmRequests.length,1);
process.stdout.write(JSON.stringify({metricsCorrect:true,fixedOrder:true,truthfulProvider:true}));
JS;
    $script = str_replace(['METRICS', 'DISTANCE', 'DURATION'], [$metrics, json_encode($distance), json_encode($duration)], $script);
    expect(p54RunDriverRerouteJavaScript($script))->toBe(['metricsCorrect' => true, 'fixedOrder' => true, 'truthfulProvider' => true]);
})->with([
    'raw 12000 meters and 900 seconds' => ['{distance:12000,duration:900}', 12, 15],
    'normalized meters and seconds' => ['{distanceMeters:12000,durationSeconds:900}', 12, 15],
    'raw fields take precedence' => ['{distance:12000,duration:900,distanceMeters:5000,durationSeconds:60}', 12, 15],
    'decimals' => ['{distance:12500.5,duration:930.5}', 12.5005, 930.5 / 60],
    'numeric strings' => ['{distance:"12000",duration:"900"}', 12, 15],
    'legitimate zeros' => ['{distance:0,duration:0}', 0, 0],
    'normalized zeros' => ['{distanceMeters:0,durationSeconds:0}', 0, 0],
    'missing duration' => ['{distance:12000}', 12, null],
    'missing distance' => ['{duration:900}', null, 15],
    'missing both' => ['{}', null, null],
    'explicit null is not replaced by aliases' => ['{distance:null,duration:null,distanceMeters:12000,durationSeconds:900}', null, null],
    'normalized null' => ['{distanceMeters:null,durationSeconds:null}', null, null],
    'nonnumeric strings' => ['{distance:"invalid",duration:"invalid"}', null, null],
    'empty strings' => ['{distance:"",duration:" "}', null, null],
    'negative' => ['{distance:-1,duration:-1}', null, null],
    'NaN' => ['{distance:NaN,duration:NaN}', null, null],
    'Infinity' => ['{distance:Infinity,duration:-Infinity}', null, null],
    'booleans' => ['{distance:false,duration:true}', null, null],
    'arrays and objects' => ['{distance:[],duration:{}}', null, null],
]);

it('calculates and renders truthful live navigation ETA metrics', function (string $metrics, float $remainingDistance, ?int $expectedMinutes, string $expectedDisplay): void {
    $script = <<<'JS'
const route={...plain(savedRoute),...METRICS};
for(const id of ['fullMapDistanceLabel','fullMapEtaLabel','fullMapStatusLabel'])nodes[id]=new Element();
nodes.fullMapEtaLabel.textContent='Stale 99 min';
context.window.__driverTest.prepareMetricDisplay(REMAINING);
assert.equal(context.window.__driverTest.remainingTime(route,REMAINING),MINUTES);
context.window.__driverTest.renderMetrics(driverRecord,route);
assert.equal(nodes.fullMapEtaLabel.textContent,DISPLAY);
assert.equal(nodes.fullMapDistanceLabel.textContent,REMAINING.toFixed(1)+' km remaining');
assert.equal(nodes.fullMapStatusLabel.textContent,'En Route');
assert.equal(providerRequests.length,0);assert.equal(osrmRequests.length,0);
assert.deepEqual(state().record,driverRecord);
process.stdout.write(JSON.stringify({calculationCorrect:true,displayCorrect:true,noLifecycleChange:true}));
JS;
    $script = str_replace(['METRICS', 'REMAINING', 'MINUTES', 'DISPLAY'], [$metrics, '('.json_encode($remainingDistance).')', json_encode($expectedMinutes), json_encode($expectedDisplay)], $script);
    expect(p54RunDriverRerouteJavaScript($script))->toBe(['calculationCorrect' => true, 'displayCorrect' => true, 'noLifecycleChange' => true]);
})->with([
    'normalized 15 minute route' => ['{distanceKm:12,durationMinutes:15}', 12, 15, '15 min remaining'],
    'half route' => ['{distanceKm:12,durationMinutes:15}', 6, 8, '8 min remaining'],
    'remaining distance capped at full route' => ['{distanceKm:12,durationMinutes:15}', 16.1, 15, '15 min remaining'],
    'numeric strings' => ['{distanceKm:"12",durationMinutes:"15"}', 12, 15, '15 min remaining'],
    'zero duration' => ['{distanceKm:12,durationMinutes:0}', 12, 0, '0 min remaining'],
    'zero distance and duration' => ['{distanceKm:0,durationMinutes:0}', 0, 0, '0 min remaining'],
    'zero distance with positive duration cannot be prorated' => ['{distanceKm:0,durationMinutes:15}', 0, null, '—'],
    'missing duration' => ['{distanceKm:12,durationMinutes:undefined}', 12, null, '—'],
    'null duration' => ['{distanceKm:12,durationMinutes:null}', 12, null, '—'],
    'invalid duration' => ['{distanceKm:12,durationMinutes:"invalid"}', 12, null, '—'],
    'negative duration' => ['{distanceKm:12,durationMinutes:-1}', 12, null, '—'],
    'nonfinite duration' => ['{distanceKm:12,durationMinutes:Infinity}', 12, null, '—'],
    'NaN duration' => ['{distanceKm:12,durationMinutes:NaN}', 12, null, '—'],
    'blank duration' => ['{distanceKm:12,durationMinutes:" "}', 12, null, '—'],
    'boolean duration' => ['{distanceKm:12,durationMinutes:false}', 12, null, '—'],
    'unknown distance' => ['{distanceKm:null,durationMinutes:15}', 12, null, '—'],
    'invalid distance' => ['{distanceKm:-1,durationMinutes:15}', 12, null, '—'],
    'nonfinite distance' => ['{distanceKm:Infinity,durationMinutes:15}', 12, null, '—'],
    'normalized metrics override stale summary' => ['{distanceKm:12,durationMinutes:15,summary:{lengthInMeters:1000,travelTimeInSeconds:60}}', 12, 15, '15 min remaining'],
    'explicit unknown duration does not borrow a summary' => ['{distanceKm:12,durationMinutes:null,summary:{lengthInMeters:12000,travelTimeInSeconds:900}}', 12, null, '—'],
]);

it('preserves legacy summary and planned metric presentation without false zeros', function (): void {
    expect(p54RunDriverRerouteJavaScript(<<<'JS'
for(const id of ['fullMapDistanceLabel','fullMapEtaLabel'])nodes[id]=new Element();
context.window.__driverTest.prepareMetricDisplay(12);
const legacy={summary:{lengthInMeters:12000,travelTimeInSeconds:900}};
assert.equal(context.window.__driverTest.remainingTime(legacy,12),15);
context.window.__driverTest.renderMetrics(driverRecord,legacy);
assert.equal(nodes.fullMapEtaLabel.textContent,'15 min remaining');
for(const remaining of [null,undefined,NaN,Infinity,-1])assert.equal(context.window.__driverTest.remainingTime(legacy,remaining),null);
for(const status of ['Assigned','En Route']){
 context.window.__driverTest.prepareMetricDisplay(null);
 const record={...driverRecord,tripStatus:status};
 context.window.__driverTest.renderMetrics(record,legacy);
 assert.equal(nodes.fullMapDistanceLabel.textContent,'12.0 km');assert.equal(nodes.fullMapEtaLabel.textContent,'15 min');
 for(const value of [null,undefined,NaN,Infinity,-1,false,'',[],{}]){
  context.window.__driverTest.renderMetrics(record,{distanceKm:value,durationMinutes:value});
  assert.equal(nodes.fullMapDistanceLabel.textContent,'—');assert.equal(nodes.fullMapEtaLabel.textContent,'—');
 }
 context.window.__driverTest.renderMetrics(record,{distanceKm:0,durationMinutes:0});
 assert.equal(nodes.fullMapDistanceLabel.textContent,'0.0 km');assert.equal(nodes.fullMapEtaLabel.textContent,'0 min');
}
process.stdout.write(JSON.stringify({legacySupported:true,unavailableExplicit:true,zeroPreserved:true}));
JS))->toBe(['legacySupported' => true, 'unavailableExplicit' => true, 'zeroPreserved' => true]);
});

it('renders actual OSRM and TomTom reroute metrics while preserving navigation and dispatch', function (string $provider): void {
    $script = <<<'JS'
providerMode=PROVIDER==='OSRM'?'unavailable':'reordered';
const before=state();await context.window.__driverTest.reroute();const route=state().route;
const distance=PROVIDER==='OSRM'?12:10,minutes=PROVIDER==='OSRM'?15:12;
assert.equal(route.distanceKm,distance);assert.equal(route.durationMinutes,minutes);
assert.equal(route.provider,PROVIDER);
assert.deepEqual(route.routeCoordinates.stops,(PROVIDER==='OSRM'?[0,1,2]:[2,0,1]).map(i=>before.route.routeCoordinates.stops[i]));
assert.deepEqual(state().record,before.record);assert.equal(draws.length,1);
for(const id of ['fullMapDistanceLabel','fullMapEtaLabel'])nodes[id]=new Element();
context.window.__driverTest.prepareMetricDisplay(distance);
context.window.__driverTest.renderMetrics(driverRecord,route);
assert.equal(nodes.fullMapEtaLabel.textContent,minutes+' min remaining');
assert.equal(providerRequests.length,1);assert.equal(osrmRequests.length,PROVIDER==='OSRM'?1:0);
process.stdout.write(JSON.stringify({realAdapterRendered:true,stopOrderPreserved:true,dispatchPreserved:true}));
JS;
    expect(p54RunDriverRerouteJavaScript(str_replace('PROVIDER', json_encode($provider), $script)))
        ->toBe(['realAdapterRendered' => true, 'stopOrderPreserved' => true, 'dispatchPreserved' => true]);
})->with(['OSRM', 'TomTom']);

it('retains all saved intermediate stop occurrences in driver rerouting requests', function () {
    expect(p54RunDriverRerouteJavaScript(<<<'JS'
const before=state();
await context.window.__driverTest.reroute();
assert.equal(providerRequests.length,1);
assert.deepEqual(providerRequests[0].body.coordinates,[
 {latitude:14.59,longitude:121.02},
 {latitude:14.61,longitude:121.01},
 {latitude:14.62,longitude:121.02},
 {latitude:14.63,longitude:121.03},
 {latitude:14.7,longitude:121.1},
]);
assert.deepEqual(state().route.routeCoordinates.stops,before.route.routeCoordinates.stops);
assert.deepEqual(state().record,before.record);assert.equal(draws.length,1);assert.equal(osrmRequests.length,0);
process.stdout.write(JSON.stringify({allStops:true,pairingPreserved:true,noDispatchChange:true}));
JS))->toBe(['allStops' => true, 'pairingPreserved' => true, 'noDispatchChange' => true]);
});

it('keeps driver stop identity and geometry paired for identity and reordered provider results', function (bool $repeatCoordinates, bool $reordered): void {
    $script = <<<'JS'
const route=plain(savedRoute);
if(REPEAT)route.routeCoordinates.stops[1].coordinate=plain(route.routeCoordinates.stops[0].coordinate);
route.routeCoordinates.stops[0].visited=true;
route.routeCoordinates.stops[0].coordinate.displayName='Original synthetic label';
route.routeCoordinates.stops[0].coordinate.lat=String(route.routeCoordinates.stops[0].coordinate.lat);
context.window.__driverTest.setup(route,plain(driverRecord));
const expected=plain(route.routeCoordinates.stops).map(s=>({...s,coordinate:{...s.coordinate,lat:Number(s.coordinate.lat),lng:Number(s.coordinate.lng)}}));
providerMode=REORDERED?'reordered':'identity';
await context.window.__driverTest.reroute();
const actual=state();
const order=REORDERED?[2,0,1]:[0,1,2];
assert.deepEqual(actual.route.routeCoordinates.stops,order.map(i=>expected[i]));
assert.deepEqual(actual.route.geometry.coordinates,[[121.02,14.59],...order.map(i=>[expected[i].coordinate.lng,expected[i].coordinate.lat]),[121.1,14.7]]);
assert.deepEqual(plain(draws.at(-1).route.routeCoordinates),actual.route.routeCoordinates);
assert.deepEqual(actual.record,plain(driverRecord));
assert.equal(providerRequests[0].body.coordinates.length,5);
assert.equal(actual.route.routeCoordinates.stops.length,3,'Visited flags must not introduce a new pruning policy');
assert.deepEqual(route.routeCoordinates.stops[0].coordinate.lat,String(expected[0].coordinate.lat),'Input stop objects must remain unchanged');
process.stdout.write(JSON.stringify({occurrencesPreserved:true,geometryPaired:true,savedInputUnchanged:true}));
JS;

    expect(p54RunDriverRerouteJavaScript(str_replace(['REPEAT', 'REORDERED'], [json_encode($repeatCoordinates), json_encode($reordered)], $script)))
        ->toBe(['occurrencesPreserved' => true, 'geometryPaired' => true, 'savedInputUnchanged' => true]);
})->with([
    'distinct coordinates, identity order' => [false, false],
    'distinct coordinates, reordered' => [false, true],
    'repeated coordinates, identity order' => [true, false],
    'repeated coordinates, reordered' => [true, true],
]);

it('rejects invalid required driver stop data before requesting any provider', function (string $mutation): void {
    $script = <<<'JS'
const route=plain(savedRoute);
MUTATION;
context.window.__driverTest.setup(route,plain(driverRecord));const before=state();
await context.window.__driverTest.reroute();
assert.deepEqual(state(),before);assert.equal(providerRequests.length,0);assert.equal(osrmRequests.length,0);assert.equal(draws.length,0);
assert.equal(messages.length,1);assert.match(messages[0].message,/stop.*coordinates/i);
process.stdout.write(JSON.stringify({routePreserved:true,noProviderCalls:true,noPartialRoute:true}));
JS;

    expect(p54RunDriverRerouteJavaScript(str_replace('MUTATION', $mutation, $script)))
        ->toBe(['routePreserved' => true, 'noProviderCalls' => true, 'noPartialRoute' => true]);
})->with([
    'missing list' => ['delete route.routeCoordinates.stops'],
    'null list' => ['route.routeCoordinates.stops=null'],
    'missing stop' => ['route.routeCoordinates.stops[1]=null'],
    'missing coordinate' => ['delete route.routeCoordinates.stops[1].coordinate'],
    'unverified flat format' => ['route.routeCoordinates.stops[1]={id:42,location:"Flat",lat:14.62,lng:121.02}'],
    'missing latitude' => ['delete route.routeCoordinates.stops[1].coordinate.lat'],
    'missing longitude' => ['delete route.routeCoordinates.stops[1].coordinate.lng'],
    'null latitude' => ['route.routeCoordinates.stops[1].coordinate.lat=null'],
    'blank longitude' => ['route.routeCoordinates.stops[1].coordinate.lng=" "'],
    'boolean latitude' => ['route.routeCoordinates.stops[1].coordinate.lat=true'],
    'nonnumeric longitude' => ['route.routeCoordinates.stops[1].coordinate.lng="invalid"'],
    'nonfinite latitude' => ['route.routeCoordinates.stops[1].coordinate.lat=Infinity'],
    'latitude out of range' => ['route.routeCoordinates.stops[1].coordinate.lat=91'],
    'longitude out of range' => ['route.routeCoordinates.stops[1].coordinate.lng=-181'],
]);

it('rejects malformed driver waypoint permutations without replacing navigation', function (mixed $mapping): void {
    $script = <<<'JS'
const request=context.routeApiRequest;
context.routeApiRequest=async(...args)=>({...await request(...args),optimized_waypoints:MAPPING});
const before=state();await context.window.__driverTest.reroute();
assert.deepEqual(state(),before);assert.equal(draws.length,0);assert.equal(providerRequests.length,1);assert.equal(osrmRequests.length,0);
assert.match(messages[0].message,/invalid waypoint permutation/);
process.stdout.write(JSON.stringify({routePreserved:true,noWrongMarkers:true,noNewFallback:true}));
JS;

    expect(p54RunDriverRerouteJavaScript(str_replace('MAPPING', json_encode($mapping, JSON_THROW_ON_ERROR), $script)))
        ->toBe(['routePreserved' => true, 'noWrongMarkers' => true, 'noNewFallback' => true]);
})->with([
    'empty' => [[]],
    'null' => [null],
    'not an array' => ['invalid'],
    'incomplete' => [[['providedIndex' => 0, 'optimizedIndex' => 0]]],
    'duplicate target' => [[['providedIndex' => 0, 'optimizedIndex' => 0], ['providedIndex' => 1, 'optimizedIndex' => 0], ['providedIndex' => 2, 'optimizedIndex' => 2]]],
    'duplicate source' => [[['providedIndex' => 0, 'optimizedIndex' => 0], ['providedIndex' => 0, 'optimizedIndex' => 1], ['providedIndex' => 2, 'optimizedIndex' => 2]]],
    'out of range' => [[['providedIndex' => 0, 'optimizedIndex' => 0], ['providedIndex' => 1, 'optimizedIndex' => 1], ['providedIndex' => 3, 'optimizedIndex' => 2]]],
    'string index' => [[['providedIndex' => '0', 'optimizedIndex' => 0], ['providedIndex' => 1, 'optimizedIndex' => 1], ['providedIndex' => 2, 'optimizedIndex' => 2]]],
]);

it('rejects malformed driver response geometry before drawing it', function (mixed $geometry): void {
    $script = <<<'JS'
const calculate=context.window.calculateTrafficRouteFromCoordinates;
context.window.calculateTrafficRouteFromCoordinates=async(...args)=>({...await calculate(...args),geometry:GEOMETRY,route:null});
const before=state();await context.window.__driverTest.reroute();
assert.deepEqual(state(),before);assert.equal(draws.length,0);assert.equal(providerRequests.length,1);assert.equal(osrmRequests.length,0);
assert.match(messages[0].message,/invalid geometry/);
process.stdout.write(JSON.stringify({lastValidRoutePreserved:true,noInvalidDrawing:true}));
JS;

    expect(p54RunDriverRerouteJavaScript(str_replace('GEOMETRY', json_encode($geometry, JSON_THROW_ON_ERROR), $script)))
        ->toBe(['lastValidRoutePreserved' => true, 'noInvalidDrawing' => true]);
})->with([
    'missing' => [null],
    'wrong type' => [['type' => 'Point', 'coordinates' => [121, 14.6]]],
    'too short' => [['type' => 'LineString', 'coordinates' => [[121, 14.6]]]],
    'missing latitude' => [['type' => 'LineString', 'coordinates' => [[121, 14.6], [121.1]]]],
    'null latitude' => [['type' => 'LineString', 'coordinates' => [[121, 14.6], [121.1, null]]]],
    'out of range' => [['type' => 'LineString', 'coordinates' => [[121, 14.6], [181, 14.7]]]],
]);

it('keeps fixed-order OSRM driver fallback paired with every stop occurrence', function () {
    expect(p54RunDriverRerouteJavaScript(<<<'JS'
providerMode='unavailable';const before=state();
await context.window.__driverTest.reroute();
assert.equal(providerRequests.length,1);assert.equal(osrmRequests.length,1);
assert.match(osrmRequests[0],/121\.02,14\.59;121\.01,14\.61;121\.02,14\.62;121\.03,14\.63;121\.1,14\.7/);
assert.deepEqual(state().route.routeCoordinates.stops,before.route.routeCoordinates.stops);
assert.deepEqual(state().route.geometry.coordinates,[[121.02,14.59],[121.01,14.61],[121.02,14.62],[121.03,14.63],[121.1,14.7]]);
assert.equal(state().route.provider,'OSRM');assert.equal(state().route.fallback,true);assert.equal(draws.length,1);
assert.deepEqual(state().record,before.record);
process.stdout.write(JSON.stringify({fixedOrder:true,pairedStops:true,noDispatchChange:true}));
JS))->toBe(['fixedOrder' => true, 'pairedStops' => true, 'noDispatchChange' => true]);
});

it('preserves driver navigation after rate limiting and enforces the existing reroute cooldown', function () {
    expect(p54RunDriverRerouteJavaScript(<<<'JS'
providerMode='limited';const before=state();await context.window.__driverTest.reroute();
assert.deepEqual(state(),before);assert.equal(providerRequests.length,1);assert.equal(osrmRequests.length,0);assert.equal(draws.length,0);
assert.match(messages[0].message,/Too many requests/);
providerMode='identity';await context.window.__driverTest.reroute();assert.equal(providerRequests.length,1);
context.__now+=14999;await context.window.__driverTest.reroute();assert.equal(providerRequests.length,1);
context.__now+=1;await context.window.__driverTest.reroute();assert.equal(providerRequests.length,2);assert.equal(draws.length,1);
assert.deepEqual(state().record,before.record);assert.equal(osrmRequests.length,0);
process.stdout.write(JSON.stringify({no429Fallback:true,lastRouteRetained:true,cooldown15000:true,noLifecycleChange:true}));
JS))->toBe(['no429Fallback' => true, 'lastRouteRetained' => true, 'cooldown15000' => true, 'noLifecycleChange' => true]);
});

it('preserves zero and one intermediate stop compatibility without appending the destination as a stop', function (int $count): void {
    $script = <<<'JS'
const route=plain(savedRoute);route.routeCoordinates.stops=route.routeCoordinates.stops.slice(0,COUNT);
context.window.__driverTest.setup(route,plain(driverRecord));await context.window.__driverTest.reroute();
assert.equal(providerRequests.length,1);assert.equal(providerRequests[0].body.coordinates.length,COUNT+2);
assert.equal(state().route.routeCoordinates.stops.length,COUNT);
assert.equal(providerRequests[0].body.coordinates.filter(p=>p.latitude===14.7&&p.longitude===121.1).length,1);
assert.equal(draws.length,1);assert.equal(osrmRequests.length,0);
process.stdout.write(JSON.stringify({compatible:true,noDuplicateDestination:true}));
JS;

    expect(p54RunDriverRerouteJavaScript(str_replace('COUNT', (string) $count, $script)))
        ->toBe(['compatible' => true, 'noDuplicateDestination' => true]);
})->with([0, 1]);

it('preserves a distinct driver stop occurrence sharing the final destination coordinates', function () {
    expect(p54RunDriverRerouteJavaScript(<<<'JS'
const route=plain(savedRoute);route.routeCoordinates.stops[1].coordinate=plain(route.routeCoordinates.destination);
context.window.__driverTest.setup(route,plain(driverRecord));await context.window.__driverTest.reroute();
assert.equal(providerRequests[0].body.coordinates.length,5);assert.equal(state().route.routeCoordinates.stops.length,3);
assert.equal(state().route.routeCoordinates.stops[1].id,42);assert.equal(state().route.routeCoordinates.stops[1].location,'Repeated stop');
assert.deepEqual(state().route.routeCoordinates.stops[1].coordinate,route.routeCoordinates.destination);
process.stdout.write(JSON.stringify({distinctOccurrencePreserved:true,noCoordinateDeduplication:true}));
JS))->toBe(['distinctOccurrencePreserved' => true, 'noCoordinateDeduplication' => true]);
});

it('keeps the existing driver in-flight reroute guard', function () {
    expect(p54RunDriverRerouteJavaScript(<<<'JS'
const request=context.routeApiRequest;let release,attempts=0;
context.routeApiRequest=(...args)=>{attempts++;return new Promise(resolve=>release=async()=>resolve(await request(...args)));};
const first=context.window.__driverTest.reroute();
assert.equal(attempts,1);assert.equal(state().isRerouting,true);
await context.window.__driverTest.reroute();assert.equal(attempts,1);
await release();await first;assert.equal(providerRequests.length,1);assert.equal(draws.length,1);assert.equal(state().isRerouting,false);
process.stdout.write(JSON.stringify({singleInFlight:true,noDuplicateProviderCall:true}));
JS))->toBe(['singleInFlight' => true, 'noDuplicateProviderCall' => true]);
});

it('retains Return to Hospital routing without introducing persistence or lifecycle writes', function () {
    expect(p54RunDriverRerouteJavaScript(<<<'JS'
const before=state();await context.window.__driverTest.returnHome();
assert.deepEqual(providerRequests[0].body.coordinates,[{latitude:14.59,longitude:121.02},{latitude:14.7707,longitude:121.0659}]);
assert.equal(providerRequests.length,1);assert.equal(osrmRequests.length,0);assert.equal(draws.length,1);
assert.equal(state().route.routeCoordinates.stops.length,0);assert.deepEqual(state().record,before.record);
assert.ok(providerRequests.every(r=>r.url.endsWith('/traffic-route')),'Navigation must never save a route or transition dispatch');
process.stdout.write(JSON.stringify({hospitalUnchanged:true,noPersistedWrites:true,noDispatchChange:true}));
JS))->toBe(['hospitalUnchanged' => true, 'noPersistedWrites' => true, 'noDispatchChange' => true]);
});

it('retains the driver route and dispatch state when rerouting or returning is rate limited', function () {
    expect(p54RunRouteJavaScript(<<<'JS'
const messages=[];context.window.showToast=(message,type)=>messages.push({message,type});
context.window.FLEET_RBAC={role:'driver'};context.document.readyState='loading';
context.requestTomTomRoute=async()=>{throw Object.assign(new Error('Too many requests. Please try again later.'),{status:429});};
context.window.calculateTrafficRouteFromCoordinates=context.calculateTrafficRouteFromCoordinates;
const marker='    window.initFullRouteMap = init;';
const code=fs.readFileSync(process.argv[1]+'/route-full-map.js','utf8');assert.equal(code.split(marker).length,2);
const hooks=`window.__driverTest={setup(route,record){currentFullMapRoute=route;currentFullMapRecord=record;currentFullMapLiveVehicle={location:{latitude:14.6,longitude:121}};},reroute:recalculateRouteFromCurrentLocation,returnHome:handleReturnToHospital,state(){return{route:currentFullMapRoute,record:currentFullMapRecord,isRerouting,isReturningToHospital};}};`;
vm.runInContext(code.replace(marker,hooks+marker),context);
const record={id:99,dispatchId:7,tripStatus:'In Progress',destination:'D'};
context.window.__driverTest.setup({...traffic([]),routeCoordinates:coordinates,instructions:[]},record);
const before=plain(context.window.__driverTest.state());
await context.window.__driverTest.reroute();await context.window.__driverTest.returnHome();
assert.deepEqual(plain(context.window.__driverTest.state()),before);assert.equal(fallbackCalls,0);
assert.equal(messages.length,2);assert.ok(messages.every(m=>m.message.includes('Too many requests')));
process.stdout.write(JSON.stringify({routePreserved:true,dispatchPreserved:true,noFallback:true,driverNotified:true}));
JS))->toBe(['routePreserved' => true, 'dispatchPreserved' => true, 'noFallback' => true, 'driverNotified' => true]);
});

it('renders a temporary comparison with signed raw-unit differences and preserves it through Apply', function () {
    $result = p54RunRouteJavaScript(<<<'JS'
nodes.routeComparisonEnabled = new Element('input');
nodes.routeComparisonSummary = new Element();
assert.equal(nodes.routeComparisonEnabled.checked === true, false);
context.renderRouteStops(stops.map(s=>s.location));
const cycle = [{providedIndex:0,optimizedIndex:1},{providedIndex:1,optimizedIndex:2},{providedIndex:2,optimizedIndex:0}];
const source = [coordinates.origin,...stops.map(s=>s.coordinate),coordinates.destination].map(s=>({latitude:s.lat,longitude:s.lng}));
const comparison = {
    status:'available',reason:'Estimated differences only.',provider:'TomTom',
    routing_context:{api_version:'1',route_type:'fastest',travel_mode:'car',traffic:true,depart_at:'2026-11-20T09:00:00+08:00'},
    generated_at:new Date().toISOString(),expires_at:new Date(Date.now()+30000).toISOString(),
    original_calculated_at:new Date().toISOString(),candidate_calculated_at:new Date().toISOString(),
    source_coordinates:source,candidate_order:[2,0,1],order_changed:true,
    original:{distance_meters:12500.5,travel_time_seconds:600.25},candidate:{distance_meters:10000,travel_time_seconds:720},
    difference:{distance_meters:2500.5,distance_meters_percent:2500.5/12500.5*100,travel_time_seconds:-119.75,travel_time_seconds_percent:-119.75/600.25*100},
};
context.requestTomTomRoute=async()=>({...traffic(cycle),comparison});
await context.runRouteOptimization({showSuccessToast:false});
assert.equal(nodes.routeComparisonSummary.hidden,true,'Comparison must remain hidden until explicitly enabled');
context.resetRouteOptimization();
nodes.routeComparisonEnabled.checked=true;
const route=await context.runRouteOptimization({showSuccessToast:false});
const html=nodes.routeComparisonSummary.innerHTML;
assert.match(html,/Comparable estimates/);
assert.match(html,/\+2\.50 km/);
assert.match(html,/-2\.00 min/);
assert.match(html,/Estimated travel duration/);
assert.match(html,/Estimated differences only\. Actual travel outcomes may vary\./);
assert.match(html,/Traffic enabled/);
assert.match(html,/car/);
assert.deepEqual(plain(context.getRouteStopsFromForm()),stops.map(s=>s.location));
const before=plain(vm.runInContext('routeComparisonSnapshot.result.comparison',context));
assert.equal(context.applyPendingRouteOptimization({showToast:false}),true);
assert.deepEqual(plain(vm.runInContext('routeComparisonSnapshot.result.comparison',context)),before);
assert.equal(nodes.routeComparisonSummary.innerHTML,html,'Apply must preserve original baseline rather than recalculate savings');
assert.equal(context.applyPendingRouteOptimization({showToast:false}),false);
const payload=context.buildUpdateRoutePayload(context.collectRouteFormData());
assert.equal('comparison' in payload,false);
assert.equal('include_comparison' in payload,false);
assert.equal(payload.optimization_proof,'synthetic-proof');
nodes.routeOrigin.dataset.latitude='99';
context.verifyRouteComparisonSnapshot();
assert.match(nodes.routeComparisonSummary.innerHTML,/Unavailable/);
assert.doesNotMatch(nodes.routeComparisonSummary.innerHTML,/\+2\.50 km/);
context.resetRouteOptimization();
assert.equal(nodes.routeComparisonSummary.hidden,true,'Cleared/reopened routes must not inherit comparisons');
process.stdout.write(JSON.stringify({optInOnly:true,rawUnitDifferences:true,applyPreservesSnapshot:true,noPersistence:true,invalidation:true}));
JS);

    expect($result)->toBe([
        'optInOnly' => true,
        'rawUnitDifferences' => true,
        'applyPreservesSnapshot' => true,
        'noPersistence' => true,
        'invalidation' => true,
    ]);
});

it('shows unavailable comparison values for fallback malformed mismatched and expired results', function () {
    $result = p54RunRouteJavaScript(<<<'JS'
nodes.routeComparisonEnabled=new Element('input');nodes.routeComparisonEnabled.checked=true;
nodes.routeComparisonSummary=new Element();
const source=[coordinates.origin,...stops.map(s=>s.coordinate),coordinates.destination].map(s=>({latitude:s.lat,longitude:s.lng}));
const base={
    status:'available',reason:'Stop order unchanged. Recalculated differences are not verified savings.',provider:'TomTom',
    routing_context:{api_version:'1',route_type:'fastest',travel_mode:'car',traffic:true,depart_at:'2026-11-20T09:00:00+08:00'},
    expires_at:new Date(Date.now()+30000).toISOString(),source_coordinates:source,candidate_order:[0,1,2],
    original:{distance_meters:0,travel_time_seconds:0},candidate:{distance_meters:10,travel_time_seconds:20},
    difference:{distance_meters:-10,distance_meters_percent:null,travel_time_seconds:-20,travel_time_seconds_percent:null},
};
const route={provider:'TomTom',fallback:false,routeCoordinates:coordinates,originalRouteCoordinates:coordinates,comparison:base};
context.renderRouteComparison(route);
assert.match(nodes.routeComparisonSummary.innerHTML,/Comparable estimates/);
assert.match(nodes.routeComparisonSummary.innerHTML,/not verified savings/);
assert.match(nodes.routeComparisonSummary.innerHTML,/Unavailable/,'Zero denominator percentage must be unavailable');
assert.doesNotMatch(nodes.routeComparisonSummary.innerHTML,/NaN|Infinity/);
const cases=[
    {...route,provider:'OSRM',fallback:true,comparison:null},
    {...route,comparison:{...base,expires_at:'2000-01-01T00:00:00Z'}},
    {...route,comparison:{...base,original:{distance_meters:null,travel_time_seconds:0}}},
    {...route,comparison:{...base,routing_context:{...base.routing_context,travel_mode:'truck'}}},
    {...route,comparison:{...base,source_coordinates:[]}},
    {...route,comparison:{...base,candidate_order:[0,0,2]}},
    {...route,comparison:{...base,difference:{...base.difference,distance_meters:999}}},
];
for(const current of cases){
    context.renderRouteComparison(current);
    assert.match(nodes.routeComparisonSummary.innerHTML,/Unavailable/);
    assert.doesNotMatch(nodes.routeComparisonSummary.innerHTML,/Comparable estimates/);
    assert.doesNotMatch(nodes.routeComparisonSummary.innerHTML,/NaN|Infinity/);
}
const timers=[];
context.window.setTimeout=(callback,delay)=>{timers.push({callback,delay});return timers.length;};
context.window.clearTimeout=()=>{};
context.renderRouteComparison(route);
assert.ok(timers[0].delay<=30000&&timers[0].delay>0);
timers[0].callback();
assert.match(nodes.routeComparisonSummary.innerHTML,/expired/);
assert.doesNotMatch(nodes.routeComparisonSummary.innerHTML,/Comparable estimates/);
process.stdout.write(JSON.stringify({cases:cases.length,zeroDenominator:true,identityTruthful:true,expiry:true}));
JS);

    expect($result)->toBe([
        'cases' => 7,
        'zeroDenominator' => true,
        'identityTruthful' => true,
        'expiry' => true,
    ]);
});

it('discards out-of-order and edited-context responses before they overwrite the map or comparison', function () {
    $result = p54RunRouteJavaScript(<<<'JS'
context.renderRouteStops(stops.map(s=>s.location));
const pending=[],drawn=[];
context.requestTomTomRoute=()=>new Promise(resolve=>pending.push(resolve));
context.drawRouteOnLeaflet=(route,coords)=>drawn.push(plain(coords));
const identity=[0,1,2].map(i=>({providedIndex:i,optimizedIndex:i}));
const older=context.runRouteOptimization({showSuccessToast:false}).then(()=>null,error=>error.message);
await new Promise(resolve=>setImmediate(resolve));
const newer=context.runRouteOptimization({showSuccessToast:false});
await new Promise(resolve=>setImmediate(resolve));
assert.equal(pending.length,2);
pending[1](traffic(identity));await newer;
assert.equal(drawn.length,1);
const current=plain(vm.runInContext('routePendingOptimization',context));
pending[0](traffic(identity));assert.match(await older,/Unable|changed/);
assert.deepEqual(plain(vm.runInContext('routePendingOptimization',context)),current);
assert.equal(drawn.length,1);
for(const change of [
    ()=>nodes.routeReservation.value='2',
    ()=>nodes.routeOrigin.dataset.latitude='14.9',
    ()=>nodes.routeStopsList.querySelectorAll('input.route-stop-input')[0].value='Changed',
    ()=>nodes.routeDepartureTime.value='10:00',
]){
    context.resetRouteOptimization();
    const start=pending.length;
    const work=context.runRouteOptimization({showSuccessToast:false}).then(()=>null,error=>error.message);
    await new Promise(resolve=>setImmediate(resolve));
    let writes=0;context.createRoutePlanApi=async()=>{writes++;};
    await context.saveRouteFromForm();
    assert.equal(writes,0,'In-flight Save must be blocked');
    change();pending[start](traffic(identity));
    assert.match(await work,/Unable|changed/);
    assert.equal(vm.runInContext('routePendingOptimization',context),null);
    assert.equal(drawn.length,1);
}
process.stdout.write(JSON.stringify({outOfOrderBlocked:true,contextChanges:4,noStaleMap:true,inFlightSaveBlocked:true}));
JS);

    expect($result)->toBe([
        'outOfOrderBlocked' => true,
        'contextChanges' => 4,
        'noStaleMap' => true,
        'inFlightSaveBlocked' => true,
    ]);
});

it('requests extra comparison work only with an explicit option and keeps raw response measurements', function () {
    $result = p54RunRouteJavaScript(<<<'JS'
const calls=[];
context.routeApiRequest=async(url,options)=>{calls.push({url,body:JSON.parse(options.body)});return {success:true,provider:'TomTom',distance_km:10,travel_time_minutes:12,points:[{latitude:14.6,longitude:121},{latitude:14.7,longitude:121.1}],optimized_waypoints:[],optimization_proof:'proof',comparison:{status:'unavailable',reason:'Synthetic baseline failure'}};};
await context.requestTomTomRoute(coordinates,{reservationId:1,departureDate:'2026-11-20',departureTime:'09:00'});
await context.requestTomTomRoute(coordinates,{reservationId:1,departureDate:'2026-11-20',departureTime:'09:00',includeComparison:false});
const result=await context.requestTomTomRoute(coordinates,{reservationId:1,departureDate:'2026-11-20',departureTime:'09:00',includeComparison:true});
assert.equal('include_comparison' in calls[0].body,false);
assert.equal('include_comparison' in calls[1].body,false);
assert.equal(calls[2].body.include_comparison,true);
assert.deepEqual(calls[0].body.coordinates,calls[2].body.coordinates);
assert.equal(calls[0].body.depart_at,calls[2].body.depart_at);
assert.equal(result.comparison.status,'unavailable');
assert.equal(result.optimizationProof,'proof');
process.stdout.write(JSON.stringify({explicitOptIn:true,sameFrozenInput:true,candidateProofPreserved:true}));
JS);

    expect($result)->toBe([
        'explicitOptIn' => true,
        'sameFrozenInput' => true,
        'candidateProofPreserved' => true,
    ]);
});

it('invalidates pending results after coordinate mutations and clears comparison when opening historical routes', function () {
    $result = p54RunRouteJavaScript(<<<'JS'
context.renderRouteStops(stops.map(s=>s.location));
context.requestTomTomRoute=async()=>traffic([0,1,2].map(i=>({providedIndex:i,optimizedIndex:i})));
await context.runRouteOptimization({showSuccessToast:false});
nodes.routeOrigin.dataset.longitude='122';
assert.throws(()=>context.applyPendingRouteOptimization({showToast:false}),/inputs changed/);
assert.equal(vm.runInContext('routePendingOptimization',context),null);
let response;
context.requestTomTomRoute=()=>new Promise(resolve=>response=resolve);
const work=context.runRouteOptimization({showSuccessToast:false}).then(()=>null,error=>error.message);
await new Promise(resolve=>setImmediate(resolve));
context.invalidateRouteComparisonContext();
response(traffic([0,1,2].map(i=>({providedIndex:i,optimizedIndex:i}))));
assert.match(await work,/Unable|changed/);
assert.equal(vm.runInContext('routePendingOptimization',context),null);
nodes.routeComparisonEnabled=new Element('input');nodes.routeComparisonEnabled.checked=true;
nodes.routeComparisonSummary=new Element();nodes.routeComparisonSummary.hidden=false;nodes.routeComparisonSummary.innerHTML='Old comparison';
nodes.routeFormModal=new Element();
nodes.routeForm.reset=()=>{};
nodes.routeNotes=new Element();
nodes.routePurpose=new Element();
nodes.routeStatus.options=[];
context.document.body={style:{}};
context.requestAnimationFrame=callback=>callback();
context.populateRouteFormOptions=()=>{};
context.clearRouteFieldErrors=()=>{};
context.fetchRoutePlanById=async()=>context.normalizeRouteApiRecord({id:99,reservation_id:1,origin:'O',destination:'D',stops:[]});
context.populateRouteReservations=async()=>[];
context.applyReservationResourceDisplay=()=>{};
context.getRouteRecordById=()=>null;
context.applyRouteFormLifecycleRules=()=>{};
nodes.routeNumber=new Element();nodes.routePriority=new Element();
await context.openRouteFormModal('edit',{id:99});
assert.equal(nodes.routeComparisonEnabled.checked,false);
assert.equal(nodes.routeComparisonSummary.hidden,true);
assert.equal(vm.runInContext('routeComparisonSnapshot',context),null);
process.stdout.write(JSON.stringify({directCoordinateMutationRejected:true,inFlightCoordinateInvalidation:true,historicalComparisonAbsent:true}));
JS);

    expect($result)->toBe([
        'directCoordinateMutationRejected' => true,
        'inFlightCoordinateInvalidation' => true,
        'historicalComparisonAbsent' => true,
    ]);
});

it('exposes a default-off temporary comparison control and accessible estimate panel', function () {
    $this->actingAs(User::factory()->create(['role' => 'fleet_manager']))->get(route('route-planning'))
        ->assertSuccessful()
        ->assertSee('id="routeComparisonEnabled"', false)
        ->assertSee('id="routeComparisonSummary"', false)
        ->assertSee('Estimated differences only. Actual travel outcomes may vary.');
    $view = file_get_contents(resource_path('views/route-planning/index.blade.php'));
    preg_match('/<input[^>]+id="routeComparisonEnabled"[^>]*>/', $view, $match);
    expect($match[0])->not->toContain(' checked')
        ->and($view)->toContain('aria-live="polite" aria-labelledby="routeComparisonTitle" hidden');
});

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
    assert.match(nodes.routeOptimizeSummary.innerHTML, scenario.provider === 'OSRM' ? /Route Stop Order Applied/ : /Optimization Applied/);
    if (scenario.provider === 'OSRM') assert.doesNotMatch(nodes.routeOptimizeSummary.innerHTML, /Optimization Applied/);
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

it('replays saved routes in persisted order without geocoding or optimizing them again', function () {
    $result = p54RunRouteJavaScript(<<<'JS'
context.geocodeRouteRecord = actualGeocodeRouteRecord;
context.requestOsrmRoute = actualRequestOsrmRoute;
context.geocodeRouteLocation = async () => { throw Error('Saved coordinates must not be geocoded again'); };
let tomTomCalls = 0;
context.requestTomTomRoute = async () => { tomTomCalls++; return traffic([]); };
context.loadRoutePlanningVehicleLocation = async () => {};
const savedStops = [
    {id:303,stop_order:1,location:'Third stop',latitude:14.63,longitude:121.03},
    {id:301,stop_order:2,location:'Repeated stop',latitude:14.61,longitude:121.01},
    {id:302,stop_order:3,location:'Repeated stop',latitude:14.62,longitude:121.02},
    {id:304,stop_order:4,location:'Coordinate alias',latitude:14.61,longitude:121.01},
];
const raw = {id:99,origin:'Saved origin',origin_latitude:14.6,origin_longitude:121,destination:'Saved destination',destination_latitude:14.7,destination_longitude:121.1,stops:[...savedStops].reverse(),optimization_strategy:'TomTom Traffic-Aware Optimized',optimization_score:85,estimated_distance:10,estimated_time:12};
const original = JSON.stringify(raw);
const record = context.normalizeRouteRecord(context.cloneRouteRecord(context.normalizeRouteApiRecord(raw)));
const before = JSON.stringify(record);
const expected = [[121,14.6],...savedStops.map(s=>[s.longitude,s.latitude]),[121.1,14.7]];
const requests = [], drawn = [];
context.fetch = async url => {
    assert.match(url, /^https:\/\/router\.project-osrm\.org\/route\/v1\/driving\//);
    assert.doesNotMatch(url, /\/trip\//);
    const pairs = new URL(url).pathname.split('/').at(-1).split(';').map(pair=>pair.split(',').map(Number));
    assert.deepEqual(pairs, expected);
    requests.push(url);
    return {ok:true,json:async()=>({code:'Ok',routes:[{distance:12000,duration:900,geometry:{type:'LineString',coordinates:pairs}}]})};
};
context.drawRouteOnLeaflet = (route, points) => {
    assert.deepEqual(plain(route.geometry.coordinates), expected);
    assert.deepEqual(plain(points.stops.map(s=>({id:s.id,location:s.location,latitude:s.coordinate.lat,longitude:s.coordinate.lng}))), savedStops.map(({stop_order,...s})=>s));
    drawn.push(plain(points));
};
for (let i=0;i<2;i++) {
    await context.updateRouteMapPanel(record);
    const replay = context.window.getCurrentRoutePlanningMapRoute();
    assert.deepEqual(plain(replay.geometry.coordinates), expected);
    assert.equal(replay.optimizationProof, undefined);
}
assert.equal(tomTomCalls, 0);
assert.equal(requests.length, 2);
assert.equal(drawn.length, 2);
assert.equal(JSON.stringify(record), before);
assert.equal(JSON.stringify(raw), original);
process.stdout.write(JSON.stringify({savedOrder:true,stopIdsPreserved:true,duplicateLabelsAndCoordinates:true,noReoptimization:true,sourceUnchanged:true}));
JS);

    expect($result)->toBe([
        'savedOrder' => true,
        'stopIdsPreserved' => true,
        'duplicateLabelsAndCoordinates' => true,
        'noReoptimization' => true,
        'sourceUnchanged' => true,
    ]);
});

it('uses positional saved coordinates and only geocodes missing or invalid replay coordinates', function () {
    $result = p54RunRouteJavaScript(<<<'JS'
context.geocodeRouteRecord = actualGeocodeRouteRecord;
const calls = [];
context.geocodeRouteLocation = async label => { calls.push(label); return {lat:10,lng:20,displayName:label}; };
const record = {origin:'O',originLatitude:'0',originLongitude:0,destination:'D',destinationLatitude:90,destinationLongitude:-180,stops:['Same','Same'],stopCoordinates:[{id:1,location:'Same',latitude:14,longitude:121},{id:2,location:'Same',latitude:15,longitude:122}]};
const good = await context.geocodeRouteRecord(record,{usePersistedCoordinates:true});
assert.deepEqual(calls, []);
assert.deepEqual(plain(good.origin), {lat:0,lng:0,displayName:'O'});
assert.deepEqual(plain(good.destination), {lat:90,lng:-180,displayName:'D'});
assert.deepEqual(plain(good.stops.map(s=>[s.id,s.coordinate.lat,s.coordinate.lng])), [[1,14,121],[2,15,122]]);
const invalid = [null,undefined,'',' ',false,true,{},NaN,Infinity,91,-91];
for (const value of invalid) {
    calls.length = 0;
    const source = {...record,stopCoordinates:[record.stopCoordinates[0],{...record.stopCoordinates[1],latitude:value}]};
    const resolved = await context.geocodeRouteRecord(source,{usePersistedCoordinates:true});
    assert.deepEqual(calls, ['Same']);
    assert.equal(resolved.stops[0].coordinate.lat, 14);
    assert.equal(resolved.stops[1].id, 2);
    assert.equal(resolved.stops[1].coordinate.lat, 10);
}
calls.length = 0;
await context.geocodeRouteRecord({...record,originLatitude:null,destinationLongitude:181,stopCoordinates:[{location:'Wrong',latitude:1,longitude:2}]},{usePersistedCoordinates:true});
assert.deepEqual(calls, ['O','Same','Same','D']);
calls.length = 0;
await context.geocodeRouteRecord(record);
assert.deepEqual(calls, ['O','Same','Same','D'], 'Explicit optimization keeps its existing fresh geocoding behavior');
process.stdout.write(JSON.stringify({positionalPairing:true,invalidCases:invalid.length,legacyFallback:true,explicitOptimizationUnchanged:true}));
JS);

    expect($result)->toBe([
        'positionalPairing' => true,
        'invalidCases' => 11,
        'legacyFallback' => true,
        'explicitOptimizationUnchanged' => true,
    ]);
});

it('renders an escaped ordered print report after detaching the opener and releases print state', function () {
    $result = p54RunRouteJavaScript(<<<'JS'
const notices = [], timers = [], events = {}, order = [];
context.showToast = (message,type) => notices.push({message,type});
context.setTimeout = (callback,delay) => timers.push({callback,delay});
const row = {routeNumber:'SYNTHETIC-1',origin:'<script>bad</script>',destination:'D',stops:['C','A','A alias'],vehicle:'Synthetic vehicle',driver:'Synthetic driver',priority:'normal',estimatedDistance:10,estimatedTravelTime:'12 min',optimizationStrategy:'OSRM Route — Existing Stop Order (Fallback)',optimizationScore:85,status:'Draft'};
const model = {rows:[row],stats:{total:1,ready:0,highPriority:0,avgDistance:10,vehicles:1},filterSummary:[],generatedDate:'2026-11-11',generatedTime:'10:00'};
context.getRouteOutputModel = () => model;
let html = '', popupCount = 0, opener = {application:true};
const win = {
    get opener(){return opener;},set opener(value){opener=value;order.push('detach');},
    document:{readyState:'loading',open(){assert.equal(opener,null);order.push('open');},write(value){html=value;order.push('write');},close(){order.push('document-close');}},
    focus(){order.push('focus');},print(){order.push('print');},close(){order.push('close');},addEventListener(event,callback){events[event]=callback;},
};
context.window.open = (url,target,features) => {
    popupCount++;
    assert.equal(url,'');assert.equal(target,'_blank');assert.doesNotMatch(features,/noopener|noreferrer/);
    return win;
};
context.printRoutes();
assert.deepEqual(order, ['detach','open','write','document-close']);
assert.match(html, /&lt;script&gt;bad&lt;\/script&gt;/);
assert.doesNotMatch(html, /<script>/);
assert.match(html, /C; A; A alias/);
assert.match(html, /OSRM Route — Existing Stop Order \(Fallback\)/);
assert.match(html, /name="referrer" content="no-referrer"/);
assert.match(html, /Route Assessment Score/);
context.printRoutes();assert.equal(popupCount,1,'Busy print must not open a second popup');
win.onload();assert.equal(order.at(-1),'print');
assert.equal(timers[0].delay,1500);
events.afterprint();assert.equal(order.at(-1),'close');
assert.equal(vm.runInContext('routeExportBusy',context),false);
timers[0].callback();assert.equal(vm.runInContext('routeExportBusy',context),false);
assert.deepEqual(notices,[]);
process.stdout.write(JSON.stringify({openerDetachedBeforeWrite:true,orderedEscapedContents:true,printInvoked:true,cleanup:true}));
JS);

    expect($result)->toBe([
        'openerDetachedBeforeWrite' => true,
        'orderedEscapedContents' => true,
        'printInvoked' => true,
        'cleanup' => true,
    ]);
});

it('handles blocked print popups and refuses to render when opener isolation fails', function () {
    $result = p54RunRouteJavaScript(<<<'JS'
const notices=[];
context.showToast=(message,type)=>notices.push({message,type});
context.getRouteOutputModel=()=>({rows:[{}]});
for (const open of [()=>null,()=>{throw Error('Blocked');}]) {
    context.window.open=open;context.printRoutes();
    assert.deepEqual(notices.pop(),{message:'Unable to open the print report.',type:'error'});
    assert.equal(vm.runInContext('routeExportBusy',context),false);
}
let closed=0,writes=0;
const win={get opener(){return {};},set opener(value){},document:{open(){writes++;},write(){writes++;}},close(){closed++;}};
context.window.open=()=>win;
context.printRoutes();
assert.equal(writes,0);assert.equal(closed,1);
assert.deepEqual(notices.pop(),{message:'Unable to print routes.',type:'error'});
assert.equal(vm.runInContext('routeExportBusy',context),false);
context.getRouteOutputModel=()=>({rows:[]});
context.window.open=()=>{throw Error('Empty report must not open');};
context.printRoutes();
assert.deepEqual(notices.pop(),{message:'No routes available to print.',type:'warning'});
process.stdout.write(JSON.stringify({blockedPopup:true,isolationFailClosed:true,emptyReport:true}));
JS);

    expect($result)->toBe([
        'blockedPopup' => true,
        'isolationFailClosed' => true,
        'emptyReport' => true,
    ]);
});

it('preserves Excel stop ordering and the existing PDF summary export contract', function () {
    $result = p54RunRouteJavaScript(<<<'JS'
const row={routeNumber:'SYNTHETIC-1',origin:'O',destination:'D',stops:['C','A','B'],vehicle:'V',driver:'Driver',priority:'normal',estimatedDistance:10,estimatedTravelTime:'12 min',optimizationStrategy:'OSRM Route — Existing Stop Order (Fallback)',optimizationScore:85,status:'Draft'};
context.getRouteOutputModel=()=>({rows:[row],stats:{total:1,ready:0,highPriority:0,avgDistance:10,avgMinutes:12,vehicles:1},filterSummary:[],generatedDate:'2026-11-11',generatedTime:'10:00'});
const sheets={},files=[];
context.window.XLSX={utils:{book_new:()=>({}),aoa_to_sheet:rows=>({rows:plain(rows)}),book_append_sheet:(book,sheet,name)=>sheets[name]=sheet.rows},writeFile:(book,name)=>files.push(name)};
context.exportRoutesToExcel();
assert.deepEqual(sheets['Route Data'][1].slice(0,4),['SYNTHETIC-1','O','D','C; A; B']);
assert.equal(sheets['Route Data'][0][12],'Route Assessment Score');
assert.equal(sheets['Route Data'][1][12],85);
assert.equal(sheets['Route Data'][1][11],row.optimizationStrategy);
assert.match(sheets.Summary.find(r=>r[0]==='Route Assessment Score')[1], /not a percentage saved/);
assert.match(files[0],/\.xlsx$/);
let pdfTable;
context.window.jspdf={jsPDF:function(){return {setFontSize(){},setTextColor(){},text(){},autoTable:table=>{pdfTable=plain(table);},save:name=>files.push(name)};}};
context.exportRoutesToPdf();
assert.deepEqual(pdfTable.head[0],['Route No.','Origin','Destination','Vehicle','Driver','Priority','Distance','Time','Strategy','Status','Departure']);
assert.equal(pdfTable.body[0][0],'SYNTHETIC-1');
assert.equal(pdfTable.body[0][8],row.optimizationStrategy);
assert.match(files[1],/\.pdf$/);
process.stdout.write(JSON.stringify({excelOrdering:true,scoreContract:true,pdfSummaryUnchanged:true}));
JS);

    expect($result)->toBe([
        'excelOrdering' => true,
        'scoreContract' => true,
        'pdfSummaryUnchanged' => true,
    ]);
});
