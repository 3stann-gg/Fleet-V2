<?php

namespace App\Http\Controllers;

use App\Http\Requests\SecureReportExportRequest;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\SecureReportCsvExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecureReportExportController extends Controller
{
    private const CONFIRMATION_SECONDS = 300;

    private const MAX_FAILED_ATTEMPTS = 5;

    public function __construct(
        public SecureReportCsvExportService $csvExporter
    ) {}

    public function authorizeExport(SecureReportExportRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_if($user?->status === 'inactive', 403);

        $validated = $request->validated();
        $this->authorizeContext(
            $validated['context'],
            $validated['report_type'] ?? null,
            $user
        );

        if ($validated['protected_file'] && $validated['format'] !== 'pdf') {
            throw ValidationException::withMessages([
                'protected_file' => 'Protected export is not available for this format on the current server configuration.',
            ]);
        }

        if (! $this->hasRecentConfirmation($request)) {
            $this->confirmCurrentPassword(
                $request,
                (string) ($validated['current_password'] ?? '')
            );
        }

        $filters = $validated['filters'] ?? [];

        if ($user->hasRole('department_head')) {
            $filters['department'] = $user->department;
        }

        AuditLogService::log(
            module: 'Reports',
            action: $validated['format'] === 'print' ? 'Printed' : 'Exported',
            description: sprintf(
                '%s export authorized for %s.',
                strtoupper($validated['format']),
                str_replace('_', ' ', $validated['report_type'] ?? $validated['context'])
            ),
            newValues: [
                'format' => $validated['format'],
                'report_type' => $validated['report_type'] ?? $validated['context'],
                'filters' => $filters,
                'protected_file' => $validated['protected_file'],
            ]
        );

        return response()
            ->json([
                'authorized' => true,
                'expires_in' => self::CONFIRMATION_SECONDS,
            ])
            ->withHeaders([
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'Pragma' => 'no-cache',
            ]);
    }

    public function downloadCsv(SecureReportExportRequest $request): StreamedResponse
    {
        $user = $request->user();

        abort_if($user?->status === 'inactive', 403);

        $validated = $request->validated();
        abort_unless(
            $validated['context'] === 'reports' && $validated['format'] === 'csv',
            404
        );
        $this->authorizeContext('reports', $validated['report_type'] ?? null, $user);

        if ($validated['protected_file']) {
            throw ValidationException::withMessages([
                'protected_file' => 'Protected export is not available for this format on the current server configuration.',
            ]);
        }

        if (! $this->hasRecentConfirmation($request)) {
            $this->confirmCurrentPassword(
                $request,
                (string) ($validated['current_password'] ?? '')
            );
        }

        $filters = $validated['filters'] ?? [];
        $sources = app(ReportController::class)
            ->data($request)
            ->getData(true);
        $export = $this->csvExporter->build(
            $sources,
            $validated['report_type'],
            $filters
        );
        $filename = sprintf(
            'fleet_%s_report_%s.csv',
            str_replace('-', '_', $validated['report_type']),
            now()->format('Y-m-d')
        );

        AuditLogService::log(
            module: 'Reports',
            action: 'Exported',
            description: sprintf(
                'CSV report generated for %s.',
                str_replace('_', ' ', $validated['report_type'])
            ),
            newValues: [
                'format' => 'csv',
                'report_type' => $validated['report_type'],
                'filters' => $filters,
                'record_count' => count($export['rows']),
                'protected_file' => false,
            ]
        );

        return response()->streamDownload(function () use ($export): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $export['headers']);

            foreach ($export['rows'] as $row) {
                fputcsv($output, array_map($this->sanitizeCsvCell(...), $row));
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Export-Confirmation-Expires-In' => (string) self::CONFIRMATION_SECONDS,
        ]);
    }

    private function confirmCurrentPassword(
        SecureReportExportRequest $request,
        string $password
    ): void {
        $key = $this->rateLimitKey($request);

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILED_ATTEMPTS)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Password confirmation failed. Please wait before trying again.',
                'errors' => [
                    'current_password' => [
                        'Password confirmation failed. Please wait before trying again.',
                    ],
                ],
            ], 429));
        }

        if ($password === '' || ! Hash::check($password, $request->user()->password)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'current_password' => 'Password confirmation failed.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->put('secure_report_export_confirmed_at', now()->timestamp);
    }

    private function hasRecentConfirmation(SecureReportExportRequest $request): bool
    {
        $confirmedAt = (int) $request->session()->get(
            'secure_report_export_confirmed_at',
            0
        );

        return $confirmedAt > 0
            && now()->timestamp - $confirmedAt < self::CONFIRMATION_SECONDS;
    }

    private function rateLimitKey(SecureReportExportRequest $request): string
    {
        return sprintf(
            'secure-report-export:%s:%s',
            $request->user()->getAuthIdentifier(),
            $request->ip()
        );
    }

    private function authorizeContext(
        string $context,
        ?string $reportType,
        User $user
    ): void {
        $module = match ($context) {
            'vehicles' => 'vehicles',
            'drivers' => 'drivers',
            'dispatch' => 'dispatch',
            'reservations' => 'reservations',
            'maintenance' => 'maintenance',
            'fuel' => 'fuel',
            'route_planning' => 'route_planning',
            'cost_analysis' => 'cost_analysis',
            default => 'reports',
        };

        abort_unless($user->canViewModule($module), 403);

        if ($context !== 'reports') {
            return;
        }

        $allowedTypes = match ($user->role) {
            'dispatcher' => ['overview', 'utilization', 'trips', 'reservations', 'drivers'],
            'maintenance', 'finance' => ['overview', 'utilization', 'maintenance', 'fuel'],
            default => ['overview', 'utilization', 'trips', 'reservations', 'maintenance', 'fuel', 'drivers'],
        };

        abort_unless(in_array($reportType, $allowedTypes, true), 403);
    }

    private function sanitizeCsvCell(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_match('/^[=+\-@]/', $value) === 1
            ? "'{$value}"
            : $value;
    }
}
