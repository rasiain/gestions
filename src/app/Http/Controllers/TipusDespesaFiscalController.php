<?php

namespace App\Http\Controllers;

use App\Models\CategoriaLloguerFiscal;
use App\Models\TipusDespesaFiscal;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TipusDespesaFiscalController extends Controller
{
    private const CATEGORIES = [
        ['value' => 'comunitat',   'label' => 'Comunitat'],
        ['value' => 'taxes',       'label' => 'Taxes'],
        ['value' => 'assegurança', 'label' => 'Assegurança'],
        ['value' => 'compres',     'label' => 'Compres'],
        ['value' => 'reparacions', 'label' => 'Reparacions'],
        ['value' => 'gestoria',    'label' => 'Gestoria'],
        ['value' => 'comissions',  'label' => 'Comissions bancàries'],
        ['value' => 'altres',      'label' => 'Altres'],
    ];

    public function index()
    {
        $mapping = CategoriaLloguerFiscal::all()->keyBy('categoria');

        $categoriesAmbFiscal = array_map(function ($cat) use ($mapping) {
            $row = $mapping->get($cat['value']);
            return [
                'categoria'              => $cat['value'],
                'label'                  => $cat['label'],
                'tipus_despesa_fiscal_id' => $row?->tipus_despesa_fiscal_id,
            ];
        }, self::CATEGORIES);

        return Inertia::render('Impostos/TipusDespesaFiscal', [
            'categoriesMapping' => $categoriesAmbFiscal,
            'tipusDespesaOpcions' => TipusDespesaFiscal::orderBy('codi')->get(['id', 'codi', 'descripcio']),
        ]);
    }

    public function updateMapping(Request $request)
    {
        $categories = array_column(self::CATEGORIES, 'value');

        $request->validate([
            'categoria'               => ['required', 'string', 'in:' . implode(',', $categories)],
            'tipus_despesa_fiscal_id' => ['nullable', 'integer', 'exists:g_tipus_despesa_fiscal,id'],
        ]);

        CategoriaLloguerFiscal::updateOrCreate(
            ['categoria' => $request->input('categoria')],
            ['tipus_despesa_fiscal_id' => $request->input('tipus_despesa_fiscal_id')]
        );

        return back();
    }
}
