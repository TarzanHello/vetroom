<?php
/**
 * Default template mapping presets for VetRoom visit PDFs.
 *
 * These presets are used when:
 * - No custom mapping JSON is configured, OR
 * - A user uploads a template without providing a mapping.
 *
 * The coordinates are in millimeters on an A4 page:
 * - x_mm: distance from left edge
 * - y_mm: distance from top edge
 * - w_mm/h_mm: width/height of the text area
 */

function vr_visit_template_default_map(string $type): array {
    $type = strtolower(trim($type));

    // Generic preset for custom modules (visit_forms)
    if ($type === 'custom' || $type === 'personalizzata' || $type === 'modulo') {
        return vr_visit_template_default_map_custom_form('Visita personalizzata');
    }

    if ($type === 'oftalmologica' || $type === 'oftalmo') {
        return [
            'meta' => [
                'name' => 'VetRoom preset - visita oftalmologica',
                'version' => 1,
            ],
            'default_font' => [
                'size' => 9,
                'style' => '',
            ],
            'items' => [
                // --- Dati paziente e proprietario ---
                ['x_mm' => 43.04, 'y_mm' => 54.68, 'w_mm' => 47.98, 'key' => 'pet_name', 'source' => 'pet.name', 'align' => 'L'],
                ['x_mm' => 137.46, 'y_mm' => 54.68, 'w_mm' => 47.98, 'key' => 'owner_full', 'source' => 'computed:owner_full', 'align' => 'L'],

                ['x_mm' => 43.04, 'y_mm' => 59.27, 'w_mm' => 47.98, 'key' => 'pet_species', 'source' => 'pet.species', 'align' => 'L'],
                ['x_mm' => 137.46, 'y_mm' => 59.27, 'w_mm' => 47.98, 'key' => 'owner_phone', 'source' => 'owner.phone', 'align' => 'L'],

                ['x_mm' => 43.04, 'y_mm' => 63.85, 'w_mm' => 47.98, 'key' => 'pet_breed', 'source' => 'pet.breed', 'align' => 'L'],
                ['x_mm' => 137.46, 'y_mm' => 63.85, 'w_mm' => 47.98, 'key' => 'owner_email', 'source' => 'owner.email', 'align' => 'L'],

                ['x_mm' => 43.04, 'y_mm' => 68.44, 'w_mm' => 47.98, 'key' => 'pet_birth_date', 'source' => 'pet.birth_date', 'align' => 'L', 'format' => 'date_it'],
                ['x_mm' => 137.46, 'y_mm' => 68.44, 'w_mm' => 47.98, 'key' => 'owner_address', 'source' => 'computed:owner_address_compact', 'align' => 'L'],

                ['x_mm' => 43.04, 'y_mm' => 73.02, 'w_mm' => 47.98, 'key' => 'pet_sex', 'source' => 'pet.sex', 'align' => 'L'],
                ['x_mm' => 137.46, 'y_mm' => 73.02, 'w_mm' => 47.98, 'key' => 'visit_date', 'source' => 'visit.visit_date', 'align' => 'L', 'format' => 'date_it'],

                ['x_mm' => 43.04, 'y_mm' => 77.61, 'w_mm' => 47.98, 'key' => 'pet_microchip', 'source' => 'pet.microchip', 'align' => 'L'],
                ['x_mm' => 137.46, 'y_mm' => 77.61, 'w_mm' => 47.98, 'key' => 'visit_reason', 'source' => 'computed:visit_reason_fallback', 'align' => 'L'],

                // --- Anamnesi ---
                // Label is printed together with the value so the generated PDF remains self-descriptive
                // even when the background template is a flat image.
                ['x_mm' => 14.82, 'y_mm' => 91.72, 'w_mm' => 180.37, 'h_mm' => 16.23, 'key' => 'anamnesis', 'label' => 'Anamnesi', 'label_mode' => 'inline', 'source' => 'form.anamnesis', 'align' => 'L', 'font_size' => 9, 'line_height_pt' => 11, 'max_lines' => 8],

                // --- Esame oftalmologico (righe) ---
                ['x_mm' => 60.68, 'y_mm' => 121.00, 'w_mm' => 44.45, 'key' => 'vision', 'source' => 'computed:eye_pair', 'dx' => 'form.reflex.minaccia_dx', 'sx' => 'form.reflex.minaccia_sx'],
                ['x_mm' => 155.09, 'y_mm' => 121.00, 'w_mm' => 44.45, 'key' => 'cornea', 'source' => 'computed:eye_pair', 'dx' => 'form.occhio.cornea_dx', 'sx' => 'form.occhio.cornea_sx'],

                ['x_mm' => 60.68, 'y_mm' => 125.59, 'w_mm' => 44.45, 'key' => 'pupillare', 'source' => 'computed:eye_pair', 'dx' => 'form.reflex.pupillare_dx', 'sx' => 'form.reflex.pupillare_sx'],
                ['x_mm' => 155.09, 'y_mm' => 125.59, 'w_mm' => 44.45, 'key' => 'camera', 'source' => 'computed:eye_pair', 'dx' => 'form.occhio.camera_dx', 'sx' => 'form.occhio.camera_sx'],

                ['x_mm' => 60.68, 'y_mm' => 130.17, 'w_mm' => 44.45, 'key' => 'schirmer', 'source' => 'computed:eye_pair', 'dx' => 'form.occhio.schirmer_dx', 'sx' => 'form.occhio.schirmer_sx'],
                ['x_mm' => 155.09, 'y_mm' => 130.17, 'w_mm' => 44.45, 'key' => 'cristallino', 'source' => 'computed:eye_pair', 'dx' => 'form.occhio.cristallino_dx', 'sx' => 'form.occhio.cristallino_sx'],

                ['x_mm' => 60.68, 'y_mm' => 134.76, 'w_mm' => 44.45, 'key' => 'tonometria', 'source' => 'computed:eye_pair', 'dx' => 'form.occhio.iop_dx', 'sx' => 'form.occhio.iop_sx'],
                ['x_mm' => 155.09, 'y_mm' => 134.76, 'w_mm' => 44.45, 'key' => 'fondo', 'source' => 'computed:eye_pair', 'dx' => 'form.occhio.fondo_dx', 'sx' => 'form.occhio.fondo_sx'],

                ['x_mm' => 60.68, 'y_mm' => 139.35, 'w_mm' => 44.45, 'key' => 'fluor', 'source' => 'computed:eye_pair', 'dx' => 'form.occhio.fluor_dx', 'sx' => 'form.occhio.fluor_sx'],
                ['x_mm' => 155.09, 'y_mm' => 139.35, 'w_mm' => 44.45, 'key' => 'pio_od', 'source' => 'form.occhio.iop_dx'],

                ['x_mm' => 60.68, 'y_mm' => 143.93, 'w_mm' => 44.45, 'key' => 'palpebre', 'source' => 'computed:eye_pair', 'dx' => 'form.annessi.palpebre_dx', 'sx' => 'form.annessi.palpebre_sx'],
                ['x_mm' => 155.09, 'y_mm' => 143.93, 'w_mm' => 44.45, 'key' => 'pio_os', 'source' => 'form.occhio.iop_sx'],

                ['x_mm' => 60.68, 'y_mm' => 148.52, 'w_mm' => 44.45, 'key' => 'congiuntiva', 'source' => 'computed:eye_pair', 'dx' => 'form.annessi.congiuntiva_dx', 'sx' => 'form.annessi.congiuntiva_sx'],
                ['x_mm' => 155.09, 'y_mm' => 148.52, 'w_mm' => 44.45, 'key' => 'note_exam', 'source' => 'visit.notes'],

                // --- Diagnosi / Terapia ---
                ['x_mm' => 14.82, 'y_mm' => 164.39, 'w_mm' => 180.37, 'h_mm' => 12.70, 'key' => 'diagnosis', 'label' => 'Diagnosi', 'label_mode' => 'inline', 'source' => 'visit.diagnosis', 'align' => 'L', 'font_size' => 9, 'line_height_pt' => 11, 'max_lines' => 10],
                ['x_mm' => 14.82, 'y_mm' => 189.09, 'w_mm' => 180.37, 'h_mm' => 16.23, 'key' => 'therapy', 'label' => 'Terapia', 'label_mode' => 'inline', 'source' => 'visit.therapy', 'align' => 'L', 'font_size' => 9, 'line_height_pt' => 11, 'max_lines' => 10],
            ],
        ];
    }

    // Default: CLINICA / GENERALE
    return [
        'meta' => [
            'name' => 'VetRoom preset - visita clinica',
            'version' => 1,
        ],
        'default_font' => [
            'size' => 9,
            'style' => '',
        ],
        'items' => [
            // --- Dati paziente e proprietario ---
            ['x_mm' => 43.04, 'y_mm' => 54.68, 'w_mm' => 47.98, 'key' => 'pet_name', 'source' => 'pet.name', 'align' => 'L'],
            ['x_mm' => 137.46, 'y_mm' => 54.68, 'w_mm' => 47.98, 'key' => 'owner_full', 'source' => 'computed:owner_full', 'align' => 'L'],

            ['x_mm' => 43.04, 'y_mm' => 59.27, 'w_mm' => 47.98, 'key' => 'pet_species', 'source' => 'pet.species', 'align' => 'L'],
            ['x_mm' => 137.46, 'y_mm' => 59.27, 'w_mm' => 47.98, 'key' => 'owner_phone', 'source' => 'owner.phone', 'align' => 'L'],

            ['x_mm' => 43.04, 'y_mm' => 63.85, 'w_mm' => 47.98, 'key' => 'pet_breed', 'source' => 'pet.breed', 'align' => 'L'],
            ['x_mm' => 137.46, 'y_mm' => 63.85, 'w_mm' => 47.98, 'key' => 'owner_email', 'source' => 'owner.email', 'align' => 'L'],

            ['x_mm' => 43.04, 'y_mm' => 68.44, 'w_mm' => 47.98, 'key' => 'pet_birth_date', 'source' => 'pet.birth_date', 'align' => 'L', 'format' => 'date_it'],
            ['x_mm' => 137.46, 'y_mm' => 68.44, 'w_mm' => 47.98, 'key' => 'owner_address', 'source' => 'computed:owner_address_compact', 'align' => 'L'],

            ['x_mm' => 43.04, 'y_mm' => 73.02, 'w_mm' => 47.98, 'key' => 'pet_sex', 'source' => 'pet.sex', 'align' => 'L'],
            ['x_mm' => 137.46, 'y_mm' => 73.02, 'w_mm' => 47.98, 'key' => 'visit_date', 'source' => 'visit.visit_date', 'align' => 'L', 'format' => 'date_it'],

            ['x_mm' => 43.04, 'y_mm' => 77.61, 'w_mm' => 47.98, 'key' => 'pet_microchip', 'source' => 'pet.microchip', 'align' => 'L'],
            ['x_mm' => 137.46, 'y_mm' => 77.61, 'w_mm' => 47.98, 'key' => 'visit_reason', 'source' => 'form.reason', 'align' => 'L'],

            // --- Anamnesi ---
            ['x_mm' => 14.82, 'y_mm' => 91.72, 'w_mm' => 180.37, 'h_mm' => 19.76, 'key' => 'anamnesis', 'label' => 'Anamnesi', 'label_mode' => 'inline', 'source' => 'form.anamnesis', 'align' => 'L', 'font_size' => 9, 'line_height_pt' => 11, 'max_lines' => 8],

            // --- Esame obiettivo (righe) ---
            ['x_mm' => 57.15, 'y_mm' => 124.53, 'w_mm' => 33.87, 'key' => 'obj_feci', 'source' => 'form.objective', 'subkey' => 'Feci', 'align' => 'L'],
            ['x_mm' => 158.62, 'y_mm' => 124.53, 'w_mm' => 40.92, 'key' => 'obj_idratazione', 'source' => 'form.objective', 'subkey' => 'Stato di idratazione', 'align' => 'L'],

            ['x_mm' => 57.15, 'y_mm' => 129.12, 'w_mm' => 33.87, 'key' => 'obj_temperatura', 'source' => 'form.objective', 'subkey' => 'Temperatura', 'align' => 'L'],
            ['x_mm' => 158.62, 'y_mm' => 129.12, 'w_mm' => 40.92, 'key' => 'obj_linfonodi', 'source' => 'form.objective', 'subkey' => 'Note linfonodi', 'align' => 'L'],

            ['x_mm' => 57.15, 'y_mm' => 133.70, 'w_mm' => 33.87, 'key' => 'obj_fc', 'source' => 'form.objective', 'subkey' => 'Frequenza cardiaca', 'align' => 'L'],
            ['x_mm' => 158.62, 'y_mm' => 133.70, 'w_mm' => 40.92, 'key' => 'obj_polso_femorale', 'source' => 'form.objective', 'subkey' => 'Polso femorale', 'align' => 'L'],

            ['x_mm' => 57.15, 'y_mm' => 138.29, 'w_mm' => 33.87, 'key' => 'obj_sensorio', 'source' => 'form.objective', 'subkey' => 'Stato sensorio', 'align' => 'L'],
            ['x_mm' => 158.62, 'y_mm' => 138.29, 'w_mm' => 40.92, 'key' => 'obj_polso_tarsale', 'source' => 'form.objective', 'subkey' => 'Polso tarsale', 'align' => 'L'],

            ['x_mm' => 57.15, 'y_mm' => 142.88, 'w_mm' => 33.87, 'key' => 'obj_respirazione', 'source' => 'form.objective', 'subkey' => 'Respirazione', 'align' => 'L'],
            ['x_mm' => 158.62, 'y_mm' => 142.88, 'w_mm' => 40.92, 'key' => 'obj_auscultazione_cardiaca', 'source' => 'form.objective', 'subkey' => 'Auscultazione cardiaca', 'align' => 'L'],

            ['x_mm' => 57.15, 'y_mm' => 147.46, 'w_mm' => 33.87, 'key' => 'obj_mucose', 'source' => 'form.objective', 'subkey' => 'Mucose', 'align' => 'L'],
            ['x_mm' => 158.62, 'y_mm' => 147.46, 'w_mm' => 40.92, 'key' => 'obj_apparato_respiratorio', 'source' => 'form.objective', 'subkey' => 'Apparato respiratorio', 'align' => 'L'],

            ['x_mm' => 57.15, 'y_mm' => 152.05, 'w_mm' => 33.87, 'key' => 'obj_riempimento_capillare', 'source' => 'form.objective', 'subkey' => 'Riempimento capillare', 'align' => 'L'],
            ['x_mm' => 158.62, 'y_mm' => 152.05, 'w_mm' => 40.92, 'key' => 'obj_palpazione_addome', 'source' => 'form.objective', 'subkey' => 'Palpazione addome', 'align' => 'L'],

            // --- Diagnosi / Terapia / Note ---
            ['x_mm' => 14.82, 'y_mm' => 167.92, 'w_mm' => 180.37, 'h_mm' => 12.70, 'key' => 'diagnosis', 'label' => 'Diagnosi', 'label_mode' => 'inline', 'source' => 'visit.diagnosis', 'align' => 'L', 'font_size' => 9, 'line_height_pt' => 11, 'max_lines' => 10],
            ['x_mm' => 14.82, 'y_mm' => 192.62, 'w_mm' => 180.37, 'h_mm' => 16.23, 'key' => 'therapy', 'label' => 'Terapia', 'label_mode' => 'inline', 'source' => 'visit.therapy', 'align' => 'L', 'font_size' => 9, 'line_height_pt' => 11, 'max_lines' => 10],
            ['x_mm' => 14.82, 'y_mm' => 220.84, 'w_mm' => 180.37, 'h_mm' => 12.70, 'key' => 'notes', 'label' => 'Note', 'label_mode' => 'inline', 'source' => 'visit.notes', 'align' => 'L', 'font_size' => 9, 'line_height_pt' => 11, 'max_lines' => 10],
        ],
    ];
}

