<?php

namespace App\Services;

use RuntimeException;

class MaintenanceNotificationSourceNotAuthoritativeException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The maintenance notification source is not authoritative: '.$reason.'.');
    }
}
