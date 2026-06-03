<?php
declare(strict_types=1);

/**
 * VetRoom - Policy layer (minimal)
 *
 * Centralizes authorization decisions for the STAFF app:
 * - Roles (staff app): CHIEF / VET / SECRETARY / STAFF / READONLY
 * - Capabilities: write, backup_admin
 *
 * This is intentionally small and conservative: it should not change the UX for
 * authorized users, but it prevents unauthorized roles (especially READONLY)
 * from performing state-changing actions.
 */

function vr_policy_role(?array $user): string {
    $r = strtoupper(trim((string)($user['role'] ?? '')));
    // Backward compatibility: the clinic admin role used to be named "ADMIN".
    // We now reserve ADMIN for the platform superuser, and use CHIEF for clinic scope.
    if ($r === 'ADMIN') $r = 'CHIEF';

    // Normalize legacy secretary role name (if present in older builds).
    if ($r === 'SEGRETARIA') $r = 'SECRETARY';

    if (!in_array($r, ['CHIEF','VET','SECRETARY','STAFF','READONLY'], true)) {
        $r = 'STAFF';
    }
    return $r;
}

function vr_policy_is_admin(?array $user): bool {
    // Keep function name for legacy code; CHIEF is the clinic-level admin.
    return vr_policy_role($user) === 'CHIEF';
}

function vr_policy_is_vet(?array $user): bool {
    return vr_policy_role($user) === 'VET';
}

function vr_policy_is_staff(?array $user): bool {
    $r = vr_policy_role($user);
    return in_array($r, ['STAFF','SECRETARY'], true);
}

function vr_policy_is_readonly(?array $user): bool {
    return vr_policy_role($user) === 'READONLY';
}

/**
 * Generic capability check.
 *
 * Capabilities:
 * - write: any state-changing action in the staff app (default: ADMIN/VET/STAFF)
 * - backup_admin: full DB/ZIP backup export/import (ADMIN only)
 */
function vr_policy_can(string $capability, ?array $user): bool {
    $capability = strtolower(trim($capability));
    $role = vr_policy_role($user);

    return match ($capability) {
        'write' => in_array($role, ['CHIEF','VET','SECRETARY','STAFF'], true),
        // Clinical visits (create/edit/export) are restricted to veterinarians only.
        // SECRETARY must never compile visit forms or generate visit sheets.
        'visit_write' => in_array($role, ['CHIEF','VET'], true),
        'backup_admin' => ($role === 'CHIEF'),
        default => false,
    };
}

/**
 * Map staff POST form_action to required capability.
 *
 * - staff_login: no role required (handled by auth logic)
 * - export/import backup: backup_admin
 * - everything else: write
 *
 * Returning empty string means "no policy check".
 */
function vr_policy_required_capability_for_form_action(string $formAction): string {
    $a = strtolower(trim($formAction));

    if ($a === '') {
        // Any POST without an explicit action should be treated as state-changing.
        return 'write';
    }

    if ($a === 'staff_login') return '';

    $backupActions = [
        'export_backup_full',
        'export_backup_db',
        'import_backup',
    ];
    if (in_array($a, $backupActions, true)) {
        return 'backup_admin';
    }

    // Visit actions must be restricted to CHIEF/VET.
    $visitActions = [
        'create_visit',
        'update_visit',
        'delete_visit',
        'generate_visit_pdf',
        'generate_visit_pdf_draft',
        'generate_visit_pdf_final',
        'upload_visit_attachment',
        'delete_visit_attachment',
        'send_email_visit',
    ];
    if (in_array($a, $visitActions, true)) {
        return 'visit_write';
    }

    // Default: all other POSTs are state-changing.
    return 'write';
}
