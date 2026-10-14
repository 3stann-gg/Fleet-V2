<?php

namespace App\Http\Requests;

use App\Models\TrafficViolation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTrafficViolationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $trafficViolation = $this->route('trafficViolation');

        return $trafficViolation instanceof TrafficViolation
            && $this->user()?->can('update', $trafficViolation) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'citation_number' => ['nullable', 'string', 'max:100'],
            'violation_date' => ['required', 'date'],
            'violation_time' => ['nullable', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:255'],
            'issuing_agency' => ['nullable', 'string', 'max:150'],
            'violation_type' => ['required', Rule::in(array_keys(TrafficViolation::TYPE_LABELS))],
            'description' => ['nullable', 'string', 'max:5000'],
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'dispatch_id' => ['nullable', 'integer', 'exists:dispatch,id'],
            'trip_log_id' => ['nullable', 'integer', 'exists:trip_logs,id'],
            'reservation_id' => ['nullable', 'integer', 'exists:reservations,id'],
            'fine_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
