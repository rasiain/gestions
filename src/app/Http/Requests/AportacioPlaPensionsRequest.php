<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AportacioPlaPensionsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'contracte_id'   => ['required', 'integer', 'exists:g_pp_contractes,id'],
            'data'           => ['required', 'date'],
            'import'         => ['required', 'numeric', 'min:0.01'],
            'participacions' => ['required', 'numeric', 'min:0.000001'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'data.required'           => 'La data és obligatòria.',
            'import.required'         => "L'import és obligatori.",
            'import.min'              => "L'import ha de ser positiu.",
            'participacions.required' => 'Les participacions són obligatòries.',
            'participacions.min'      => 'Les participacions han de ser positives.',
        ];
    }
}
