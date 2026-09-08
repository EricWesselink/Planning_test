<?php

namespace Tests\Unit;

use App\Services\MeetstaatParser;
use App\Services\SpreadsheetReader;
use App\Support\DutchNumber;
use Tests\TestCase;

class MeetstaatParserTest extends TestCase
{
    public function test_parses_dutch_numbers(): void
    {
        $this->assertSame(1500.0, DutchNumber::parse('1.500'));
        $this->assertSame(1500.5, DutchNumber::parse('1.500,5'));
        $this->assertSame(860.0, DutchNumber::parse('860 m²'));
        $this->assertSame(32.5, DutchNumber::parse('32,5'));
    }

    public function test_parses_wide_csv_into_rooms_and_work(): void
    {
        $rows = (new SpreadsheetReader)->fromCsv($this->writeTemp(implode("\n", [
            'Meetstaat Apotheek Zwolle',
            'nummer,naam,verdieping,m2,egaliseren,pvc,plinten',
            '01,Showroom,Begane grond,860,"1.500",860,45',
            '02,Kantoor,Begane grond,48,48,48,22',
            'Totaal,,,908,1548,908,67',
        ])."\n"));

        $parsed = (new MeetstaatParser)->parse($rows);

        $this->assertCount(1, $parsed['floors']);
        $this->assertSame('Begane grond', $parsed['floors'][0]['name']);
        $this->assertCount(2, $parsed['floors'][0]['areas']);
        $this->assertSame('Showroom', $parsed['floors'][0]['areas'][0]['name']);
        $this->assertSame(1500.0, $parsed['floors'][0]['areas'][0]['tasks'][0]['quantity']);
        $this->assertSame('m1', collect($parsed['floors'][0]['areas'][0]['tasks'])->firstWhere('work_name', 'plinten')['unit']);
    }

    public function test_merges_long_format_rows_for_the_same_room(): void
    {
        $parsed = (new MeetstaatParser)->parse([
            ['Nummer', 'Naam', 'Verdieping', 'Werkzaamheid', 'Eenheid', 'Hoeveelheid'],
            ['01', 'Showroom', 'Begane grond', 'PVC', 'm2', '860'],
            ['01', 'Showroom', 'Begane grond', 'Egaliseren', 'm2', '860'],
        ]);

        $this->assertCount(1, $parsed['floors'][0]['areas']);
        $this->assertCount(2, $parsed['floors'][0]['areas'][0]['tasks']);
    }

    public function test_reads_semicolon_csv(): void
    {
        $rows = (new SpreadsheetReader)->fromCsv($this->writeTemp("nummer;naam;m2\n01;Hal;32,5\n"));
        $parsed = (new MeetstaatParser)->parse($rows);

        $this->assertSame(32.5, $parsed['floors'][0]['areas'][0]['square_meters']);
        $this->assertSame('Vloerwerk', $parsed['floors'][0]['areas'][0]['tasks'][0]['work_name']);
    }

    public function test_reads_xlsx_sheet(): void
    {
        $path = $this->writeXlsx([
            ['nummer', 'naam', 'm2', 'pvc'],
            ['01', 'Hal', '32', '32'],
        ]);

        $rows = (new SpreadsheetReader)->rows($path);
        $parsed = (new MeetstaatParser)->parse($rows);

        $this->assertSame('Hal', $parsed['floors'][0]['areas'][0]['name']);
        $this->assertSame(32.0, $parsed['floors'][0]['areas'][0]['square_meters']);
    }

    private function writeTemp(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'meet');
        file_put_contents($path, $contents);

        return $path;
    }

    /** @param list<list<string>> $rows */
    private function writeXlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Meetstaat" sheetId="1" r:id="rId1"/></sheets></workbook>');
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
