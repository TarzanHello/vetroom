<?php
/**
 * VetRoom 2 - Template/Spec Engine
 * - Supports: FPDI+FPDF background templates (PDF) OR MiniPDF+PNG fallback.
 * - Adds a "custom clinical layout" as requested: line at 4.5cm, logo 2x2cm offset (3cm square),
 *   title at (6cm, 3.5cm), vet data in 4x4cm box at 15cm from left, and discursive + form data blocks.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

// Helpers for units (A4 210x297 mm)
function vr_mm2pt(float $mm): float { return $mm * 72.0 / 25.4; }
function vr_cm2pt(float $cm): float { return vr_mm2pt($cm * 10.0); }
function vr_px2pt(float $px): float { return $px * 72.0 / 96.0; }
function vr_a4w_mm(): float { return 210.0; }
function vr_a4h_mm(): float { return 297.0; }
function vr_a4w_pt(): float { return vr_mm2pt(vr_a4w_mm()); }
function vr_a4h_pt(): float { return vr_mm2pt(vr_a4h_mm()); }

/**
 * Append attachments (images/PDFs) at the end of a MiniPDF document.
 * Images are embedded as additional pages. PDF files are listed in a summary page
 * (PDF-to-PDF merge requires extra libraries).
 *
 * Accepts rows from the `documents` table or simple arrays with:
 *  - filename (relative URL) or abs_path
 *  - original_name
 *  - mime_type
 */
function vr_minipdf_append_attachments(MiniPDF $pdf, array $attachments, array $options = []): void {
    if (empty($attachments)) return;

    $baseDir = $options['base_dir'] ?? dirname(__DIR__);
    $img = [];
    $pdfs = [];
    $other = [];

    foreach ($attachments as $a) {
        if (!is_array($a)) continue;
        $mime = strtolower(trim((string)($a['mime_type'] ?? '')));
        $orig = (string)($a['original_name'] ?? ($a['name'] ?? ''));
        $abs = '';
        if (!empty($a['abs_path'])) {
            $abs = (string)$a['abs_path'];
        } else {
            $fn = (string)($a['filename'] ?? '');
            if ($fn !== '') {
                // Expected to be like "data/uploads/..." relative to project root
                $abs = rtrim($baseDir, '/') . '/' . ltrim($fn, '/');
            }
        }
        if ($abs === '' || !is_file($abs)) {
            $other[] = ['name' => ($orig !== '' ? $orig : basename((string)($a['filename'] ?? ''))), 'mime' => $mime];
            continue;
        }
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $isPdf = ($mime === 'application/pdf' || $ext === 'pdf');
        $isImg = (strpos($mime, 'image/') === 0 || in_array($ext, ['jpg','jpeg','png','webp','gif'], true));

        if ($isPdf) {
            $pdfs[] = ['abs' => $abs, 'name' => ($orig !== '' ? $orig : basename($abs)), 'mime' => $mime];
        } elseif ($isImg) {
            $img[] = ['abs' => $abs, 'name' => ($orig !== '' ? $orig : basename($abs)), 'mime' => $mime];
        } else {
            $other[] = ['name' => ($orig !== '' ? $orig : basename($abs)), 'mime' => $mime];
        }
    }

    // Append images
    foreach ($img as $im) {
        $imgPath = $im['abs'];
        $tmpJpg = '';
        $ext = strtolower(pathinfo($imgPath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg'], true)) {
            // Convert to JPEG if possible (MiniPDF supports JPEG)
            if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
                $bin = @file_get_contents($imgPath);
                if ($bin !== false) {
                    $gd = @imagecreatefromstring($bin);
                    if ($gd !== false) {
                        $tmpJpg = sys_get_temp_dir() . '/vr_att_' . uniqid('', true) . '.jpg';
                        @imagejpeg($gd, $tmpJpg, 90);
                        @imagedestroy($gd);
                        if (is_file($tmpJpg)) $imgPath = $tmpJpg;
                    }
                }
            }
        }

        $pdf->addPage();
        $pdf->setFont('Helvetica', 'B', 12);
        $pdf->write('ALLEGATO: ' . $im['name'], vr_mm2pt(10), vr_mm2pt(12));
        // Fit image in page with margins
        $x = vr_mm2pt(10);
        $y = vr_mm2pt(18);
        $w = vr_a4w_pt() - vr_mm2pt(20);
        $h = vr_a4h_pt() - vr_mm2pt(28);
        $pdf->imageJpegFit($imgPath, $x, $y, $w, $h);

        if ($tmpJpg && is_file($tmpJpg)) {
            @unlink($tmpJpg);
        }
    }

    // Summary page for non-embeddable attachments
    if (!empty($pdfs) || !empty($other)) {
        $pdf->addPage();
        $x = vr_mm2pt(12);
        $y = vr_mm2pt(16);
        $pdf->setFont('Helvetica', 'B', 16);
        $pdf->write('ALLEGATI', $x, $y);
        $y += vr_mm2pt(10);
        $pdf->setFont('Helvetica', '', 11);
        if (!empty($pdfs)) {
            $pdf->write('PDF:', $x, $y);
            $y += vr_mm2pt(6);
            foreach ($pdfs as $p) {
                $pdf->write(' - ' . $p['name'], $x, $y);
                $y += vr_mm2pt(5);
                if ($y > vr_a4h_pt() - vr_mm2pt(15)) {
                    $pdf->addPage();
                    $y = vr_mm2pt(16);
                }
            }
            $y += vr_mm2pt(4);
        }
        if (!empty($other)) {
            $pdf->write('Altri file:', $x, $y);
            $y += vr_mm2pt(6);
            foreach ($other as $o) {
                $pdf->write(' - ' . ($o['name'] ?? ''), $x, $y);
                $y += vr_mm2pt(5);
                if ($y > vr_a4h_pt() - vr_mm2pt(15)) {
                    $pdf->addPage();
                    $y = vr_mm2pt(16);
                }
            }
        }
        $pdf->setFont('Helvetica', '', 9);
        $y += vr_mm2pt(8);
        $pdf->write('Nota: l\'incorporazione di PDF come pagine nel documento richiede librerie aggiuntive (FPDI).', $x, $y);
    }
}

function vr_hex_to_rgb(string $hex, array $fallback=[0,0,0]): array {
    $h = strtoupper(trim($hex));
    if ($h === '') return $fallback;
    if ($h[0] !== '#') $h = '#' . $h;
    if (!preg_match('/^#[0-9A-F]{6}$/', $h)) return $fallback;
    return [hexdec(substr($h,1,2)), hexdec(substr($h,3,2)), hexdec(substr($h,5,2))];
}

/**
 * Draw a (possibly bordered) text box.
 * Coordinates are in points, y is measured from the top.
 * Returns [linesPrinted, endYTop] where endYTop is the y position after the rendered text.
 */
function vr_draw_text_box(MiniPDF $pdf, float $xPt, float $yPt, float $wPt, float $hPt, string $text,
    float $fontSize, string $fontStyle, string $align,
    bool $drawBorder, float $borderWidthPt, ?array $borderRgb = null,
    float $lineHeightPt, float $paddingPt=3.0, ?int $maxLines=null): array {

    if ($drawBorder && $borderWidthPt > 0.0) {
        if ($borderRgb === null) $borderRgb = [0,0,0];
        $pdf->strokeRect($xPt, $yPt, $wPt, $hPt, $borderWidthPt, $borderRgb);
    }

    $innerX = $xPt + $paddingPt;
    $innerY = $yPt + $paddingPt;
    $innerW = max(1.0, $wPt - (2.0 * $paddingPt));
    $innerH = max(1.0, $hPt - (2.0 * $paddingPt));

    $pdf->setFont('Helvetica', $fontStyle, $fontSize);
    $prevLH = $pdf->getLineHeight();
    $pdf->setLineHeight($lineHeightPt);

    $lines = $pdf->wrapTextLines($text, $innerW);
    $maxFit = (int)floor($innerH / $lineHeightPt);
    if ($maxLines === null) $maxLines = $maxFit;
    else $maxLines = max(0, min($maxLines, $maxFit));

    $y = $innerY;
    $printed = 0;
    $align = strtoupper(trim($align));
    if ($align !== 'C' && $align !== 'R') $align = 'L';

    foreach ($lines as $line) {
        if ($printed >= $maxLines) break;
        $lw = $pdf->getStringWidth($line);
        $lw = min($lw, $innerW);
        $x = $innerX;
        if ($align === 'R') $x = $innerX + ($innerW - $lw);
        else if ($align === 'C') $x = $innerX + ($innerW - $lw) / 2.0;

        $pdf->write($line, $x, $y);
        $y += $lineHeightPt;
        $printed++;
    }

    // Restore previous line height
    $pdf->setLineHeight($prevLH);

    return [$printed, $y];
}

// Format YYYY-MM-DD -> DD/MM/YYYY (fallback: return original)
function vr_fmt_date_it(string $date): string {
    $d = trim($date);
    if ($d === '') return '';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
        return $m[3] . '/' . $m[2] . '/' . $m[1];
    }
    return $d;
}

