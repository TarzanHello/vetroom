<?php
/**
 * MiniPDF - Simple PDF generator (no external deps).
 * Supports: single/multi pages, base14 font (Helvetica), basic text, and JPEG images.
 * This is intentionally minimal to keep VetRoom lightweight.
 * NOT a complete PDF library.
 */
class MiniPDF {
    private $pages = [];
    private $images = [];
    private $w = 595.28; // A4 width in points (72dpi) 210mm
    private $h = 841.89; // A4 height in points (72dpi) 297mm
    private $cursorX = 40; // default margin-left
    private $cursorY = 801.89; // top margin (h - 40)
    private $lineHeight = 14;
    private $objects = [];
    private $offsets = [];
    private $objNum = 0;
    private $pagesObjs = [];
    private $contentStreams = [];
    // Multiple base14 Helvetica variants (no external font files needed)
    // Keys are PDF resource names used in content streams.
    private $fonts = [
        'F1' => ['name' => 'Helvetica', 'size' => 12],
        'F2' => ['name' => 'Helvetica-Bold', 'size' => 12],
        'F3' => ['name' => 'Helvetica-Oblique', 'size' => 12],
        'F4' => ['name' => 'Helvetica-BoldOblique', 'size' => 12],
    ];
    private $currentFontKey = 'F1';

    // Character width tables for Base14 Helvetica variants (WinAnsi / Windows-1252).
    // Widths are in 1/1000 em. Used for accurate wrapping + right/center alignment.
    private $fontWidths = [
        'Helvetica' => [0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,278,278,355,556,556,889,667,222,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,222,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,333,556,556,278,556,556,556,556,191,333,556,333,333,500,500,500,556,556,556,278,500,537,350,222,333,333,556,1000,1000,500,611,500,333,333,333,333,333,333,333,333,500,333,333,500,333,333,333,1000,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,1000,500,370,500,500,500,500,556,778,1000,365,500,500,500,500,500,889,500,500,500,278,500,500,222,611,944,611,500,500,500,500],
        'Helvetica-Bold' => [0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,278,333,474,556,556,889,722,278,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,278,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,333,556,556,278,556,556,556,556,238,500,556,333,333,611,611,500,556,556,556,278,500,556,350,278,500,500,556,1000,1000,500,611,500,333,333,333,333,333,333,333,333,500,333,333,500,333,333,333,1000,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,1000,500,370,500,500,500,500,611,778,1000,365,500,500,500,500,500,889,500,500,500,278,500,500,278,611,944,611,500,500,500,500],
        'Helvetica-Oblique' => [0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,278,278,355,556,556,889,667,222,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,222,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,333,556,556,278,556,556,556,556,191,333,556,333,333,500,500,500,556,556,556,278,500,537,350,222,333,333,556,1000,1000,500,611,500,333,333,333,333,333,333,333,333,500,333,333,500,333,333,333,1000,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,1000,500,370,500,500,500,500,556,778,1000,365,500,500,500,500,500,889,500,500,500,278,500,500,222,611,944,611,500,500,500,500],
        'Helvetica-BoldOblique' => [0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,278,333,474,556,556,889,722,278,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,278,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,333,556,556,278,556,556,556,556,238,500,556,333,333,611,611,500,556,556,556,278,500,556,350,278,500,500,556,1000,1000,500,611,500,333,333,333,333,333,333,333,333,500,333,333,500,333,333,333,1000,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,500,1000,500,370,500,500,500,500,611,778,1000,365,500,500,500,500,500,889,500,500,500,278,500,500,278,611,944,611,500,500,500,500],
    ];

    public function __construct() {}

    // --- Encoding helpers (WinAnsi / Windows-1252) ---
    // Base14 fonts like Helvetica typically expect WinAnsiEncoding. We therefore
    // convert UTF-8 text (Italian accents, etc.) to Windows-1252 when possible.
    private function toWin1252(string $s): string {
        if ($s === '') return $s;
        if (!function_exists('iconv')) return $s;
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        if ($out === false) {
            $out = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
        }
        return ($out === false) ? $s : $out;
    }

