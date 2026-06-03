<?php
/**
 * VetRoom 2 - Template Generator (beta)
 *
 * Generates a clean A4 background PNG and a matching mapping JSON so vets can
 * start from a professional layout without external tools.
 *
 * Requirements:
 * - PHP GD extension enabled (imagecreatetruecolor, imagepng, etc.)
 */

declare(strict_types=1);

require_once __DIR__ . '/pdf_templates.php'; // reuse unit helpers (mm2pt, hex->rgb, ...)

function vr_tplgen_has_gd(): bool {
    return function_exists('imagecreatetruecolor') && function_exists('imagepng');
}

function vr_tplgen_mm2px(float $mm, float $pxPerMm): int {
    return (int)round($mm * $pxPerMm);
}

function vr_tplgen_lighten_rgb(array $rgb, float $mix = 0.85): array {
    // mix: 0 => original, 1 => white
    $mix = max(0.0, min(1.0, $mix));
    return [
        (int)round($rgb[0] + (255 - $rgb[0]) * $mix),
        (int)round($rgb[1] + (255 - $rgb[1]) * $mix),
        (int)round($rgb[2] + (255 - $rgb[2]) * $mix),
    ];
}

/**
 * Generates a clean map.
 *
 * $kind can be: clinica, oftalmo, custom.
 * For custom modules, the map prints a compact "Dati visita" box.
 */
