<?php

namespace App\Services;

use App\Models\CompteCorrent;
use App\Models\PatrimoniNota;
use Illuminate\Support\Facades\DB;

/**
 * Les notes que expliquen els salts del patrimoni mobiliari.
 *
 * El criteri d'una nota automàtica és **només l'import**: tot moviment d'un compte corrent
 * que passi del llindar. No mira ni categories ni conceptes a posta —a diferència de les
 * taxes i les assegurances, que els necessiten per saber de què parlen— perquè aquí la
 * pregunta no és de què és el moviment sinó si és prou gros per moure el patrimoni.
 *
 * Les notes **no es desen**: es dedueixen dels moviments cada cop. El que hi ha desat és el
 * que s'hi afegeix a mà —una descripció, o amagar-la— i les notes que no vénen de cap
 * moviment.
 */
class NotesPatrimoniService
{
    /**
     * El llindar més baix que la pantalla pot triar.
     *
     * S'envien totes les notes des d'aquí i el llindar es mou al client, com la resta de la
     * tria d'aquesta pantalla: són poc més de mil files i així cada canvi respon a l'instant.
     */
    public const MINIM = 1000;

    /** El llindar amb què obre la pantalla. */
    public const PER_DEFECTE = 3000;

    /**
     * Una fila per moviment gros i per nota escrita a mà, de la més recent a la més antiga.
     *
     * @return array<int, array<string, mixed>>
     */
    public function notes(): array
    {
        return collect([...$this->deMoviments(), ...$this->manuals()])
            ->sortByDesc('data')
            ->values()
            ->all();
    }

    /**
     * Els moviments que passen del llindar, amb el text que s'hi hagi afegit.
     *
     * @return array<int, array<string, mixed>>
     */
    private function deMoviments(): array
    {
        // Els titulars són els del compte del moviment: és el patrimoni de qui es mou
        $titularsPerCompte = CompteCorrent::where('tipus', 'corrent')
            ->with('titulars')
            ->get()
            ->mapWithKeys(fn (CompteCorrent $c) => [$c->id => [
                'nom'      => $c->nom ?? $c->compte_corrent,
                'titulars' => $c->titulars->pluck('id')->all(),
            ]]);

        $escrites = PatrimoniNota::whereNotNull('moviment_id')->get()->keyBy('moviment_id');

        return DB::table('g_moviments_comptes_corrents as m')
            ->leftJoin('g_moviments_conceptes as k', 'k.id', '=', 'm.concepte_id')
            ->whereIn('m.compte_corrent_id', $titularsPerCompte->keys())
            ->whereRaw('ABS(m.import) >= ?', [self::MINIM])
            ->select('m.id', 'm.data_moviment', 'm.import', 'm.compte_corrent_id', 'm.concepte_original', 'k.concepte')
            ->orderByDesc('m.data_moviment')
            ->get()
            ->map(function (object $m) use ($titularsPerCompte, $escrites) {
                $nota   = $escrites->get($m->id);
                $compte = $titularsPerCompte[$m->compte_corrent_id];

                return [
                    'clau'        => 'mov-' . $m->id,
                    'moviment_id' => $m->id,
                    // La posició d'on surt: si el compte es desmarca, la nota no compta
                    'compte_id'   => $m->compte_corrent_id,
                    'nota_id'     => $nota?->id,
                    'data'        => substr((string) $m->data_moviment, 0, 10),
                    // El títol surt del concepte del banc; la descripció és el que s'hi afegeix
                    'titol'       => $nota?->titol ?? $m->concepte ?? $m->concepte_original,
                    'descripcio'  => $nota?->descripcio,
                    'import'      => (float) $m->import,
                    'compte'      => $compte['nom'],
                    'titulars'    => $compte['titulars'],
                    'ocult'       => (bool) ($nota?->ocult ?? false),
                    'manual'      => false,
                ];
            })
            ->all();
    }

    /**
     * Les notes que no vénen de cap moviment: una revaloració, un fet de fora.
     *
     * Sense titulars val per a tothom, que és el que se sol voler d'un fet que afecta el
     * patrimoni sencer.
     *
     * @return array<int, array<string, mixed>>
     */
    private function manuals(): array
    {
        return PatrimoniNota::whereNull('moviment_id')
            ->with('titulars')
            ->get()
            ->map(fn (PatrimoniNota $n) => [
                'clau'        => 'nota-' . $n->id,
                'moviment_id' => null,
                'compte_id'   => null,
                'nota_id'     => $n->id,
                'data'        => $n->data?->toDateString(),
                'titol'       => $n->titol,
                'descripcio'  => $n->descripcio,
                'import'      => $n->import === null ? null : (float) $n->import,
                'compte'      => null,
                'titulars'    => $n->titulars->pluck('id')->all(),
                'ocult'       => $n->ocult,
                'manual'      => true,
            ])
            ->all();
    }
}
