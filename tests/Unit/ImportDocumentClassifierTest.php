<?php

namespace Tests\Unit;

use App\Services\Meetstaat\ImportDocumentClassifier;
use App\Services\Meetstaat\PdfTextExtractor;
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

    public function test_marks_spreadsheets_without_a_hint_as_uncertain_meetstaat(): void
    {
        $result = $this->classifier()->fromFilename('ruimtes.csv');

        $this->assertSame('meetstaat', $result['type']);
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
        return new ImportDocumentClassifier(new PdfTextExtractor);
    }
}