    public function setMargins(float $left, float $top): void {
        $this->cursorX = $left;
        $this->cursorY = $this->h - $top;
    }
    // --- VetRoom extensions for easier layout control ---
    public function setCursor(float $x, float $y): void {
        $this->cursorX = $x;
        $this->cursorY = $this->h - $y;
    }

    public function getTopY(): float {
        return $this->h - $this->cursorY;
    }

    public function setLineHeight(float $h): void {
        $this->lineHeight = $h;
    }

    public function multiLineAt(float $x, float $y, string $txt, float $maxWidth=515): void {
        $this->setCursor($x, $y);
        $this->multiLine($txt, $maxWidth);
    }

    public function imageJpegFit(string $path, float $x, float $y, float $maxW, float $maxH): void {
        if (!file_exists($path)) return;
        $info = @getimagesize($path);
        if (!$info) { return; }
        $iw = floatval($info[0]); $ih = floatval($info[1]);
        if ($iw <= 0 || $ih <= 0) { return; }
        $ratio = min($maxW / $iw, $maxH / $ih);
        $w = $iw * $ratio;
        $h = $ih * $ratio;
        $dx = $x + ($maxW - $w) / 2.0;
        $dy = $y + ($maxH - $h) / 2.0;
        $this->imageJpeg($path, $dx, $dy, $w, $h);
    }


    public function addPage(): void {
        $this->pages[] = true;
        $this->contentStreams[] = '';
        $this->cursorX = 40;
        $this->cursorY = $this->h - 40;
    }

    public function setFont(string $family='Helvetica', string $style='', float $size=12): void {
        // Only Helvetica base14 supported.
        // Styles supported: '' (regular), 'B', 'I', 'BI'.
        $style = strtoupper(trim($style));
        $key = 'F1';
        if ($style === 'B') $key = 'F2';
        else if ($style === 'I') $key = 'F3';
        else if ($style === 'BI' || $style === 'IB') $key = 'F4';

        // Update current font size on the selected key
        if (!isset($this->fonts[$key])) {
            $this->fonts[$key] = ['name' => 'Helvetica', 'size' => $size];
        } else {
            $this->fonts[$key]['size'] = $size;
        }
        $this->currentFontKey = $key;
        $this->writeRaw(sprintf("BT /%s %0.2f Tf ET\n", $key, $size));
    }

    public function ln(float $h=null): void {
        $this->cursorY -= ($h ?? $this->lineHeight);
        $this->cursorX = 40;
    }

    public function text(float $x, float $y, string $txt): void {
        $this->writeTextAt($x, $y, $txt);
    }

    public function write(string $txt, float $x=null, float $y=null): void {
        if ($x !== null && $y !== null) {
            $this->writeTextAt($x, $y, $txt);
        } else {
            $this->writeTextAt($this->cursorX, $this->cursorY, $txt);
            $this->cursorY -= $this->lineHeight;
        }
    }

    public function multiLine(string $txt, float $maxWidth=515): void {
        $lines = $this->wrapText($txt, $maxWidth, $this->fonts[$this->currentFontKey]['size']);
        foreach ($lines as $line) {
            $this->write($line);
        }
    }

