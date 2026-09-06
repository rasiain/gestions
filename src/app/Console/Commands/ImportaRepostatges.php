<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Models\VehicleRepostatge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa els repostatges d'un CSV exportat del Numbers.
 *
 * El full porta columnes calculades —litres, increment de km, consum— que NO s'importen:
 * aquí es tornen a calcular a partir del que s'apunta, i comparar-les amb les del full és
 * la manera de saber que la importació és bona.
 */
class ImportaRepostatges extends Command
{
    protected $signature = 'vehicles:importa-repostatges
                            {vehicle : Id del vehicle}
                            {fitxer : CSV exportat del Numbers}
                            {--substitueix : Esborra els repostatges que ja hi hagi}
                            {--prova : Només comprova, no desa res}';

    protected $description = "Importa els repostatges d'un vehicle des d'un CSV";

    public function handle(): int
    {
        $vehicle = Vehicle::find($this->argument('vehicle'));

        if ($vehicle === null) {
            $this->error('No hi ha cap vehicle amb aquest id.');

            return self::FAILURE;
        }

        $fitxer = $this->argument('fitxer');

        if (! is_readable($fitxer)) {
            $this->error("No es pot llegir {$fitxer}.");

            return self::FAILURE;
        }

        $files = $this->llegeix($fitxer);

        if ($files === []) {
            $this->error('El fitxer no té cap fila que es pugui llegir.');

            return self::FAILURE;
        }

        $this->comprova($files);

        if ($this->option('prova')) {
            $this->info(count($files) . ' files llegides. Amb --prova no s\'ha desat res.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($vehicle, $files) {
            if ($this->option('substitueix')) {
                VehicleRepostatge::where('vehicle_id', $vehicle->id)->delete();
            }

            foreach ($files as $fila) {
                VehicleRepostatge::create([
                    'vehicle_id' => $vehicle->id,
                    'data'       => $fila['data'],
                    'km_totals'  => $fila['km_totals'],
                    'preu_litre' => $fila['preu_litre'],
                    'cost'       => $fila['cost'],
                    'benzinera'  => $fila['benzinera'],
                ]);
            }
        });

        $this->info(count($files) . " repostatges importats a «{$vehicle->nom}».");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function llegeix(string $fitxer): array
    {
        $files   = [];
        $columnes = null;

        foreach (file($fitxer, FILE_IGNORE_NEW_LINES) as $linia) {
            $trossos = str_getcsv($linia, ';');

            // La capçalera: el nom de la taula va en una línia a part
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

            if ($data === null || ($fila['km totals'] ?? '') === '') {
                continue;
            }

            $files[] = [
                'data'        => $data,
                'km_totals'   => (int) preg_replace('/\D/', '', $fila['km totals']),
                'preu_litre'  => $this->numero($fila['preu/litre'] ?? ''),
                'cost'        => $this->numero($fila['cost'] ?? ''),
                'benzinera'   => trim($fila['benzinera'] ?? '') ?: null,
                // Les del full, només per comprovar que el càlcul coincideix
                'litres_full' => $this->numero($fila['litres'] ?? ''),
                'consum_full' => $this->numero($fila['l/100km'] ?? ''),
                'km_full'     => $this->numero($fila['increm. km'] ?? ''),
            ];
        }

        // El full va del més recent al més antic; a la base de dades, a l'inrevés.
        // Hi ha dies amb dos repostatges: el comptador desempata, perquè només puja.
        usort($files, fn (array $a, array $b) => [$a['data'], $a['km_totals']] <=> [$b['data'], $b['km_totals']]);

        return $files;
    }

    /** «5/9/26 20:15» → «2026-09-05». L'hora no es desa: el full només l'usa per als dies passats. */
    private function data(string $text): ?string
    {
        if (! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})#', trim($text), $m)) {
            return null;
        }

        $any = (int) $m[3];
        $any += $any < 100 ? 2000 : 0;

        return sprintf('%04d-%02d-%02d', $any, (int) $m[2], (int) $m[1]);
    }

    /** Els números del full van amb coma decimal. */
    private function numero(string $text): ?float
    {
        $text = trim($text);

        return $text === '' ? null : (float) str_replace(',', '.', $text);
    }

    /**
     * Compara els càlculs propis amb les columnes calculades del full.
     *
     * @param  array<int, array<string, mixed>>  $files
     */
    private function comprova(array $files): void
    {
        $diferencies = 0;
        $anterior    = null;

        foreach ($files as $fila) {
            $litres = $fila['preu_litre'] > 0 ? round($fila['cost'] / $fila['preu_litre'], 2) : null;
            $km     = $anterior !== null ? $fila['km_totals'] - $anterior['km_totals'] : null;
            $consum = $km > 0 && $litres !== null ? round($litres / $km * 100, 2) : null;

            foreach ([
                ['litres', $litres, $fila['litres_full']],
                ['km', $km, $fila['km_full']],
                ['consum', $consum, $fila['consum_full']],
            ] as [$nom, $meu, $seu]) {
                if ($meu === null || $seu === null) {
                    continue;
                }

                // El full arrodoneix a dos decimals; hi cap un cèntim de diferència
                if (abs($meu - $seu) > 0.02) {
                    $diferencies++;
                    $this->warn("{$fila['data']} · {$nom}: calculat {$meu}, al full {$seu}");
                }
            }

            $anterior = $fila;
        }

        if ($diferencies === 0) {
            $this->info('Els litres, els quilòmetres i el consum calculats coincideixen amb els del full.');

            return;
        }

        $this->warn("{$diferencies} valors no coincideixen amb el full.");
    }
}
