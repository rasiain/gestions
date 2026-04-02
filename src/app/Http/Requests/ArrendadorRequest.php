<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ArrendadorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'arrendadorable_type' => ['required', 'string', Rule::in(['persona', 'comunitat_bens'])],
            'arrendadorable_id'   => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'arrendadorable_type.required' => 'El tipus d\'arrendador és obligatori.',
            'arrendadorable_type.in'       => 'El tipus d\'arrendador ha de ser persona o comunitat_bens.',
            'arrendadorable_id.required'   => 'L\'identificador de l\'arrendador és obligatori.',
            'arrendadorable_id.integer'    => 'L\'identificador de l\'arrendador ha de ser un número enter.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $type = $this->input('arrendadorable_type');
            $id   = $this->input('arrendadorable_id');

            if (!$type || !$id) {
                return;
            }

            $modelClass = match ($type) {
                'persona'        => \App\Models\Persona::class,
                'comunitat_bens' => \App\Models\ComunitatBens::class,
                default          => null,
            };

            if ($modelClass && !$modelClass::find($id)) {
                $validator->errors()->add('arrendadorable_id', 'El registre seleccionat no existeix.');
            }
        });
    }
}
