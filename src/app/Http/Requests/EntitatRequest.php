<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EntitatRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('entitat')?->id;

        return [
            'nom' => ['required', 'string', 'max:200', Rule::unique('g_entitats', 'nom')->ignore($id)],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => "El nom de l'entitat és obligatori.",
            'nom.unique'   => "Ja existeix una entitat amb aquest nom.",
        ];
    }
}
