<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FonsInversioRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'El nom és obligatori.',
        ];
    }
}