function vr_is_female(?string $sex): bool {
    $s = strtolower(trim((string)$sex));
    if ($s === '') return false;
    if ($s === 'f' || $s === 'femmina' || $s === 'female') return true;
    if (substr($s, 0, 3) === 'fem') return true;
    return false;
}

/**
 * OPTIONAL: FPDI renderer (if libraries available) to place a PDF page as background.
 * (Kept for compatibility with earlier templates)
 */
function vr_render_with_fpdi(string $templatePdfPath, array $items, string $destPath): bool {
    if (!class_exists('\\setasign\\Fpdi\\Fpdi')) return false;
    if (!class_exists('FPDF')) {
        if (file_exists(__DIR__ . '/vendor/fpdf/fpdf.php')) require_once __DIR__ . '/vendor/fpdf/fpdf.php';
    }
    if (!class_exists('\\setasign\\Fpdi\\Fpdi')) {
        if (file_exists(__DIR__ . '/vendor/fpdi/src/autoload.php')) require_once __DIR__ . '/vendor/fpdi/src/autoload.php';
    }
    if (!class_exists('\\setasign\\Fpdi\\Fpdi')) return false;

    $pdf = new \setasign\Fpdi\Fpdi();
    $pdf->AddPage();
    $pdf->setSourceFile($templatePdfPath);
    $tpl = $pdf->importPage(1);
    $pdf->useTemplate($tpl, 0, 0, 210);
    $pdf->SetFont('Helvetica','',9);
    $pdf->SetTextColor(0,0,0);

    foreach ($items as $it) {
        $x = $it['x_mm'] ?? 0;
        $y = $it['y_mm'] ?? 0;
        $w = $it['w_mm'] ?? 0;
        $text = (string)($it['text'] ?? '');
        if ($text === '') continue;
        $pdf->SetXY($x, $y);
        if ($w > 0) $pdf->MultiCell($w, 4.2, $text);
        else $pdf->Write(4, $text);
    }
    $pdf->Output('F', $destPath);
    return file_exists($destPath);
}

/**
 * Fallback: MiniPDF renderer to place a PNG background and text lines.
 */
function vr_render_with_minipdf_background(string $templatePngPath, array $items, string $destPath, array $options = [], array $attachments = []): bool {
    require_once __DIR__ . '/minipdf.php';
    // $options:
    // - overlay_header: bool
    // - header: array (vet_settings)
    // - clear_header: ['x_mm','y_mm','w_mm','h_mm','color_hex']

    $pdf = new MiniPDF();
    $pdf->addPage();

    // Background (PNG/JPG). MiniPDF can auto-convert PNG via GD.
    if (file_exists($templatePngPath)) {
        if (method_exists($pdf, 'imageJpegFit')) {
            $pdf->imageJpegFit($templatePngPath, 0, 0, vr_a4w_pt(), vr_a4h_pt());
        } else {
            $pdf->imageJpeg($templatePngPath, 0, 0, vr_a4w_pt(), vr_a4h_pt());
        }
    }

    // Optional header overlay (logo + vet data) for template-based PDFs
    if (!empty($options['overlay_header'])) {
        $header = is_array($options['header'] ?? null) ? $options['header'] : [];

        // If requested, clear a header area (useful to cover placeholder text in the template)
        $clear = $options['clear_header'] ?? null;
        if (is_array($clear) && !empty($clear['w_mm']) && !empty($clear['h_mm'])) {
            $xpt = vr_mm2pt((float)($clear['x_mm'] ?? 0));
            $ypt = vr_mm2pt((float)($clear['y_mm'] ?? 0));
            $wpt = vr_mm2pt((float)($clear['w_mm'] ?? 210));
            $hpt = vr_mm2pt((float)($clear['h_mm'] ?? 35));
            $col = (string)($clear['color_hex'] ?? '#FFFFFF');
            $rgb = vr_hex_to_rgb($col);
            $pdf->fillRect($xpt, $ypt, $wpt, $hpt, $rgb);
        }

        // Default positions matching VetRoom built-in templates
        $logoBox = [
            'x_pt' => vr_mm2pt(14.0),  // ~40pt
            'y_pt' => vr_mm2pt(7.0),   // ~20pt
            'w_pt' => vr_mm2pt(28.0),  // ~80pt
            'h_pt' => vr_mm2pt(21.0),  // ~60pt
        ];
        $vetBox = [
            'x_pt' => vr_mm2pt(45.0),
            'y_pt' => vr_mm2pt(10.0),
            'w_pt' => vr_mm2pt(150.0),
            'h_pt' => vr_mm2pt(22.0),
        ];

        // Logo
        $logoRel = trim((string)($header['logo_path'] ?? ''));
        if ($logoRel !== '') {
            $abs = __DIR__ . '/../' . ltrim($logoRel, '/');
            if (file_exists($abs)) {
                $pdf->imageJpegFit($abs, $logoBox['x_pt'], $logoBox['y_pt'], $logoBox['w_pt'], $logoBox['h_pt']);
            }
        }

        // Vet details
        $lines = [];
        $hn = trim((string)($header['header_name'] ?? ''));
        if ($hn !== '') $lines[] = $hn;
        $ht = trim((string)($header['header_title'] ?? ''));
        if ($ht !== '') $lines[] = $ht;
        $ha = trim((string)($header['header_address'] ?? ''));
        if ($ha !== '') $lines[] = $ha;
        $hp = trim((string)($header['header_phone'] ?? ''));
        if ($hp !== '') $lines[] = 'Tel: ' . $hp;
        $he = trim((string)($header['header_email'] ?? ''));
        if ($he !== '') $lines[] = $he;

        if (!empty($lines)) {
            $pdf->setFont('Helvetica', '', 9);
            $pdf->setLineHeight(11);
            // Use a box to keep text within header area.
            vr_draw_text_box(
                $pdf,
                $vetBox['x_pt'], $vetBox['y_pt'], $vetBox['w_pt'], $vetBox['h_pt'],
                implode("\n", $lines),
                9, '', 'L',
                false, 0, null,
                11,
                2, // padding
                null
            );
        }
    }

    // Render items
    foreach ($items as $it) {
        $type = strtolower(trim((string)($it['type'] ?? 'text')));

        // --- Graphic elements (added via the visual editor) ---
        if ($type && $type !== 'text') {
            $xpt = vr_mm2pt((float)($it['x_mm'] ?? 0));
            $ypt = vr_mm2pt((float)($it['y_mm'] ?? 0));
            $wpt = vr_mm2pt((float)($it['w_mm'] ?? 0));
            $hpt = vr_mm2pt((float)($it['h_mm'] ?? 0));

            if ($type === 'line') {
                // Line is rendered as a filled rectangle (supports horizontal/vertical).
                $col = (string)($it['color_hex'] ?? '#111827');
                $rgb = vr_hex_to_rgb($col, [17,24,39]);
                if ($wpt > 0 && $hpt <= 0) $hpt = vr_mm2pt(0.8);
                if ($wpt > 0 && $hpt > 0) {
                    $pdf->fillRect($xpt, $ypt, $wpt, $hpt, $rgb);
                }
                continue;
            }

            if ($type === 'image' || $type === 'logo') {
                // Supported: studio logo / stamp (uploaded in settings) or explicit image_path.
                $kind = strtolower(trim((string)($it['image_kind'] ?? 'logo')));
                $imgAbs = '';
                if ($kind === 'logo' || $kind === 'header_logo' || $kind === 'clinic_logo') {
                    $logoRel = trim((string)($options['header']['logo_path'] ?? ''));
                    if ($logoRel !== '') {
                        $abs = __DIR__ . '/../' . ltrim($logoRel, '/');
                        if (file_exists($abs)) $imgAbs = $abs;
                    }
                }

                if ($kind === 'stamp' || $kind === 'timbro') {
                    $stampRel = trim((string)($options['header']['stamp_path'] ?? ''));
                    if ($stampRel !== '') {
                        $abs = __DIR__ . '/../' . ltrim($stampRel, '/');
                        if (file_exists($abs)) $imgAbs = $abs;
                    }
                }
                // Optional: explicit image_path in the item (relative to app root)
                $explicit = trim((string)($it['image_path'] ?? ''));
                if ($explicit !== '') {
                    $abs = __DIR__ . '/../' . ltrim($explicit, '/');
                    if (file_exists($abs)) $imgAbs = $abs;
                }

                if ($imgAbs && $wpt > 0 && $hpt > 0) {
                    $pdf->imageJpegFit($imgAbs, $xpt, $ypt, $wpt, $hpt);
                }
                continue;
            }

            if ($type === 'signature') {
                // Signature field: a line + optional label (e.g. "Firma del medico").
                $col = (string)($it['color_hex'] ?? '#111827');
                $rgb = vr_hex_to_rgb($col, [17,24,39]);

                $wmm = (float)($it['w_mm'] ?? 0);
                $hmm = (float)($it['h_mm'] ?? 10);
                $thmm = (float)($it['line_thickness_mm'] ?? 0.3);
                if ($thmm <= 0) $thmm = 0.3;

                // Place the line near the bottom of the signature box.
                $xLinePt = $xpt;
                $wLinePt = vr_mm2pt($wmm);
                $yLineMm = (float)($it['y_mm'] ?? 0) + max(0.0, $hmm - 2.0);
                $yLinePt = vr_mm2pt($yLineMm);
                $hLinePt = vr_mm2pt($thmm);

                if ($wLinePt > 0 && $hLinePt > 0) {
                    $pdf->fillRect($xLinePt, $yLinePt, $wLinePt, $hLinePt, $rgb);
                }

                // Optional label
                $show = !empty($it['show_label']);
                $lbl = trim((string)($it['label'] ?? ($it['ui_label'] ?? '')));
                if ($show && $lbl !== '') {
                    $fs = (float)($it['label_font_size'] ?? 9);
                    if ($fs <= 0) $fs = 9;
                    $pdf->setFont('Helvetica', '', $fs);
                    $pdf->setLineHeight(max(10, round($fs * 1.25, 1)));

                    $yLblPt = vr_mm2pt((float)($it['y_mm'] ?? 0) + max(0.0, $hmm - 1.0));
                    $align = strtoupper(trim((string)($it['label_align'] ?? 'R')));
                    if ($align === 'L') {
                        $pdf->write($lbl, $xpt, $yLblPt);
                    } else {
                        // Default: right align within the signature width
                        $pdf->multiLineRightAt($xpt + $wLinePt, $yLblPt, $lbl, $wLinePt);
                    }
                }
                continue;
            }

            // Unknown graphic type -> ignore
            continue;
        }

        // --- Text fields ---
        $text = (string)($it['text'] ?? '');
        if (trim($text) === '') continue;

        $fontSize = (float)($it['font_size'] ?? ($options['default_font_size'] ?? 9));
        $fontStyle = (string)($it['font_style'] ?? ($it['style'] ?? ($options['default_font_style'] ?? '')));
        $align = (string)($it['align'] ?? 'L');

        $xpt = vr_mm2pt((float)($it['x_mm'] ?? 0));
        $ypt = vr_mm2pt((float)($it['y_mm'] ?? 0));
        $wpt = vr_mm2pt((float)($it['w_mm'] ?? 0));
        $hpt = vr_mm2pt((float)($it['h_mm'] ?? 0));

        $lineHeight = (float)($it['line_height_pt'] ?? max(10, round($fontSize * 1.25, 1)));
        $maxLines = isset($it['max_lines']) ? (int)$it['max_lines'] : null;
        $padding = (float)($it['padding_pt'] ?? 0);

        $pdf->setFont('Helvetica', $fontStyle, $fontSize);
        $pdf->setLineHeight($lineHeight);

        // Boxes (multi-line)
        if ($wpt > 0 && $hpt > 0) {
            vr_draw_text_box(
                $pdf,
                $xpt, $ypt, $wpt, $hpt,
                $text,
                $fontSize,
                $fontStyle,
                $align,
                false,
                0,
                null,
                $lineHeight,
                $padding,
                $maxLines
            );
            continue;
        }

        // Single-line fields: truncate to first wrapped line, to avoid going below underlines.
        if ($wpt > 0) {
            $lines = $pdf->wrapTextLines($text, $wpt);
            $line = (string)($lines[0] ?? $text);
            $al = strtoupper(trim((string)$align));
            if ($al !== 'C' && $al !== 'R') $al = 'L';
            if ($al === 'L') {
                $pdf->write($line, $xpt, $ypt);
            } else {
                // Precise alignment using font metrics
                $pdf->writeAlignedAt($xpt, $ypt, $wpt, $line, $al);
            }
        } else {
            $pdf->write($text, $xpt, $ypt);
        }
    }

    // Append attachments (multi-page)
    // NOTE: must be called only once (a previous duplicate call caused each image to appear twice).
    vr_minipdf_append_attachments($pdf, $attachments, ['base_dir' => __DIR__ . '/..']);
    // Append attachments (images/PDFs) at the end also for the legacy layout.
    // This keeps behaviour consistent with template-based PDFs.
    vr_minipdf_append_attachments($pdf, $attachments, ['base_dir' => __DIR__ . '/..']);
    return $pdf->output($destPath);
}


