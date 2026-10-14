<?php

namespace App\Http\Requests;

use App\Models\TrafficViolation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettleTrafficViolationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $trafficViolation = $this->route('trafficViolation');

        return $trafficViolation instanceof TrafficViolation
            && $this->user()?->can('settlement', $trafficViolation) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fine_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'due_date' => ['nullable', 'date'],
            'payment_status' => ['required', Rule::in(array_keys(TrafficViolation::PAYMENT_STATUS_LABELS))],
            'payment_date' => ['nullable', 'required_if:payment_status,paid', 'date'],
            'payment_reference' => ['nullable', 'string', 'max:150'],
            'settlement_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
