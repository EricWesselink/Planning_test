<?php

namespace App\Services\QuoteCalculation;

use App\Services\SpreadsheetReader;

class WorkbookAnalyzer
{
    private WorkbookColumnGuesser $guesser;

    public function __construct(
        private SpreadsheetReader $reader = new SpreadsheetReader,
        ?WorkbookColumnGuesser $guesser = null,
    ) {
        $this->guesser = $guesser ?? WorkbookColumnGuesser::remembering();
    }

    /**
     * @return array{
     *     filename: string,
     *     sheets: list<array<string, mixed>>,
     *     skippable: bool,
     *     skip_reason: ?string,
     *     labels: list<array{column: string, header: string, role: string, confidence: string, sheet: string}>
     * }
     */
    public function analyze(string $path, string $filename): array
    {
        $sheets = $this->reader->sheets($path, $filename);
        $analyzed = [];
        $labels = [];
        $anyUseful = false;
        $skipReasons = [];

        foreach ($sheets as $sheet) {
            $guess = $this->guesser->guess($sheet['rows']);
            $guess['name'] = $sheet['name'];
            $analyzed[] = $guess;
            if (! $guess['skippable']) {
                $anyUseful = true;
            } elseif (is_string($guess['skip_reason'])) {
                $skipReasons[] = $sheet['name'].': '.$guess['skip_reason'];
            }
            foreach ($guess['labels'] as $label) {
                $label['sheet'] = $sheet['name'];
                $labels[] = $label;
            }
        }

        $skipReason = null;
        if (! $anyUseful) {
            $skipReason = $skipReasons[0] ?? 'Dit Excelbestand bevat geen bruikbare vloer- of plintinformatie.';
        }

        return [
            'filename' => $filename,
            'sheets' => $analyzed,
            'skippable' => ! $anyUseful,
            'skip_reason' => $skipReason,
            'labels' => $labels,
        ];
    }
}
