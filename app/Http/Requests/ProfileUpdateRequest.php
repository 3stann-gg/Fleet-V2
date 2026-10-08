<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->user();

        $rules = [
            'first_name' => [
                'required',
                'string',
                'max:60',
            ],

            'middle_name' => [
                'nullable',
                'string',
                'max:60',
            ],

            'last_name' => [
                'required',
                'string',
                'max:60',
            ],

            'name' => [
                'required',
                'string',
                'max:120',
            ],

            'mobile_number' => [
                'nullable',
                'string',
                'max:40',
            ],

            'office_extension' => [
                'nullable',
                'string',
                'max:20',
            ],

            'office_location' => [
                'nullable',
                'string',
                'max:200',
            ],

            'profile_photo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],

            'remove_profile_photo' => [
                'nullable',
                'boolean',
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Driver Managed Fields
        |--------------------------------------------------------------------------
        |
        | Driver name and mobile number are managed from the Driver module.
        |--------------------------------------------------------------------------
        */
        if ($user?->hasRole('driver')) {
            unset(
                $rules['first_name'],
                $rules['last_name'],
                $rules['name'],
                $rules['mobile_number']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | IT Admin - Full Administrative Profile Access
        |--------------------------------------------------------------------------
        */
        if ($user?->hasRole('it_admin')) {
            $rules['employee_id'] = [
                'nullable',
                'string',
                'max:40',

                Rule::unique(
                    'users',
                    'employee_id'
                )->ignore($user->id),
            ];

            $rules['department'] = [
                'required',
                'string',
                'max:120',
            ];

            $rules['job_title'] = [
                'required',
                'string',
                'max:120',
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'first_name.required' =>
                'First name is required.',

            'last_name.required' =>
                'Last name is required.',

            'name.required' =>
                'Display name is required.',

            'email.required' =>
                'Email address is required.',

            'email.email' =>
                'Enter a valid email address.',

            'email.unique' =>
                'This email address is already in use.',

            'employee_id.unique' =>
                'This employee ID is already in use.',

            'department.required' =>
                'Department is required.',

            'job_title.required' =>
                'Job title is required.',

            'profile_photo.image' =>
                'The profile photo must be a valid image.',

            'profile_photo.mimes' =>
                'Profile photo must be JPG, JPEG, PNG, or WEBP.',

            'profile_photo.max' =>
                'Profile photo must not exceed 2 MB.',
        ];
    }
}