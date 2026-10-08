<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SecureReportExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('reports.export') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['nullable', 'string', 'max:1024'],
            'context' => [
                'required',
                Rule::in([
                    'vehicles', 'drivers', 'dispatch', 'reservations',
                    'maintenance', 'fuel', 'route_planning',
                    'cost_analysis', 'reports',
                ]),
            ],
            'format' => ['required', Rule::in(['print', 'pdf', 'csv', 'excel'])],
            'report_type' => ['nullable', 'required_if:context,reports', 'string', 'max:50'],
            'protected_file' => ['required', 'boolean'],
            'export_password' => [
                'nullable', 'required_if:protected_file,true', 'string',
                'min:8', 'max:1024', 'confirmed',
            ],
            'filters' => [
                'nullable',
                'array:date_range,start_date,end_date,date,vehicle_id,vehicle_type,powertrain,department,driver_id,status,priority',
            ],
            'filters.date_range' => ['nullable', 'string', 'max:50'],
            'filters.start_date' => ['nullable', 'required_if:filters.date_range,custom', 'date'],
            'filters.end_date' => ['nullable', 'required_if:filters.date_range,custom', 'date', 'after_or_equal:filters.start_date'],
            'filters.date' => ['nullable', 'date'],
            'filters.vehicle_id' => ['nullable', 'integer'],
            'filters.vehicle_type' => ['nullable', 'string', 'max:100'],
            'filters.powertrain' => ['nullable', 'string', 'max:100'],
            'filters.department' => ['nullable', 'string', 'max:120'],
            'filters.driver_id' => ['nullable', 'integer'],
            'filters.status' => ['nullable', 'string', 'max:100'],
            'filters.priority' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'export_password.confirmed' => 'Export file passwords do not match.',
            'export_password.min' => 'The export file password must be at least 8 characters.',
        ];
    }
}
