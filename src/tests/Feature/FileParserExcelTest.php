<?php

namespace Tests\Feature;

use App\Http\Services\ImportFiles\FileParserService;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Els extractes en Excel (XLSX i l'XLS binari de CaixaBank i Caixa d'Enginyers) passen per
 * `Excel::toArray()`. Laravel Excel 4 en va canviar la signatura —demana un objecte d'importació
 * on abans hi anava `[]`— i cap test no hi passava: ara sí.
 */
class FileParserExcelTest extends TestCase
{
    private function fitxer(string $format): UploadedFile
    {
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->fromArray([
            ['Data', 'Concepte', 'Import', 'Saldo'],
            ['10/11/2026', 'TARGETA *1234 BOTIGA BICIS', -10.95, 1234.56],
            ['11/11/2026', 'NÒMINA', 2500, 3734.56],
        ]);
        $ruta = tempnam(sys_get_temp_dir(), 'extracte') . ".$format";
        ($format === 'xls' ? new Xls($ss) : new Xlsx($ss))->save($ruta);

        return new UploadedFile($ruta, "extracte.$format", null, null, true);
    }

    public function test_llegeix_un_xlsx_i_un_xls_fila_a_fila(): void
    {
        foreach (['xlsx', 'xls'] as $format) {
            $files = app(FileParserService::class)->parse($this->fitxer($format));

            $this->assertCount(3, $files, $format);
            $this->assertSame(['Data', 'Concepte', 'Import', 'Saldo'], $files[0], $format);
            $this->assertSame('TARGETA *1234 BOTIGA BICIS', $files[1][1], $format);
            $this->assertEqualsWithDelta(-10.95, $files[1][2], 0.001, $format);
            $this->assertSame('NÒMINA', $files[2][1], $format);
        }
    }
}
