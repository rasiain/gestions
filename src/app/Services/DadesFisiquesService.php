<?php

namespace App\Services;

use App\Models\Preferencia;
use App\Models\SalutRegistre;
use App\Models\SortidaBici;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Les dades físiques vigents un dia, que són les entrades del càlcul de l'estat de forma.
 *
 * Cada dada val des de la seva data fins al registre següent que la porti, **camp a camp**: un
 * registre amb només el pes no anul·la la pulsació en repòs de l'anterior. Abans del primer
 * registre s'hi val el primer valor conegut, i es diu.
 *
 * La pulsació màxima, si no s'ha apuntat, es dedueix de les sortides: la 5a més alta dels 365 dies
 * anteriors (el màxim absolut té punxes), i si no n'hi ha prou, la de l'any d'abans; com a últim
 * recurs, la fórmula de Tanaka amb l'edat del perfil, que acostuma a quedar curta.
 */
class DadesFisiquesService
{
    public const PREFERENCIA_PERFIL = 'salut.perfil';

    /** Quina de les més altes es pren: la 5a, per no fer cas de les punxes. */
    public const POSICIO_FC_MAXIMA = 5;

    /** Sortides amb pulsació que calen en un any perquè la deduïda sigui fiable. */
    public const MINIM_SORTIDES = 20;

    /** @var Collection<int, SalutRegistre>|null */
    private ?Collection $registres = null;

    /** @var array<int, array{0: string, 1: int}>|null data i fc màxima de cada sortida, per data */
    private ?array $maximes = null;

    /** @return array{data_naixement: ?string, sexe: ?string, alcada: ?int} alçada en cm, per a l'IMC */
    public function perfil(): array
    {
        return array_merge(['data_naixement' => null, 'sexe' => null, 'alcada' => null], (array) Preferencia::valor(self::PREFERENCIA_PERFIL, []));
    }

    /**
     * El valor d'un camp vigent un dia.
     *
     * @return array{valor: float|int, data: string, anterior: bool}|null  `anterior`: el dia és abans del
     *         primer registre que el porta, i s'hi ha aplicat aquest
     */
    public function valorA(string $camp, CarbonInterface $dia): ?array
    {
        $amb = $this->registres()->filter(fn (SalutRegistre $r) => $r->{$camp} !== null);
        $registre = $amb->last(fn (SalutRegistre $r) => $r->data->lte($dia));
        $anterior = false;
        if (! $registre) {
            $registre = $amb->first();
            $anterior = true;
        }

        return $registre ? ['valor' => $registre->{$camp}, 'data' => $registre->data->toDateString(), 'anterior' => $anterior] : null;
    }

    /**
     * La pulsació màxima vigent un dia: l'apuntada, o la deduïda.
     *
     * @return array{valor: int, origen: string, data?: string}|null  origen: `registre`, `sortides` o `tanaka`
     */
    public function fcMaxima(CarbonInterface $dia): ?array
    {
        $apuntada = $this->valorA('fc_maxima', $dia);
        if ($apuntada && ! $apuntada['anterior']) {
            return ['valor' => (int) $apuntada['valor'], 'origen' => 'registre', 'data' => $apuntada['data']];
        }

        return $this->fcMaximaDeduida($dia);
    }

    /** @return array{valor: int, origen: string}|null */
    public function fcMaximaDeduida(CarbonInterface $dia): ?array
    {
        // L'any que acaba el dia; si no té prou sortides, el d'abans
        foreach ([0, 1] as $enrere) {
            $fins = Carbon::parse($dia)->subYears($enrere);
            $valors = $this->maximesEntre($fins->copy()->subDays(365), $fins);
            if (count($valors) >= self::MINIM_SORTIDES) {
                rsort($valors);

                return ['valor' => $valors[self::POSICIO_FC_MAXIMA - 1], 'origen' => 'sortides'];
            }
        }

        $naixement = $this->perfil()['data_naixement'];
        if ($naixement) {
            $edat = Carbon::parse($naixement)->diffInYears($dia);

            return ['valor' => (int) round(208 - 0.7 * $edat), 'origen' => 'tanaka'];
        }

        return null;
    }

    /** Oblida el que té llegit: després de desar un registre o el perfil. */
    public function oblida(): void
    {
        $this->registres = null;
        $this->maximes = null;
    }

    /** @return Collection<int, SalutRegistre> */
    private function registres(): Collection
    {
        return $this->registres ??= SalutRegistre::orderBy('data')->get();
    }

    /** @return array<int, int> les fc màximes de les sortides de (des de, fins a] */
    private function maximesEntre(CarbonInterface $des, CarbonInterface $fins): array
    {
        $this->maximes ??= SortidaBici::whereNotNull('fc_max')->orderBy('data_local')
            ->get(['data_local', 'fc_max'])
            ->map(fn (SortidaBici $s) => [$s->data_local->toDateString(), (int) $s->fc_max])
            ->all();

        $d = $des->toDateString();
        $f = $fins->toDateString();

        return array_values(array_map(fn ($m) => $m[1], array_filter($this->maximes, fn ($m) => $m[0] > $d && $m[0] <= $f)));
    }
}
