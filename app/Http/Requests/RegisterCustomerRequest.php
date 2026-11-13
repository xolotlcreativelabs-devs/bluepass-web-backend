<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El registro de compradores es público.
        return true;
    }

    public function rules(): array
    {
        return [
            'idToken' => ['required', 'string'],
            'name' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'idToken.required' => 'El token de Firebase es obligatorio.',
            'idToken.string' => 'El token de Firebase debe ser válido.',
            'name.required' => 'El nombre es obligatorio.',
            'name.string' => 'El nombre debe ser texto.',
            'name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'name.max' => 'El nombre no puede superar los 100 caracteres.',
        ];
    }
}
