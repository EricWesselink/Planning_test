<?php

namespace Tests\Support;

class SimpleXlsx
{
    /**
     * @param  array<string, list<list<string>>>  $sheets
     */
    public static function path(array $sheets): string
    {
        if ($sheets === []) {
            $sheets = ['Blad1' => []];
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $overrides = ['<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'];
        $workbookSheets = '';
        $rels = '';
        $sheetIndex = 0;
        foreach (array_keys($sheets) as $name) {
            $sheetIndex++;
            $overrides[] = '<Override PartName="/xl/worksheets/sheet'.$sheetIndex.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbookSheets .= '<sheet name="'.htmlspecialchars($name, ENT_XML1).'" sheetId="'.$sheetIndex.'" r:id="rId'.$sheetIndex.'"/>';
            $rels .= '<Relationship Id="rId'.$sheetIndex.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$sheetIndex.'.xml"/>';
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'.implode('', $overrides).'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$workbookSheets.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');

        $sheetIndex = 0;
        foreach ($sheets as $rows) {
            $sheetIndex++;
            $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            foreach ($rows as $r => $cols) {
                $xml .= '<row r="'.($r + 1).'">';
                foreach ($cols as $c => $value) {
                    $xml .= '<c r="'.chr(65 + $c).($r + 1).'" t="inlineStr"><is><t>'.htmlspecialchars((string) $value, ENT_XML1).'</t></is></c>';
                }
                $xml .= '</row>';
            }
            $xml .= '</sheetData></worksheet>';
            $zip->addFromString('xl/worksheets/sheet'.$sheetIndex.'.xml', $xml);
        }
        $zip->close();

        return $path;
    }
}
