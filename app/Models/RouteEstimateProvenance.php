<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteEstimateProvenance extends Model
{
    protected $table = 'route_estimate_provenances';

    protected $guarded = ['*'];

    protected $visible = ['id', 'route_plan_id', 'reservation_id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'id' => 'integer', 'route_plan_id' => 'integer', 'reservation_id' => 'integer',
            'accepted_by_user_id' => 'integer', 'proof_version' => 'integer', 'snapshot_version' => 'integer',
            'source_route_revision' => 'integer', 'saved_route_revision' => 'integer', 'current_marker' => 'integer',
            'estimated_duration_minutes' => 'integer',
            'distance_meters' => 'decimal:6', 'duration_seconds' => 'decimal:6', 'estimated_distance_km' => 'decimal:2',
            'route_snapshot' => 'array', 'routing_options' => 'array',
            'departure_at_utc' => 'immutable_datetime', 'calculated_at_utc' => 'immutable_datetime',
            'accepted_at_utc' => 'immutable_datetime', 'proof_issued_at_utc' => 'immutable_datetime',
            'proof_expires_at_utc' => 'immutable_datetime', 'ended_at_utc' => 'immutable_datetime',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
        ];
    }

    public function routePlan(): BelongsTo
    {
        return $this->belongsTo(RoutePlan::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    /** Interpret this table's timezone-less DATETIME columns as UTC, independently of the application timezone. */
    protected function asDateTime(mixed $value): Carbon
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->utc();
        }
        if (is_int($value) || is_float($value)) {
            return Carbon::createFromTimestampUTC($value);
        }

        return Carbon::parse($value, 'UTC')->utc();
    }
}
