<?php
/**
 * Visit template repository (per-clinic, per-sheet).
 *
 * A "scheda visita" (sheet) can be:
 * - built-in:  clinica, oftalmo
 * - custom:    form:<ID>  (linked to visit_forms)
 *
 * This file provides small helpers used by:
 * - Settings page (upload/save/reset/export/import)
 * - PDF template engine (runtime selection)
 */

/**
 * Normalize a visit sheet key.
 */
function vr_visit_sheet_key_normalize(string $key): string {
    $k = strtolower(trim($key));
    if ($k === 'oftalmologica' || $k === 'oculistica') return 'oftalmo';
    if ($k === 'oftalmo') return 'oftalmo';
    if ($k === 'clinica' || $k === 'clinical') return 'clinica';
    // Custom forms: form:<id>
    if (preg_match('/^form\s*:\s*(\d+)$/i', $k, $m)) {
        return 'form:' . ((int)$m[1]);
    }
    return $k;
}

/**
 * Safe file-name fragment from a visit sheet key.
 */
function vr_visit_sheet_key_safe(string $key): string {
    $k = vr_visit_sheet_key_normalize($key);
    $k = preg_replace('/[^a-z0-9]+/i', '_', $k);
    $k = trim($k, '_');
    return $k !== '' ? $k : 'sheet';
}

/**
 * Backward-compatible alias used across the app.
 *
 * Some parts of the codebase (settings actions: upload/import/export/generator)
 * reference vr_visit_sheet_key_to_filename(). The canonical helper is
 * vr_visit_sheet_key_safe().
 */
function vr_visit_sheet_key_to_filename(string $key): string {
    return vr_visit_sheet_key_safe($key);
}

/**
 * Ensure the visit_templates table exists.
 */
function vr_visit_templates_ensure_schema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS visit_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        clinic_id INTEGER NOT NULL,
        visit_key TEXT NOT NULL,
        enabled INTEGER NOT NULL DEFAULT 1,
        bg_path TEXT,
        map_json TEXT,
        overlay_header INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(clinic_id, visit_key),
        FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
    );");
}

/**
 * Fetch a template config row by key.
 */
function vr_visit_template_get(PDO $db, int $clinicId, string $visitKey): ?array {
    $visitKey = vr_visit_sheet_key_normalize($visitKey);
    vr_visit_templates_ensure_schema($db);
    $stmt = $db->prepare("SELECT * FROM visit_templates WHERE clinic_id = ? AND visit_key = ? LIMIT 1");
    $stmt->execute([$clinicId, $visitKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Upsert template config row.
 */
function vr_visit_template_upsert(PDO $db, int $clinicId, string $visitKey, array $data): void {
    $visitKey = vr_visit_sheet_key_normalize($visitKey);
    vr_visit_templates_ensure_schema($db);

    // IMPORTANT: preserve existing values when a field is not provided.
    // The settings page often saves only enabled/overlay/map_json and must NOT
    // accidentally clear the background image path.
    $existing = null;
    try {
        $stmt = $db->prepare("SELECT enabled, bg_path, map_json, overlay_header FROM visit_templates WHERE clinic_id = ? AND visit_key = ? LIMIT 1");
        $stmt->execute([$clinicId, $visitKey]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        $existing = null;
    }

    $enabled = array_key_exists('enabled', $data)
        ? (int)!empty($data['enabled'])
        : (int)($existing['enabled'] ?? 1);

    // bg_path: keep current if not present in $data
    $bg = null;
    if (array_key_exists('bg_path', $data)) {
        // allow explicit clearing by passing null / empty
        $bg = ($data['bg_path'] === null) ? '' : (string)$data['bg_path'];
    } else {
        $bg = (string)($existing['bg_path'] ?? '');
    }

    // map_json: keep current if not present in $data
    $map = null;
    if (array_key_exists('map_json', $data)) {
        $map = ($data['map_json'] === null) ? null : (string)$data['map_json'];
    } else {
        $map = $existing['map_json'] ?? null;
    }

    $overlay = array_key_exists('overlay_header', $data)
        ? (int)!empty($data['overlay_header'])
        : (int)($existing['overlay_header'] ?? 1);
    $now = $data['now'] ?? (function_exists('vr_now_iso') ? vr_now_iso() : date('c'));

    // Try update first
    $stmtUp = $db->prepare("UPDATE visit_templates SET enabled = ?, bg_path = ?, map_json = ?, overlay_header = ?, updated_at = ? WHERE clinic_id = ? AND visit_key = ?");
    $stmtUp->execute([$enabled, ($bg === '' ? null : $bg), ($map === '' ? null : $map), $overlay, $now, $clinicId, $visitKey]);
    if ($stmtUp->rowCount() > 0) return;

    $stmtIns = $db->prepare("INSERT INTO visit_templates (clinic_id, visit_key, enabled, bg_path, map_json, overlay_header, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtIns->execute([$clinicId, $visitKey, $enabled, ($bg === '' ? null : $bg), ($map === '' ? null : $map), $overlay, $now, $now]);
}

/**
 * Delete a template config for a sheet.
 */
function vr_visit_template_delete(PDO $db, int $clinicId, string $visitKey): void {
    $visitKey = vr_visit_sheet_key_normalize($visitKey);
    vr_visit_templates_ensure_schema($db);
    $stmt = $db->prepare("DELETE FROM visit_templates WHERE clinic_id = ? AND visit_key = ?");
    $stmt->execute([$clinicId, $visitKey]);
}

/**
 * Migrate legacy vet_settings columns for built-in templates into visit_templates.
 * Safe to call multiple times (INSERT OR IGNORE).
 */
function vr_visit_templates_migrate_legacy(PDO $db): void {
    vr_visit_templates_ensure_schema($db);
    try {
        // Only if legacy columns exist.
        $cols = $db->query("PRAGMA table_info(vet_settings)")->fetchAll(PDO::FETCH_ASSOC);
        $names = [];
        foreach ($cols as $c) { $names[strtolower((string)$c['name'])] = true; }
        if (empty($names['visit_template_clinica_enabled']) || empty($names['visit_template_oftalmo_enabled'])) {
            return;
        }
        $now = function_exists('vr_now_iso') ? vr_now_iso() : date('c');

        // Clinica
        $db->exec("INSERT OR IGNORE INTO visit_templates (clinic_id, visit_key, enabled, bg_path, map_json, overlay_header, created_at, updated_at)
                  SELECT clinic_id, 'clinica', visit_template_clinica_enabled, visit_template_clinica_bg, visit_template_clinica_map, visit_template_clinica_overlay_header, '$now', '$now'
                  FROM vet_settings");
        // Oftalmo
        $db->exec("INSERT OR IGNORE INTO visit_templates (clinic_id, visit_key, enabled, bg_path, map_json, overlay_header, created_at, updated_at)
                  SELECT clinic_id, 'oftalmo', visit_template_oftalmo_enabled, visit_template_oftalmo_bg, visit_template_oftalmo_map, visit_template_oftalmo_overlay_header, '$now', '$now'
                  FROM vet_settings");
    } catch (Exception $e) {
        // ignore
    }
}
