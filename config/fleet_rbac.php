<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Approved Fleet Roles
    |--------------------------------------------------------------------------
    */

    'roles' => [
        'fleet_manager' => 'Fleet Manager',
        'dispatcher' => 'Dispatcher',
        'driver' => 'Driver',
        'department_head' => 'Department Head',
        'finance' => 'Finance',
        'maintenance' => 'Maintenance',
        'it_admin' => 'IT Admin',
    ],

    /*
    |--------------------------------------------------------------------------
    | Access levels
    |--------------------------------------------------------------------------
    */

    'access_levels' => [
        'full' => [
            'view',
            'create',
            'edit',
            'delete',
            'approve',
            'manage',
        ],

        'limited' => [
            'view',
        ],

        'view' => [
            'view',
        ],

        'none' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Module Access Matrix
    |--------------------------------------------------------------------------
    |
    | full    = Full Access
    | limited = Limited Access
    | view    = View Only
    | none    = No Access
    |
    */

    'modules' => [

        'dashboard' => [
            'fleet_manager' => 'full',
            'dispatcher' => 'full',
            'driver' => 'limited',
            'department_head' => 'limited',
            'finance' => 'view',
            'maintenance' => 'limited',
            'it_admin' => 'view',
        ],

        'vehicles' => [
            'fleet_manager' => 'full',
            'dispatcher' => 'limited',
            'driver' => 'view',
            'department_head' => 'none',
            'finance' => 'view',
            'maintenance' => 'limited',
            'it_admin' => 'view',
        ],

        'reservations' => [
            'fleet_manager' => 'full',
            'dispatcher' => 'full',
            'driver' => 'limited',
            'department_head' => 'limited',
            'finance' => 'none',
            'maintenance' => 'none',
            'it_admin' => 'view',
        ],

        'dispatch' => [
            'fleet_manager' => 'full',
            'dispatcher' => 'full',
            'driver' => 'limited',
            'department_head' => 'view',
            'finance' => 'none',
            'maintenance' => 'none',
            'it_admin' => 'view',
        ],

        'drivers' => [
            'fleet_manager' => 'full',
            'dispatcher' => 'full',
            'driver' => 'view',
            'department_head' => 'none',
            'finance' => 'none',
            'maintenance' => 'none',
            'it_admin' => 'view',
        ],

        'driver_performance' => [
            'fleet_manager' => 'full',
            'dispatcher' => 'none',
            'driver' => 'none',
            'department_head' => 'none',
            'finance' => 'none',
            'maintenance' => 'none',
            'it_admin' => 'view',
        ],

        'maintenance' => [
            'fleet_manager' => 'full',
            'dispatcher' => 'view',
            'driver' => 'none',
            'department_head' => 'none',
            'finance' => 'view',
            'maintenance' => 'full',
            'it_admin' => 'view',
        ],

        'fuel' => [
            'fleet_manager' => 'full',
            'dispatcher' => 'view',
            'driver' => 'limited',
            'department_head' => 'none',
            'finance' => 'view',
            'maintenance' => 'full',
            'it_admin' => 'view',
        ],

        'route_planning' => [
            'fleet_manager' => 'full',
            'dispatcher' => 'full',
            'driver' => 'view',
            'department_head' => 'none',
            'finance' => 'none',
            'maintenance' => 'none',
            'it_admin' => 'view',
        ],

        'cost_analysis' => [
            'fleet_manager' => 'full',
            'dispatcher' => 'none',
            'driver' => 'none',
            'department_head' => 'none',
            'finance' => 'full',
            'maintenance' => 'view',
            'it_admin' => 'view',
        ],

        'reports' => [
            'fleet_manager' => 'full',
            'dispatcher' => 'limited',
            'driver' => 'none',
            'department_head' => 'none',
            'finance' => 'full',
            'maintenance' => 'limited',
            'it_admin' => 'view',
        ],

        'audit_logs' => [
            'fleet_manager' => 'view',
            'dispatcher' => 'none',
            'driver' => 'none',
            'department_head' => 'none',
            'finance' => 'none',
            'maintenance' => 'none',
            'it_admin' => 'view',
        ],

        'profile' => [
            'fleet_manager' => 'limited',
            'dispatcher' => 'limited',
            'driver' => 'limited',
            'department_head' => 'limited',
            'finance' => 'limited',
            'maintenance' => 'limited',
            'it_admin' => 'full',
        ],

        'settings' => [
            'fleet_manager' => 'none',
            'dispatcher' => 'none',
            'driver' => 'none',
            'department_head' => 'none',
            'finance' => 'none',
            'maintenance' => 'none',
            'it_admin' => 'full',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Action Permissions
    |--------------------------------------------------------------------------
    |
    | These permissions refine module visibility. Policies and protected JSON
    | endpoints remain authoritative; sidebar and JavaScript checks are UX only.
    |
    */

    'permissions' => [
        'fleet_manager' => [
            'dashboard.view',
            'vehicles.view', 'vehicles.create', 'vehicles.update', 'vehicles.archive',
            'operational_health.view', 'battery_health.update',
            'drivers.view', 'drivers.create', 'drivers.update', 'drivers.archive',
            'driver_performance.view', 'driver_qualifications.update',
            'reservations.view', 'reservations.create', 'reservations.update',
            'reservations.cancel', 'reservations.archive', 'reservations.manage_all',
            'dispatch.view', 'dispatch.create', 'dispatch.update', 'dispatch.complete', 'dispatch.archive',
            'dispatch.candidate_comparison.view',
            'pretrip.view', 'posttrip.view',
            'sanitation.view', 'sanitation.create', 'sanitation.update',
            'maintenance.view', 'maintenance.create', 'maintenance.update', 'maintenance.archive',
            'fuel.view', 'fuel.create', 'fuel.update',
            'charging.view', 'charging.create',
            'routes.view', 'routes.manage',
            'cost_analysis.view',
            'reports.view', 'reports.export',
            'audit_logs.view', 'audit_logs.export',
            'hospital_facilities.view', 'hospital_facilities.manage',
        ],

        'it_admin' => [
            'dashboard.view',
            'vehicles.view', 'operational_health.view',
            'drivers.view', 'driver_performance.view',
            'reservations.view', 'dispatch.view',
            'pretrip.view', 'posttrip.view', 'sanitation.view',
            'maintenance.view', 'fuel.view', 'charging.view',
            'routes.view', 'cost_analysis.view', 'reports.view',
            'audit_logs.view', 'audit_logs.export',
            'settings.view', 'settings.update',
            'users.view', 'users.create', 'users.update',
            'users.activate', 'users.deactivate', 'users.assign_role',
            'hospital_facilities.view',
        ],

        'dispatcher' => [
            'dashboard.view',
            'vehicles.view', 'operational_health.view',
            'drivers.view',
            'reservations.view', 'reservations.create', 'reservations.update',
            'reservations.cancel', 'reservations.manage_all',
            'dispatch.view', 'dispatch.create', 'dispatch.update', 'dispatch.complete', 'dispatch.archive',
            'dispatch.candidate_comparison.view',
            'pretrip.view', 'posttrip.view',
            'sanitation.view', 'sanitation.create',
            'routes.view', 'routes.manage',
            'fuel.view',
            'reports.view', 'reports.export',
            'hospital_facilities.view',
        ],

        'driver' => [
            'dashboard.view',
            'vehicles.view', 'drivers.view',
            'reservations.view', 'dispatch.view', 'dispatch.update', 'dispatch.complete',
            'pretrip.view', 'pretrip.complete', 'posttrip.view', 'posttrip.complete',
            'sanitation.view', 'sanitation.create',
            'fuel.view', 'fuel.create', 'charging.view', 'charging.create',
            'routes.view', 'profile.view',
        ],

        'maintenance' => [
            'dashboard.view',
            'vehicles.view', 'vehicles.update',
            'operational_health.view', 'battery_health.update',
            'pretrip.view', 'posttrip.view',
            'sanitation.view', 'sanitation.create', 'sanitation.update',
            'maintenance.view', 'maintenance.create', 'maintenance.update', 'maintenance.archive',
            'fuel.view', 'fuel.create', 'fuel.update',
            'charging.view', 'charging.create',
            'cost_analysis.view',
            'reports.view', 'reports.export',
        ],

        'department_head' => [
            'dashboard.view',
            'reservations.view', 'reservations.create', 'reservations.update',
            'reservations.cancel', 'reservations.view_department',
            'dispatch.view', 'profile.view',
            'hospital_facilities.view',
        ],

        'finance' => [
            'dashboard.view',
            'vehicles.view', 'maintenance.view',
            'fuel.view', 'charging.view',
            'cost_analysis.view',
            'reports.view', 'reports.export',
            'profile.view',
        ],
    ],

];
