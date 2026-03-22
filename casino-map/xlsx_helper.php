<?php
/**
 * Minimal .xlsx generator using PHP's built-in ZipArchive.
 * No external dependencies required.
 *
 * Usage:
 *   send_xlsx('filename.xlsx', ['Col A', 'Col B'], [['row1a', 'row1b'], ...]);
 */

function send_xlsx(string $filename, array $headers, array $rows): void
{
    $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');

    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
        die('ZipArchive açılamadı: ' . ($zip->getStatusString() ?: 'bilinmeyen hata'));
    }

    // ── [Content_Types].xml ───────────────────────────────────────────────────
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml"
            ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml"
            ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml"
            ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>');

    // ── _rels/.rels ───────────────────────────────────────────────────────────
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
    Target="xl/workbook.xml"/>
</Relationships>');

    // ── xl/_rels/workbook.xml.rels ────────────────────────────────────────────
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"
    Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"
    Target="styles.xml"/>
</Relationships>');

    // ── xl/workbook.xml ───────────────────────────────────────────────────────
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Sheet1" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>');

    // ── xl/styles.xml ─────────────────────────────────────────────────────────
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts><font><sz val="11"/><name val="Calibri"/></font>
         <font><sz val="11"/><name val="Calibri"/><b/></font></fonts>
  <fills><fill><patternFill patternType="none"/></fill>
         <fill><patternFill patternType="gray125"/></fill></fills>
  <borders><border><left/><right/><top/><bottom/><diagonal/></border></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>
  </cellXfs>
</styleSheet>');

    // ── xl/worksheets/sheet1.xml ──────────────────────────────────────────────
    $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $xml .= '<sheetData>';

    // Header row (style 1 = bold)
    $xml .= '<row r="1">';
    foreach ($headers as $ci => $hdr) {
        $col  = xlsx_col_letter($ci + 1);
        $xml .= '<c r="' . $col . '1" t="inlineStr" s="1"><is><t>' . xlsx_esc((string)$hdr) . '</t></is></c>';
    }
    $xml .= '</row>';

    // Data rows
    foreach ($rows as $ri => $row) {
        $rowNum = $ri + 2;
        $xml .= '<row r="' . $rowNum . '">';
        foreach ($row as $ci => $cell) {
            $col  = xlsx_col_letter($ci + 1);
            $xml .= '<c r="' . $col . $rowNum . '" t="inlineStr"><is><t>' . xlsx_esc((string)($cell ?? '')) . '</t></is></c>';
        }
        $xml .= '</row>';
    }

    $xml .= '</sheetData></worksheet>';

    $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
    $zip->close();

    // Send file to browser
    $content = file_get_contents($tmpFile);
    unlink($tmpFile);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $content;
    exit;
}

/** Convert 1-based column index to Excel letter (A, B, …, Z, AA, AB, …) */
function xlsx_col_letter(int $n): string
{
    $letter = '';
    while ($n > 0) {
        $rem    = ($n - 1) % 26;
        $letter = chr(65 + $rem) . $letter;
        $n      = intval(($n - $rem - 1) / 26);
    }
    return $letter;
}

/** Escape a string for use inside an XML text node */
function xlsx_esc(string $s): string
{
    return htmlspecialchars($s, ENT_XML1, 'UTF-8');
}

/**
 * Read an .xlsx file and return all rows from the first worksheet as a 2-D array.
 * Uses only PHP built-ins (ZipArchive + SimpleXML) — no Composer packages needed.
 *
 * @param  string $path  Absolute path to the .xlsx file
 * @return array[]       Array of rows; each row is an array of string cell values
 */
function read_xlsx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return [];
    }

    // ── Shared string table ──────────────────────────────────────────────────
    $sharedStrings = [];
    $ssRaw = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssRaw !== false) {
        // Suppress XML warnings for malformed files
        $prev = libxml_use_internal_errors(true);
        $ssXml = simplexml_load_string($ssRaw);
        libxml_use_internal_errors($prev);
        if ($ssXml !== false) {
            foreach ($ssXml->si as $si) {
                // A <si> can contain either a plain <t> or rich-text <r><t> runs
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $r) {
                        $text .= (string)$r->t;
                    }
                    $sharedStrings[] = $text;
                }
            }
        }
    }

    // ── Worksheet (first sheet only) ─────────────────────────────────────────
    // Try the canonical path first, then scan for any worksheet
    $wsRaw = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($wsRaw === false) {
        // Scan for any worksheet file
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, 'xl/worksheets/') === 0 && substr($name, -4) === '.xml') {
                $wsRaw = $zip->getFromIndex($i);
                break;
            }
        }
    }
    $zip->close();

    if ($wsRaw === false) {
        return [];
    }

    $prev  = libxml_use_internal_errors(true);
    $wsXml = simplexml_load_string($wsRaw);
    libxml_use_internal_errors($prev);
    if ($wsXml === false) {
        return [];
    }

    $rows = [];
    foreach ($wsXml->sheetData->row as $row) {
        $cells   = [];
        $maxCol  = -1;

        foreach ($row->c as $c) {
            $ref    = (string)$c['r'];            // e.g. "B3"
            $colIdx = xlsx_col_index($ref);       // 0-based
            $type   = (string)$c['t'];

            if ($type === 's') {
                // Shared string reference
                $idx = intval((string)$c->v);
                $val = $sharedStrings[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = isset($c->is->t) ? (string)$c->is->t : '';
            } elseif ($type === 'b') {
                $val = ((string)$c->v === '1') ? 'TRUE' : 'FALSE';
            } else {
                // Number, date (stored as number), or empty
                $val = isset($c->v) ? (string)$c->v : '';
            }

            $cells[$colIdx] = $val;
            if ($colIdx > $maxCol) {
                $maxCol = $colIdx;
            }
        }

        // Build a dense array (fill gaps with empty string)
        $rowArr = [];
        for ($i = 0; $i <= $maxCol; $i++) {
            $rowArr[] = $cells[$i] ?? '';
        }
        $rows[] = $rowArr;
    }

    return $rows;
}

/**
 * Convert a cell reference (e.g. "AB3") to a 0-based column index.
 * "A" → 0, "B" → 1, …, "Z" → 25, "AA" → 26, …
 */
function xlsx_col_index(string $cellRef): int
{
    preg_match('/^([A-Za-z]+)/', $cellRef, $m);
    $letters = strtoupper($m[1] ?? 'A');
    $idx = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $idx = $idx * 26 + (ord($letters[$i]) - 64);
    }
    return $idx - 1; // convert to 0-based
}
