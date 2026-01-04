<?php

namespace App\Http\Services\ImportFiles;

use DateTime;

abstract class AbstractMovementParserService
{
    /**
     * Parse file content and return array of movements.
     *
     * @param mixed $input File content (array for XLS, string for QIF)
     * @param int $compteCorrentId
     * @return array Array of movements
     */
    abstract public function parse($input, int $compteCorrentId): array;

    /**
     * Check if this parser supports the given bank type.
     *
     * @param string $bankType
     * @return bool
     */
    abstract public function supports(string $bankType): bool;

    /**
     * Normalize date from various formats to Y-m-d.
     * Supports: DD/MM/YYYY, DD-MM-YYYY, DD.MM.YYYY
     *
     * @param string $date
     * @return string
     */
    protected function normalizeDate(string $date): string
    {
        $date = trim($date);

        // Try different date formats
        $formats = [
            'd/m/Y',
            'd-m-Y',
            'd.m.Y',
            'Y-m-d',
            'd/m/y',
            'd-m-y',
            'd.m.y',
        ];

        foreach ($formats as $format) {
            $parsed = DateTime::createFromFormat($format, $date);
            if ($parsed && $parsed->format($format) === $date) {
                return $parsed->format('Y-m-d');
            }
        }

        // If no format matches, try strtotime as fallback
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        // Return original if can't parse
        return $date;
    }

    /**
     * Normalize amount string to float.
     * Handles: comma/dot decimal separators, thousand separators, negative amounts.
     *
     * @param string $amount
     * @return float
     */
    protected function normalizeAmount(string $amount): float
    {
        $amount = trim($amount);

        // Remove whitespace
        $amount = str_replace(' ', '', $amount);

        // Detect if negative (can be with minus sign or parentheses)
        $isNegative = false;
        if (str_starts_with($amount, '-') || str_starts_with($amount, '(')) {
            $isNegative = true;
            $amount = str_replace(['(', ')', '-'], '', $amount);
        }

        // Count dots and commas to determine decimal separator
        $dotCount = substr_count($amount, '.');
        $commaCount = substr_count($amount, ',');

        if ($commaCount > 0 && $dotCount > 0) {
            // Both present: last one is decimal separator
            $lastDot = strrpos($amount, '.');
            $lastComma = strrpos($amount, ',');

            if ($lastComma > $lastDot) {
                // Comma is decimal separator: remove dots, replace comma with dot
                $amount = str_replace('.', '', $amount);
                $amount = str_replace(',', '.', $amount);
            } else {
                // Dot is decimal separator: remove commas
                $amount = str_replace(',', '', $amount);
            }
        } elseif ($commaCount > 0) {
            // Only commas: if more than one, they're thousand separators
            if ($commaCount > 1 || strlen($amount) - strrpos($amount, ',') > 3) {
                $amount = str_replace(',', '', $amount);
            } else {
                // Single comma as decimal separator
                $amount = str_replace(',', '.', $amount);
            }
        }
        // If only dots, assume already correct format (or thousand separators)
        elseif ($dotCount > 1) {
            // Multiple dots: thousand separators
            $amount = str_replace('.', '', $amount);
        }

        $value = (float) $amount;

        return $isNegative ? -$value : $value;
    }

    /**
     * Trim and normalize concept text.
     * Removes extra whitespace and converts to uppercase.
     *
     * @param string $concept
     * @return string
     */
    protected function trimConcept(string $concept): string
    {
        // Trim whitespace
        $concept = trim($concept);

        // Collapse multiple spaces into one
        $concept = preg_replace('/\s+/', ' ', $concept);

        // Convert to uppercase
        $concept = mb_strtoupper($concept, 'UTF-8');

        return $concept;
    }

    protected function conteNumeros(string $amount): bool
    {
        return preg_match('/[-+]?\d*\.?\d+/', $amount);
    }
}
