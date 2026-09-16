<?php

namespace Tests\Unit;

use App\Services\SpreadsheetReader;
use Tests\Support\SimpleXlsx;
use Tests\TestCase;

class SpreadsheetReaderTest extends TestCase
{
    public function test_reads_xlsx_when_the_temp_path_has_no_spreadsheet_extension(): void
    {
        $xlsx = $this->writeXlsx([
            ['Productie Eenheid Omschrijving', 'Aantal', 'EH'],
            ['Screen H: 1700 mm B: 960 mm', '12', 'st'],
        ]);
        $tmp = tempnam(sys_get_temp_dir(), 'php');
        copy($xlsx, $tmp);

        $rows = (new SpreadsheetReader)->rows($tmp, 'screen.xlsx');

        $this->assertSame('Productie Eenheid Omschrijving', $rows[0][0]);
        $this->assertSame('Screen H: 1700 mm B: 960 mm', $rows[1][0]);
        $this->assertSame('12', $rows[1][1]);
        $this->assertSame('st', $rows[1][2]);
        $this->assertFalse(str_contains(implode(' ', $rows[0]), 'PK'));
    }

    public function test_sniffs_a_zip_container_when_the_filename_is_missing(): void
    {
        $xlsx = $this->writeXlsx([
            ['Productie Eenheid Omschrijving', 'Aantal', 'EH'],
            ['BNR 11 Screen H: 1574 mm B: 770 mm', '2', 'st'],
        ]);
        $tmp = tempnam(sys_get_temp_dir(), 'php');
        copy($xlsx, $tmp);

        $rows = (new SpreadsheetReader)->rows($tmp);

        $this->assertSame('BNR 11 Screen H: 1574 mm B: 770 mm', $rows[1][0]);
        $this->assertSame('2', $rows[1][1]);
    }

    public function test_reads_every_worksheet_instead_of_only_the_first(): void
    {
        $xlsx = SimpleXlsx::path([
            'wandafwerking' => [
                ['Wandafwerking'],
                ['A-00-01', 'Sauswerk'],
            ],
            'vloerafwerking' => [
                ['Ruimte nr.', 'Vloer'],
                ['A-00-13', 'v04'],
            ],
        ]);

        $sheets = (new SpreadsheetReader)->sheets($xlsx, 'staat.xlsx');

        $this->assertSame(['wandafwerking', 'vloerafwerking'], array_column($sheets, 'name'));
        $this->assertSame('Sauswerk', $sheets[0]['rows'][1][1]);
        $this->assertSame('v04', $sheets[1]['rows'][1][1]);
        $this->assertSame('Wandafwerking', (new SpreadsheetReader)->rows($xlsx, 'staat.xlsx')[0][0]);
    }

    /** @param list<list<string>> $rows */
    private function writeXlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');

        $sheet = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $r => $cols) {
            $sheet .= '<row r="'.($r + 1).'">';
            foreach ($cols as $c => $value) {
                $ref = chr(65 + $c).($r + 1);
                $sheet .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        return $path;
    }
}
