<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogService
{
    public static function log(
        string $module,
        string $action,
        string $description,
        ?Model $record = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): AuditLog {
        $user = auth()->user();

        return AuditLog::create([
            'user_id' =>
                $user?->id,

            'user_name' =>
                $user?->name,

            'user_email' =>
                $user?->email,

            'user_role' =>
                $user?->role,

            'module' =>
                $module,

            'action' =>
                $action,

            'description' =>
                $description,

            'auditable_type' =>
                $record
                    ? get_class($record)
                    : null,

            'auditable_id' =>
                $record?->getKey(),

            'old_values' =>
                self::sanitize($oldValues),

            'new_values' =>
                self::sanitize($newValues),

            'ip_address' =>
                request()?->ip(),

            'user_agent' =>
                request()?->userAgent(),

            'request_method' =>
                request()?->method(),

            'request_path' =>
                request()?->path(),
        ]);
    }

    private static function sanitize(
        ?array $values
    ): ?array {
        if ($values === null) {
            return null;
        }

        $sensitiveFields = [
            'password',
            'password_confirmation',
            'current_password',
            'export_password',
            'export_password_confirmation',
            'remember_token',
            'token',
            'api_token',
            'access_token',
            'refresh_token',
        ];

        foreach ($values as $field => $value) {
            if (in_array(strtolower((string) $field), $sensitiveFields, true)) {
                unset($values[$field]);

                continue;
            }

            if (is_array($value)) {
                $values[$field] = self::sanitize($value);
            }
        }

        return $values;
    }
}
