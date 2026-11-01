<?php

namespace App\Services;

use App\Models\Preferencia;
use App\Models\SalutRegistre;
use RuntimeException;
use XMLReader;

/**
 * La càrrega inicial de les dades físiques des de l'exportació d'Apple Salut (`exportació.xml`).
 *
 * En surt **un registre per mes**, datat el 15: la mediana del pes (de MyTanita, i si el mes no en té,
 * de Garmin Connect), la del % de greix (només de MyTanita, que és qui el mesura) i la de la pulsació en
 * repòs diària. Abans es descarten les pesades que s'aparten
 * més d'un 10 % de la mediana dels 60 dies del voltant: a la Tanita s'hi pesa algú altre de tant en tant.
 *
 * No és cap integració: l'usuari manté les dades a mà, i això només serveix per començar (o per
 * tornar-ho a fer). Per això només toca els registres que va crear ella (`origen = apple_salut`).
 */
class ImportacioAppleSalutService
{
    public const ORIGEN = 'apple_salut';

    /** Les pesades que s'aparten més d'això de les del voltant són d'una altra persona. */
    public const DESVIACIO_MAXIMA = 0.10;

    /** El mateix per al greix, en punts: d'un dia a l'altre la bioimpedància en varia un o dos. */
    public const DESVIACIO_MAXIMA_GREIX = 5.0;

    private const GREIX = 'HKQuantityTypeIdentifierBodyFatPercentage';

    private const PES = 'HKQuantityTypeIdentifierBodyMass';

    private const FC_REPOS = 'HKQuantityTypeIdentifierRestingHeartRate';

    private const ALCADA = 'HKQuantityTypeIdentifierHeight';

    /** Per ordre de preferència: la bàscula primer. */
    private const FONTS_PES = ['MyTanita', 'Connect'];

