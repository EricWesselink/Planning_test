<?php

namespace App\Services\QuoteCalculation;

use App\Enums\QuantitySource;
use App\Enums\WorkUnit;
use App\Models\Calculation;
use App\Models\CalculationLine;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CalculationExcelExporter
{
    public function __construct(private CalculationTotals $totals = new CalculationTotals) {}

    public function download(Calculation $calculation): BinaryFileResponse
    {
        $calculation->loadMissing(['lines']);
        $spreadsheet = $this->workbook($calculation);
        $path = tempnam(sys_get_temp_dir(), 'nicon-calculatie-');
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        $filename = 'Calculatie-'.$this->safeName($calculation->name).'.xlsx';

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function workbook(Calculation $calculation): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $details = $spreadsheet->getActiveSheet();
        $details->setTitle('Details');
        $this->fillDetails($details, $calculation);

        $totals = $spreadsheet->createSheet();
        $totals->setTitle('Totalen');
        $this->fillTotals($totals, $calculation);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function fillDetails(Worksheet $sheet, Calculation $calculation): void
    {
        $headers = ['Productcode', 'Product', 'Ruimtenr.', 'Ruimte', 'Hoeveelheid', 'Eenheid', 'Bron', 'Gevonden', 'Bevestigd', 'Opmerking'];
        $sheet->fromArray($headers, null, 'A1');
        $row = 2;
        foreach ($calculation->lines as $line) {
            $sheet->setCellValueExplicit('A'.$row, (string) ($line->product_code ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B'.$row, (string) ($line->product ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('C'.$row, (string) ($line->room_number ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('D'.$row, (string) ($line->room_name ?? ''), DataType::TYPE_STRING);
            if ($line->quantity !== null) {
                $sheet->setCellValue('E'.$row, (float) $line->quantity);
                $sheet->getStyle('E'.$row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
            }
            $sheet->setCellValueExplicit('F'.$row, $line->unit?->label() ?? '', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('G'.$row, $line->source?->label() ?? '', DataType::TYPE_STRING);
            $found = $line->original_quantity !== null ? (float) $line->original_quantity : null;
            if ($found !== null) {
                $sheet->setCellValue('H'.$row, $found);
                $sheet->getStyle('H'.$row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
            }
            $sheet->setCellValueExplicit('I'.$row, $this->confirmedLabel($line), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('J'.$row, $this->remark($line), DataType::TYPE_STRING);
            $row++;
        }

        $last = max(2, $row - 1);
        $this->styleTable($sheet, 'A1:J'.$last, 'Details', range('A', 'J'));
        $sheet->getStyle('A1:J1')->getFont()->setBold(true);
        $sheet->getStyle('E2:E'.$last)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('H2:H'.$last)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    private function fillTotals(Worksheet $sheet, Calculation $calculation): void
    {
        $headers = ['Productcode', 'Product', 'Totale hoeveelheid', 'Eenheid'];
        $sheet->fromArray($headers, null, 'A1');
        $row = 2;
        foreach ($this->totals->grouped($calculation->lines) as $total) {
            $sheet->setCellValueExplicit('A'.$row, (string) ($total['product_code'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B'.$row, (string) ($total['product'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue('C'.$row, (float) $total['quantity']);
            $sheet->getStyle('C'.$row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
            $sheet->setCellValueExplicit('D'.$row, $total['unit_label'], DataType::TYPE_STRING);
            $row++;
        }

        $last = max(2, $row - 1);
        $this->styleTable($sheet, 'A1:D'.$last, 'Totalen', range('A', 'D'));
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->getStyle('C2:C'.$last)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    /**
     * @param  list<string>  $columns
     */
    private function styleTable(Worksheet $sheet, string $range, string $name, array $columns): void
    {
        foreach ($columns as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        $table = new Table($range, preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'Tabel');
        $style = new TableStyle;
        $style->setTheme(TableStyle::TABLE_STYLE_MEDIUM2);
        $table->setStyle($style);
        $table->setShowHeaderRow(true);
        $sheet->addTable($table);
    }

    private function remark(CalculationLine $line): string
    {
        if ($line->unit === WorkUnit::LinearMeter) {
            $label = PlinthLengthCalculator::breakdownFrom($line->calculation_trace)['label'];
            if (filled($label)) {
                return $label;
            }
        }

        return (string) ($line->note ?? $line->calculation_trace ?? '');
    }

    private function confirmedLabel(CalculationLine $line): string
    {
        if ($line->confirmed_manually) {
            return 'Handmatig';
        }
        if (in_array($line->source, [QuantitySource::FromDrawing, QuantitySource::Calculated], true)) {
            return 'Automatisch';
        }

        return '';
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N}\-_ ]+/u', '', $name) ?? $name;
        $name = trim(preg_replace('/\s+/', '-', $name) ?? $name, '-');

        return $name !== '' ? $name : 'export';
    }
}