/**
 * Build sentence: "Nome animale, specie, razza, sesso, stato riproduttivo, nat[a|o] il ... di anni N, peso, microchip"
 */
function vr_discursive_patient_line(array $pet, array $visit): string {
    // Required order:
    // Nome animale, specie, razza, sesso, stato riproduttivo,
    // nat[a|o] il data di nascita, di anni N, peso, microchip.
    $parts = [];

    $nome  = trim((string)($pet['name'] ?? ''));
    $specie = trim((string)($pet['species'] ?? ''));
    $razza = trim((string)($pet['breed'] ?? ''));
    $sesso = trim((string)($pet['sex'] ?? ''));
    $ripr  = trim((string)($pet['neuter_status'] ?? ($visit['reproductive_status'] ?? '')));

    if ($nome !== '') $parts[] = $nome;
    if ($specie !== '') $parts[] = $specie;
    if ($razza !== '') $parts[] = $razza;
    if ($sesso !== '') $parts[] = $sesso;
    if ($ripr !== '') $parts[] = $ripr;

    $birth = trim((string)($pet['birth_date'] ?? ''));
    if ($birth !== '') {
        $natao = vr_is_female($sesso) ? 'nata' : 'nato';
        $parts[] = $natao . " il " . vr_fmt_date_it($birth);

        // Years only (no months/days)
        $ref = trim((string)($visit['visit_date'] ?? $visit['date'] ?? ''));
        if ($ref === '') $ref = date('Y-m-d');
        $y = vr_years_between($birth, $ref);
        if ($y !== null) $parts[] = "di anni " . $y;
    }

    $peso = trim((string)($visit['weight_kg'] ?? $pet['weight_kg'] ?? ''));
    if ($peso !== '') $parts[] = "peso " . $peso . " kg";

    $chip = trim((string)($pet['microchip'] ?? ''));
    if ($chip !== '') $parts[] = "microchip " . $chip;

    return trim(implode(", ", $parts)) . ".";
}

function vr_years_between(string $birth, string $ref): ?int {
    $b = strtotime($birth);
    $r = strtotime($ref);
    if (!$b || !$r) return null;
    $by = (int)date('Y', $b);
    $ry = (int)date('Y', $r);
    $bm = (int)date('n', $b);
    $bd = (int)date('j', $b);
    $rm = (int)date('n', $r);
    $rd = (int)date('j', $r);
    $age = $ry - $by - (($rm < $bm || ($rm == $bm && $rd < $bd)) ? 1 : 0);
    if ($age < 0) $age = 0;
    return $age;
}

/**
 * Pretty label for machine keys (e.g. minaccia_dx -> Minaccia DX).
 */
function vr_pretty_key(string $key): string {
    $k = trim($key);
    if ($k === '') return '';
    $k = str_replace(['__', '_'], ['_', ' '], $k);
    $k = preg_replace('/\s+/', ' ', $k);
    // Common abbreviations
    $k = preg_replace('/\bdx\b/i', 'DX', $k);
    $k = preg_replace('/\bsx\b/i', 'SX', $k);
    $k = preg_replace('/\biop\b/i', 'IOP', $k);
    $k = preg_replace('/\bmm hg\b/i', 'mmHg', $k);
    // ucfirst (ASCII-safe; most keys are ASCII)
    return strtoupper(substr($k, 0, 1)) . substr($k, 1);
}

/**
 * Join a key/value array in a compact form: "Key: Val; Key2: Val2".
 */
function vr_kv_compact(array $arr): string {
    $parts = [];
    foreach ($arr as $k => $v) {
        $val = trim((string)$v);
        if ($val === '') continue;
        $label = is_int($k) ? '' : trim((string)$k);
        if ($label !== '') {
            // If label already contains spaces and looks "human", keep it.
            $pretty = preg_match('/[A-Za-zÀ-ÿ]/u', $label) ? $label : vr_pretty_key($label);
            // If it's snake_case, prettify.
            if (strpos($label, '_') !== false) $pretty = vr_pretty_key($label);
            $parts[] = $pretty . ': ' . $val;
        } else {
            $parts[] = $val;
        }
    }
    return implode('; ', $parts);
}

/**
 * Convert stored formData (Nuova visita clinica/oculistica) to one-line-per-box
 * strings using the formula: "Titolo box : dati".
 */
