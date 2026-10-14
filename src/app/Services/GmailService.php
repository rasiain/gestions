<?php

namespace App\Services;

use App\Models\GoogleConnexio;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Crea esborranys al Gmail de qui envia les factures, amb el PDF ja adjunt.
 *
 * Un enllaç de Gmail pot portar destinatari, assumpte i cos, però no cap fitxer, i a més
 * s'obre en una pàgina de redacció sola, sense la resta de Gmail. Creant l'esborrany per
 * l'API, l'adjunt hi és i el que s'obre és Gmail sencer amb l'esborrany a sobre.
 *
 * Només demana `gmail.compose`: pot crear esborranys, però no llegir el correu.
 */
class GmailService
{
    public const SCOPE = 'https://www.googleapis.com/auth/gmail.compose';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function configurat(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    public function connexio(): ?GoogleConnexio
    {
        return GoogleConnexio::first();
    }

    public function urlAutoritzacio(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => config('services.google.client_id'),
            'redirect_uri'  => config('services.google.redirect'),
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            // Sense «offline» no hi ha token de refresc, i sense «consent» Google no el torna
            // a donar si ja s'havia autoritzat abans.
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);
    }

    /** Canvia el codi de l'autorització pels tokens i desa la connexió (n'hi ha una de sola). */
    public function connectar(string $codi): GoogleConnexio
    {
        $resposta = Http::asForm()->post(self::TOKEN_URL, [
            'code'          => $codi,
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri'  => config('services.google.redirect'),
            'grant_type'    => 'authorization_code',
        ]);

        if ($resposta->failed() || ! $resposta->json('refresh_token')) {
            throw new RuntimeException('Google no ha tornat cap token: ' . $resposta->body());
        }

        GoogleConnexio::query()->delete();

        $connexio = GoogleConnexio::create([
            'refresh_token'       => $resposta->json('refresh_token'),
            'access_token'        => $resposta->json('access_token'),
            'access_token_expira' => now()->addSeconds((int) $resposta->json('expires_in', 3600) - 60),
        ]);

        $perfil = Http::withToken($connexio->access_token)->get(self::API . '/profile');
        $connexio->update(['email' => $perfil->json('emailAddress')]);

        return $connexio;
    }

    /**
     * Crea l'esborrany i en torna l'adreça per obrir-lo a Gmail.
     *
     * @param  array{to: ?string, cc: ?string, assumpte: string, cos: string}  $dades
     */
    public function crearEsborrany(array $dades, string $pdf, string $nomFitxer): string
    {
        $connexio = $this->connexio() ?? throw new GmailDesconnectat();

        $resposta = Http::withToken($this->accessToken($connexio))
            ->post(self::API . '/drafts', [
                'message' => ['raw' => self::base64url($this->mime($dades, $pdf, $nomFitxer))],
            ]);

        if ($resposta->status() === 401) {
            throw new GmailDesconnectat();
        }

        if ($resposta->failed()) {
            throw new RuntimeException('Gmail no ha creat l\'esborrany: ' . $resposta->body());
        }

        $missatge = $resposta->json('message.id');

        // Amb «authuser» s'obre el compte connectat encara que al navegador n'hi hagi d'altres
        return 'https://mail.google.com/mail/?' . http_build_query(['authuser' => $connexio->email])
            . '#drafts?compose=' . $missatge;
    }

    /** El token d'accés, renovat si ha caducat. */
    private function accessToken(GoogleConnexio $connexio): string
    {
        if ($connexio->access_token && $connexio->access_token_expira?->isFuture()) {
            return $connexio->access_token;
        }

        $resposta = Http::asForm()->post(self::TOKEN_URL, [
            'refresh_token' => $connexio->refresh_token,
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'grant_type'    => 'refresh_token',
        ]);

        // Permís retirat o token caducat: no es pot fer res sense tornar a autoritzar
        if ($resposta->json('error') === 'invalid_grant') {
            $connexio->delete();
            throw new GmailDesconnectat();
        }

        if ($resposta->failed()) {
            throw new RuntimeException('No s\'ha pogut renovar el token de Google: ' . $resposta->body());
        }

        $connexio->update([
            'access_token'        => $resposta->json('access_token'),
            'access_token_expira' => now()->addSeconds((int) $resposta->json('expires_in', 3600) - 60),
        ]);

        return $connexio->access_token;
    }

    /**
     * El missatge en MIME: el text i el PDF. Els camps buits no hi van —un esborrany sense
     * destinatari també és un esborrany, i el destinatari l'escriu qui l'envia.
     */
    public function mime(array $dades, string $pdf, string $nomFitxer): string
    {
        $limit = 'gestions-' . Str::random(24);

        $capcaleres = array_filter([
            'To'           => $dades['to'] ?? null,
            'Cc'           => $dades['cc'] ?? null,
            'Subject'      => '=?UTF-8?B?' . base64_encode($dades['assumpte'] ?? '') . '?=',
            'MIME-Version' => '1.0',
            'Content-Type' => "multipart/mixed; boundary=\"{$limit}\"",
        ]);

        $linies = [];
        foreach ($capcaleres as $nom => $valor) {
            $linies[] = "{$nom}: {$valor}";
        }

        $nomAscii = Str::ascii($nomFitxer);
        $nomUtf8 = rawurlencode($nomFitxer);

        return implode("\r\n", $linies) . "\r\n\r\n"
            . "--{$limit}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($dades['cos'] ?? ''), 76, "\r\n")
            . "--{$limit}\r\n"
            . "Content-Type: application/pdf; name=\"{$nomAscii}\"\r\n"
            . "Content-Disposition: attachment; filename=\"{$nomAscii}\"; filename*=UTF-8''{$nomUtf8}\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($pdf), 76, "\r\n")
            . "--{$limit}--\r\n";
    }

    private static function base64url(string $valor): string
    {
        return rtrim(strtr(base64_encode($valor), '+/', '-_'), '=');
    }
}
