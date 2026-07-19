<?php

namespace App\Http\Services;

/**
 * Parseja el text enganxat de la posició de fons d'inversió de Caixa d'Enginyers.
 *
 * Cada registre té aquesta estructura (amb línies buides intercalades):
 *   3025 0016 16 8741010181          ← número de compte
 *   NORDEA GLB CLIMATE AND ENVIR...  ← nom del fons
 *   3.790,005000                     ← total participacions
 *   40,603700 €                      ← valor per participació
 *   (16/07/2026)                     ← data del valor
 *   153.888,22 €                     ← valoració
 *   ...
 *
 * Àncora robusta: el valor per participació és l'import en € seguit
 * immediatament d'una línia amb una data entre parèntesis.
 */
class FonsValorsPasteParser
{
    private const RE_COMPTE = '/^\d{4}\s+\d{4}\s+\d{2}\s+\d+$/';
    private const RE_IMPORT = '/^[\d.]+,\d+\s*€$/';
    private const RE_DATA   = '/^\((\d{2})\/(\d{2})\/(\d{4})\)$/';

    /**
     * @return array<int, array{compte: string, nom: string, data: string, valor_participacio: float}>
     */
    public function parse(string $text): array
    {
        // Neteja: trim per línia i descarta les buides.
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $text)),
            fn($l) => $l !== ''
        ));

        // Talla el text en blocs, un per número de compte.
        $blocks = [];
        $current = null;
        foreach ($lines as $line) {
            if (preg_match(self::RE_COMPTE, $line)) {
                if ($current !== null) {
                    $blocks[] = $current;
                }
                $current = [$line];
            } elseif ($current !== null) {
                $current[] = $line;
            }
        }
        if ($current !== null) {
            $blocks[] = $current;
        }

        $records = [];
        foreach ($blocks as $block) {
            $record = $this->parseBlock($block);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @param array<int, string> $block
     * @return array{compte: string, nom: string, data: string, valor_participacio: float}|null
     */
    private function parseBlock(array $block): ?array
    {
        $compte = $block[0];
        $nom    = $block[1] ?? '';

        // Busca el parell (import €, data) que defineix el valor per participació.
        for ($i = 1; $i < count($block) - 1; $i++) {
            if (preg_match(self::RE_IMPORT, $block[$i])
                && preg_match(self::RE_DATA, $block[$i + 1], $m)) {
                return [
                    'compte'             => $compte,
                    'nom'                => $nom,
                    'data'               => "{$m[3]}-{$m[2]}-{$m[1]}",
                    'valor_participacio' => $this->parseNumber($block[$i]),
                ];
            }
        }

        return null;
    }

    /** Converteix "3.790,005000 €" → 3790.005 (format espanyol). */
    private function parseNumber(string $raw): float
    {
        $clean = str_replace(['€', ' '], '', $raw);
        $clean = str_replace(['.', ','], ['', '.'], $clean);

        return (float) $clean;
    }
}
