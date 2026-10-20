<?php

namespace App\Console\Commands;

use App\Models\MaterialEsportiu;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * Importa el material esportiu de l'exportació d'una aplicació d'esport (avui Garmin).
 *
 * Es pot tornar a executar: cada peça es reconeix per l'origen i el seu identificador, i
 * s'actualitza en lloc de duplicar-se. Les bicis s'enllacen amb la del catàleg que es diu
 * igual. El tipus i l'enllaç d'una peça que ja hi és no es toquen: són classificació
 * nostra —Garmin posa quasi tots els components com a «Other»— i s'hi han pogut corregir.
 * D'una peça editada a mà (`editat`) tampoc el nom, el model, l'estat ni les dates.
 */
class ImportaMaterialEsportiu extends Command
{
    protected $signature = 'material:importa
                            {fitxer : ZIP de l\'exportació de Garmin, o el seu *_gear.json}
                            {--prova : Només mostra què faria, no desa res}';

    protected $description = "Importa el material esportiu (bicis, sabates, components) d'una exportació de Garmin";

    /** Tipus de Garmin → tipus propis. «Other» és el que Garmin no sap classificar. */
    private const TIPUS_GARMIN = [
        'Bike'           => 'bici',
        'Shoes'          => 'sabates',
        'Bike Component' => 'component',
        'Other'          => 'altres',
    ];

    public function handle(): int
    {
        $json = $this->llegeixGear($this->argument('fitxer'));

        if ($json === null) {
            return self::FAILURE;
        }

        $peces = $this->peces($json);
        $bicis = Vehicle::where('tipus', 'bici')->get();

        $files = [];
        foreach ($peces as &$peca) {
            $existent = MaterialEsportiu::where('origen', $peca['origen'])->where('id_extern', $peca['id_extern'])->first();

            if ($existent) {
                $peca['tipus'] = $existent->tipus;
            }

            // Una peça corregida a mà només rep el registre original: la resta és nostra
            if ($existent?->editat) {
                $peca = array_merge($peca, $existent->only(['nom', 'model', 'estat', 'data_inici', 'data_fi']));
                $peca['data_inici'] = $existent->data_inici?->toDateString();
                $peca['data_fi'] = $existent->data_fi?->toDateString();
            }

            if ($existent?->vehicle_id) {
                $peca['vehicle_id'] = $existent->vehicle_id;
            } elseif ($peca['tipus'] === 'bici') {
                $peca['vehicle_id'] = $this->bici($bicis, $peca)?->id;
            }

            $files[] = [
                $existent ? 'actualitza' : 'nova',
                $peca['tipus'],
                $peca['nom'],
                $peca['estat'],
                $peca['data_inici'] ?? '—',
                $peca['data_fi'] ?? '—',
                $peca['vehicle_id'] ? $bicis->firstWhere('id', $peca['vehicle_id'])?->nom : ($peca['tipus'] === 'bici' ? '⚠ cap' : ''),
            ];
        }
        unset($peca);

        $this->table(['', 'Tipus', 'Nom', 'Estat', 'Inici', 'Fi', 'Bici del catàleg'], $files);

        if ($this->option('prova')) {
            $this->info(count($peces) . ' peces llegides. Amb --prova no s\'ha desat res.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($peces) {
            foreach ($peces as $peca) {
                MaterialEsportiu::updateOrCreate(
                    ['origen' => $peca['origen'], 'id_extern' => $peca['id_extern']],
                    $peca,
                );
            }
        });

        $this->info(count($peces) . ' peces desades.');

        return self::SUCCESS;
    }

    /** El contingut del *_gear.json, del ZIP o del fitxer mateix. */
    private function llegeixGear(string $fitxer): ?array
    {
        if (! is_readable($fitxer)) {
            $this->error("No es pot llegir {$fitxer}.");

            return null;
        }

        $contingut = null;

        if (str_ends_with(strtolower($fitxer), '.zip')) {
            $zip = new ZipArchive();
            if ($zip->open($fitxer) !== true) {
                $this->error("{$fitxer} no és un ZIP vàlid.");

                return null;
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_ends_with($zip->getNameIndex($i), '_gear.json')) {
                    $contingut = $zip->getFromIndex($i);
                    break;
                }
            }
            $zip->close();

            if ($contingut === null) {
                $this->error("El ZIP no porta cap *_gear.json: no sembla una exportació de Garmin.");

                return null;
            }
        } else {
            $contingut = file_get_contents($fitxer);
        }

        $json = json_decode($contingut, true);

        if (! is_array($json)) {
            $this->error('El fitxer de material no és JSON vàlid.');

            return null;
        }

        return $json;
    }

    /**
     * Les peces de material en la forma de la taula.
     *
     * @return array<int, array<string, mixed>>
     */
    private function peces(array $json): array
    {
        // L'exportació és una llista d'un sol element amb tot a dins
        $gear = array_is_list($json) ? ($json[0] ?? []) : $json;

        return collect($gear['gearDTOS'] ?? [])->map(function (array $d) {
            // Garmin hi escriu entitats HTML: «Coberta davant -&gt; darrera»
            $text = fn (?string $t) => $t === null ? null : html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $model = $text($d['customMakeModel'] ?? null);

            return [
                'origen'          => 'garmin',
                'id_extern'       => (string) $d['gearPk'],
                'tipus'           => self::TIPUS_GARMIN[$d['gearTypeName'] ?? ''] ?? 'altres',
                // Garmin només té «displayName» quan s'ha posat un nom diferent del model
                'nom'             => $text($d['displayName'] ?? null) ?? $model ?? (string) $d['gearPk'],
                'model'           => $model,
                'estat'           => ($d['gearStatusName'] ?? '') === 'retired' ? 'retirat' : 'actiu',
                'data_inici'      => $d['dateBegin'] ?? null,
                'data_fi'         => $d['dateEnd'] ?? null,
                'vehicle_id'      => null,
                'dades_originals' => $d,
            ];
        })->all();
    }

    /** La bici del catàleg que es diu com la peça, pel nom o pel model de Garmin. */
    private function bici($bicis, array $peca): ?Vehicle
    {
        $normal = fn (?string $t) => mb_strtolower(trim((string) $t));

        return $bicis->first(fn (Vehicle $v) => in_array($normal($v->nom), [$normal($peca['nom']), $normal($peca['model'])], true));
    }
}
