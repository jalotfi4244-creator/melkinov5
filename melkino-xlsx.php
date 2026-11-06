<?php
/**
 * ساخت و خواندن xlsx بدون PhpSpreadsheet.
 * اگر ZipArchive روی هاست نباشد (InfinityFree)، ZIP با PHP خالص نوشته می‌شود.
 */
if (!function_exists('melkinoXlsxEsc')) {
    function melkinoXlsxEsc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('melkinoXlsxColLetter')) {
    function melkinoXlsxColLetter(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $i--;
            $s = chr(65 + ($i % 26)) . $s;
            $i = intdiv($i, 26);
        }
        return $s;
    }
}

if (!function_exists('melkinoZipStoreWrite')) {
    /** @param array<string,string> $files path => content */
    function melkinoZipStoreWrite(string $path, array $files): bool
    {
        $now = getdate();
        $dosTime = (($now['hours'] << 11) | ($now['minutes'] << 5) | max(0, $now['seconds'] >> 1)) & 0xFFFF;
        $dosDate = ((($now['year'] - 1980) << 9) | ($now['mon'] << 5) | $now['mday']) & 0xFFFF;
        $local = '';
        $central = '';
        $offset = 0;
        $count = 0;
        foreach ($files as $name => $data) {
            $name = str_replace('\\', '/', (string)$name);
            $data = (string)$data;
            $crc = crc32($data);
            $len = strlen($data);
            $nlen = strlen($name);
            $localHdr = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $len, $len, $nlen, 0);
            $local .= $localHdr . $name . $data;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $len, $len, $nlen, 0, 0, 0, 0, 0, $offset) . $name;
            $offset += strlen($localHdr) + $nlen + $len;
            $count++;
        }
        $cdLen = strlen($central);
        $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $cdLen, $offset, 0);
        $ok = @file_put_contents($path, $local . $central . $eocd) !== false;
        return $ok && is_file($path);
    }
}

if (!function_exists('melkinoZipReadFiles')) {
    /** @return array<string,string> */
    function melkinoZipReadFiles(string $path): array
    {
        $out = [];
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($path) === true) {
                // Zip-bomb guard: legit xlsx files are small; refuse absurd archives.
                $sizeTotal = 0;
                $maxFiles = min($zip->numFiles, 500);
                for ($i = 0; $i < $maxFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if ($name === false) {
                        continue;
                    }
                    $stat = $zip->statIndex($i);
                    $sizeTotal += (int)(is_array($stat) ? ($stat['size'] ?? 0) : 0);
                    if ($sizeTotal > 64 * 1024 * 1024) {
                        break;
                    }
                    $data = $zip->getFromIndex($i);
                    if (is_string($data)) {
                        $out[$name] = $data;
                    }
                }
                $zip->close();
                return $out;
            }
        }
        $bin = @file_get_contents($path);
        if (!is_string($bin) || strlen($bin) < 22) {
            return $out;
        }
        $eocd = strrpos($bin, "PK\x05\x06");
        if ($eocd === false) {
            return $out;
        }
        $meta = unpack('vdisk/vcdDisk/vthis/vtotal/Vsize/Voffset/vcomment', substr($bin, $eocd + 4, 18));
        if (!$meta) {
            return $out;
        }
        $pos = (int)$meta['offset'];
        $total = (int)$meta['total'];
        for ($i = 0; $i < $total; $i++) {
            if (substr($bin, $pos, 4) !== "PK\x01\x02") {
                break;
            }
            $h = unpack('vverMade/vverNeed/vflag/vmethod/vmtime/vmdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen/vcommentlen/vdisk/vinternal/Vexternal/Voffset', substr($bin, $pos + 4, 42));
            if (!$h) {
                break;
            }
            $name = substr($bin, $pos + 46, $h['namelen']);
            $pos += 46 + $h['namelen'] + $h['extralen'] + $h['commentlen'];
            $lp = (int)$h['offset'];
            if (substr($bin, $lp, 4) !== "PK\x03\x04") {
                continue;
            }
            $lh = unpack('vver/vflag/vmethod/vmtime/vmdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen', substr($bin, $lp + 4, 26));
            if (!$lh) {
                continue;
            }
            $dataStart = $lp + 30 + $lh['namelen'] + $lh['extralen'];
            $raw = substr($bin, $dataStart, $lh['csize']);
            if ($lh['method'] === 0) {
                $out[$name] = $raw;
            } elseif ($lh['method'] === 8) {
                $infl = @gzinflate($raw);
                if (is_string($infl)) {
                    $out[$name] = $infl;
                }
            }
        }
        return $out;
    }
}

