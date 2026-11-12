<?php

use App\Services\RouteEstimateProvenanceReadinessService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Tests\TestCase;

pest()->extend(TestCase::class);

function rprCondition(string $condition): array
{
    return (new ReflectionMethod(RouteEstimateProvenanceReadinessService::class, 'condition'))->invoke(new RouteEstimateProvenanceReadinessService, $condition);
}

it('accepts redundant provider-rendered parentheses identifier quotes and literal charset prefixes', function (string $first, string $second): void {
    expect(rprCondition($first))->toBe(rprCondition($second));
})->with([
    ['current_marker IS NULL OR current_marker = 1', '((`current_marker` is null) OR (`current_marker` = 1))'],
    ["provider = 'TomTom' AND hospital_timezone = 'Asia/Manila'", "((`provider` = _utf8mb4'TomTom') and (`hospital_timezone` = _utf8mb4'Asia/Manila'))"],
    ['a IS NOT NULL AND b > a AND c >= 0', '((a is not null AND b > a) AND c >= 0)'],
    ["mode IN ('manual','planned')", "(`mode` in (_utf8mb4'manual',_utf8mb4'planned'))"],
    ['JSON_VALID(route_snapshot)', '(json_valid(`route_snapshot`))'],
]);

it('rejects semantic drift even when the same condition names and tokens remain', function (string $first, string $second): void {
    expect(rprCondition($first))->not->toBe(rprCondition($second));
})->with([
    ['(a IS NULL OR a >= 0) AND (b IS NULL OR b >= 0)', 'a IS NULL OR a >= 0 AND b IS NULL OR b >= 0'],
    ['a = 1 AND (b = 2 OR c = 3)', '(a = 1 AND b = 2) OR c = 3'],
    ["provider = 'TomTom'", "provider = 'tomtom'"],
    ["hospital_timezone = 'Asia/Manila'", "hospital_timezone = 'asia/manila'"],
    ['current_marker IS NULL OR current_marker = 1', 'current_marker IS NULL OR current_marker = 2'],
    ["mode IN ('manual','planned')", "mode IN ('manual','planned','other')"],
]);

it('fails closed for malformed or unsupported constraint syntax', function (string $condition): void {
    expect(fn () => rprCondition($condition))->toThrow(HttpResponseException::class);
})->with(['', '(a = 1', 'a = 1)', 'a = 1;', "provider = 'unterminated", 'a = 1 AND', '/* hidden */ a=1']);

it('parses all approved conditions without database access or ignoring boolean grouping', function (): void {
    $service = new RouteEstimateProvenanceReadinessService;
    $checks = (new ReflectionMethod($service, 'expectedChecks'))->invoke($service);
    $before = count(app('db')->getConnections());
    foreach ($checks as $condition) {
        expect(rprCondition($condition))->toBe(rprCondition('((('.$condition.')))'));
    }
    expect($checks)->toHaveCount(9)->and(count(app('db')->getConnections()))->toBe($before);
});