    public function imageJpeg(string $path, float $x, float $y, float $w, float $h): void {
        if (!file_exists($path)) return;
        $info = @getimagesize($path);
        if (!$info || ($info[2] !== IMAGETYPE_JPEG && $info['mime'] !== 'image/jpeg')) {
            // Try to convert if GD is available
            if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
                $data = file_get_contents($path);
                if ($data !== false) {
                    $im = @imagecreatefromstring($data);
                    if ($im) {
                        $tmp = sys_get_temp_dir() . '/minipdf_tmp_' . uniqid() . '.jpg';
                        imagejpeg($im, $tmp, 90);
                        imagedestroy($im);
                        $path = $tmp;
                        $info = @getimagesize($path);
                    }
                }
            }
        }
        if (!$info) return;
        $imgData = file_get_contents($path);
        if ($imgData === false) return;
        $obj = [
            'data' => $imgData,
            'w' => $info[0],
            'h' => $info[1],
            'n' => 'Im'.(count($this->images)+1),
            'type' => 'DCTDecode' // JPEG
        ];
        $this->images[] = $obj;
        $this->writeRaw(sprintf("q %0.2f 0 0 %0.2f %0.2f %0.2f cm /%s Do Q\n", $w, $h, $x, $this->h - $y - $h, $obj['n']));
    }

    
    // ---- Drawing helpers and extra text alignment ----
    public function fillRect(float $x, float $yTop, float $w, float $h, array $rgb=null): void {
        // Coordinates in points; yTop measured from top.
        // Optional $rgb = [r,g,b] in 0..255 (kept local thanks to q/Q).
        $y = $this->h - $yTop - $h;
        if (is_array($rgb) && count($rgb) === 3) {
            $r = max(0, min(255, (int)$rgb[0])) / 255.0;
            $g = max(0, min(255, (int)$rgb[1])) / 255.0;
            $b = max(0, min(255, (int)$rgb[2])) / 255.0;
            $this->writeRaw(sprintf("q %0.3f %0.3f %0.3f rg %0.2f %0.2f %0.2f %0.2f re f Q\n", $r, $g, $b, $x, $y, $w, $h));
        } else {
            $this->writeRaw(sprintf("q %0.2f %0.2f %0.2f %0.2f re f Q\n", $x, $y, $w, $h));
        }
    }

    public function strokeRect(float $x, float $yTop, float $w, float $h, float $lineWidth=1.0, array $rgb=null): void {
        $y = $this->h - $yTop - $h;
        if (is_array($rgb) && count($rgb) === 3) {
            $r = max(0, min(255, (int)$rgb[0])) / 255.0;
            $g = max(0, min(255, (int)$rgb[1])) / 255.0;
            $b = max(0, min(255, (int)$rgb[2])) / 255.0;
            $this->writeRaw(sprintf("q %0.2f w %0.3f %0.3f %0.3f RG %0.2f %0.2f %0.2f %0.2f re S Q\n", $lineWidth, $r, $g, $b, $x, $y, $w, $h));
        } else {
            $this->writeRaw(sprintf("q %0.2f w %0.2f %0.2f %0.2f %0.2f re S Q\n", $lineWidth, $x, $y, $w, $h));
        }
    }

    public function multiLineRightAt(float $xRight, float $yTop, string $txt, float $maxWidth=515): void {
        // Render right-aligned within a box whose right edge is xRight and width is maxWidth
        $fontSize = $this->fonts[$this->currentFontKey]['size'];
        $lines = $this->wrapText($txt, $maxWidth, $fontSize);
        $y = $yTop;
        $fontName = (string)($this->fonts[$this->currentFontKey]['name'] ?? 'Helvetica');
        foreach ($lines as $line) {
            $lineWidth = $this->stringWidth($line, $fontName, $fontSize);
            $x = $xRight - min($lineWidth, $maxWidth);
            $this->write($line, $x, $y);
            $y += $this->lineHeight;
        }
    }

    // --- Public helpers for templates/layout engines ---
    public function getLineHeight(): float {
        return (float)$this->lineHeight;
    }

    public function getFontSize(): float {
        return (float)($this->fonts[$this->currentFontKey]['size'] ?? 12);
    }


    // --- Text measurement for alignment / wrapping ---
    public function getStringWidth(string $txt): float {
        $key = $this->currentFontKey;
        $size = (float)($this->fonts[$key]['size'] ?? 12);
        $name = (string)($this->fonts[$key]['name'] ?? 'Helvetica');
        return $this->stringWidth($txt, $name, $size);
    }

    public function writeAlignedAt(float $xLeft, float $yTop, float $wBox, string $txt, string $align='L'): void {
        $align = strtoupper(trim($align));
        if ($align !== 'C' && $align !== 'R') $align = 'L';
        $lw = $this->getStringWidth($txt);
        if ($wBox > 0 && $lw > $wBox) $lw = $wBox;
        $x = $xLeft;
        if ($align === 'R') $x = $xLeft + max(0.0, $wBox - $lw);
        else if ($align === 'C') $x = $xLeft + max(0.0, ($wBox - $lw) / 2.0);
        $this->write($txt, $x, $yTop);
    }

    private function stringWidth(string $txt, string $fontName, float $fontSize): float {
        $tbl = $this->fontWidths[$fontName] ?? null;
        if (!is_array($tbl) || count($tbl) < 128) {
            // Fallback: old heuristic (average char width)
            $avg = 0.5 * $fontSize;
            return strlen($this->toWin1252($txt)) * $avg;
        }
        $s = $this->toWin1252($txt);
        $sum = 0;
        $len = strlen($s);
        for ($i=0; $i<$len; $i++) {
            $code = ord($s[$i]);
            $sum += $tbl[$code] ?? 500;
        }
        return ($sum / 1000.0) * $fontSize;
    }

    public function wrapTextLines(string $txt, float $maxWidth): array {
        $fontSize = $this->getFontSize();
        return $this->wrapText($txt, $maxWidth, $fontSize);
    }

    private function wrapText(string $txt, float $maxWidth, float $fontSize): array {
        // Width-based word wrapping using Base14 font metrics (no external deps).
        // Keeps the original UTF-8 text; width calculations use Windows-1252/WinAnsi.
        $txt = str_replace("\r", '', $txt);
        $out = [];
        $fontName = (string)($this->fonts[$this->currentFontKey]['name'] ?? 'Helvetica');

        foreach (explode("\n", $txt) as $para) {
            $p = trim((string)$para);
            if ($p === '') {
                $out[] = '';
                continue;
            }
            $words = preg_split('/\s+/', $p);
            $line = '';
            foreach ($words as $word) {
                if ($word === '') continue;
                $test = ($line === '') ? $word : ($line . ' ' . $word);
                if ($this->stringWidth($test, $fontName, $fontSize) <= $maxWidth) {
                    $line = $test;
                    continue;
                }
                if ($line !== '') {
                    $out[] = $line;
                    $line = '';
                }
                // If the single word is longer than maxWidth, split by characters.
                if ($this->stringWidth($word, $fontName, $fontSize) > $maxWidth) {
                    $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
                    $chunk = '';
                    foreach ($chars as $ch) {
                        $trial = $chunk . $ch;
                        if ($chunk !== '' && $this->stringWidth($trial, $fontName, $fontSize) > $maxWidth) {
                            $out[] = $chunk;
                            $chunk = $ch;
                        } else {
                            $chunk = $trial;
                        }
                    }
                    $line = $chunk;
                } else {
                    $line = $word;
                }
            }
            if ($line !== '') $out[] = $line;
        }
        return $out;
    }


    private function writeTextAt(float $x, float $y, string $txt): void {
        $txt = $this->escape($this->toWin1252($txt));
        $key = $this->currentFontKey;
        $size = $this->fonts[$key]['size'] ?? 12;
        $stream = sprintf("BT /%s %0.2f Tf %0.2f %0.2f Td (%s) Tj ET\n",
            $key,
            $size,
            $x,
            $this->h - $y,
            $txt
        );
        $this->writeRaw($stream);
    }

    private function writeRaw(string $s): void {
        $idx = count($this->contentStreams) - 1;
        if ($idx < 0) $this->addPage();
        $this->contentStreams[$idx] .= $s;
    }

    private function escape(string $s): string {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    public function output(string $path): bool {
        // Build objects: catalog, pages, fonts, images, content, page objects
        $this->objects = [];
        $this->offsets = [];
        $this->objNum = 0;

        $numPages = count($this->contentStreams);
        $pagesKids = [];

        // Font objects (Base14 Helvetica variants)
        $fontObjNums = [];
        foreach ($this->fonts as $key => $font) {
            $base = $font['name'] ?? 'Helvetica';
            $n = $this->newObj();
            $fontObjNums[$key] = $n;
            $this->objects[$n] = "<< /Type /Font /Subtype /Type1 /BaseFont /{$base} >>\nendobj\n";
        }

        // Image objects
        $imageObjNums = [];
        foreach ($this->images as $img) {
            $n = $this->newObj();
            $imageObjNums[$img['n']] = $n;
            $this->objects[$n]  = "<< /Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']} ";
            $this->objects[$n] .= "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /{$img['type']} /Length ".strlen($img['data'])." >>\nstream\n";
            $this->objects[$n] .= $img['data'];
            $this->objects[$n] .= "\nendstream\nendobj\n";
        }

        // For each page: content stream + page object
        $resources = "<< /Font <<";
        foreach ($fontObjNums as $key => $num) {
            $resources .= " /{$key} {$num} 0 R";
        }
        $resources .= " >>";
        if (!empty($imageObjNums)) {
            $resources .= " /XObject <<";
            foreach ($imageObjNums as $name => $num) {
                $resources .= " /{$name} {$num} 0 R";
            }
            $resources .= " >>";
        }
        $resources .= " >>";

        $contentObjNums = [];
        $pageObjNums = [];
        for ($i=0; $i<$numPages; $i++) {
            $content = $this->contentStreams[$i];
            $cNum = $this->newObj();
            $contentObjNums[] = $cNum;
            $this->objects[$cNum] = "<< /Length ".strlen($content)." >>\nstream\n".$content."\nendstream\nendobj\n";

            $pNum = $this->newObj();
            $pageObjNums[] = $pNum;
            $this->objects[$pNum] = "<< /Type /Page /Parent %PAGES% /Resources {$resources} /MediaBox [0 0 {$this->w} {$this->h}] /Contents {$cNum} 0 R >>\nendobj\n";
            $pagesKids[] = "{$pNum} 0 R";
        }

        // Pages object
        $pagesObjNum = $this->newObj();
        $this->objects[$pagesObjNum] = "<< /Type /Pages /Count {$numPages} /Kids [ ".implode(' ', $pagesKids)." ] >>\nendobj\n";

        // Replace %PAGES% in page objects
        foreach ($pageObjNums as $pNum) {
            $this->objects[$pNum] = str_replace('%PAGES%', "{$pagesObjNum} 0 R", $this->objects[$pNum]);
        }

        // Catalog
        $catalogNum = $this->newObj();
        $this->objects[$catalogNum] = "<< /Type /Catalog /Pages {$pagesObjNum} 0 R >>\nendobj\n";

        // Build file
        $out = "%PDF-1.4\n%âãÏÓ\n";
        foreach ($this->objects as $num => $obj) {
            $this->offsets[$num] = strlen($out);
            $out .= "{$num} 0 obj\n{$obj}";
        }
        $xrefPos = strlen($out);
        $out .= "xref\n0 ".($this->objNum+1)."\n";
        $out .= "0000000000 65535 f \n";
        for ($i=1; $i<=$this->objNum; $i++) {
            $offset = $this->offsets[$i] ?? 0;
            $out .= sprintf("%010d 00000 n \n", $offset);
        }
        $out .= "trailer << /Size ".($this->objNum+1)." /Root {$catalogNum} 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

        return file_put_contents($path, $out) !== false;
    }

    private function newObj(): int {
        $this->objNum++;
        return $this->objNum;
    }
}