if (!function_exists('melkinoXlsxParts')) {
    function melkinoXlsxParts(array $headers, array $rows, string $sheetName): array
    {
        $sheetName = preg_replace('/[\\/\\*\\?\\:\\[\\]]/', '', $sheetName) ?: 'Sheet1';
        $rowsXml = '';
        $all = array_merge([$headers], $rows);
        foreach ($all as $r => $cols) {
            $rn = $r + 1;
            $cells = '';
            foreach (array_values($cols) as $c => $val) {
                $ref = melkinoXlsxColLetter($c) . $rn;
                $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . melkinoXlsxEsc((string)$val) . '</t></is></c>';
            }
            $style = $r === 0 ? ' s="1"' : '';
            $rowsXml .= '<row r="' . $rn . '"' . $style . '>' . $cells . '</row>';
        }
        $lastCol = melkinoXlsxColLetter(max(0, count($headers) - 1));
        $lastRow = max(1, count($all));
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:' . $lastCol . $lastRow . '"/>'
            . '<sheetData>' . $rowsXml . '</sheetData></worksheet>';
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . melkinoXlsxEsc($sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $wbRels = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
        $rels = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
        $types = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs>'
            . '</styleSheet>';
        return [
            '[Content_Types].xml' => $types,
            '_rels/.rels' => $rels,
            'xl/workbook.xml' => $workbook,
            'xl/_rels/workbook.xml.rels' => $wbRels,
            'xl/worksheets/sheet1.xml' => $sheet,
            'xl/styles.xml' => $styles,
        ];
    }
}

if (!function_exists('melkinoXlsxWrite')) {
    function melkinoXlsxWrite(string $path, array $headers, array $rows, string $sheetName = 'آگهی‌ها'): bool
    {
        $files = melkinoXlsxParts($headers, $rows, $sheetName);
        if (is_file($path)) {
            @unlink($path);
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                foreach ($files as $name => $content) {
                    $zip->addFromString($name, $content);
                }
                $zip->close();
                if (is_file($path) && filesize($path) > 0) {
                    return true;
                }
            }
        }
        return melkinoZipStoreWrite($path, $files);
    }
}

if (!function_exists('melkinoXlsxRead')) {
    /** @return array{headers:list<string>,rows:list<array<string,string>>} */
    function melkinoXlsxRead(string $path): array
    {
        if (!is_file($path)) {
            return ['headers' => [], 'rows' => []];
        }
        $files = melkinoZipReadFiles($path);
        $shared = [];
        $ss = $files['xl/sharedStrings.xml'] ?? '';
        if ($ss !== '') {
            if (preg_match_all('/<si[^>]*>(.*?)<\\/si>/s', $ss, $sis)) {
                foreach ($sis[1] as $si) {
                    $text = '';
                    if (preg_match_all('/<t[^>]*>(.*?)<\\/t>/s', $si, $ts)) {
                        $text = html_entity_decode(implode('', $ts[1]), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    }
                    $shared[] = $text;
                }
            }
        }
        $sheet = $files['xl/worksheets/sheet1.xml'] ?? '';
        if ($sheet === '') {
            return ['headers' => [], 'rows' => []];
        }
        $grid = [];
        if (preg_match_all('/<row[^>]*>(.*?)<\\/row>/s', $sheet, $rowMatches)) {
            foreach ($rowMatches[1] as $rowXml) {
                $line = [];
                if (preg_match_all('/<c\\s([^>]*)>(.*?)<\\/c>/s', $rowXml, $cells, PREG_SET_ORDER)) {
                    foreach ($cells as $cell) {
                        $attrs = $cell[1];
                        $inner = $cell[2];
                        $ref = '';
                        if (preg_match('/r="([A-Z]+)(\\d+)"/', $attrs, $rm)) {
                            $ref = $rm[1];
                        }
                        $ci = 0;
                        if ($ref !== '') {
                            $n = 0;
                            $len = strlen($ref);
                            for ($i = 0; $i < $len; $i++) {
                                $n = $n * 26 + (ord($ref[$i]) - 64);
                            }
                            $ci = $n - 1;
                        }
                        $t = '';
                        if (preg_match('/t="([^"]+)"/', $attrs, $tm)) {
                            $t = $tm[1];
                        }
                        $val = '';
                        if ($t === 's' && preg_match('/<v[^>]*>(.*?)<\\/v>/s', $inner, $vm)) {
                            $idx = (int)$vm[1];
                            $val = (string)($shared[$idx] ?? '');
                        } elseif ($t === 'inlineStr' && preg_match('/<t[^>]*>(.*?)<\\/t>/s', $inner, $tm2)) {
                            $val = html_entity_decode($tm2[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                        } elseif (preg_match('/<v[^>]*>(.*?)<\\/v>/s', $inner, $vm)) {
                            $val = html_entity_decode($vm[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                        }
                        $line[$ci] = $val;
                    }
                }
                if ($line) {
                    ksort($line);
                    $grid[] = $line;
                }
            }
        }
        if (!$grid) {
            return ['headers' => [], 'rows' => []];
        }
        $max = 0;
        foreach ($grid as $line) {
            $max = max($max, $line ? max(array_keys($line)) + 1 : 0);
        }
        $headers = [];
        for ($i = 0; $i < $max; $i++) {
            $headers[] = trim((string)($grid[0][$i] ?? ''));
        }
        $rows = [];
        for ($r = 1; $r < count($grid); $r++) {
            $assoc = [];
            $empty = true;
            for ($i = 0; $i < $max; $i++) {
                $h = $headers[$i] !== '' ? $headers[$i] : ('col' . $i);
                $v = trim((string)($grid[$r][$i] ?? ''));
                $assoc[$h] = $v;
                if ($v !== '') {
                    $empty = false;
                }
            }
            if (!$empty) {
                $rows[] = $assoc;
            }
        }
        return ['headers' => $headers, 'rows' => $rows];
    }
}
