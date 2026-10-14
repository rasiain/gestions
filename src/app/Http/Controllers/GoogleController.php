<?php

namespace App\Http\Controllers;

use App\Services\GmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Connexió amb el Gmail on es creen els esborranys de les factures. Es fa una vegada:
 * Google demana permís, torna aquí amb un codi i se'n desa el token de refresc.
 */
class GoogleController extends Controller
{
    public function connectar(Request $request, GmailService $gmail)
    {
        if (! $gmail->configurat()) {
            return $this->pagina(
                'Falta configurar Google',
                'Cal posar GOOGLE_CLIENT_ID i GOOGLE_CLIENT_SECRET al fitxer .env.'
            );
        }

        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away($gmail->urlAutoritzacio($state));
    }

    public function callback(Request $request, GmailService $gmail)
    {
        $state = $request->session()->pull('google_oauth_state');

        if (! $state || ! hash_equals($state, (string) $request->query('state'))) {
            return $this->pagina('No s\'ha pogut connectar', 'La resposta de Google no correspon a cap petició feta des d\'aquí. Torna-ho a provar.');
        }

        if ($request->query('error')) {
            return $this->pagina('No s\'ha connectat', 'Google diu: ' . $request->query('error'));
        }

        $connexio = $gmail->connectar((string) $request->query('code'));

        return $this->pagina(
            'Gmail connectat',
            "Els esborranys es crearan a {$connexio->email}. Ja pots tancar aquesta finestra i tornar a clicar «Correu»."
        );
    }

    /** Una pàgina mínima: s'obre a la finestra que havia de ser Gmail. */
    private function pagina(string $titol, string $text)
    {
        return response(
            '<!doctype html><meta charset="utf-8"><title>' . e($titol) . '</title>'
            . '<body style="font-family: system-ui, sans-serif; max-width: 36rem; margin: 4rem auto; padding: 0 1rem">'
            . '<h1 style="font-size: 1.25rem">' . e($titol) . '</h1><p>' . e($text) . '</p></body>'
        );
    }
}