function vr_tplgen_generate_clean_map(string $kind, string $layout, string $accentHex, string $title = '', array $opts = []): array {
    $kind = strtolower(trim($kind));
    $layout = strtolower(trim($layout));
    if ($kind === 'oftalmologica') $kind = 'oftalmo';
    if ($kind !== 'clinica' && $kind !== 'oftalmo' && $kind !== 'custom') {
        $kind = 'clinica';
    }
    if ($layout === '') $layout = 'standard';

    $showLabels = true;
    if (is_array($opts) && array_key_exists('show_labels', $opts)) {
        $showLabels = !empty($opts['show_labels']);
    }

    $accentRgb = vr_hex_to_rgb($accentHex, [14, 116, 144]);
    $headerRgb = vr_tplgen_lighten_rgb($accentRgb, 0.85);
    $headerHex = sprintf('#%02X%02X%02X', $headerRgb[0], $headerRgb[1], $headerRgb[2]);

    // Shared defaults
    $prettyTitle = trim($title);
    if ($prettyTitle === '') {
        $prettyTitle = ($kind === 'clinica') ? 'Clinica' : (($kind === 'oftalmo') ? 'Oftalmologica' : 'Personalizzata');
    }

    $map = [
        'meta' => [
            'name' => 'Template pulito (beta) - ' . $prettyTitle . ' - ' . $layout,
            'version' => 1,
        ],
        'default_font' => [
            'size' => 9,
            'style' => '',
        ],
        // Keep the overlay header area consistent with the generated header band color.
        'overlay_header' => 1,
        'clear_header' => [
            'x_mm' => 0,
            'y_mm' => 0,
            'w_mm' => 210,
            'h_mm' => 32,
            'color_hex' => $headerHex,
        ],
        'items' => [],
    ];

    // --- Layout definitions (mm) ---
    // NOTE: We keep the "clean" templates label-free and rely on printed labels (show_label)
    // so the PDF remains self-descriptive regardless of the background.

    if ($kind === 'oftalmo') {
        // Keep oftalmo generation simple: a single-column layout.
        $layout = 'standard';
    }

    if ($layout === 'two_col' || $layout === '2col' || $layout === 'due_colonne') {
        // Patient/owner box (full width)
        $infoTop = 48;
        $map['items'] = array_merge($map['items'], vr_tplgen_clean_info_items($infoTop, $kind === 'custom', $showLabels));

        // Two-column boxes
        $leftX = 10; $rightX = 105; $colW = 95;
        $box1Y = 88; $boxH = 55;
        $box2Y = 147; $box2H = 65;
        $box3Y = 216; $box3H = 55;

        if ($kind === 'custom') {
            $cfItem = vr_tplgen_box_item('custom_fields', 'Dati visita', 'computed:custom_fields_compact', $leftX+2, $box1Y+2, $colW-4, $boxH-4, $showLabels);
            $cfItem['compact_show_labels'] = ($showLabels ? 1 : 0);
            $map['items'][] = $cfItem;
            $map['items'][] = vr_tplgen_box_item('diagnosis', 'Diagnosi', 'visit.diagnosis', $rightX+2, $box1Y+2, $colW-4, $boxH-4, $showLabels);
        } else {
            $map['items'][] = vr_tplgen_box_item('anamnesis', 'Anamnesi', 'form.anamnesis', $leftX+2, $box1Y+2, $colW-4, $boxH-4, $showLabels);
            $map['items'][] = vr_tplgen_box_item('diagnosis', 'Diagnosi', 'visit.diagnosis', $rightX+2, $box1Y+2, $colW-4, $boxH-4, $showLabels);
        }

        if ($kind === 'custom') {
            $map['items'][] = vr_tplgen_box_item('therapy', 'Terapia / Prescrizioni', 'visit.therapy', $leftX+2, $box2Y+2, $colW-4, $box2H-4, $showLabels);
            $map['items'][] = vr_tplgen_box_item('notes', 'Note / Raccomandazioni', 'visit.notes', $rightX+2, $box2Y+2, $colW-4, $box2H-4, $showLabels);
        } else {
            $map['items'][] = vr_tplgen_box_item('objective_any', 'Esame obiettivo', 'computed:objective_any', $leftX+2, $box2Y+2, $colW-4, $box2H-4, $showLabels);
            $map['items'][] = vr_tplgen_box_item('therapy', 'Terapia / Prescrizioni', 'visit.therapy', $rightX+2, $box2Y+2, $colW-4, $box2H-4, $showLabels);
        }

        if ($kind !== 'custom') {
            $map['items'][] = vr_tplgen_box_item('notes', 'Note / Raccomandazioni', 'visit.notes', 12, $box3Y+2, 186, $box3H-4, $showLabels);
        }
        return $map;
    }

    // Standard (single-column)
    $infoTop = 48;
    $map['items'] = array_merge($map['items'], vr_tplgen_clean_info_items($infoTop, $kind === 'custom', $showLabels));

    if ($kind === 'custom') {
        $cfItem = vr_tplgen_box_item('custom_fields', 'Dati visita', 'computed:custom_fields_compact', 12, 90, 186, 70, $showLabels);
        $cfItem['compact_show_labels'] = ($showLabels ? 1 : 0);
        $map['items'][] = $cfItem;
        $map['items'][] = vr_tplgen_box_item('diagnosis', 'Diagnosi', 'visit.diagnosis', 12, 166, 186, 20, $showLabels);
        $map['items'][] = vr_tplgen_box_item('therapy', 'Terapia / Prescrizioni', 'visit.therapy', 12, 194, 186, 30, $showLabels);
        $map['items'][] = vr_tplgen_box_item('notes', 'Note / Raccomandazioni', 'visit.notes', 12, 232, 186, 48, $showLabels);
    } else {
        $map['items'][] = vr_tplgen_box_item('anamnesis', 'Anamnesi', 'form.anamnesis', 12, 90, 186, 28, $showLabels);
        $map['items'][] = vr_tplgen_box_item('objective_any', 'Esame obiettivo', 'computed:objective_any', 12, 126, 186, 44, $showLabels);
        $map['items'][] = vr_tplgen_box_item('diagnosis', 'Diagnosi', 'visit.diagnosis', 12, 178, 186, 20, $showLabels);
        $map['items'][] = vr_tplgen_box_item('therapy', 'Terapia / Prescrizioni', 'visit.therapy', 12, 206, 186, 30, $showLabels);
        $map['items'][] = vr_tplgen_box_item('notes', 'Note / Raccomandazioni', 'visit.notes', 12, 244, 186, 34, $showLabels);
    }

    return $map;
}

