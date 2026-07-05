<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DespesaPlaPensionsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'contracte_id' => ['required', 'integer', 'exists:g_pp_contractes,id'],
            'data'         => ['required', 'date'],
            'import'       => ['required', 'numeric', 'min:0.01'],
            'concepte'     => ['nullable', 'string', 'max:300'],
        ];
    }

    public function messages(): array
    {
        return [
            'data.required'   => 'La data és obligatòria.',
            'import.required' => "L'import és obligatori.",
            'import.min'      => "L'import ha de ser positiu.",
        ];
    }
}
