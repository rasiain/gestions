<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RendaFixaTitolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // L'ISIN són dues lletres de país i deu caràcters
            'isin' => [
                'required',
                'string',
                'size:12',
                'regex:/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/',
                Rule::unique('g_rf_titols', 'isin')->ignore($this->route('titol')),
            ],
            'nom'     => ['required', 'string', 'max:200'],
            'emissor' => ['nullable', 'string', 'max:150'],
            'notes'   => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('isin')) {
            $this->merge(['isin' => strtoupper(trim((string) $this->input('isin')))]);
        }
    }

    public function messages(): array
    {
        return [
            'isin.size'   => "L'ISIN té dotze caràcters.",
            'isin.regex'  => "L'ISIN comença amb dues lletres de país i acaba amb un dígit de control.",
            'isin.unique' => 'Ja hi ha un títol amb aquest ISIN.',
            'nom.required' => 'El nom del valor és obligatori.',
        ];
    }
}