    /**
     * Llegeix l'XML en flux (fa més de 100 MB i creix).
     *
     * @return array{pesades: array<string, array<int, array{0: string, 1: float}>>, greix: array<int, array{0: string, 1: float}>, fc_repos: array<int, array{0: string, 1: int}>, naixement: ?string, alcada: ?int}
     *         pesades per font, [dia, kg]; greix, [dia, %]; fc en repòs, [dia, ppm]
     */
    public function llegeix(string $fitxer, string $desDe): array
    {
        $xml = new XMLReader;
        if (! @$xml->open($fitxer)) {
            throw new RuntimeException("No es pot obrir {$fitxer}");
        }

        $pesades = [];
        $greix = [];
        $fcRepos = [];
        $naixement = null;
        $alcada = null;

        while ($xml->read()) {
            if ($xml->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($xml->name === 'Me') {
                $naixement = $xml->getAttribute('HKCharacteristicTypeIdentifierDateOfBirth') ?: null;

                continue;
            }
            if ($xml->name !== 'Record') {
                continue;
            }

            $tipus = $xml->getAttribute('type');
            // L'alçada, la darrera que hi hagi, sense mirar la data: no canvia
            if ($tipus === self::ALCADA) {
                $valor = (float) $xml->getAttribute('value');
                $alcada = (int) round($xml->getAttribute('unit') === 'm' ? $valor * 100 : $valor);

                continue;
            }
            if ($tipus !== self::PES && $tipus !== self::GREIX && $tipus !== self::FC_REPOS) {
                continue;
            }

            // «2025-01-06 09:25:00 +0100»: el dia, en hora local
            $dia = substr((string) $xml->getAttribute('startDate'), 0, 10);
            if ($dia < $desDe) {
                continue;
            }
            $valor = (float) $xml->getAttribute('value');

            if ($tipus === self::PES) {
                $font = (string) $xml->getAttribute('sourceName');
                if ($xml->getAttribute('unit') === 'lb') {
                    $valor *= 0.45359237;
                }
                if (in_array($font, self::FONTS_PES, true)) {
                    $pesades[$font][] = [$dia, $valor];
                }
            } elseif ($tipus === self::GREIX) {
                // Salut el desa com a fracció (0,212 = 21,2 %)
                if ($xml->getAttribute('sourceName') === 'MyTanita') {
                    $greix[] = [$dia, $valor <= 1 ? $valor * 100 : $valor];
                }
            } else {
                $fcRepos[] = [$dia, (int) round($valor)];
            }
        }
        $xml->close();

        return ['pesades' => $pesades, 'greix' => $greix, 'fc_repos' => $fcRepos, 'naixement' => $naixement, 'alcada' => $alcada];
    }

    /**
     * Un registre per mes amb la mediana de cada dada.
     *
     * @param  array{pesades: array<string, array<int, array{0: string, 1: float}>>, greix?: array<int, array{0: string, 1: float}>, fc_repos: array<int, array{0: string, 1: int}>}  $llegit
     * @return array{mesos: array<string, array{pes: ?float, greix: ?float, fc_repos: ?int, pesades: int}>, descartades: array<int, array{0: string, 1: float, 2: string}>}
     *         descartades: [dia, valor, font o «greix»]
     */
    public function perMes(array $llegit): array
    {
        $mesos = [];
        $descartades = [];

        $perFont = [];
        foreach (self::FONTS_PES as $font) {
            [$bones, $fora] = $this->descartaEstranyes($llegit['pesades'][$font] ?? []);
            foreach ($fora as [$dia, $kg]) {
                $descartades[] = [$dia, $kg, $font];
            }
            foreach ($bones as [$dia, $kg]) {
                $perFont[$font][substr($dia, 0, 7)][] = $kg;
            }
        }

        $greixPerMes = [];
        [$bones, $fora] = $this->descartaEstranyes($llegit['greix'] ?? [], absoluta: self::DESVIACIO_MAXIMA_GREIX);
        foreach ($fora as [$dia, $pct]) {
            $descartades[] = [$dia, $pct, 'greix'];
        }
        foreach ($bones as [$dia, $pct]) {
            $greixPerMes[substr($dia, 0, 7)][] = $pct;
        }

        $nomesMes = fn (array $files) => array_unique(array_map(fn ($f) => substr($f[0], 0, 7), $files));
        $claus = array_unique(array_merge(
            ...array_map(fn ($f) => array_keys($f), array_values($perFont)),
            ...[$nomesMes($llegit['fc_repos']), array_keys($greixPerMes)],
        ));
        sort($claus);

        $fcPerMes = [];
        foreach ($llegit['fc_repos'] as [$dia, $fc]) {
            $fcPerMes[substr($dia, 0, 7)][] = $fc;
        }

        foreach ($claus as $mes) {
            // La primera font que en té, aquell mes
            $kg = null;
            $n = 0;
            foreach (self::FONTS_PES as $font) {
                if (! empty($perFont[$font][$mes])) {
                    $kg = round($this->mediana($perFont[$font][$mes]), 1);
                    $n = count($perFont[$font][$mes]);
                    break;
                }
            }
            $fc = isset($fcPerMes[$mes]) ? (int) round($this->mediana($fcPerMes[$mes])) : null;

            $pct = isset($greixPerMes[$mes]) ? round($this->mediana($greixPerMes[$mes]), 1) : null;

            $mesos[$mes] = ['pes' => $kg, 'greix' => $pct, 'fc_repos' => $fc, 'pesades' => $n];
        }

        return ['mesos' => $mesos, 'descartades' => $descartades];
    }

    /**
     * Desa els mesos: crea o actualitza els registres d'Apple Salut, i mai no toca els manuals (si un
     * mes en té un el mateix dia, se salta).
     *
     * @param  array<string, array{pes: ?float, greix?: ?float, fc_repos: ?int}>  $mesos
     * @return array{creats: int, actualitzats: int, saltats: int}
     */
    public function desa(array $mesos, ?string $naixement, ?int $alcada = null): array
    {
        $compte = ['creats' => 0, 'actualitzats' => 0, 'saltats' => 0];

        foreach ($mesos as $mes => $m) {
            $dia = "{$mes}-15";
            $existent = SalutRegistre::whereDate('data', $dia)->first();
            if ($existent && $existent->origen !== self::ORIGEN) {
                $compte['saltats']++;

                continue;
            }

            $dades = ['pes' => $m['pes'], 'greix' => $m['greix'] ?? null, 'fc_repos' => $m['fc_repos'], 'origen' => self::ORIGEN];
            if ($existent) {
                $existent->update($dades);
                $compte['actualitzats']++;
            } else {
                SalutRegistre::create(['data' => $dia] + $dades);
                $compte['creats']++;
            }
        }

        // La data de naixement i l'alçada, només si el perfil encara no en té: les del perfil manen
        $perfil = (array) Preferencia::valor(DadesFisiquesService::PREFERENCIA_PERFIL, []);
        $nou = $perfil;
        if ($naixement && empty($perfil['data_naixement'])) {
            $nou['data_naixement'] = $naixement;
        }
        if ($alcada && empty($perfil['alcada'])) {
            $nou['alcada'] = $alcada;
        }
        if ($nou !== $perfil) {
            Preferencia::desa(DadesFisiquesService::PREFERENCIA_PERFIL, $nou);
        }

        return $compte;
    }

    /**
     * Separa les mesures que s'aparten massa de la mediana dels 60 dies del voltant: en proporció (el pes)
     * o en punts (el greix, que ja és un percentatge).
     *
     * @param  array<int, array{0: string, 1: float}>  $pesades
     * @return array{0: array<int, array{0: string, 1: float}>, 1: array<int, array{0: string, 1: float}>}
     */
    private function descartaEstranyes(array $pesades, ?float $absoluta = null): array
    {
        $bones = [];
        $fora = [];
        foreach ($pesades as [$dia, $kg]) {
            $t = strtotime($dia);
            $veines = array_map(fn ($p) => $p[1], array_filter($pesades, fn ($p) => abs(strtotime($p[0]) - $t) <= 30 * 86400));
            $referencia = $this->mediana($veines);
            $lluny = $absoluta !== null ? abs($kg - $referencia) > $absoluta : abs($kg - $referencia) / $referencia > self::DESVIACIO_MAXIMA;
            if ($lluny) {
                $fora[] = [$dia, $kg];
            } else {
                $bones[] = [$dia, $kg];
            }
        }

        return [$bones, $fora];
    }

    /** @param  array<int, float|int>  $valors */
    private function mediana(array $valors): float
    {
        sort($valors);
        $n = count($valors);
        $mig = intdiv($n, 2);

        return $n % 2 ? (float) $valors[$mig] : ($valors[$mig - 1] + $valors[$mig]) / 2;
    }
}