function vr_tplgen_clean_info_items(float $infoTop, bool $custom = false, bool $showLabels = true): array {
    // Returns items for patient/owner quick info section.
    $xL = 12; $xR = 108; $w = 90;
    $y0 = $infoTop + 6; // first row baseline-ish
    $dy = 6;

    $rows = [
        ['pet_name', 'Nome animale', 'pet.name', 'owner_full', 'Proprietario', 'computed:owner_full'],
        ['pet_species', 'Specie', 'pet.species', 'owner_phone', 'Telefono', 'owner.phone'],
        ['pet_breed', 'Razza', 'pet.breed', 'owner_email', 'Email', 'owner.email'],
        ['pet_birth_date', 'Data di nascita', 'pet.birth_date', 'owner_address', 'Indirizzo', 'computed:owner_address_compact'],
        ['pet_sex', 'Sesso', 'pet.sex', 'visit_date', 'Data visita', 'visit.visit_date'],
        $custom
            ? ['pet_microchip', 'Microchip', 'pet.microchip', 'visit_title', 'Titolo visita', 'visit.title']
            : ['pet_microchip', 'Microchip', 'pet.microchip', 'visit_reason', 'Motivo visita', 'computed:visit_reason_fallback'],
    ];

    $items = [];
    foreach ($rows as $i => $r) {
        $y = $y0 + ($i * $dy);
        $items[] = vr_tplgen_line_item($r[0], $r[1], $r[2], $xL, $y, $w, $showLabels);
        $items[] = vr_tplgen_line_item($r[3], $r[4], $r[5], $xR, $y, $w, $showLabels);
    }
    // Visit date formatting
    foreach ($items as &$it) {
        if (($it['key'] ?? '') === 'pet_birth_date' || ($it['key'] ?? '') === 'visit_date') {
            $it['format'] = 'date_it';
        }
    }
    unset($it);
    return $items;
}

function vr_tplgen_line_item(string $key, string $uiLabel, string $source, float $x, float $y, float $w, bool $showLabels = true): array {
    return [
        'key' => $key,
        'ui_label' => $uiLabel,
        'label' => $uiLabel,
        'show_label' => ($showLabels ? 1 : 0),
        'label_mode' => 'inline',
        'x_mm' => $x,
        'y_mm' => $y,
        'w_mm' => $w,
        'h_mm' => 0,
        'source' => $source,
        'align' => 'L',
    ];
}

function vr_tplgen_box_item(string $key, string $uiLabel, string $source, float $x, float $y, float $w, float $h, bool $showLabels = true): array {
    return [
        'key' => $key,
        'ui_label' => $uiLabel,
        'label' => $uiLabel,
        'show_label' => ($showLabels ? 1 : 0),
        'label_mode' => 'above',
        'x_mm' => $x,
        'y_mm' => $y,
        'w_mm' => $w,
        'h_mm' => $h,
        'source' => $source,
        'align' => 'L',
        'font_size' => 9,
        'line_height_pt' => 11,
        'max_lines' => 999,
    ];
}

