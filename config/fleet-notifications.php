<?php

$disabledByDefault = static function (string $name): bool {
    return filter_var(
        env($name, false),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    ) ?? false;
};

return [
    'occurrences' => [
        'enabled' => $disabledByDefault(
            'FLEET_NOTIFICATION_OCCURRENCES_ENABLED'
        ),
    ],

    'reconciliation' => [
        'after_commit_enabled' => $disabledByDefault(
            'FLEET_NOTIFICATION_AFTER_COMMIT_RECONCILIATION_ENABLED'
        ),
        'scheduled_recovery_enabled' => $disabledByDefault(
            'FLEET_NOTIFICATION_SCHEDULED_RECOVERY_ENABLED'
        ),
        'historical_enabled' => $disabledByDefault(
            'FLEET_NOTIFICATION_HISTORICAL_RECONCILIATION_ENABLED'
        ),
    ],
];