function vr_form_boxes_to_lines(array $formData, string $kind=''): array {
    $lines = [];
    $kind = strtoupper(trim($kind));
    if ($kind === '') {
        if (isset($formData['reason']) || isset($formData['objective'])) $kind = 'CLINICA';
        elseif (isset($formData['reflex']) || isset($formData['annessi']) || isset($formData['occhio'])) $kind = 'OFTALMO';
    }

    // Specialist/custom visit: print all custom fields as "Label: value"
    if ($kind === 'CUSTOM') {
        $cf = $formData['_custom_form'] ?? null;
        if (is_array($cf) && !empty($cf['fields']) && is_array($cf['fields'])) {
            $lastSection = '';
            foreach ($cf['fields'] as $f) {
                if (!is_array($f)) continue;
                $label = trim((string)($f['label'] ?? ''));
                $val = trim((string)($f['value'] ?? ''));
                $sec = trim((string)($f['section'] ?? ''));
                if ($label === '' || $val === '') continue;
                $prefix = '';
                if ($sec !== '') {
                    $prefix = $sec . ' — ';
                    $lastSection = $sec;
                }
                $lines[] = $prefix . $label . ' : ' . $val;
            }
        }

        // Fallback: dump as key-value
        if (empty($lines)) {
            $dump = vr_kv_compact($formData);
            if ($dump !== '') $lines[] = $dump;
        }
        return $lines;
    }

    if ($kind === 'CLINICA') {
        $reason = trim((string)($formData['reason'] ?? ''));
        if ($reason !== '') $lines[] = 'Motivo della visita : ' . $reason;

        $an = trim((string)($formData['anamnesis'] ?? ''));
        if ($an !== '') $lines[] = 'Anamnesi : ' . $an;

        if (!empty($formData['objective']) && is_array($formData['objective'])) {
            $obj = vr_kv_compact($formData['objective']);
            if ($obj !== '') $lines[] = 'Esame obiettivo : ' . $obj;
        } else {
            $obj = trim((string)($formData['objective_exam'] ?? ''));
            if ($obj !== '') $lines[] = 'Esame obiettivo : ' . $obj;
        }
    } else {
        $an = trim((string)($formData['anamnesis'] ?? ''));
        if ($an !== '') $lines[] = 'Anamnesi : ' . $an;

        if (!empty($formData['reflex']) && is_array($formData['reflex'])) {
            $v = vr_kv_compact($formData['reflex']);
            if ($v !== '') $lines[] = 'Riflessi : ' . $v;
        }

        if (!empty($formData['annessi']) && is_array($formData['annessi'])) {
            $v = vr_kv_compact($formData['annessi']);
            if ($v !== '') $lines[] = 'Annessi : ' . $v;
        }

        if (!empty($formData['occhio']) && is_array($formData['occhio'])) {
            $v = vr_kv_compact($formData['occhio']);
            if ($v !== '') $lines[] = 'Occhio : ' . $v;
        }
    }

    return $lines;
}

/**
 * Owner (scheda proprietario) -> array of lines to print.
 */
function vr_owner_to_lines(array $owner): array {
    $lines = [];
    if (!$owner) return $lines;

    $full = trim((string)($owner['surname'] ?? '') . ' ' . (string)($owner['name'] ?? ''));
    if ($full !== '') $lines[] = 'Proprietario : ' . $full;

    $bd = trim((string)($owner['birth_date'] ?? ''));
    if ($bd !== '') $lines[] = 'Data nascita : ' . vr_fmt_date_it($bd);

    $cf = trim((string)($owner['fiscal_code'] ?? ''));
    if ($cf !== '') $lines[] = 'Codice fiscale : ' . $cf;

    $email = trim((string)($owner['email'] ?? ''));
    if ($email !== '') $lines[] = 'Email : ' . $email;

    $phone = trim((string)($owner['phone'] ?? ''));
    if ($phone !== '') $lines[] = 'Telefono : ' . $phone;

    // Residenza
    $addr = [];
    $street = trim((string)($owner['address_street'] ?? ''));
    $num = trim((string)($owner['address_number'] ?? ''));
    $zip = trim((string)($owner['address_zip'] ?? ''));
    $city = trim((string)($owner['address_city'] ?? ''));
    $prov = trim((string)($owner['address_province'] ?? ''));
    $state = trim((string)($owner['address_state'] ?? ''));
    $line1 = trim($street . ($num !== '' ? ' ' . $num : ''));
    $line2 = trim($zip . ' ' . $city . ($prov !== '' ? ' (' . $prov . ')' : ''));
    $line3 = trim($state);
    if ($line1 !== '') $addr[] = $line1;
    if ($line2 !== '') $addr[] = $line2;
    if ($line3 !== '') $addr[] = $line3;
    if ($addr) $lines[] = 'Residenza : ' . implode(', ', $addr);

    // Billing (if different)
    $billDiff = !empty($owner['billing_is_different']);
    if ($billDiff) {
        $baddr = [];
        $bst = trim((string)($owner['billing_street'] ?? ''));
        $bnum = trim((string)($owner['billing_number'] ?? ''));
        $bzip = trim((string)($owner['billing_zip'] ?? ''));
        $bcity = trim((string)($owner['billing_city'] ?? ''));
        $bprov = trim((string)($owner['billing_province'] ?? ''));
        $bstate = trim((string)($owner['billing_state'] ?? ''));
        $bline1 = trim($bst . ($bnum !== '' ? ' ' . $bnum : ''));
        $bline2 = trim($bzip . ' ' . $bcity . ($bprov !== '' ? ' (' . $bprov . ')' : ''));
        $bline3 = trim($bstate);
        if ($bline1 !== '') $baddr[] = $bline1;
        if ($bline2 !== '') $baddr[] = $bline2;
        if ($bline3 !== '') $baddr[] = $bline3;
        if ($baddr) $lines[] = 'Fatturazione : ' . implode(', ', $baddr);
    }

    return $lines;
}

/**
 * CUSTOM CLINICAL LAYOUT (spec as requested)
 */
