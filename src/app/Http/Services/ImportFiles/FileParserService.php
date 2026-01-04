<?php

namespace App\Http\Services\ImportFiles;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class FileParserService
{
    /**
     * Parse uploaded file into array of rows
     *
     * @param UploadedFile $file
     * @param string $format
     * @return array<int, array<int, string|null>>
     */
    public function parse(UploadedFile $file, string $format = 'auto'): array
    {
        // Auto-detect format if not specified
        if ($format === 'auto') {
            $format = $this->detectFileFormat($file);
        }

        Log::info('File format detected', [
            'format' => $format,
            'extension' => $file->getClientOriginalExtension(),
        ]);

        if ($format === 'html') {
            return $this->parseHtmlFile($file);
        }

        if ($format === 'csv') {
            return $this->parseCsvWithDelimiterDetection($file->getRealPath());
        }

        return $this->parseExcelFile($file);
    }

    /**
     * Detect file format by analyzing content
     *
     * @param UploadedFile $file
     * @return string 'excel', 'html', or 'csv'
     */
    private function detectFileFormat(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $filePath = $file->getRealPath();

        // Read first bytes to detect file type
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return $this->fallbackToExtension($extension);
        }

        $header = fread($handle, 8);
        fclose($handle);

        if ($header === false) {
            return $this->fallbackToExtension($extension);
        }

        // Check for ZIP signature (XLSX files are ZIP archives)
        // ZIP magic bytes: 50 4B 03 04
        if (str_starts_with($header, "PK\x03\x04")) {
            return 'excel';
        }

        // Check for OLE2 signature (XLS files)
        // OLE2 magic bytes: D0 CF 11 E0 A1 B1 1A E1
        if (str_starts_with($header, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            // Could be real XLS or HTML disguised as XLS
            // Read more content to check for HTML
            $content = file_get_contents($filePath, false, null, 0, 1024);
            if ($content !== false && $this->looksLikeHtml($content)) {
                return 'html';
            }
            return 'excel';
        }

        // Read more content for text-based format detection
        $content = file_get_contents($filePath, false, null, 0, 2048);
        if ($content === false) {
            return $this->fallbackToExtension($extension);
        }

        // Check if content looks like HTML
        if ($this->looksLikeHtml($content)) {
            return 'html';
        }

        // Default to CSV for text files
        if (in_array($extension, ['csv', 'txt'])) {
            return 'csv';
        }

        // For .xls files that aren't binary, check if they're HTML
        if ($extension === 'xls' && $this->looksLikeHtml($content)) {
            return 'html';
        }

        return $this->fallbackToExtension($extension);
    }

    /**
     * Check if content looks like HTML
     *
     * @param string $content
     * @return bool
     */
    private function looksLikeHtml(string $content): bool
    {
        $content = trim($content);

        // Check for common HTML indicators
        $htmlPatterns = [
            '/^\s*<!DOCTYPE\s+html/i',
            '/^\s*<html/i',
            '/<table[\s>]/i',
            '/<tr[\s>]/i',
            '/<td[\s>]/i',
            '/<th[\s>]/i',
        ];

        foreach ($htmlPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fallback to extension-based detection
     *
     * @param string $extension
     * @return string
     */
    private function fallbackToExtension(string $extension): string
    {
        return match ($extension) {
            'xlsx' => 'excel',
            'xls' => 'excel',
            'csv', 'txt' => 'csv',
            'html', 'htm' => 'html',
            default => 'csv',
        };
    }

    /**
     * Parse HTML file content
     *
     * @param UploadedFile $file
     * @return array<int, array<int, string|null>>
     */
    private function parseHtmlFile(UploadedFile $file): array
    {
        $htmlContent = file_get_contents($file->getRealPath());
        if ($htmlContent === false) {
            return [];
        }

        return $this->parseHtmlTableToArray($htmlContent);
    }

    /**
     * Parse Excel file using Maatwebsite Excel
     *
     * @param UploadedFile $file
     * @return array<int, array<int, string|null>>
     */
    private function parseExcelFile(UploadedFile $file): array
    {
        $data = Excel::toArray([], $file);
        return $data[0] ?? [];
    }

    /**
     * Parse CSV file with automatic delimiter detection
     *
     * @param string $filePath
     * @return array<int, array<int, string|null>>
     */
    private function parseCsvWithDelimiterDetection(string $filePath): array
    {
        $rows = [];

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return $rows;
        }

        $firstLine = fgets($handle);
        rewind($handle);

        if ($firstLine === false) {
            fclose($handle);
            return $rows;
        }

        $detectedDelimiter = $this->detectDelimiter($firstLine);

        Log::info('CSV delimiter detected', [
            'delimiter' => $detectedDelimiter === "\t" ? 'TAB' : $detectedDelimiter,
        ]);

        while (($row = fgetcsv($handle, 0, $detectedDelimiter)) !== false) {
            $rows[] = $this->processCsvRow($row);
        }

        fclose($handle);
        return $rows;
    }

    /**
     * Detect CSV delimiter from first line
     *
     * @param string $line
     * @return string
     */
    private function detectDelimiter(string $line): string
    {
        $delimiters = [
            "\t" => substr_count($line, "\t"),
            ',' => substr_count($line, ','),
            ';' => substr_count($line, ';'),
            '|' => substr_count($line, '|'),
        ];

        arsort($delimiters);
        $detectedDelimiter = array_key_first($delimiters);

        return $delimiters[$detectedDelimiter] === 0 ? "\t" : $detectedDelimiter;
    }

    /**
     * Parse a simple HTML table into an array of rows
     *
     * @param string $html
     * @return array<int, array<int, string|null>>
     */
    private function parseHtmlTableToArray(string $html): array
    {
        $rows = [];
        $doc = new \DOMDocument();

        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $table = $xpath->query('//table')->item(0);

        if (!$table) {
            return $rows;
        }

        foreach ($xpath->query('.//tr', $table) as $tr) {
            $rows[] = $this->extractTableRow($xpath, $tr);
        }

        return $rows;
    }

    /**
     * Process a single CSV row: trim cells and convert empty strings to null.
     *
     * @param array $row
     * @return array
     */
    private function processCsvRow(array $row): array
    {
        return array_map(function ($cell) {
            $cell = trim($cell ?? '');
            return $cell === '' ? null : $cell;
        }, $row);
    }

    /**
     * Extract cells from an HTML table row.
     *
     * @param \DOMXPath $xpath
     * @param \DOMElement $tr
     * @return array
     */
    private function extractTableRow(\DOMXPath $xpath, \DOMElement $tr): array
    {
        $row = [];
        foreach ($xpath->query('./th|./td', $tr) as $cell) {
            $text = trim($cell->textContent);
            $row[] = $text === '' ? null : $text;
        }
        return $row;
    }
}
