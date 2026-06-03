<?php
declare(strict_types=1);

require_once __DIR__ . '/policy.php';

/**
 * VetRoom - Consent / Relationship layer
 *
 * Centralizes the VET (clinic) ↔ OWNER relationship logic.
 *
 * Statuses:
 * - pending
 * - active
 * - rejected
 * - revoked
 * - suspended
 */

function vr_consent_normalize_status(string $status): string {
    $s = strtolower(trim($status));
    $allowed = ['pending','active','rejected','revoked','suspended'];
    if (!in_array($s, $allowed, true)) return 'pending';
    return $s;
}

/**
 * Fetch the relationship row (if any) for a given clinic ↔ owner pair.
 *
 * Some parts of the UI need to inspect the current state (pending/active/etc.)
 * after creating or linking an owner.
 */
function vr_consent_get_relation(PDO $db, int $clinicId, int $ownerId): ?array {
    $clinicId = (int)$clinicId;
    $ownerId  = (int)$ownerId;
    if ($clinicId <= 0 || $ownerId <= 0) return null;
    try {
        $st = $db->prepare("SELECT * FROM vet_owner_relations WHERE clinic_id=? AND owner_id=? LIMIT 1");
        $st->execute([$clinicId, $ownerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $t) {
        return null;
    }
}

function vr_consent_is_owner_active(PDO $db, int $clinicId, int $ownerId): bool {
    if ($clinicId <= 0 || $ownerId <= 0) return false;
    try {
        $st = $db->prepare("SELECT status FROM vet_owner_relations WHERE clinic_id=? AND owner_id=? LIMIT 1");
        $st->execute([$clinicId, $ownerId]);
        $s = (string)($st->fetchColumn() ?: '');
        return vr_consent_normalize_status($s) === 'active';
    } catch (Throwable $t) {
        return false;
    }
}

function vr_consent_is_pet_active(PDO $db, int $clinicId, int $petId): bool {
    if ($clinicId <= 0 || $petId <= 0) return false;
    try {
        $st = $db->prepare(
            "SELECT r.status
             FROM pets p
             JOIN owners o ON o.id = p.owner_id
             JOIN vet_owner_relations r ON r.owner_id = o.id AND r.clinic_id = o.clinic_id
             WHERE p.id=? AND p.clinic_id=?
             LIMIT 1"
        );
        $st->execute([$petId, $clinicId]);
        $s = (string)($st->fetchColumn() ?: '');
        return vr_consent_normalize_status($s) === 'active';
    } catch (Throwable $t) {
        return false;
    }
}

/**
 * Staff visibility check (ADMIN bypass).
 */
function vr_consent_staff_can_access_owner(PDO $db, ?array $user, int $clinicId, int $ownerId): bool {
    if (vr_policy_is_admin($user)) return true;
    return vr_consent_is_owner_active($db, $clinicId, $ownerId);
}

function vr_consent_staff_can_access_pet(PDO $db, ?array $user, int $clinicId, int $petId): bool {
    if (vr_policy_is_admin($user)) return true;
    return vr_consent_is_pet_active($db, $clinicId, $petId);
}

/**
 * Upsert relation row (idempotent).
 *
 * - Unique key: (clinic_id, owner_id)
 */
function vr_consent_upsert_relation(PDO $db, int $clinicId, int $ownerId, ?int $ownerAccountId, string $status): void {
    $clinicId = (int)$clinicId;
    $ownerId = (int)$ownerId;
    if ($clinicId <= 0 || $ownerId <= 0) return;
    $ownerAccountId = $ownerAccountId !== null ? (int)$ownerAccountId : null;
    $status = vr_consent_normalize_status($status);
    $now = date('c');

    try {
        $stmt = $db->prepare(
            "INSERT INTO vet_owner_relations (clinic_id, owner_id, owner_account_id, status, created_at, updated_at)
             VALUES (:cid,:oid,:aid,:st,:now,:now)
             ON CONFLICT(clinic_id, owner_id) DO UPDATE SET
                owner_account_id=COALESCE(excluded.owner_account_id, vet_owner_relations.owner_account_id),
                status=excluded.status,
                updated_at=excluded.updated_at"
        );
        $stmt->execute([
            ':cid' => $clinicId,
            ':oid' => $ownerId,
            ':aid' => $ownerAccountId,
            ':st'  => $status,
            ':now' => $now,
        ]);
    } catch (Throwable $t) {
        // ignore
    }
}
