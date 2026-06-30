<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Identity
            'name'             => ['required', 'string', 'max:100'],
            'email'            => ['required', 'email:rfc', 'max:255', 'unique:users,email'],

            // Password: min 8 chars, mixed case, at least one number.
            // "confirmed" expects a matching "password_confirmation" field.
            'password'         => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],

            // GDPR: user must explicitly accept the privacy policy.
            'consent'          => ['required', 'accepted'],

            // Optional preferences
            'marketing_opt_in' => ['nullable', 'boolean'],
            'locale'           => ['nullable', 'string', 'in:en,fr,es,de,it,pt,ar,nl'],

            // Client identifier for Sanctum token naming (e.g. "Chrome on MacOS")
            'device_name'      => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'consent.required' => 'You must accept our Privacy Policy to create an account.',
            'consent.accepted' => 'You must accept our Privacy Policy to create an account.',
            'email.unique'     => 'An account with this email address already exists.',
            'email.email'      => 'Please enter a valid email address.',
            'password.confirmed' => 'The password confirmation does not match.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name'             => 'full name',
            'email'            => 'email address',
            'password'         => 'password',
            'marketing_opt_in' => 'marketing preference',
        ];
    }

    /**
     * Override the default 422 response to return a consistent JSON envelope.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'data'    => null,
                'error'   => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => 'The provided data is invalid.',
                    'errors'  => $validator->errors(),
                ],
            ], 422)
        );
    }
}