function vr_tplgen_generate_clean_png(string $kind, string $layout, string $accentHex, string $outAbs, string $title = ''): bool {
    if (!vr_tplgen_has_gd()) return false;

    $kind = strtolower(trim($kind));
    $layout = strtolower(trim($layout));
    if ($kind === 'oftalmologica') $kind = 'oftalmo';
    if ($kind !== 'clinica' && $kind !== 'oftalmo' && $kind !== 'custom') {
        $kind = 'clinica';
    }

    // A4 at 150 DPI (good balance between quality and size)
    $wPx = 1240;
    $hPx = 1754;
    $pxPerMm = $wPx / 210.0;

    $accentRgb = vr_hex_to_rgb($accentHex, [14, 116, 144]);
    $headerRgb = vr_tplgen_lighten_rgb($accentRgb, 0.85);

    $im = imagecreatetruecolor($wPx, $hPx);
    if (!$im) return false;

    // Colors
    $white = imagecolorallocate($im, 255, 255, 255);
    $border = imagecolorallocate($im, 180, 180, 180);
    $header = imagecolorallocate($im, $headerRgb[0], $headerRgb[1], $headerRgb[2]);
    $accent = imagecolorallocate($im, $accentRgb[0], $accentRgb[1], $accentRgb[2]);
    $text = imagecolorallocate($im, 25, 25, 25);

    // Background
    imagefilledrectangle($im, 0, 0, $wPx, $hPx, $white);

    // Header band (32mm)
    $headerH = vr_tplgen_mm2px(32, $pxPerMm);
    imagefilledrectangle($im, 0, 0, $wPx, $headerH, $header);
    // Accent thin line under header
    imagefilledrectangle($im, 0, $headerH - 2, $wPx, $headerH, $accent);

    // Title
    $title = trim($title);
    if ($kind === 'custom') {
        $title = ($title !== '') ? ('SCHEDA VISITA - ' . $title) : 'SCHEDA VISITA';
    } else {
        $title = ($kind === 'clinica') ? 'SCHEDA VISITA CLINICA' : 'SCHEDA VISITA OFTALMOLOGICA';
    }
    // Built-in GD font 5 is small; place title below header.
    imagestring($im, 5, vr_tplgen_mm2px(10, $pxPerMm), vr_tplgen_mm2px(36, $pxPerMm), $title, $text);

    // Boxes
    $drawBox = function(float $x, float $y, float $w, float $h) use ($im, $pxPerMm, $border) {
        $x1 = vr_tplgen_mm2px($x, $pxPerMm);
        $y1 = vr_tplgen_mm2px($y, $pxPerMm);
        $x2 = vr_tplgen_mm2px($x + $w, $pxPerMm);
        $y2 = vr_tplgen_mm2px($y + $h, $pxPerMm);
        imagerectangle($im, $x1, $y1, $x2, $y2, $border);
    };

    // Info box
    $drawBox(10, 48, 190, 38);

    if ($kind === 'oftalmo') {
        // Simple single-column for oftalmo
        $drawBox(10, 88, 190, 40); // anamnesis
        $drawBox(10, 132, 190, 60); // exam
        $drawBox(10, 196, 190, 40); // diagnosis
        $drawBox(10, 240, 190, 40); // therapy
    } else if ($kind === 'custom') {
        if ($layout === 'two_col' || $layout === '2col' || $layout === 'due_colonne') {
            // Two columns for custom visits
            $drawBox(10, 88, 95, 55);   // custom fields
            $drawBox(105, 88, 95, 55);  // diagnosis
            $drawBox(10, 147, 95, 65);  // therapy
            $drawBox(105, 147, 95, 65); // notes
        } else {
            // Custom module: bigger "Dati visita" box
            $drawBox(10, 88, 190, 70);   // custom fields
            $drawBox(10, 162, 190, 24);  // diagnosis
            $drawBox(10, 190, 190, 34);  // therapy
            $drawBox(10, 228, 190, 52);  // notes
        }
    } else if ($layout === 'two_col' || $layout === '2col' || $layout === 'due_colonne') {
        // Two columns
        $drawBox(10, 88, 95, 55);   // anamnesis
        $drawBox(105, 88, 95, 55);  // diagnosis
        $drawBox(10, 147, 95, 65);  // objective
        $drawBox(105, 147, 95, 65); // therapy
        $drawBox(10, 216, 190, 55); // notes
    } else {
        // Standard single column
        $drawBox(10, 88, 190, 32);   // anamnesis
        $drawBox(10, 124, 190, 48);  // objective
        $drawBox(10, 176, 190, 24);  // diagnosis
        $drawBox(10, 204, 190, 34);  // therapy
        $drawBox(10, 242, 190, 38);  // notes
    }

    // Signature line
    $ySig = vr_tplgen_mm2px(287, $pxPerMm);
    imageline($im, vr_tplgen_mm2px(120, $pxPerMm), $ySig, vr_tplgen_mm2px(200, $pxPerMm), $ySig, $border);
    imagestring($im, 3, vr_tplgen_mm2px(120, $pxPerMm), $ySig + 4, 'Firma', $text);

    // Save
    $ok = imagepng($im, $outAbs, 6);
    imagedestroy($im);
    return $ok && file_exists($outAbs);
}
