<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules.
     */
    public function rules(): array
    {
        return [

            'full_name' => [
                'required',
                'string',
                'max:255'
            ],

            'phone' => [
                'required',
                'string',
                'max:20',
                'unique:users,phone'
            ],

            'email' => [
                'nullable',
                'email',
                'unique:users,email'
            ],

            'password' => [
                'required',
                'confirmed',
                'min:8'
            ],

            'role' => [
                'required',
                'in:worker,homeowner,company'
            ],

            'location' => [
                'nullable',
                'string',
                'max:255'
            ],

        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [

            'full_name.required' => 'Full name is required.',

            'phone.required' => 'Phone number is required.',
            'phone.unique' => 'This phone number is already registered.',

            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'This email address is already registered.',

            'password.required' => 'Password is required.',
            'password.confirmed' => 'Passwords do not match.',
            'password.min' => 'Password must be at least 8 characters.',

            'role.required' => 'Select your account type.',
            'role.in' => 'Role must be worker, homeowner, or company.',
        ];
    }
}
