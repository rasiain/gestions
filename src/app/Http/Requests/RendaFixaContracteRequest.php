<?php

namespace App\Http\Requests;

use App\Models\RendaFixaTitol;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Un contracte de renda fixa, amb el seu títol.
 *
 * El títol es pot triar del catàleg (`titol_id`) o escriure aquí mateix (`isin`, `nom` i
 * `emissor`): apuntar una compra és un sol gest i no dos, i el catàleg queda per consultar-lo.
 * Un ISIN que ja hi és no és cap error, és el mateix producte: s'hi reaprofita el títol.
 */
class RendaFixaContracteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'titol_id' => ['nullable', 'integer', 'exists:g_rf_titols,id', 'required_without:isin'],
            // L'ISIN són dues lletres de país, nou caràcters i un dígit de control
            'isin'     => ['nullable', 'string', 'size:12', 'regex:/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/', 'required_without:titol_id'],
            // El nom només cal per a un títol que encara no hi és: d'un ISIN conegut ja se sap
            'nom'      => ['nullable', 'string', 'max:200', Rule::requiredIf(fn () => $this->filled('isin') && ! $this->isinConegut())],
            'emissor'  => ['nullable', 'string', 'max:150'],

            'compte_corrent_id'      => ['required', 'integer', 'exists:g_comptes_corrents,id'],
            // On arriben els cupons: sol ser un compte corrent i no el del títol
            'compte_rendibilitat_id' => ['nullable', 'integer', 'exists:g_comptes_corrents,id'],
            'nominal'                => ['required', 'numeric', 'min:0'],
            'data_compra'            => ['nullable', 'date'],
            'data_venciment'         => ['nullable', 'date', 'after_or_equal:data_compra'],
            'notes'                  => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('isin')) {
            $this->merge(['isin' => strtoupper(trim((string) $this->input('isin')))]);
        }
    }

    private function isinConegut(): bool
    {
        return RendaFixaTitol::where('isin', $this->input('isin'))->exists();
    }

    public function messages(): array
    {
        return [
            'titol_id.required_without' => 'Tria un títol del catàleg o escriu-ne l\'ISIN.',
            'isin.required_without'     => 'Tria un títol del catàleg o escriu-ne l\'ISIN.',
            'isin.size'                 => "L'ISIN té dotze caràcters.",
            'isin.regex'                => "L'ISIN comença amb dues lletres de país i acaba amb un dígit de control.",
            'nom.required'              => 'El nom del valor és obligatori.',
            'data_venciment.after_or_equal' => 'El venciment no pot ser anterior a la compra.',
        ];
    }

    /**
     * El que va a `g_rf_contractes`: el títol s'hi resol a part.
     *
     * @return array<string, mixed>
     */
    public function dadesDelContracte(): array
    {
        return collect($this->validated())
            ->except(['titol_id', 'isin', 'nom', 'emissor'])
            ->all();
    }
}
