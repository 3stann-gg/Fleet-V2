<?php

namespace App\Http\Controllers;

use App\Models\Dispatch;
use App\Models\FuelLog;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Services\EnergyAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;

class CostAnalysisController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user?->hasPermission('cost_analysis.view'),
            403
        );

        $costAnalysisPermissions = [
            'role' =>
                $user->role,

            'canView' =>
                $user->hasPermission('cost_analysis.view'),

            'canManageBudget' =>
                $user->canModuleAction(
                    'cost_analysis',
                    'manage'
                ),
        ];

        return view(
            'cost-analysis.index',
            compact(
                'costAnalysisPermissions'
            )
        );
    }

    /**
     * Cost Analysis consolidated data endpoint.
     */
    public function data(Request $request)
    {
        /*
        |--------------------------------------------------------------------------
        | Cost Analysis Access
        |--------------------------------------------------------------------------
        |
        | This endpoint belongs to Cost Analysis itself.
        | We do not require the user to independently have access
        | to Fuel, Maintenance, Dispatch, or Vehicles.
        |
        */
        abort_unless(
            $request->user()?->hasPermission('cost_analysis.view'),
            403
        );

        /*
        |--------------------------------------------------------------------------
        | Vehicles
        |--------------------------------------------------------------------------
        */
        $vehicles = Vehicle::query()
            ->select([
                'id',
                'brand',
                'model',
                'vehicle_type',
                'department',
                'plate_number',
                'powertrain_type',
            ])
            ->orderBy('id')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Fuel Transactions
        |--------------------------------------------------------------------------
        */
        $fuel = FuelLog::query()
            ->with([
                'vehicle:id,brand,model,vehicle_type,department,plate_number',
                'driver:id,first_name,last_name',
            ])
            ->select([
                'id',
                'fuel_number',
                'vehicle_id',
                'driver_id',
                'fuel_amount',
                'cost_per_liter',
                'cost',
                'date',
                'fuel_type',
                'fuel_station',
            ])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Maintenance Transactions
        |--------------------------------------------------------------------------
        |
        | Only completed maintenance records are considered actual expenses.
        |
        */
        $maintenance = Maintenance::query()
            ->with([
                'vehicle:id,brand,model,vehicle_type,department,plate_number',
            ])
            ->select([
                'id',
                'maintenance_number',
                'vehicle_id',
                'maintenance_type',
                'description',
                'maintenance_date',
                'completion_date',
                'cost',
                'status',
            ])
            ->where('status', 'Completed')
            ->orderByDesc('completion_date')
            ->orderByDesc('id')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Dispatch / Trip Operational Information
        |--------------------------------------------------------------------------
        |
        | Dispatch currently has no authoritative monetary trip cost,
        | therefore Cost Analysis must not invent one.
        |
        | Department is obtained from RoutePlan / Reservation context,
        | not from Vehicle.
        |
        */
        $dispatches = Dispatch::query()
            ->with([
                'reservation.vehicle:id,brand,model,vehicle_type,department,plate_number',

                'reservation.driver:id,first_name,last_name',

                'reservation.routePlan:id,reservation_id,origin,destination,department,estimated_distance',
            ])
            ->select([
                'id',
                'dispatch_number',
                'reservation_id',
                'dispatch_date',
                'trip_status',
            ])
            ->orderByDesc('dispatch_date')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'vehicles' => $vehicles,
            'fuel' => $fuel,
            'maintenance' => $maintenance,
            'dispatches' => $dispatches,
        ]);
    }

    public function energyAnalytics(Request $request, EnergyAnalyticsService $analytics): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()?->hasPermission('cost_analysis.view'), 403);

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'vehicle_id' => ['nullable', 'integer', Rule::exists((new Vehicle)->getTable(), 'id')],
            'vehicle_type' => ['nullable', Rule::in(Vehicle::TYPE_OPTIONS)],
            'powertrain_type' => ['nullable', Rule::in(array_keys(Vehicle::POWERTRAIN_LABELS))],
            'department' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($analytics->analyze($validated));
    }
}
