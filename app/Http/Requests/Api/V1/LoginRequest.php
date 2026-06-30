<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'       => ['required', 'email'],
            'password'    => ['required', 'string'],

            // Optional client identifier for Sanctum token naming.
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required'    => 'An email address is required.',
            'password.required' => 'A password is required.',
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
