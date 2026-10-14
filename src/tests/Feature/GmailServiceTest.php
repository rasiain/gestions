<?php

namespace Tests\Feature;

use App\Models\GoogleConnexio;
use App\Services\GmailDesconnectat;
use App\Services\GmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * L'esborrany de la factura es crea a Gmail amb el PDF adjunt: un enllaç de Gmail no pot
 * portar fitxers. Google se simula; el que es comprova és què se li envia.
 */
class GmailServiceTest extends TestCase
{
    use RefreshDatabase;

    private const DADES = [
        'to'       => 'llogater@example.com',
        'cc'       => null,
        'assumpte' => 'Factura novembre 2026 - Lloguer local Joan Maragall 33',
        'cos'      => "Bon dia,\n\nUs adjuntem la factura 202611, d'import 19.268,54 €.",
    ];

    private function connexio(bool $caducat = false): GoogleConnexio
    {
        return GoogleConnexio::create([
            'email'               => 'qui@gmail.com',
            'refresh_token'       => 'refresc',
            'access_token'        => 'acces',
            'access_token_expira' => $caducat ? now()->subMinute() : now()->addHour(),
        ]);
    }

    private static function raw(Request $peticio): string
    {
        return base64_decode(strtr($peticio['message']['raw'], '-_', '+/'));
    }

    public function test_crea_l_esborrany_amb_el_pdf_adjunt_i_torna_on_obrir_lo(): void
    {
        $this->connexio();
        Http::fake(['gmail.googleapis.com/*' => Http::response(['id' => 'r-1', 'message' => ['id' => '19a2b3c4d5']])]);

        $url = app(GmailService::class)->crearEsborrany(self::DADES, '%PDF-contingut', 'Factura 202611.pdf');

        $this->assertSame('https://mail.google.com/mail/?authuser=qui%40gmail.com#drafts?compose=19a2b3c4d5', $url);

        Http::assertSent(function (Request $peticio) {
            $raw = self::raw($peticio);
            // Els blocs en base64 van tallats a 76 caràcters per línia
            $sencer = str_replace("\r\n", '', $raw);

            return $peticio->hasHeader('Authorization', 'Bearer acces')
                && str_contains($raw, 'To: llogater@example.com')
                && ! str_contains($raw, 'Cc:')
                && str_contains($raw, '=?UTF-8?B?' . base64_encode(self::DADES['assumpte']) . '?=')
                && str_contains($sencer, base64_encode(self::DADES['cos']))
                && str_contains($raw, 'filename="Factura 202611.pdf"')
                && str_contains($sencer, base64_encode('%PDF-contingut'));
        });
    }

    public function test_sense_destinatari_l_esborrany_es_crea_igualment(): void
    {
        $this->connexio();
        Http::fake(['gmail.googleapis.com/*' => Http::response(['message' => ['id' => 'x']])]);

        app(GmailService::class)->crearEsborrany(['to' => null] + self::DADES, '%PDF', 'f.pdf');

        Http::assertSent(fn (Request $peticio) => ! str_contains(self::raw($peticio), 'To:'));
    }

    public function test_renova_el_token_caducat_abans_de_crear_l_esborrany(): void
    {
        $connexio = $this->connexio(caducat: true);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'nou', 'expires_in' => 3600]),
            'gmail.googleapis.com/*'      => Http::response(['message' => ['id' => 'x']]),
        ]);

        app(GmailService::class)->crearEsborrany(self::DADES, '%PDF', 'f.pdf');

        Http::assertSent(fn (Request $peticio) => str_contains($peticio->url(), 'gmail.googleapis.com')
            && $peticio->hasHeader('Authorization', 'Bearer nou'));
        $this->assertSame('nou', $connexio->fresh()->access_token);
    }

    public function test_si_google_ha_retirat_el_permis_la_connexio_s_esborra(): void
    {
        $this->connexio(caducat: true);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        try {
            app(GmailService::class)->crearEsborrany(self::DADES, '%PDF', 'f.pdf');
            $this->fail('Havia de dir que Gmail està desconnectat');
        } catch (GmailDesconnectat) {
            $this->assertSame(0, GoogleConnexio::count());
        }
    }

    public function test_sense_connexio_diu_que_cal_connectar(): void
    {
        Http::fake();

        $this->expectException(GmailDesconnectat::class);

        app(GmailService::class)->crearEsborrany(self::DADES, '%PDF', 'f.pdf');
    }
}
