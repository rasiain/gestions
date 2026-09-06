<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Models\VehicleDespesa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa les despeses d'un vehicle —reparacions, ITV, assegurança, impost— d'un CSV
 * exportat del Numbers.
 */
class ImportaDespesesVehicle extends Command
{
    protected $signature = 'vehicles:importa-despeses
                            {vehicle : Id del vehicle}
                            {fitxer : CSV exportat del Numbers}
                            {--substitueix : Esborra les despeses que ja hi hagi}
                            {--prova : Només comprova, no desa res}';

    protected $description = "Importa les despeses d'un vehicle des d'un CSV";

    /** Com s'escriuen al full els tipus que fem servir. */
    private const TIPUS = [
        'reparació/revisió'   => 'reparacio',
        'reparacio/revisio'   => 'reparacio',
        'itv'                 => 'itv',
        'assegurança'         => 'asseguranca',
        'asseguranca'         => 'asseguranca',
        'impost circulació'   => 'impost_circulacio',
        'impost circulacio'   => 'impost_circulacio',
    ];

    public function handle(): int
    {
        $vehicle = Vehicle::find($this->argument('vehicle'));

        if ($vehicle === null) {
            $this->error('No hi ha cap vehicle amb aquest id.');

            return self::FAILURE;
        }

        if (! is_readable($this->argument('fitxer'))) {
            $this->error('No es pot llegir el fitxer.');

            return self::FAILURE;
        }

        $files = $this->llegeix($this->argument('fitxer'));

        if ($files === []) {
            $this->error('El fitxer no té cap fila que es pugui llegir.');

            return self::FAILURE;
        }

        $this->table(
            ['Tipus', 'Files', 'Import'],
            collect($files)->groupBy('tipus')
                ->map(fn ($grup, $tipus) => [
                    VehicleDespesa::TIPUS[$tipus] ?? $tipus,
                    $grup->count(),
                    number_format($grup->sum('import'), 2, ',', '.') . ' €',
                ])->values()->all(),
        );

        if ($this->option('prova')) {
            $this->info(count($files) . " files llegides. Amb --prova no s'ha desat res.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($vehicle, $files) {
            if ($this->option('substitueix')) {
                VehicleDespesa::where('vehicle_id', $vehicle->id)->delete();
            }

            foreach ($files as $fila) {
                VehicleDespesa::create(['vehicle_id' => $vehicle->id] + $fila);
            }
        });

        $this->info(count($files) . " despeses importades a «{$vehicle->nom}».");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function llegeix(string $fitxer): array
    {
        $files    = [];
        $columnes = null;

        foreach (file($fitxer, FILE_IGNORE_NEW_LINES) as $linia) {
            $trossos = str_getcsv($linia, ';');

            if ($columnes === null) {
                if (count($trossos) > 1 && mb_strtolower(trim($trossos[0])) === 'data') {
                    $columnes = array_map(fn ($c) => mb_strtolower(trim($c)), $trossos);
                }

                continue;
            }

            $fila = array_combine(
                array_slice($columnes, 0, count($trossos)),
                array_slice($trossos, 0, count($columnes)),
            );

            $data = $this->data($fila['data'] ?? '');

            if ($data === null) {
                continue;
            }

            $km = preg_replace('/\D/', '', $fila['km totals'] ?? '');

            $files[] = [
                'data'      => $data,
                // Una fila sense tipus és una compra per al cotxe: va a «altres»
                'tipus'     => self::TIPUS[mb_strtolower(trim($fila['tipus'] ?? ''))] ?? 'altres',
                'import'    => (float) str_replace(',', '.', trim($fila['preu'] ?? '0')),
                'km_totals' => $km !== '' ? (int) $km : null,
                'taller'    => trim($fila['taller'] ?? '') ?: null,
                'motiu'     => trim($fila['motiu'] ?? '') ?: null,
            ];
        }

        usort($files, fn (array $a, array $b) => $a['data'] <=> $b['data']);

        return $files;
    }

    /** «31/7/26» → «2026-07-31». */
    private function data(string $text): ?string
    {
        if (! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})#', trim($text), $m)) {
            return null;
        }

        $any = (int) $m[3];
        $any += $any < 100 ? 2000 : 0;

        return sprintf('%04d-%02d-%02d', $any, (int) $m[2], (int) $m[1]);
    }
}
