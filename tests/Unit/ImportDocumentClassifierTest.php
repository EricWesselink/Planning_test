<?php

namespace Tests\Unit;

use App\Services\CalculationExcelParser;
use App\Services\Meetstaat\ImportDocumentClassifier;
use App\Services\Meetstaat\MaterialIdentity;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class ImportDocumentClassifierTest extends TestCase
{
    public function test_recognizes_material_list_from_the_filename(): void
    {
        $result = $this->classifier()->fromFilename('MaterialList_Laakse.pdf');

        $this->assertSame('materialenstaat', $result['type']);
        $this->assertSame('high', $result['confidence']);
    }

    public function test_recognizes_snijmaten_and_drawings_from_the_filename(): void
    {
        $snijmaten = $this->classifier()->fromFilename('Snijmaten-BG.pdf');
        $drawing = $this->classifier()->fromFilename('Plattegrond_1e_verd.pdf');
        $meetstaat = $this->classifier()->fromFilename('Meetbon_Laakse_Tuinen.pdf');

        $this->assertSame('snijmaten', $snijmaten['type']);
        $this->assertSame('plattegrond', $drawing['type']);
        $this->assertSame('meetstaat', $meetstaat['type']);
        $this->assertSame('high', $snijmaten['confidence']);
    }

    public function test_marks_spreadsheets_without_a_hint_as_uncertain_other(): void
    {
        $result = $this->classifier()->fromFilename('ruimtes.csv');

        $this->assertSame('overig', $result['type']);
        $this->assertSame('low', $result['confidence']);
        $this->assertNotSame('meetstaat', $result['type']);
    }

    public function test_recognizes_ericwesselink_xlsx_as_calculation_not_meetstaat(): void
    {
        $file = new UploadedFile(
            base_path('tests/fixtures/11-ericwesselink.xlsx'),
            '11-ericwesselink.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $result = $this->classifier()->classify($file);

        $this->assertSame('calculatie', $result['type']);
        $this->assertSame('high', $result['confidence']);
        $this->assertSame('Calculatie', $result['type_label']);
        $this->assertNotSame('meetstaat', $result['type']);
        $this->assertNotSame('overig', $result['type']);
    }

    public function test_calculation_content_wins_over_a_meetstaat_type_hint(): void
    {
        $file = new UploadedFile(
            base_path('tests/fixtures/11-ericwesselink.xlsx'),
            '11-ericwesselink.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $result = $this->classifier()->classify($file, 'meetstaat');

        $this->assertSame('calculatie', $result['type']);
        $this->assertSame('high', $result['confidence']);
    }

    public function test_does_not_classify_spreadsheet_without_calculation_columns_as_calculation(): void
    {
        $file = $this->spreadsheetWithoutCalculationColumns();

        $result = $this->classifier()->classify($file);

        $this->assertNotSame('calculatie', $result['type']);
        $this->assertSame('overig', $result['type']);
        $this->assertSame('low', $result['confidence']);
    }

    public function test_uses_pdf_text_when_the_filename_is_generic(): void
    {
        $path = SimplePdf::path("Materialenstaat\nMarmoleum Real, 3120 rosato, Linoleum\nNetto : 100 m²\n");
        $file = new UploadedFile($path, 'bestand.pdf', 'application/pdf', null, true);

        $result = $this->classifier()->classify($file);

        $this->assertSame('materialenstaat', $result['type']);
        $this->assertSame('high', $result['confidence']);
        $this->assertContains('MATERIAL_TOTAL_SOURCE', $result['roles']);
        $this->assertSame('Hoog', $result['reliability_label']);
    }

    public function test_room_level_materiaallijst_content_is_classified_as_meetstaat_task_source(): void
    {
        $text = "Meetstaat\nBouwlaag: begane grond\nMarmoleum Real\n0.07 groepsruimte 50,97 m²\n";
        $result = $this->classifier()->fromText($text);
        $roles = $this->classifier()->detectRoles($result['type'], $text);

        $this->assertSame('meetstaat', $result['type']);
        $this->assertContains('TASK_SOURCE', $roles);
    }

    public function test_keeps_a_manual_type_from_the_upload_form(): void
    {
        $path = SimplePdf::path("begane grond\n0.07 groepsruimte 50,97 m2\n");
        $file = new UploadedFile($path, 'onbekend.pdf', 'application/pdf', null, true);

        $result = $this->classifier()->classify($file, 'snijmaten');

        $this->assertSame('snijmaten', $result['type']);
        $this->assertSame('manual', $result['confidence']);
    }

    public function test_recognizes_afmetingen_from_filename(): void
    {
        $fromName = $this->classifier()->fromFilename('afmetingen-werk.pdf');
        $this->assertSame('afmetingen', $fromName['type']);
        $this->assertSame('high', $fromName['confidence']);
    }

    private function classifier(): ImportDocumentClassifier
    {
        return new ImportDocumentClassifier(
            new PdfTextExtractor,
            new SpreadsheetReader,
            new CalculationExcelParser(new MaterialIdentity),
        );
    }

    private function spreadsheetWithoutCalculationColumns(): UploadedFile
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nicon-generic-'.uniqid('', true).'.xlsx';
        $zip = new \ZipArchive;
        $this->assertSame(true, $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('xl/worksheets/sheet1.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>
    <row r="1">
      <c r="A1" t="inlineStr"><is><t>Omschrijving</t></is></c>
      <c r="B1" t="inlineStr"><is><t>Aantal</t></is></c>
      <c r="C1" t="inlineStr"><is><t>EH</t></is></c>
    </row>
    <row r="2">
      <c r="A2" t="inlineStr"><is><t>Screenwit</t></is></c>
      <c r="B2"><v>12</v></c>
      <c r="C2" t="inlineStr"><is><t>st</t></is></c>
    </row>
  </sheetData>
</worksheet>
XML);
        $zip->close();

        return new UploadedFile(
            $path,
            'opdrachtlijst.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }
}