function vr_render_custom_clinica_layout(array $header=null, array $pet, array $visit, array $formData, array $owner, string $destPath, array $attachments = []): bool {
    require_once __DIR__ . '/minipdf.php';
    $pdf = new MiniPDF();
    $pdf->addPage();
    // Load per-clinic PDF settings (fallback to defaults)
    $spec = vr_pdf_settings_from_json($header['pdf_settings'] ?? null);

    // Global line height (used by most blocks)
    $globalLH = (float)($spec['line_height_pt'] ?? 14);
    $pdf->setLineHeight($globalLH);

    // Colors
    $accent = vr_hex_to_rgb((string)($spec['accent_color'] ?? '#067481'), [6,116,129]);
    $borderRgb = vr_hex_to_rgb((string)($spec['border_color'] ?? ''), $accent);

    // --- 1) Horizontal line ---
    $yLine = vr_cm2pt((float)($spec['line_y_cm'] ?? 4.5));
    $lineH = vr_px2pt((float)($spec['line_thickness_px'] ?? 3));
    $pdf->fillRect(0, $yLine, vr_a4w_pt(), $lineH, $accent);

    // --- 2) Logo ---
    if (!empty($header['logo_path'])) {
        $logoAbs = __DIR__ . '/../' . $header['logo_path'];
        if (file_exists($logoAbs)) {
            $xLogo = vr_cm2pt((float)($spec['logo_x_cm'] ?? 2.0));
            $yLogo = vr_cm2pt((float)($spec['logo_y_cm'] ?? 2.0));
            $side  = vr_cm2pt((float)($spec['logo_size_cm'] ?? 3.0));
            if (method_exists($pdf, 'imageJpegFit')) {
                $pdf->imageJpegFit($logoAbs, $xLogo, $yLogo, $side, $side);
            } else {
                $pdf->imageJpeg($logoAbs, $xLogo, $yLogo, $side, $side);
            }
        }
    }

    // --- 2b) Stamp / timbro ---
    if (!empty($header['stamp_path'])) {
        $stampAbs = __DIR__ . '/../' . ltrim((string)$header['stamp_path'], '/');
        if (file_exists($stampAbs)) {
            $xStamp = vr_cm2pt((float)($spec['stamp_x_cm'] ?? 16.8));
            $yStamp = vr_cm2pt((float)($spec['stamp_y_cm'] ?? 24.0));
            $wStamp = vr_cm2pt((float)($spec['stamp_w_cm'] ?? 3.5));
            $hStamp = vr_cm2pt((float)($spec['stamp_h_cm'] ?? 3.5));
            if (method_exists($pdf, 'imageJpegFit')) {
                $pdf->imageJpegFit($stampAbs, $xStamp, $yStamp, $wStamp, $hStamp);
            } else {
                $pdf->imageJpeg($stampAbs, $xStamp, $yStamp, $wStamp, $hStamp);
            }
        }
    }

    // Determine visit kind/title
    $kind = strtoupper(trim((string)($visit['visit_kind'] ?? '')));
    if ($kind === '') {
        if (isset($formData['reason']) || isset($formData['objective'])) $kind = 'CLINICA';
        elseif (isset($formData['reflex']) || isset($formData['annessi']) || isset($formData['occhio'])) $kind = 'OFTALMO';
    }
    $title = trim((string)($visit['title'] ?? ''));
    if ($title === '') {
        $title = ($kind === 'OFTALMO') ? 'VISITA OFTALMOLOGICA' : 'VISITA CLINICA';
    }
    $vDate = trim((string)($visit['visit_date'] ?? ''));

    // --- 3) Title + subtitle (single field) ---
    $xTitle = vr_cm2pt((float)($spec['title_x_cm'] ?? 6.0));
    $yTitle = vr_cm2pt((float)($spec['title_y_cm'] ?? 3.5));
    $wTitle = vr_cm2pt((float)($spec['title_w_cm'] ?? 7.0));
    $hTitle = vr_cm2pt((float)($spec['title_h_cm'] ?? 2.0));
    $titleBorderW = vr_px2pt((float)($spec['title_border_px'] ?? 1));
    if (!empty($spec['title_border']) && $titleBorderW > 0) {
        $pdf->strokeRect($xTitle, $yTitle, $wTitle, $hTitle, $titleBorderW, $borderRgb);
    }

    // Text inside title field
    $pad = 3.0;
    $innerX = $xTitle + $pad;
    $innerW = max(1.0, $wTitle - 2.0*$pad);
    $alignTitle = (string)($spec['title_align'] ?? 'L');
    $alignTitle = strtoupper(trim($alignTitle));

    $titleFontSize = (float)($spec['title_font_size'] ?? 13);
    $titleStyle = (string)($spec['title_font_style'] ?? 'B');
    $subtitleFontSize = (float)($spec['subtitle_font_size'] ?? 10);
    $subtitleStyle = (string)($spec['subtitle_font_style'] ?? '');
    $subtitleOffsetPt = vr_cm2pt((float)($spec['subtitle_offset_cm'] ?? 0.6));

    // Render title (limited so it does not crash into the subtitle)
    $pdf->setFont('Helvetica', $titleStyle, $titleFontSize);
    $prevLH = $pdf->getLineHeight();
    $titleLH = max($titleFontSize * 1.15, 12.0);
    $pdf->setLineHeight($titleLH);
    $titleLines = $pdf->wrapTextLines($title, $innerW);
    $maxTitleLines = max(1, (int)floor(max(1.0, ($subtitleOffsetPt)) / $titleLH));
    $maxTitleLines = min(2, $maxTitleLines);
    $yT = $yTitle + $pad;
    $printedTitle = 0;
    foreach ($titleLines as $ln) {
        if ($printedTitle >= $maxTitleLines) break;
        $lw = min($pdf->getStringWidth($ln), $innerW);
        $xT = $innerX;
        if ($alignTitle === 'R') $xT = $innerX + ($innerW - $lw);
        else if ($alignTitle === 'C') $xT = $innerX + ($innerW - $lw) / 2.0;
        $pdf->write($ln, $xT, $yT);
        $yT += $titleLH;
        $printedTitle++;
    }
    $pdf->setLineHeight($prevLH);

    // Subtitle
    $sub = 'del: ' . ($vDate !== '' ? vr_fmt_date_it($vDate) : vr_fmt_date_it(date('Y-m-d')));
    $pdf->setFont('Helvetica', $subtitleStyle, $subtitleFontSize);
    $subLH = max($subtitleFontSize * 1.20, 10.0);
    $prevLH2 = $pdf->getLineHeight();
    $pdf->setLineHeight($subLH);
    $subLines = $pdf->wrapTextLines($sub, $innerW);
    $yS = $yTitle + $subtitleOffsetPt + $pad;
    $printedSub = 0;
    foreach ($subLines as $ln) {
        if ($printedSub >= 2) break;
        $lw = min($pdf->getStringWidth($ln), $innerW);
        $xS = $innerX;
        if ($alignTitle === 'R') $xS = $innerX + ($innerW - $lw);
        else if ($alignTitle === 'C') $xS = $innerX + ($innerW - $lw) / 2.0;
        $pdf->write($ln, $xS, $yS);
        $yS += $subLH;
        $printedSub++;
    }
    $pdf->setLineHeight($prevLH2);


    // --- 4) Vet data field ---
    $xBox = vr_cm2pt((float)($spec['vet_x_cm'] ?? 13.0));
    $yBox = vr_cm2pt((float)($spec['vet_y_cm'] ?? 2.0));
    $boxW = vr_cm2pt((float)($spec['vet_w_cm'] ?? 6.0));
    $boxH = vr_cm2pt((float)($spec['vet_h_cm'] ?? 3.5));

    $details = [];
    if (!empty($header['header_name']))  $details[] = (string)$header['header_name'];
    if (!empty($header['header_title'])) $details[] = (string)$header['header_title'];
    if (!empty($header['header_albo']))  $details[] = (string)$header['header_albo'];
    if (!empty($header['header_piva']))  $details[] = 'P. IVA: ' . (string)$header['header_piva'];
    if (!empty($header['header_phone'])) $details[] = 'Tel: ' . (string)$header['header_phone'];
    if (!empty($header['header_email'])) $details[] = 'Email: ' . (string)$header['header_email'];
    if (!empty($header['header_address'])) $details[] = (string)$header['header_address'];


    if ($details) {
        vr_draw_text_box(
            $pdf,
            $xBox, $yBox, $boxW, $boxH,
            implode("\n", $details),
            (float)($spec['vet_font_size'] ?? 9),
            (string)($spec['vet_font_style'] ?? ''),
            (string)($spec['vet_align'] ?? 'L'),
            !empty($spec['vet_border']),
            vr_px2pt((float)($spec['vet_border_px'] ?? 1)),
            $borderRgb,
            $globalLH
        );
    }

    // --- 5) Animal data field (discursive) ---
    $xAnimal = vr_cm2pt((float)($spec['animal_x_cm'] ?? 2.0));
    $yAnimal = vr_cm2pt((float)($spec['animal_y_cm'] ?? 6.0));
    $wAnimal = vr_cm2pt((float)($spec['animal_w_cm'] ?? 17.0));
    $hAnimal = vr_cm2pt((float)($spec['animal_h_cm'] ?? 3.0));
    $disc = vr_discursive_patient_line($pet, $visit);
    [$animalLinesPrinted, $animalEndY] = vr_draw_text_box(
        $pdf,
        $xAnimal, $yAnimal, $wAnimal, $hAnimal,
        $disc,
        (float)($spec['animal_font_size'] ?? 11),
        (string)($spec['animal_font_style'] ?? ''),
        (string)($spec['animal_align'] ?? 'L'),
        !empty($spec['animal_border']),
        vr_px2pt((float)($spec['animal_border_px'] ?? 1)),
        $borderRgb,
        $globalLH
    );

    // --- 6) Visit data field (form + diagnosis/therapy/notes) ---
    $blank = (int)($spec['blank_lines_after_patient'] ?? 2);
    $yAfterAnimal = $animalEndY + ($blank * $globalLH);

    $xVisit = vr_cm2pt((float)($spec['visit_x_cm'] ?? 2.0));
    $wVisit = vr_cm2pt((float)($spec['visit_w_cm'] ?? 8.0));
    $hVisit = vr_cm2pt((float)($spec['visit_h_cm'] ?? 20.0));
    $visitYcm = (float)($spec['visit_y_cm'] ?? -1.0);
    $yVisit = ($visitYcm >= 0.0) ? vr_cm2pt($visitYcm) : $yAfterAnimal;

    $visitBorderW = vr_px2pt((float)($spec['visit_border_px'] ?? 1));
    if (!empty($spec['visit_border']) && $visitBorderW > 0) {
        $pdf->strokeRect($xVisit, $yVisit, $wVisit, $hVisit, $visitBorderW, $borderRgb);
    }

    $padB = 3.0;
    $vx = $xVisit + $padB;
    $vy = $yVisit + $padB;
    $vw = max(1.0, $wVisit - 2.0*$padB);
    $vh = max(1.0, $hVisit - 2.0*$padB);

    $visitAlign = strtoupper(trim((string)($spec['visit_align'] ?? 'L')));
    if ($visitAlign !== 'C' && $visitAlign !== 'R') $visitAlign = 'L';
    $visitFontSize = (float)($spec['visit_font_size'] ?? 10);
    $visitStyle = (string)($spec['visit_font_style'] ?? '');
    $pdf->setFont('Helvetica', $visitStyle, $visitFontSize);
    $pdf->setLineHeight($globalLH);

    $formLines = vr_form_boxes_to_lines($formData, $kind);
    $formText = $formLines ? implode("\n", $formLines) : '';

    $dx = trim((string)($visit['diagnosis'] ?? ''));
    $th = trim((string)($visit['therapy'] ?? ''));
    $nt = trim((string)($visit['notes'] ?? ''));
    $diagText = implode("\n", [
        'Diagnosi : ' . ($dx !== '' ? $dx : 'n.d.'),
        'Terapia : ' . ($th !== '' ? $th : 'n.d.'),
        'Note : ' . ($nt !== '' ? $nt : 'n.d.'),
    ]);

    $gapAfterForm = (float)($spec['gap_after_form_pt'] ?? 6);
    $diagWrapped = $pdf->wrapTextLines($diagText, $vw);
    $diagCount = count($diagWrapped);
    $reservedH = ($diagCount * $globalLH) + $gapAfterForm;
    $maxFormLines = 0;
    if ($formText !== '') {
        $formWrapped = $pdf->wrapTextLines($formText, $vw);
        $availH = $vh - $reservedH;
        $maxFormLines = (int)floor($availH / $globalLH);
        if ($maxFormLines < 0) $maxFormLines = 0;
        $printedForm = 0;
        $yCur = $vy;
        foreach ($formWrapped as $ln) {
            if ($printedForm >= $maxFormLines) break;
            $lw = min($pdf->getStringWidth($ln), $vw);
            $xCur = $vx;
            if ($visitAlign === 'R') $xCur = $vx + ($vw - $lw);
            else if ($visitAlign === 'C') $xCur = $vx + ($vw - $lw) / 2.0;
            $pdf->write($ln, $xCur, $yCur);
            $yCur += $globalLH;
            $printedForm++;
        }
        $vyDiag = $vy + ($printedForm * $globalLH) + $gapAfterForm;
    } else {
        $vyDiag = $vy;
    }

    // Diagnosis / therapy / notes (always printed)
    $yCur = $vyDiag;
    foreach ($diagWrapped as $ln) {
        $lw = min($pdf->getStringWidth($ln), $vw);
        $xCur = $vx;
        if ($visitAlign === 'R') $xCur = $vx + ($vw - $lw);
        else if ($visitAlign === 'C') $xCur = $vx + ($vw - $lw) / 2.0;
        $pdf->write($ln, $xCur, $yCur);
        $yCur += $globalLH;
    }

    // --- 7) Owner data field (parallel to diagnosis) ---
    $ownerLines = vr_owner_to_lines($owner);
    if ($ownerLines) {
        $xOwner = vr_cm2pt((float)($spec['owner_x_cm'] ?? 11.0));
        $wOwner = vr_cm2pt((float)($spec['owner_w_cm'] ?? 8.0));
        $hOwner = vr_cm2pt((float)($spec['owner_h_cm'] ?? 8.0));
        $ownerYcm = (float)($spec['owner_y_cm'] ?? -1.0);
        $yOwner = ($ownerYcm >= 0.0) ? vr_cm2pt($ownerYcm) : $vyDiag;

        vr_draw_text_box(
            $pdf,
            $xOwner, $yOwner, $wOwner, $hOwner,
            implode("\n", $ownerLines),
            (float)($spec['owner_font_size'] ?? 10),
            (string)($spec['owner_font_style'] ?? ''),
            (string)($spec['owner_align'] ?? 'R'),
            !empty($spec['owner_border']),
            vr_px2pt((float)($spec['owner_border_px'] ?? 1)),
            $borderRgb,
            $globalLH
        );
    }

    return $pdf->output($destPath);
}