/**
 * Default map for a custom visit form (visit_forms).
 * It prints all custom fields in a single box (compact), plus diagnosis/therapy/notes.
 *
 * The vet can later split fields manually from the visual editor.
 */
function vr_visit_template_default_map_custom_form(string $formName = 'Visita personalizzata'): array {
    $formName = trim((string)$formName);
    if ($formName === '') $formName = 'Visita personalizzata';

    // Start from the clinical preset for the patient/owner header positions.
    $base = vr_visit_template_default_map('clinica');
    $base['meta']['name'] = 'VetRoom preset - ' . $formName;

    // Replace the clinical body with a "custom fields" box.
    $items = [];
    foreach (($base['items'] ?? []) as $it) {
        if (!is_array($it)) continue;
        // Keep only the shared top section (patient/owner + visit date).
        $k = (string)($it['key'] ?? '');
        if (in_array($k, ['pet_name','owner_full','pet_species','owner_phone','pet_breed','owner_email','pet_birth_date','owner_address','pet_sex','visit_date','pet_microchip'], true)) {
            $items[] = $it;
            continue;
        }
        // Keep the visit "reason" slot but map it to the visit title for custom modules.
        if ($k === 'visit_reason') {
            $it['key'] = 'visit_title';
            $it['source'] = 'visit.title';
            $it['label'] = 'Titolo visita';
            $it['label_mode'] = 'inline';
            $items[] = $it;
            continue;
        }
    }

    // Main custom fields box
    $items[] = [
        'x_mm' => 14.82,
        'y_mm' => 91.72,
        'w_mm' => 180.37,
        'h_mm' => 70.00,
        'key' => 'custom_fields_compact',
        'label' => $formName,
        'label_mode' => 'above',
        'source' => 'computed:custom_fields_compact',
        'align' => 'L',
        'font_size' => 9,
        'line_height_pt' => 11,
        'max_lines' => 999
    ];

    // Diagnosis / Therapy / Notes (same as clinical)
    $items[] = [
        'x_mm' => 14.82,
        'y_mm' => 167.92,
        'w_mm' => 180.37,
        'h_mm' => 12.70,
        'key' => 'diagnosis',
        'label' => 'Diagnosi',
        'label_mode' => 'inline',
        'source' => 'visit.diagnosis',
        'align' => 'L',
        'font_size' => 9,
        'line_height_pt' => 11,
        'max_lines' => 10
    ];
    $items[] = [
        'x_mm' => 14.82,
        'y_mm' => 192.62,
        'w_mm' => 180.37,
        'h_mm' => 16.23,
        'key' => 'therapy',
        'label' => 'Terapia',
        'label_mode' => 'inline',
        'source' => 'visit.therapy',
        'align' => 'L',
        'font_size' => 9,
        'line_height_pt' => 11,
        'max_lines' => 10
    ];
    $items[] = [
        'x_mm' => 14.82,
        'y_mm' => 220.84,
        'w_mm' => 180.37,
        'h_mm' => 12.70,
        'key' => 'notes',
        'label' => 'Note',
        'label_mode' => 'inline',
        'source' => 'visit.notes',
        'align' => 'L',
        'font_size' => 9,
        'line_height_pt' => 11,
        'max_lines' => 10
    ];

    $base['items'] = $items;
    return $base;
}

function vr_visit_template_default_map_custom_form_json(string $formName = 'Visita personalizzata'): string {
    $arr = vr_visit_template_default_map_custom_form($formName);
    $json = json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? '{}' : $json;
}

function vr_visit_template_default_map_json(string $type): string {
    $arr = vr_visit_template_default_map($type);
    $json = json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? '{}' : $json;
}