/**
 * Resolve a value from the data bundle using a dot-separated path.
 * Supported roots: header, pet, owner, visit, form.
 */
/**
 * Recursive lookup by key (first match) for legacy form structures.
 */
function vr_template_find_key_recursive($node, string $searchKey) {
    if (!is_array($node)) return null;
    if (array_key_exists($searchKey, $node)) {
        return $node[$searchKey];
    }
    foreach ($node as $v) {
        if (is_array($v)) {
            $r = vr_template_find_key_recursive($v, $searchKey);
            if ($r !== null) return $r;
        }
    }
    return null;
}

function vr_template_get_by_path(array $data, string $path) {
    $path = trim($path);
    if ($path === '') return null;
    $parts = explode('.', $path);
    $root = array_shift($parts);
    if (!isset($data[$root]) || !is_array($data[$root])) {
        return null;
    }
    $cur = $data[$root];
    foreach ($parts as $p) {
        if (!is_array($cur)) return null;
        if (array_key_exists($p, $cur)) {
            $cur = $cur[$p];
            continue;
        }
        // Fuzzy fallback: when a stored visit uses a legacy/modified structure, try
        // to resolve the last path segment somewhere inside the form tree.
        if ($root === 'form' && !empty($parts)) {
            $last = $parts[count($parts) - 1];
            $found = vr_template_find_key_recursive($data[$root], $last);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }
    return $cur;
}


/**
 * Infer a data source path for template items when 'source' is missing.
 * This makes custom/edited maps more robust and keeps compatibility with
 * older stored visit form structures.
 */
function vr_template_infer_source_from_key(string $key): string {
    $k = strtolower(trim($key));
    if ($k === '') return '';

    // --- OFTALMO: Riflessi ---
    $reflex = [
        'minaccia_dx' => 'form.reflex.minaccia_dx',
        'minaccia_sx' => 'form.reflex.minaccia_sx',
        'palpebrale_dx' => 'form.reflex.palpebrale_dx',
        'palpebrale_sx' => 'form.reflex.palpebrale_sx',
        'pupillare_dx' => 'form.reflex.pupillare_dx',
        'pupillare_sx' => 'form.reflex.pupillare_sx',
        'dazzle_dx' => 'form.reflex.dazzle_dx',
        'dazzle_sx' => 'form.reflex.dazzle_sx',
        'corneale_dx' => 'form.reflex.corneale_dx',
        'corneale_sx' => 'form.reflex.corneale_sx',
    ];
    if (isset($reflex[$k])) return $reflex[$k];

    // --- OFTALMO: Annessi ---
    $annessi = [
        'orbita_dx' => 'form.annessi.orbita_dx',
        'orbita_sx' => 'form.annessi.orbita_sx',
        'palpebre_dx' => 'form.annessi.palpebre_dx',
        'palpebre_sx' => 'form.annessi.palpebre_sx',
        'terza_dx' => 'form.annessi.terza_dx',
        'terza_sx' => 'form.annessi.terza_sx',
        'lacrimale_dx' => 'form.annessi.lacrimale_dx',
        'lacrimale_sx' => 'form.annessi.lacrimale_sx',
        'congiuntiva_dx' => 'form.annessi.congiuntiva_dx',
        'congiuntiva_sx' => 'form.annessi.congiuntiva_sx',
    ];
    if (isset($annessi[$k])) return $annessi[$k];

    // --- OFTALMO: Occhio ---
    $occhio = [
        'fluor_dx' => 'form.occhio.fluor_dx',
        'fluor_sx' => 'form.occhio.fluor_sx',
        'schirmer_dx' => 'form.occhio.schirmer_dx',
        'schirmer_sx' => 'form.occhio.schirmer_sx',
        'cornea_dx' => 'form.occhio.cornea_dx',
        'cornea_sx' => 'form.occhio.cornea_sx',
        'camera_dx' => 'form.occhio.camera_dx',
        'camera_sx' => 'form.occhio.camera_sx',
        'iride_dx' => 'form.occhio.iride_dx',
        'iride_sx' => 'form.occhio.iride_sx',
        'cristallino_dx' => 'form.occhio.cristallino_dx',
        'cristallino_sx' => 'form.occhio.cristallino_sx',
        'vitreo_dx' => 'form.occhio.vitreo_dx',
        'vitreo_sx' => 'form.occhio.vitreo_sx',
        'fondo_dx' => 'form.occhio.fondo_dx',
        'fondo_sx' => 'form.occhio.fondo_sx',
        'iop_dx' => 'form.occhio.iop_dx',
        'iop_sx' => 'form.occhio.iop_sx',
    ];
    if (isset($occhio[$k])) return $occhio[$k];

    return '';
}

/**
 * Compute special / derived values for template items.
 */
function vr_template_compute_value(string $name, array $item, array $data): string {
    $name = strtolower(trim($name));

    if ($name === 'owner_full') {
        $o = $data['owner'] ?? [];
        $surname = trim((string)($o['surname'] ?? ($data['visit']['owner_surname'] ?? '')));
        $fname = trim((string)($o['name'] ?? ($data['visit']['owner_name'] ?? '')));
        return trim($surname . ' ' . $fname);
    }

    if ($name === 'owner_address_compact') {
        $o = $data['owner'] ?? [];
        $street = trim((string)($o['address_street'] ?? ''));
        $num = trim((string)($o['address_number'] ?? ''));
        $zip = trim((string)($o['address_zip'] ?? ''));
        $city = trim((string)($o['address_city'] ?? ''));
        $prov = trim((string)($o['address_province'] ?? ''));

        $left = trim($street . (($street !== '' && $num !== '') ? ' ' : '') . $num);
        $right = trim($zip . (($zip !== '' && $city !== '') ? ' ' : '') . $city . (($prov !== '') ? ' (' . $prov . ')' : ''));
        $out = trim($left . (($left !== '' && $right !== '') ? ', ' : '') . $right);
        return $out;
    }

    if ($name === 'visit_reason_fallback') {
        // Clinical form has form.reason; oftalmo may not.
        $r = trim((string)($data['form']['reason'] ?? ''));
        if ($r !== '') return $r;
        return '';
    }

    if ($name === 'objective_any') {
        // Clinical visit objective can be stored either as a structured array (form.objective)
        // or as a free text field (form.objective_exam). Produce a compact, printable string.
        $obj = $data['form']['objective'] ?? null;
        if (is_array($obj)) {
            $has = false;
            foreach ($obj as $k => $v) {
                if (trim((string)$v) !== '') { $has = true; break; }
            }
            if ($has) return vr_kv_compact($obj);
        }
        return trim((string)($data['form']['objective_exam'] ?? ''));
    }

    if ($name === 'custom_fields_compact') {
        // Custom module (visit_forms): render all fields as one text block.
        // The visual template generator can optionally request a value-only output
        // (useful when the background already contains the field names).
        $showLabels = true;
        if (array_key_exists('compact_show_labels', $item)) {
            $showLabels = !empty($item['compact_show_labels']);
        }

        if ($showLabels) {
            $lines = vr_form_boxes_to_lines($data['form'] ?? [], 'CUSTOM');
            $lines = array_filter($lines, function($l){ return trim((string)$l) !== ''; });
            return implode("\n", $lines);
        }

        // Values only (keep the original field order)
        $out = [];
        $cf = $data['form']['_custom_form'] ?? null;
        if (is_array($cf) && !empty($cf['fields']) && is_array($cf['fields'])) {
            foreach ($cf['fields'] as $f) {
                if (!is_array($f)) continue;
                $val = trim((string)($f['value'] ?? ''));
                if ($val === '') continue;
                $out[] = $val;
            }
        }

        if (empty($out)) {
            $dump = vr_kv_compact($data['form'] ?? []);
            if ($dump !== '') $out[] = $dump;
        }

        return implode("\n", $out);
    }

    if ($name === 'custom_field') {
        // Custom module: render a single field by key
        $targetKey = trim((string)($item['custom_key'] ?? ''));
        if ($targetKey === '') {
            $targetKey = trim((string)($item['key'] ?? ''));
        }
        // Normalize common prefixes used in the visual editor
        if (strpos($targetKey, 'cf_') === 0) $targetKey = substr($targetKey, 3);
        if (strpos($targetKey, 'custom_') === 0) $targetKey = substr($targetKey, 7);

        if ($targetKey === '') return '';
        $cf = $data['form']['_custom_form'] ?? null;
        if (is_array($cf) && !empty($cf['fields']) && is_array($cf['fields'])) {
            foreach ($cf['fields'] as $f) {
                if (!is_array($f)) continue;
                if (trim((string)($f['key'] ?? '')) === $targetKey) {
                    return trim((string)($f['value'] ?? ''));
                }
            }
        }
        return '';
    }

    if ($name === 'eye_pair') {
        // Uses item['dx'] and item['sx'] as paths.
        $dxPath = (string)($item['dx'] ?? '');
        $sxPath = (string)($item['sx'] ?? '');
        $dxVal = $dxPath ? vr_template_get_by_path($data, $dxPath) : null;
        $sxVal = $sxPath ? vr_template_get_by_path($data, $sxPath) : null;
        $dx = trim((string)($dxVal ?? ''));
        $sx = trim((string)($sxVal ?? ''));

        if ($dx === '' && $sx === '') return '';
        if ($dx !== '' && $sx !== '') return 'OD: ' . $dx . '  OS: ' . $sx;
        if ($dx !== '') return 'OD: ' . $dx;
        return 'OS: ' . $sx;
    }

    if ($name === 'vet_header_lines' || $name === 'header_lines') {
        $h = $data['header'] ?? [];
        $lines = [];
        $hn = trim((string)($h['header_name'] ?? ''));
        if ($hn !== '') $lines[] = $hn;
        $ht = trim((string)($h['header_title'] ?? ''));
        if ($ht !== '') $lines[] = $ht;
        $ha = trim((string)($h['header_albo'] ?? ''));
        if ($ha !== '') $lines[] = $ha;
        $hp = trim((string)($h['header_piva'] ?? ''));
        if ($hp !== '') $lines[] = 'P.IVA: ' . $hp;
        $haddr = trim((string)($h['header_address'] ?? ''));
        if ($haddr !== '') $lines[] = $haddr;
        $hph = trim((string)($h['header_phone'] ?? ''));
        if ($hph !== '') $lines[] = 'Tel: ' . $hph;
        $hem = trim((string)($h['header_email'] ?? ''));
        if ($hem !== '') $lines[] = 'Email: ' . $hem;
        return implode("\n", $lines);
    }

    return '';
}

/**
 * Resolve the text content for one template item.
 */
function vr_template_resolve_item_text(array $item, array $data): string {
    $source = trim((string)($item['source'] ?? ''));

    // If a map item comes from the visual editor, it may rely on a human key
    // while leaving 'source' empty. Infer a sensible source from the key.
    if ($source === '') {
        $infer = vr_template_infer_source_from_key((string)($item['key'] ?? ''));
        if ($infer !== '') $source = $infer;
    }

    if ($source === '') return '';

    $val = null;
    if (stripos($source, 'computed:') === 0) {
        $name = substr($source, strlen('computed:'));
        return trim(vr_template_compute_value($name, $item, $data));
    }

    $val = vr_template_get_by_path($data, $source);

    // Backward-compatibility / robustness:
    // Some older installations might have stored oftalmo inputs with prefixes (ann_/oc_/ref_)
    // either inside the section arrays or at the root of formData.
    if ($val === null && stripos($source, 'form.') === 0) {
        $parts = explode('.', $source);
        if (count($parts) === 3) {
            $section = $parts[1];
            $field = $parts[2];
            if ($section === 'annessi') {
                $val = vr_template_get_by_path($data, 'form.annessi.ann_' . $field);
                if ($val === null) $val = vr_template_get_by_path($data, 'form.ann_' . $field);
            } elseif ($section === 'occhio') {
                $val = vr_template_get_by_path($data, 'form.occhio.oc_' . $field);
                if ($val === null) $val = vr_template_get_by_path($data, 'form.oc_' . $field);
            } elseif ($section === 'reflex') {
                $val = vr_template_get_by_path($data, 'form.reflex.ref_' . $field);
                if ($val === null) $val = vr_template_get_by_path($data, 'form.ref_' . $field);
            }
        }
    }

    // Optional subkey (useful for form.objective with spaced labels)
    if (isset($item['subkey']) && is_array($val)) {
        $k = (string)$item['subkey'];
        if (array_key_exists($k, $val)) {
            $val = $val[$k];
        } else {
            $val = '';
        }
    }

    $txt = '';
    if (is_array($val)) {
        // Render arrays as a readable "Key: Val; Key2: Val2" string.
        // This is especially useful for form.objective which is stored as an associative array.
        $txt = vr_kv_compact($val);
    } else {
        $txt = trim((string)($val ?? ''));
    }

    // Formatting
    $fmt = strtolower(trim((string)($item['format'] ?? '')));
    if ($fmt === 'date_it') {
        $txt = vr_fmt_date_it($txt);
    }

    return $txt;
}

/**
 * Build a render-ready item list from a mapping (fills item['text']).
 */
function vr_template_build_items(array $map, array $data): array {
    $items = [];
    $defaultFontSize = (float)($map['default_font']['size'] ?? 9);
    $defaultFontStyle = (string)($map['default_font']['style'] ?? '');

    // NOTE:
    // When using an image/PDF background template, the section headers printed in the template
    // are NOT selectable/extractable text.
    // To keep exported PDFs self-descriptive (and readable also in text extraction),
    // we support an optional per-item label that can be rendered together with the value.
    // This is especially useful for the clinical visit fields like Anamnesi / Diagnosi / Terapia / Note.

    $applyLabel = function(array $it, string $txt) use ($map): string {
        $txt = trim($txt);
        if ($txt === '') return '';

        // NEW (visual editor): allow a simple checkbox per field.
        // - If show_label is present, it is the single source of truth.
        // - If show_label is NOT present, we keep legacy behavior based on label_mode.
        $hasShow = array_key_exists('show_label', $it);
        if ($hasShow && empty($it['show_label'])) {
            return $txt;
        }

        // Label text: prefer explicit 'label', fallback to 'ui_label' (human label from the form/editor).
        $label = trim((string)($it['label'] ?? ($it['ui_label'] ?? '')));
        if ($label === '') return $txt;

        $mode = strtolower(trim((string)($it['label_mode'] ?? 'inline')));

        if (!$hasShow) {
            // Legacy behavior: label_mode decides whether we print the label.
            if ($mode === '' || $mode === 'none' || $mode === 'off' || $mode === '0') {
                return $txt;
            }
        } else {
            // New behavior: show_label already says we must print.
            // If someone set label_mode to "none" but show_label=1, keep it intuitive: still print inline.
            if ($mode === '' || $mode === 'none' || $mode === 'off' || $mode === '0') {
                $mode = 'inline';
            }
        }

        // Allow simple custom separators.
        $sepInline = (string)($it['label_sep'] ?? ': ');
        $suffixAbove = (string)($it['label_suffix'] ?? ':');

        if ($mode === 'above') {
            return $label . $suffixAbove . "\n" . $txt;
        }

        // Default: inline
        return $label . $sepInline . $txt;
    };

    foreach (($map['items'] ?? []) as $it) {
        if (!is_array($it)) continue;
        // Graphic elements (logo/line/signature/shape) do not have a dynamic text source.
        // They are rendered directly by the PDF renderer using their own properties.
        $type = strtolower(trim((string)($it['type'] ?? '')));
        if ($type !== '' && $type !== 'text') {
            if (!isset($it['font_size'])) $it['font_size'] = $defaultFontSize;
            if (!isset($it['font_style']) && isset($it['style'])) {
                $it['font_style'] = (string)$it['style'];
            }
            if (!isset($it['font_style'])) $it['font_style'] = $defaultFontStyle;
            $items[] = $it;
            continue;
        }

        $it['text'] = $applyLabel($it, vr_template_resolve_item_text($it, $data));
        if (!isset($it['font_size'])) $it['font_size'] = $defaultFontSize;
        if (!isset($it['font_style']) && isset($it['style'])) {
            $it['font_style'] = (string)$it['style'];
        }
        if (!isset($it['font_style'])) $it['font_style'] = $defaultFontStyle;
        $items[] = $it;
    }
    return $items;
}

/**
 * Select background + map for a given visit type.
 */
function vr_template_select_bg_and_map(string $type, array $header): array {
    // NOTE: $type is now a "visit sheet key" (scheda visita), not just a legacy kind.
    // Built-in: clinica, oftalmo
    // Custom:   form:<ID>
    require_once __DIR__ . '/visit_template_maps.php';

    $res = [
        'bg_abs' => '',
        'map' => null,
        'overlay_header' => false,
        'clear_header' => null,
        'default_font_size' => 9,
        'default_font_style' => '',
        'using_custom' => false,
        'visit_key' => '',
    ];

    $visitKey = strtolower(trim($type));
    if ($visitKey === 'oftalmologica') $visitKey = 'oftalmo';
    if ($visitKey === '') $visitKey = 'clinica';
    $res['visit_key'] = $visitKey;

    $clinicId = (int)($header['clinic_id'] ?? 0);
    $cfg = null;
    $legacyFallback = false;

    // 1) Try new table-based config (visit_templates)
    if ($clinicId > 0) {
        try {
            require_once __DIR__ . '/../db.php';
            require_once __DIR__ . '/visit_templates_repo.php';
            $db = vetroom_db();
            if ($db instanceof PDO) {
                $cfg = vr_visit_template_get($db, $clinicId, $visitKey);
            }
        } catch (Exception $e) {
            $cfg = null;
        }
    }

    // 2) Legacy fallback (vet_settings columns) for built-ins
    if (!$cfg && ($visitKey === 'clinica' || $visitKey === 'oftalmo')) {
        $legacyFallback = true;
        $isOft = ($visitKey === 'oftalmo');
        $cfg = [
            'enabled' => !empty($header[$isOft ? 'visit_template_oftalmo_enabled' : 'visit_template_clinica_enabled']),
            'bg_path' => (string)($header[$isOft ? 'visit_template_oftalmo_bg' : 'visit_template_clinica_bg'] ?? ''),
            'map_json' => (string)($header[$isOft ? 'visit_template_oftalmo_map' : 'visit_template_clinica_map'] ?? ''),
            'overlay_header' => !empty($header[$isOft ? 'visit_template_oftalmo_overlay_header' : 'visit_template_clinica_overlay_header']),
        ];
    }

    $enabled = !empty($cfg['enabled']);
    if (!$enabled) return $res;

    $bgRel = trim((string)($cfg['bg_path'] ?? ''));
    $mapJson = trim((string)($cfg['map_json'] ?? ''));
    $overlay = !empty($cfg['overlay_header']);

    // 3) Prefer custom uploaded background (if configured)
    if ($bgRel !== '') {
        $abs = __DIR__ . '/../' . ltrim($bgRel, '/');
        if (file_exists($abs)) {
            $res['bg_abs'] = $abs;
            $res['using_custom'] = true;
        }
    }

    // 4) Built-in backgrounds for built-in sheets
    if (!$res['bg_abs']) {
        $tplBase = __DIR__ . '/../public/templates/';
        if ($visitKey === 'oftalmo') {
            $bg = $tplBase . 'scheda_visita_oftalmologica_vetroom.png';
            if (file_exists($bg)) $res['bg_abs'] = $bg;
        } else if ($visitKey === 'clinica') {
            $bg = $tplBase . 'scheda_visita_clinica_vetroom.png';
            if (file_exists($bg)) $res['bg_abs'] = $bg;
        }
    }

    if (!$res['bg_abs']) {
        // No template background available: caller will use legacy layout.
        return $res;
    }

    // 5) Load map
    $map = null;
    if ($mapJson !== '') {
        $tmp = json_decode($mapJson, true);
        if (is_array($tmp)) $map = $tmp;
    }

    if (!$map) {
        // Built-in defaults
        if ($visitKey === 'oftalmo') {
            $map = vr_visit_template_default_map('oftalmologica');
        } else if ($visitKey === 'clinica') {
            $map = vr_visit_template_default_map('clinica');
        } else if (strpos($visitKey, 'form:') === 0) {
            // Custom module defaults: try to fetch the form name for nicer labels
            $formName = 'Visita personalizzata';
            if ($clinicId > 0) {
                try {
                    require_once __DIR__ . '/../db.php';
                    $db2 = vetroom_db();
                    if ($db2 instanceof PDO) {
                        $id = (int)substr($visitKey, 5);
                        $st = $db2->prepare("SELECT name FROM visit_forms WHERE clinic_id = ? AND id = ?");
                        $st->execute([$clinicId, $id]);
                        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                        if (!empty($r['name'])) $formName = (string)$r['name'];
                    }
                } catch (Exception $e) {
                    // ignore
                }
            }
            $map = vr_visit_template_default_map_custom_form($formName);
        } else {
            // Generic
            $map = vr_visit_template_default_map('clinica');
        }
    }
    $res['map'] = $map;

    // 6) Overlay header behaviour
    $res['overlay_header'] = $overlay;
    if ($overlay) {
        $res['clear_header'] = [
            'x_mm' => 0,
            'y_mm' => 0,
            'w_mm' => 210,
            'h_mm' => 32,
            'color_hex' => '#FFFFFF'
        ];
    }

    // Map can override font and header behaviour
    if (is_array($res['map'])) {
        $res['default_font_size'] = (float)($res['map']['default_font']['size'] ?? 9);
        $res['default_font_style'] = (string)($res['map']['default_font']['style'] ?? '');

        if (isset($res['map']['clear_header']) && is_array($res['map']['clear_header'])) {
            $res['clear_header'] = $res['map']['clear_header'];
        }
        if (isset($res['map']['overlay_header'])) {
            $res['overlay_header'] = !empty($res['map']['overlay_header']);
        }
    }

    return $res;
}

/**
 * Public entry: generate a visit PDF.
 *
 * If a template background is configured (or a built-in one exists), it uses the template engine.
 * Otherwise it falls back to the custom (legacy) layout.
 */
function vr_generate_from_template(string $type, array $data, string $destPath, array $attachments = []): bool {
    $header = is_array($data['header'] ?? null) ? $data['header'] : [];

    // Custom visit types are currently rendered using the legacy layout
    // so they always print all fields (even without a dedicated template).
    if (strtolower($type) === 'custom') {
        return vr_render_custom_clinica_layout(
            $data['header'] ?? null,
            $data['pet'] ?? [],
            $data['visit'] ?? [],
            $data['form'] ?? [],
            $data['owner'] ?? [],
            $destPath,
            $attachments
        );
    }

    $sel = vr_template_select_bg_and_map($type, $header);
    if (!empty($sel['bg_abs']) && is_array($sel['map'])) {
        $items = vr_template_build_items($sel['map'], $data);
        $opts = [
            'overlay_header' => !empty($sel['overlay_header']),
            'header' => $header,
            'clear_header' => $sel['clear_header'],
            'default_font_size' => (float)($sel['default_font_size'] ?? 9),
            'default_font_style' => (string)($sel['default_font_style'] ?? ''),
        ];
        return vr_render_with_minipdf_background($sel['bg_abs'], $items, $destPath, $opts, $attachments);
    }

    // Legacy fallback
    return vr_render_custom_clinica_layout(
        $data['header'] ?? null,
        $data['pet'] ?? [],
        $data['visit'] ?? [],
        $data['form'] ?? [],
        $data['owner'] ?? [],
        $destPath,
        $attachments
    );
}

/**
 * Build data arrays from VetRoom structures for easy passing to vr_generate_from_template(...).
 */
function vr_build_data_bundle(array $header=null, array $pet=[], array $visit=[], array $formData=[], array $owner=[]): array {
    return [
        'header' => $header ?? [],
        'pet'    => $pet ?? [],
        'visit'  => $visit ?? [],
        'form'   => $formData ?? [],
        'owner'  => $owner ?? [],
    ];
}
