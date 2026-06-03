<?php
require_once __DIR__ . '/config.php';

// The active PDO connection is stored in a global so it can be safely reset
// (e.g. during backup/restore operations that replace the SQLite file).
// Do NOT access this directly outside this file.
if (!array_key_exists('__vetroom_pdo', $GLOBALS)) {
    $GLOBALS['__vetroom_pdo'] = null;
}

/**
 * Chiude la connessione PDO (se presente).
 *
 * Utile per operazioni di manutenzione (backup/ripristino) dove il file SQLite
 * deve essere copiato/sostituito senza lock.
 */
function vetroom_db_close(): void {
    $GLOBALS['__vetroom_pdo'] = null;
}

/**
 * Ritorna l'istanza PDO verso il database SQLite.
 */
function vetroom_db(): PDO {
    if ($GLOBALS['__vetroom_pdo'] === null) {
        $dir = dirname(VETROOM_DB_PATH);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }


        // Try to ensure the data directory is writable (common install issue on some hosts).
        if (is_dir($dir) && !is_writable($dir)) {
            @chmod($dir, 0775);
            if (!is_writable($dir)) {
                @chmod($dir, 0777);
            }
        }

        // Ensure uploads directory exists (templates, logos, template backgrounds).
        $uploadsDir = $dir . '/uploads';
        if (!is_dir($uploadsDir)) {
            @mkdir($uploadsDir, 0775, true);
        }
        if (is_dir($uploadsDir) && !is_writable($uploadsDir)) {
            @chmod($uploadsDir, 0775);
            if (!is_writable($uploadsDir)) {
                @chmod($uploadsDir, 0777);
            }
        }

        // Private uploads directory: contains sensitive files (identity docs, visit PDFs, attachments).
        // Access must go through authenticated download endpoints.
        $privateDir = $dir . '/private_uploads';
        if (!is_dir($privateDir)) {
            @mkdir($privateDir, 0775, true);
        }
        if (is_dir($privateDir) && !is_writable($privateDir)) {
            @chmod($privateDir, 0775);
            if (!is_writable($privateDir)) {
                @chmod($privateDir, 0777);
            }
        }
        // Best-effort protection from direct web access (Apache).
        $pht = $privateDir . '/.htaccess';
        if (!file_exists($pht)) {
            @file_put_contents($pht, "Require all denied\n");
        }
        $pindex = $privateDir . '/index.html';
        if (!file_exists($pindex)) {
            @file_put_contents($pindex, "<!-- private -->\n");
        }
        // Protect the SQLite DB from direct download on Apache (if .htaccess is supported).
        // This file is optional; if the host doesn't use Apache or disallows file writes here, it will be ignored.
        $ht = $dir . '/.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht, "<FilesMatch \"\\\.(sqlite|db)\\$\">\n  Require all denied\n</FilesMatch>\n");
        }

        $db = new PDO('sqlite:' . VETROOM_DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA foreign_keys = ON');

        // Apply lightweight runtime migrations so upgrades don't require reinstall.
        vetroom_apply_runtime_migrations($db);

        $GLOBALS['__vetroom_pdo'] = $db;
    }
    return $GLOBALS['__vetroom_pdo'];
}

/**
 * Runtime migrations (safe, idempotent).
 * This is executed on each request (only once per request) to keep existing installations compatible
 * when new columns are added in later versions.
 */
function vetroom_apply_runtime_migrations(PDO $db): void {
    try {
        // Always keep a minimal set of tables available (even before full install)
        // so the landing page can persist requests safely.
        $db->exec("CREATE TABLE IF NOT EXISTS leads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            email TEXT NOT NULL,
            phone TEXT,
            clinic TEXT,
            city TEXT,
            message TEXT,
            ip TEXT,
            user_agent TEXT,
            created_at TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'NEW',
            notes TEXT
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT,
            ip TEXT,
            success INTEGER NOT NULL,
            created_at TEXT NOT NULL
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS mail_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            to_email TEXT NOT NULL,
            subject TEXT,
            ok INTEGER NOT NULL DEFAULT 0,
            error TEXT,
            created_at TEXT NOT NULL
        )");

        $res = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='vet_settings'");
        if (!$res || !$res->fetchColumn()) {
            return; // not installed yet
        }

        // ==========================
        // Owner portal (Identity)
        // ==========================
        // Account unico per Codice Fiscale (vincolo UNIQUE su owner_accounts.fiscal_code)
        $db->exec("CREATE TABLE IF NOT EXISTS owner_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fiscal_code TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            email TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");

        // owners.owner_account_id column (link anagrafica -> account)
        $resOwners = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='owners'");
        $ownersExists = false;
        if ($resOwners && $resOwners->fetchColumn()) {
            $ownersExists = true;
            $ocols = $db->query("PRAGMA table_info(owners)")->fetchAll(PDO::FETCH_ASSOC);
            $onames = [];
            foreach ($ocols as $oc) {
                $onames[strtolower((string)$oc['name'])] = true;
            }
            if (empty($onames['owner_account_id'])) {
                $db->exec("ALTER TABLE owners ADD COLUMN owner_account_id INTEGER");
            }
            $db->exec("CREATE INDEX IF NOT EXISTS idx_owners_owner_account_id ON owners(owner_account_id)");
        }

        // ==========================
        // VET ↔ OWNER relationship (Consent)
        // ==========================
        // Per-owner (anagrafica) relationship within a clinic, with state machine.
        // Soft migration: auto-link existing owners as ACTIVE to avoid blocking operations.
        $db->exec("CREATE TABLE IF NOT EXISTS vet_owner_relations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clinic_id INTEGER NOT NULL,
            owner_id INTEGER NOT NULL,
            owner_account_id INTEGER,
            status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE(clinic_id, owner_id),
            FOREIGN KEY(clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY(owner_id) REFERENCES owners(id) ON DELETE CASCADE,
            FOREIGN KEY(owner_account_id) REFERENCES owner_accounts(id) ON DELETE SET NULL
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_vor_owner_account_status ON vet_owner_relations(owner_account_id, status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_vor_clinic_status ON vet_owner_relations(clinic_id, status)");

        // Backfill missing relations for existing owners (soft migration)
        if ($ownersExists) {
            $now = date('c');
            // Insert ACTIVE relation rows for owners without an entry.
            $db->exec("INSERT OR IGNORE INTO vet_owner_relations (clinic_id, owner_id, owner_account_id, status, created_at, updated_at)
                      SELECT clinic_id, id, owner_account_id, 'active', '" . $now . "', '" . $now . "' FROM owners");
            // Keep owner_account_id in sync for already-created relations (best effort).
            $db->exec("UPDATE vet_owner_relations
                      SET owner_account_id = (SELECT owner_account_id FROM owners WHERE owners.id = vet_owner_relations.owner_id)
                      WHERE (owner_account_id IS NULL OR owner_account_id = 0)
                        AND EXISTS (SELECT 1 FROM owners WHERE owners.id = vet_owner_relations.owner_id AND owners.owner_account_id IS NOT NULL)");
        }

        $cols = $db->query("PRAGMA table_info(vet_settings)")->fetchAll(PDO::FETCH_ASSOC);
        $names = [];
        foreach ($cols as $c) {
            $names[strtolower((string)$c['name'])] = true;
        }

        if (empty($names['logo_path'])) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN logo_path TEXT");
        }
        if (empty($names['pdf_settings'])) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN pdf_settings TEXT");
        }
        // Visit templates (per-clinic customizable PDF/PNG backgrounds + mapping JSON)
        if (empty($names['visit_template_clinica_enabled'])) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN visit_template_clinica_enabled INTEGER NOT NULL DEFAULT 1");
        }
        if (empty($names['visit_template_clinica_bg'])) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN visit_template_clinica_bg TEXT");
        }
        if (empty($names['visit_template_clinica_map'])) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN visit_template_clinica_map TEXT");
        }
        if (empty($names['visit_template_clinica_overlay_header'])) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN visit_template_clinica_overlay_header INTEGER NOT NULL DEFAULT 1");
        }

        if (empty($names['visit_template_oftalmo_enabled'])) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN visit_template_oftalmo_enabled INTEGER NOT NULL DEFAULT 1");
        }
        if (empty($names['visit_template_oftalmo_bg'])) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN visit_template_oftalmo_bg TEXT");
        }
        if (empty($names['visit_template_oftalmo_map'])) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN visit_template_oftalmo_map TEXT");
        }
        if (empty($names['visit_template_oftalmo_overlay_header'])) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN visit_template_oftalmo_overlay_header INTEGER NOT NULL DEFAULT 1");
        }

        // Stamp (timbro) image path
        if (empty($names['stamp_path'])) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN stamp_path TEXT");
        }

        // Documents roles (visit PDF vs attachments vs generic)
        // Used to avoid ambiguity when multiple PDFs are linked to the same visit.
        $resDoc = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='documents'");
        if ($resDoc && $resDoc->fetchColumn()) {
            $dcols = $db->query("PRAGMA table_info(documents)")->fetchAll(PDO::FETCH_ASSOC);
            $dnames = [];
            foreach ($dcols as $dc) {
                $dnames[strtolower((string)$dc['name'])] = true;
            }
            if (empty($dnames['doc_role'])) {
                $db->exec("ALTER TABLE documents ADD COLUMN doc_role TEXT");
            }
        }



        // Agenda: allow an optional afternoon range (start_time2 / end_time2)
        $resAv = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='availabilities'");
        if ($resAv && $resAv->fetchColumn()) {
            $acols = $db->query("PRAGMA table_info(availabilities)")->fetchAll(PDO::FETCH_ASSOC);
            $anames = [];
            foreach ($acols as $ac) {
                $anames[strtolower((string)$ac['name'])] = true;
            }
            if (empty($anames['start_time2'])) {
                $db->exec("ALTER TABLE availabilities ADD COLUMN start_time2 TEXT");
            }
            if (empty($anames['end_time2'])) {
                $db->exec("ALTER TABLE availabilities ADD COLUMN end_time2 TEXT");
            }
        }
        // Custom visit forms
        $resForms = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='visit_forms'");
        if (!$resForms || !$resForms->fetchColumn()) {
            $db->exec("CREATE TABLE IF NOT EXISTS visit_forms (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                clinic_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                definition_json TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
            );");
        }

        // Link custom visit to a form definition
        $vcols = $db->query("PRAGMA table_info(visits)")->fetchAll(PDO::FETCH_ASSOC);
        $vnames = [];
        foreach ($vcols as $vc) { $vnames[strtolower((string)$vc['name'])] = true; }
        if (empty($vnames['visit_form_id'])) {
            $db->exec("ALTER TABLE visits ADD COLUMN visit_form_id INTEGER");
        }

        // Title shown in UI and printable templates
        if (empty($vnames['title'])) {
            $db->exec("ALTER TABLE visits ADD COLUMN title TEXT");
        }

        // Visit templates (per-clinic, per-sheet). New unified storage replacing the legacy
        // visit_template_* columns in vet_settings (still kept for backward compatibility).
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

        // Migrate legacy built-in templates (clinica / oftalmo) into visit_templates only once.
        // Uses INSERT OR IGNORE so it never overrides the new table.
        $now = date('c');
        $db->exec("INSERT OR IGNORE INTO visit_templates (clinic_id, visit_key, enabled, bg_path, map_json, overlay_header, created_at, updated_at)
                  SELECT clinic_id, 'clinica', visit_template_clinica_enabled, visit_template_clinica_bg, visit_template_clinica_map, visit_template_clinica_overlay_header, '$now', '$now'
                  FROM vet_settings");
        $db->exec("INSERT OR IGNORE INTO visit_templates (clinic_id, visit_key, enabled, bg_path, map_json, overlay_header, created_at, updated_at)
                  SELECT clinic_id, 'oftalmo', visit_template_oftalmo_enabled, visit_template_oftalmo_bg, visit_template_oftalmo_map, visit_template_oftalmo_overlay_header, '$now', '$now'
                  FROM vet_settings");

        /**
         * ===== VetRoom 3 platform tables & security =====
         */
        // app_meta for schema versioning
        $db->exec("CREATE TABLE IF NOT EXISTS app_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)");
        $verRow = $db->prepare("SELECT value FROM app_meta WHERE key='schema_version' LIMIT 1");
        $verRow->execute();
        $currentVer = (int)($verRow->fetchColumn() ?: 0);

        // Schema version (bump when introducing new runtime migrations that should trigger
        // a one-time pre-migration backup for existing installs).
        $targetVer = 9;

        if ($currentVer < $targetVer) {
            // pre-migration backup (DB file copy)
            $dir = dirname(VETROOM_DB_PATH);
            $bdir = $dir . '/backups';
            if (!is_dir($bdir)) { @mkdir($bdir, 0775, true); }
            $ts = date('Ymd_His');
            @copy(VETROOM_DB_PATH, $bdir . '/pre_migration_' . $ts . '_v' . $currentVer . '.sqlite');
        }

        // Ensure new columns exist (idempotent)
        $cCols = $db->query("PRAGMA table_info(clinics)")->fetchAll(PDO::FETCH_ASSOC);
        $cNames = [];
        foreach ($cCols as $c) { $cNames[strtolower((string)$c['name'])] = true; }
        if (empty($cNames['is_active'])) { $db->exec("ALTER TABLE clinics ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1"); }
        if (empty($cNames['status'])) { $db->exec("ALTER TABLE clinics ADD COLUMN status TEXT NOT NULL DEFAULT 'ACTIVE'"); }
        // Multi-clinic/multi-staff: hard limits (settable ONLY by PLATFORM/ADMIN)
        if (empty($cNames['max_vets'])) { $db->exec("ALTER TABLE clinics ADD COLUMN max_vets INTEGER NOT NULL DEFAULT 1"); }
        if (empty($cNames['max_secretaries'])) { $db->exec("ALTER TABLE clinics ADD COLUMN max_secretaries INTEGER NOT NULL DEFAULT 0"); }

        // ==========================
        // Clinic staff (CHIEF invites VET/SECRETARY)
        // ==========================
        $db->exec("CREATE TABLE IF NOT EXISTS staff_invitations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clinic_id INTEGER NOT NULL,
            email TEXT NOT NULL,
            role TEXT NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL,
            used_at TEXT,
            revoked_at TEXT,
            created_by_user_id INTEGER,
            created_at TEXT NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_staff_inv_clinic_used ON staff_invitations(clinic_id, used_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_staff_inv_email ON staff_invitations(email)");

        // staff_invitations upgrades:
        // - max_clinics: quota for secretary multi-clinic (settable by PLATFORM only)
        // - created_by_platform_user_id: identify platform-generated invitations
        // - used_by_user_id: link invite -> created user (for tracking in PLATFORM)
        try {
            $siCols = $db->query("PRAGMA table_info(staff_invitations)")->fetchAll(PDO::FETCH_ASSOC);
            $siNames = [];
            foreach ($siCols as $sc) { $siNames[strtolower((string)$sc['name'])] = true; }
            if (empty($siNames['max_clinics'])) {
                $db->exec("ALTER TABLE staff_invitations ADD COLUMN max_clinics INTEGER NOT NULL DEFAULT 1");
            }
            if (empty($siNames['created_by_platform_user_id'])) {
                $db->exec("ALTER TABLE staff_invitations ADD COLUMN created_by_platform_user_id INTEGER");
            }
            if (empty($siNames['used_by_user_id'])) {
                $db->exec("ALTER TABLE staff_invitations ADD COLUMN used_by_user_id INTEGER");
            }
        } catch (Throwable $t) {
            // ignore
        }

        // Backfill used_by_user_id for already-used invitations (best-effort).
        // NOTE: uses email match as approximation (safe enough because emails are unique in users).
        try {
            $db->exec("UPDATE staff_invitations
                      SET used_by_user_id = (
                        SELECT u.id FROM users u
                         WHERE lower(u.email)=lower(staff_invitations.email)
                         ORDER BY u.id DESC LIMIT 1
                      )
                      WHERE used_at IS NOT NULL AND used_by_user_id IS NULL");
        } catch (Throwable $t) {
            // ignore
        }

        // Quota increase requests (CHIEF -> PLATFORM/ADMIN)
        $db->exec("CREATE TABLE IF NOT EXISTS quota_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clinic_id INTEGER NOT NULL,
            requested_max_vets INTEGER NOT NULL,
            requested_max_secretaries INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'NEW',
            created_by_user_id INTEGER,
            created_at TEXT NOT NULL,
            decided_by_platform_user_id INTEGER,
            decided_at TEXT,
            admin_note TEXT,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (decided_by_platform_user_id) REFERENCES platform_users(id) ON DELETE SET NULL
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_quota_req_status ON quota_requests(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_quota_req_clinic ON quota_requests(clinic_id)");

        $uCols = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
        $uNames = [];
        foreach ($uCols as $c) { $uNames[strtolower((string)$c['name'])] = true; }
        if (empty($uNames['is_active'])) { $db->exec("ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1"); }

        // Password recovery / identity verification
        if (empty($uNames['secret_question'])) { $db->exec("ALTER TABLE users ADD COLUMN secret_question TEXT"); }
        if (empty($uNames['secret_answer_hash'])) { $db->exec("ALTER TABLE users ADD COLUMN secret_answer_hash TEXT"); }

        // Secretary multi-clinic: global quota (set by PLATFORM/ADMIN)
        if (empty($uNames['max_clinics'])) { $db->exec("ALTER TABLE users ADD COLUMN max_clinics INTEGER NOT NULL DEFAULT 1"); }

        // Secretary validation (PLATFORM/ADMIN)
        // validated_at is NULL => profile not yet validated by PLATFORM.
        // We keep existing accounts compatible by NOT forcing a default that could block legacy users.
        if (empty($uNames['validated_at'])) { $db->exec("ALTER TABLE users ADD COLUMN validated_at TEXT"); }
        if (empty($uNames['validated_by_platform_user_id'])) { $db->exec("ALTER TABLE users ADD COLUMN validated_by_platform_user_id INTEGER"); }

        // Backfill for legacy installs: active secretaries are considered already validated.
        try {
            $db->exec("UPDATE users
                      SET validated_at = COALESCE(validated_at, created_at)
                      WHERE validated_at IS NULL AND is_active=1 AND UPPER(role) IN ('SECRETARY','STAFF')");
        } catch (Throwable $t) {
            // ignore
        }

        // Platform superuser table
        $db->exec("CREATE TABLE IF NOT EXISTS platform_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL,
            twofa_enabled INTEGER NOT NULL DEFAULT 1,
            secret_question TEXT,
            secret_answer_hash TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");

        // Ensure new platform_users columns exist (idempotent for upgrades)
        try {
            $pCols = $db->query("PRAGMA table_info(platform_users)")->fetchAll(PDO::FETCH_ASSOC);
            $pNames = [];
            foreach ($pCols as $pc) { $pNames[strtolower((string)$pc['name'])] = true; }
            if (empty($pNames['twofa_enabled'])) { $db->exec("ALTER TABLE platform_users ADD COLUMN twofa_enabled INTEGER NOT NULL DEFAULT 1"); }
            if (empty($pNames['secret_question'])) { $db->exec("ALTER TABLE platform_users ADD COLUMN secret_question TEXT"); }
            if (empty($pNames['secret_answer_hash'])) { $db->exec("ALTER TABLE platform_users ADD COLUMN secret_answer_hash TEXT"); }
        } catch (Throwable $t) {
            // ignore
        }

        // 2FA code card (matrix)
        $db->exec("CREATE TABLE IF NOT EXISTS platform_2fa_cards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            platform_user_id INTEGER NOT NULL,
            code_index INTEGER NOT NULL,
            code_enc TEXT NOT NULL,
            nonce TEXT,
            tag TEXT,
            created_at TEXT NOT NULL,
            UNIQUE(platform_user_id, code_index),
            FOREIGN KEY(platform_user_id) REFERENCES platform_users(id) ON DELETE CASCADE
        )");

        // Cookie consents + profiling events (landing)
        $db->exec("CREATE TABLE IF NOT EXISTS cookie_consents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            consent_id TEXT NOT NULL UNIQUE,
            necessary INTEGER NOT NULL DEFAULT 1,
            profiling INTEGER NOT NULL DEFAULT 0,
            ip TEXT,
            user_agent TEXT,
            referrer TEXT,
            first_seen TEXT NOT NULL,
            last_seen TEXT NOT NULL,
            last_path TEXT
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS cookie_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            consent_id TEXT NOT NULL,
            event_type TEXT NOT NULL,
            page_path TEXT,
            x_percent REAL,
            y_percent REAL,
            viewport_w INTEGER,
            viewport_h INTEGER,
            doc_h INTEGER,
            scroll_y INTEGER,
            meta_json TEXT,
            created_at TEXT NOT NULL
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cookie_events_consent ON cookie_events(consent_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cookie_events_path ON cookie_events(page_path)");

        // Admin "master" dataset (owners + pets) to preserve patients even if a clinic is deleted.
        $db->exec("CREATE TABLE IF NOT EXISTS admin_owners (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_clinic_id INTEGER,
            source_owner_id INTEGER,
            name TEXT,
            surname TEXT,
            birth_date TEXT,
            fiscal_code TEXT,
            email TEXT,
            phone TEXT,
            address_street TEXT,
            address_number TEXT,
            address_city TEXT,
            address_province TEXT,
            address_state TEXT,
            address_zip TEXT,
            billing_is_different INTEGER,
            billing_street TEXT,
            billing_number TEXT,
            billing_city TEXT,
            billing_province TEXT,
            billing_state TEXT,
            billing_zip TEXT,
            created_at TEXT,
            updated_at TEXT,
            synced_at TEXT NOT NULL,
            source_deleted INTEGER NOT NULL DEFAULT 0,
            source_deleted_at TEXT,
            UNIQUE(source_clinic_id, source_owner_id)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS admin_pets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_clinic_id INTEGER,
            source_pet_id INTEGER,
            source_owner_id INTEGER,
            name TEXT,
            species TEXT,
            breed TEXT,
            birth_date TEXT,
            sex TEXT,
            neuter_status TEXT,
            weight_kg REAL,
            microchip TEXT,
            notes TEXT,
            created_at TEXT,
            updated_at TEXT,
            synced_at TEXT NOT NULL,
            source_deleted INTEGER NOT NULL DEFAULT 0,
            source_deleted_at TEXT,
            UNIQUE(source_clinic_id, source_pet_id)
        )");

        // Keep admin_owners/admin_pets in sync (non-destructive) so deletions do not erase the master dataset.
        $db->exec("CREATE TRIGGER IF NOT EXISTS trg_admin_owners_ai AFTER INSERT ON owners BEGIN
            INSERT INTO admin_owners (
                source_clinic_id, source_owner_id,
                name, surname, birth_date, fiscal_code, email, phone,
                address_street, address_number, address_city, address_province, address_state, address_zip,
                billing_is_different, billing_street, billing_number, billing_city, billing_province, billing_state, billing_zip,
                created_at, updated_at, synced_at, source_deleted, source_deleted_at
            ) VALUES (
                NEW.clinic_id, NEW.id,
                NEW.name, NEW.surname, NEW.birth_date, NEW.fiscal_code, NEW.email, NEW.phone,
                NEW.address_street, NEW.address_number, NEW.address_city, NEW.address_province, NEW.address_state, NEW.address_zip,
                NEW.billing_is_different, NEW.billing_street, NEW.billing_number, NEW.billing_city, NEW.billing_province, NEW.billing_state, NEW.billing_zip,
                NEW.created_at, NEW.updated_at, datetime('now'), 0, NULL
            ) ON CONFLICT(source_clinic_id, source_owner_id) DO UPDATE SET
                name=excluded.name,
                surname=excluded.surname,
                birth_date=excluded.birth_date,
                fiscal_code=excluded.fiscal_code,
                email=excluded.email,
                phone=excluded.phone,
                address_street=excluded.address_street,
                address_number=excluded.address_number,
                address_city=excluded.address_city,
                address_province=excluded.address_province,
                address_state=excluded.address_state,
                address_zip=excluded.address_zip,
                billing_is_different=excluded.billing_is_different,
                billing_street=excluded.billing_street,
                billing_number=excluded.billing_number,
                billing_city=excluded.billing_city,
                billing_province=excluded.billing_province,
                billing_state=excluded.billing_state,
                billing_zip=excluded.billing_zip,
                created_at=excluded.created_at,
                updated_at=excluded.updated_at,
                synced_at=excluded.synced_at,
                source_deleted=0,
                source_deleted_at=NULL;
        END;");
        $db->exec("CREATE TRIGGER IF NOT EXISTS trg_admin_owners_au AFTER UPDATE ON owners BEGIN
            INSERT INTO admin_owners (
                source_clinic_id, source_owner_id,
                name, surname, birth_date, fiscal_code, email, phone,
                address_street, address_number, address_city, address_province, address_state, address_zip,
                billing_is_different, billing_street, billing_number, billing_city, billing_province, billing_state, billing_zip,
                created_at, updated_at, synced_at, source_deleted, source_deleted_at
            ) VALUES (
                NEW.clinic_id, NEW.id,
                NEW.name, NEW.surname, NEW.birth_date, NEW.fiscal_code, NEW.email, NEW.phone,
                NEW.address_street, NEW.address_number, NEW.address_city, NEW.address_province, NEW.address_state, NEW.address_zip,
                NEW.billing_is_different, NEW.billing_street, NEW.billing_number, NEW.billing_city, NEW.billing_province, NEW.billing_state, NEW.billing_zip,
                NEW.created_at, NEW.updated_at, datetime('now'), 0, NULL
            ) ON CONFLICT(source_clinic_id, source_owner_id) DO UPDATE SET
                name=excluded.name,
                surname=excluded.surname,
                birth_date=excluded.birth_date,
                fiscal_code=excluded.fiscal_code,
                email=excluded.email,
                phone=excluded.phone,
                address_street=excluded.address_street,
                address_number=excluded.address_number,
                address_city=excluded.address_city,
                address_province=excluded.address_province,
                address_state=excluded.address_state,
                address_zip=excluded.address_zip,
                billing_is_different=excluded.billing_is_different,
                billing_street=excluded.billing_street,
                billing_number=excluded.billing_number,
                billing_city=excluded.billing_city,
                billing_province=excluded.billing_province,
                billing_state=excluded.billing_state,
                billing_zip=excluded.billing_zip,
                created_at=excluded.created_at,
                updated_at=excluded.updated_at,
                synced_at=excluded.synced_at,
                source_deleted=0,
                source_deleted_at=NULL;
        END;");
        $db->exec("CREATE TRIGGER IF NOT EXISTS trg_admin_owners_bd BEFORE DELETE ON owners BEGIN
            UPDATE admin_owners
               SET source_deleted=1, source_deleted_at=datetime('now'), synced_at=datetime('now')
             WHERE source_clinic_id=OLD.clinic_id AND source_owner_id=OLD.id;
        END;");

        $db->exec("CREATE TRIGGER IF NOT EXISTS trg_admin_pets_ai AFTER INSERT ON pets BEGIN
            INSERT INTO admin_pets (
                source_clinic_id, source_pet_id, source_owner_id,
                name, species, breed, birth_date, sex, neuter_status, weight_kg, microchip, notes,
                created_at, updated_at, synced_at, source_deleted, source_deleted_at
            ) VALUES (
                NEW.clinic_id, NEW.id, NEW.owner_id,
                NEW.name, NEW.species, NEW.breed, NEW.birth_date, NEW.sex, NEW.neuter_status, NEW.weight_kg, NEW.microchip, NEW.notes,
                NEW.created_at, NEW.updated_at, datetime('now'), 0, NULL
            ) ON CONFLICT(source_clinic_id, source_pet_id) DO UPDATE SET
                source_owner_id=excluded.source_owner_id,
                name=excluded.name,
                species=excluded.species,
                breed=excluded.breed,
                birth_date=excluded.birth_date,
                sex=excluded.sex,
                neuter_status=excluded.neuter_status,
                weight_kg=excluded.weight_kg,
                microchip=excluded.microchip,
                notes=excluded.notes,
                created_at=excluded.created_at,
                updated_at=excluded.updated_at,
                synced_at=excluded.synced_at,
                source_deleted=0,
                source_deleted_at=NULL;
        END;");
        $db->exec("CREATE TRIGGER IF NOT EXISTS trg_admin_pets_au AFTER UPDATE ON pets BEGIN
            INSERT INTO admin_pets (
                source_clinic_id, source_pet_id, source_owner_id,
                name, species, breed, birth_date, sex, neuter_status, weight_kg, microchip, notes,
                created_at, updated_at, synced_at, source_deleted, source_deleted_at
            ) VALUES (
                NEW.clinic_id, NEW.id, NEW.owner_id,
                NEW.name, NEW.species, NEW.breed, NEW.birth_date, NEW.sex, NEW.neuter_status, NEW.weight_kg, NEW.microchip, NEW.notes,
                NEW.created_at, NEW.updated_at, datetime('now'), 0, NULL
            ) ON CONFLICT(source_clinic_id, source_pet_id) DO UPDATE SET
                source_owner_id=excluded.source_owner_id,
                name=excluded.name,
                species=excluded.species,
                breed=excluded.breed,
                birth_date=excluded.birth_date,
                sex=excluded.sex,
                neuter_status=excluded.neuter_status,
                weight_kg=excluded.weight_kg,
                microchip=excluded.microchip,
                notes=excluded.notes,
                created_at=excluded.created_at,
                updated_at=excluded.updated_at,
                synced_at=excluded.synced_at,
                source_deleted=0,
                source_deleted_at=NULL;
        END;");
        $db->exec("CREATE TRIGGER IF NOT EXISTS trg_admin_pets_bd BEFORE DELETE ON pets BEGIN
            UPDATE admin_pets
               SET source_deleted=1, source_deleted_at=datetime('now'), synced_at=datetime('now')
             WHERE source_clinic_id=OLD.clinic_id AND source_pet_id=OLD.id;
        END;");

        // Invitations
        $db->exec("CREATE TABLE IF NOT EXISTS invitations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            token TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL,
            used_at TEXT,
            created_at TEXT NOT NULL
        )");

        // Invitation-scoped limits (copied into clinics on registration).
        // NOTE: kept here (not only in create_schema) so upgrades don't require reinstall.
        try {
            $iCols = $db->query("PRAGMA table_info(invitations)")->fetchAll(PDO::FETCH_ASSOC);
            $iNames = [];
            foreach ($iCols as $ic) { $iNames[strtolower((string)$ic['name'])] = true; }
            if (empty($iNames['max_vets'])) { $db->exec("ALTER TABLE invitations ADD COLUMN max_vets INTEGER NOT NULL DEFAULT 1"); }
            if (empty($iNames['max_secretaries'])) { $db->exec("ALTER TABLE invitations ADD COLUMN max_secretaries INTEGER NOT NULL DEFAULT 0"); }
        } catch (Throwable $t) {
            // ignore
        }

        // Clinic applications (onboarding)
        $db->exec("CREATE TABLE IF NOT EXISTS clinic_applications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invitation_id INTEGER,
            email TEXT NOT NULL,
            clinic_id INTEGER,
            user_id INTEGER,
            status TEXT NOT NULL,
            data_json TEXT NOT NULL,
            created_at TEXT NOT NULL,
            reviewed_at TEXT,
            review_notes TEXT,
            FOREIGN KEY(invitation_id) REFERENCES invitations(id),
            FOREIGN KEY(clinic_id) REFERENCES clinics(id),
            FOREIGN KEY(user_id) REFERENCES users(id)
        )");

        // Active sessions
        $db->exec("CREATE TABLE IF NOT EXISTS active_sessions (
            session_id TEXT PRIMARY KEY,
            type TEXT NOT NULL,
            user_id INTEGER NOT NULL,
            clinic_id INTEGER,
            ip TEXT,
            user_agent TEXT,
            created_at TEXT NOT NULL,
            last_seen TEXT NOT NULL
        )");

        // Multi-staff ready pivot
        $db->exec("CREATE TABLE IF NOT EXISTS user_clinic (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            clinic_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE(user_id, clinic_id),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        )");

        // Backfill user_clinic for legacy installs (idempotent).
        // Keeps the existing single-clinic behaviour but makes multi-clinic switching possible.
        try {
            $db->exec("INSERT OR IGNORE INTO user_clinic (user_id, clinic_id, role, is_active, created_at, updated_at)
                      SELECT id, clinic_id, role, is_active, created_at, updated_at FROM users");
        } catch (Throwable $t) {
            // ignore
        }

        // Secretary multi-clinic: membership tokens (QR/link) activated by CHIEF via OTP.
        $db->exec("CREATE TABLE IF NOT EXISTS secretary_membership_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            secretary_user_id INTEGER NOT NULL,
            chief_email TEXT,
            token_hash TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL,
            used_at TEXT,
            used_by_user_id INTEGER,
            used_clinic_id INTEGER,
            otp_code_hash TEXT,
            otp_expires_at TEXT,
            otp_sent_at TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY(secretary_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(used_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY(used_clinic_id) REFERENCES clinics(id) ON DELETE SET NULL
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_sec_memtok_user ON secretary_membership_tokens(secretary_user_id, used_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_sec_memtok_exp ON secretary_membership_tokens(expires_at)");

        // New (v9): bind token to a specific CHIEF email (so the link/QR is unique per chief).
        try {
            $tcols = $db->query("PRAGMA table_info(secretary_membership_tokens)")->fetchAll(PDO::FETCH_ASSOC);
            $tn = [];
            foreach ($tcols as $c) { $tn[strtolower((string)$c['name'])] = true; }
            if (empty($tn['chief_email'])) {
                $db->exec("ALTER TABLE secretary_membership_tokens ADD COLUMN chief_email TEXT");
            }
        } catch (Throwable $t) {
            // ignore
        }

        // (no other runtime migrations needed)

        // Role rename (clinic scope): ADMIN -> CHIEF
        // We reserve ADMIN for the PLATFORM superuser.
        try {
            $db->exec("UPDATE users SET role='CHIEF' WHERE role='ADMIN'");
            $db->exec("UPDATE user_clinic SET role='CHIEF' WHERE role='ADMIN'");
        } catch (Throwable $t) {
            // ignore
        }

        // Normalization: every clinic should have at least one CHIEF.
        // If an older single-vet install has only VET/STAFF users, promote the oldest active user.
        try {
            $clinicIds = $db->query("SELECT id FROM clinics")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($clinicIds as $cid) {
                $cid = (int)$cid;
                $hasChief = (int)($db->query("SELECT COUNT(1) FROM users WHERE clinic_id=".$cid." AND UPPER(role)='CHIEF'")->fetchColumn() ?: 0);
                if ($hasChief > 0) continue;
                $uid = (int)($db->query("SELECT id FROM users WHERE clinic_id=".$cid." ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
                if ($uid > 0) {
                    $db->exec("UPDATE users SET role='CHIEF' WHERE id=".$uid);
                }
            }
        } catch (Throwable $t) {
            // ignore
        }

        if ($currentVer < $targetVer) {
            $stmtUp = $db->prepare("INSERT INTO app_meta(key,value) VALUES('schema_version',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
            $stmtUp->execute([(string)$targetVer]);
        }
    } catch (Exception $e) {
        // ignore
    }
}

/**
 * Crea l'intero schema del database (se non esiste).
 * Questa funzione viene usata da install.php.
 */
function vetroom_create_schema(): void {
    $db = vetroom_db();

    // Tabella cliniche (una sola per questa installazione)
    $db->exec("
        CREATE TABLE IF NOT EXISTS clinics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            status TEXT NOT NULL DEFAULT 'ACTIVE',
            -- Hard limits set by PLATFORM/ADMIN (multi-vet, secretaries)
            max_vets INTEGER NOT NULL DEFAULT 1,
            max_secretaries INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
    ");

    // Impostazioni intestazione veterinario
    $db->exec("
        CREATE TABLE IF NOT EXISTS vet_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clinic_id INTEGER NOT NULL,
            header_name TEXT NOT NULL,
            header_title TEXT,
            header_piva TEXT,
            header_albo TEXT,
            header_phone TEXT,
            header_email TEXT,
            header_address TEXT,
            logo_path TEXT,
            pdf_settings TEXT,
            visit_template_clinica_enabled INTEGER NOT NULL DEFAULT 1,
            visit_template_clinica_bg TEXT,
            visit_template_clinica_map TEXT,
            visit_template_clinica_overlay_header INTEGER NOT NULL DEFAULT 1,
            visit_template_oftalmo_enabled INTEGER NOT NULL DEFAULT 1,
            visit_template_oftalmo_bg TEXT,
            visit_template_oftalmo_map TEXT,
            visit_template_oftalmo_overlay_header INTEGER NOT NULL DEFAULT 1,
            stamp_path TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        );
    ");

    // Utenti (login studio)
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clinic_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            secret_question TEXT,
            secret_answer_hash TEXT,
            role TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            -- Secretary multi-clinic: global quota (set by PLATFORM/ADMIN)
            max_clinics INTEGER NOT NULL DEFAULT 1,
            -- Secretary validation (done by PLATFORM/ADMIN)
            validated_at TEXT,
            validated_by_platform_user_id INTEGER,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        );
    ");

    // Inviti staff (generati da CHIEF). Il token viene salvato come hash (sha256) per sicurezza.
    $db->exec("
        CREATE TABLE IF NOT EXISTS staff_invitations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clinic_id INTEGER NOT NULL,
            email TEXT NOT NULL,
            role TEXT NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            -- Secretary multi-clinic: quota (settable by PLATFORM only)
            max_clinics INTEGER NOT NULL DEFAULT 1,
            expires_at TEXT NOT NULL,
            used_at TEXT,
            used_by_user_id INTEGER,
            revoked_at TEXT,
            created_by_user_id INTEGER,
            created_by_platform_user_id INTEGER,
            created_at TEXT NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        );
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_staff_inv_clinic_used ON staff_invitations(clinic_id, used_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_staff_inv_email ON staff_invitations(email)");

    // Richieste aumento quote (CHIEF -> PLATFORM/ADMIN)
    $db->exec("
        CREATE TABLE IF NOT EXISTS quota_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clinic_id INTEGER NOT NULL,
            requested_max_vets INTEGER NOT NULL,
            requested_max_secretaries INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'NEW',
            created_by_user_id INTEGER,
            created_at TEXT NOT NULL,
            decided_by_platform_user_id INTEGER,
            decided_at TEXT,
            admin_note TEXT,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (decided_by_platform_user_id) REFERENCES platform_users(id) ON DELETE SET NULL
        );
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_quota_req_status ON quota_requests(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_quota_req_clinic ON quota_requests(clinic_id)");

    // Owner accounts (portale clienti)
    // Nota: account unico per Codice Fiscale (vincolo UNIQUE).
    $db->exec("
        CREATE TABLE IF NOT EXISTS owner_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fiscal_code TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            email TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
    ");

    // Proprietari
    $db->exec("
        CREATE TABLE IF NOT EXISTS owners (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clinic_id INTEGER NOT NULL,
            owner_account_id INTEGER,
            name TEXT NOT NULL,
            surname TEXT NOT NULL,
            birth_date TEXT,
            fiscal_code TEXT,
            email TEXT,
            phone TEXT,
            address_street TEXT,
            address_number TEXT,
            address_city TEXT,
            address_province TEXT,
            address_state TEXT,
            address_zip TEXT,
            billing_is_different INTEGER NOT NULL DEFAULT 0,
            billing_street TEXT,
            billing_number TEXT,
            billing_city TEXT,
            billing_province TEXT,
            billing_state TEXT,
            billing_zip TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        );
    ");

    // Relazione VET (clinic) ↔ OWNER (consenso)
    $db->exec("
        CREATE TABLE IF NOT EXISTS vet_owner_relations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clinic_id INTEGER NOT NULL,
            owner_id INTEGER NOT NULL,
            owner_account_id INTEGER,
            status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE(clinic_id, owner_id),
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE CASCADE,
            FOREIGN KEY (owner_account_id) REFERENCES owner_accounts(id) ON DELETE SET NULL
        );
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_vor_owner_account_status ON vet_owner_relations(owner_account_id, status);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_vor_clinic_status ON vet_owner_relations(clinic_id, status);");

    // Animali
    $db->exec("
        CREATE TABLE IF NOT EXISTS pets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clinic_id INTEGER NOT NULL,
            owner_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            species TEXT NOT NULL,
            breed TEXT,
            birth_date TEXT,
            sex TEXT,
            neuter_status TEXT,
            weight_kg REAL,
            microchip TEXT,
            notes TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE CASCADE
        );
    ");

    // Appuntamenti
    $db->exec("
        CREATE TABLE IF NOT EXISTS appointments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clinic_id INTEGER NOT NULL,
            pet_id INTEGER NOT NULL,
            owner_id INTEGER NOT NULL,
            date TEXT NOT NULL,
            time TEXT NOT NULL,
            type TEXT,
            status TEXT NOT NULL DEFAULT 'CONFIRMED',
            notes_internal TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (pet_id) REFERENCES pets(id) ON DELETE CASCADE,
            FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE CASCADE
        );
    ");

    // Disponibilità agenda (giorni / orari)
    $db->exec("
        CREATE TABLE IF NOT EXISTS availabilities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clinic_id INTEGER NOT NULL,
            weekday INTEGER NOT NULL,
            start_time TEXT NOT NULL,
            end_time TEXT NOT NULL,
            start_time2 TEXT,
            end_time2 TEXT,
            slot_minutes INTEGER NOT NULL DEFAULT 30,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        );
    ");

    // Visite (cliniche + oculistiche)
    $db->exec("
        CREATE TABLE IF NOT EXISTS visits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clinic_id INTEGER NOT NULL,
            pet_id INTEGER NOT NULL,
            owner_id INTEGER NOT NULL,
            visit_date TEXT NOT NULL,
            visit_kind TEXT NOT NULL, -- CLINICA / OFTALMO
            visit_form_id INTEGER, -- for CUSTOM visits
            title TEXT, -- Titolo visita (mostrato in UI e usato nei PDF)
            diagnosis TEXT,
            therapy TEXT,
            notes TEXT,
            form_data TEXT, -- JSON con tutti i campi del form
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (pet_id) REFERENCES pets(id) ON DELETE CASCADE,
            FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE CASCADE
        );
    ");

    // Documenti (cartella clinica)
    $db->exec("
        CREATE TABLE IF NOT EXISTS documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clinic_id INTEGER NOT NULL,
            pet_id INTEGER NOT NULL,
            owner_id INTEGER NOT NULL,
            visit_id INTEGER,
            filename TEXT NOT NULL,
            original_name TEXT NOT NULL,
            mime_type TEXT,
            doc_role TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
            FOREIGN KEY (pet_id) REFERENCES pets(id) ON DELETE CASCADE,
            FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE CASCADE,
            FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE SET NULL
        );
    ");

    // Custom visit forms definitions (per-clinic)
    $db->exec("
        CREATE TABLE IF NOT EXISTS visit_forms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            clinic_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            slug TEXT NOT NULL,
            definition_json TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
        );
    ");

    // Visit template configs (per-clinic, per-sheet)
    // - built-in:  clinica, oftalmo
    // - custom:    form:<ID>
    $db->exec("
        CREATE TABLE IF NOT EXISTS visit_templates (
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
        );
    ");


    // --- MIGRATIONS (safe, idempotent) ---
    // Ensure vet_settings has logo_path column
    try {
        $cols = $db->query("PRAGMA table_info(vet_settings)")->fetchAll(PDO::FETCH_ASSOC);
        $hasLogo = false;
        $hasPdfSettings = false;
        foreach ($cols as $c) {
            if (strcasecmp($c['name'], 'logo_path') === 0) { $hasLogo = true; break; }
        }
        if (!$hasLogo) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN logo_path TEXT");
        }

        // Ensure vet_settings has pdf_settings column (JSON string with PDF engine customization)
        foreach ($cols as $c) {
            if (strcasecmp($c['name'], 'pdf_settings') === 0) { $hasPdfSettings = true; break; }
        }
        if (!$hasPdfSettings) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN pdf_settings TEXT");
        }

        // Ensure vet_settings has visit template columns
        $cols2 = $db->query("PRAGMA table_info(vet_settings)")->fetchAll(PDO::FETCH_ASSOC);
        $names2 = [];
        foreach ($cols2 as $c2) { $names2[strtolower((string)$c2['name'])] = true; }
        if (empty($names2['visit_template_clinica_enabled'])) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN visit_template_clinica_enabled INTEGER NOT NULL DEFAULT 1");
        }
        if (empty($names2['visit_template_clinica_bg'])) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN visit_template_clinica_bg TEXT");
        }
        if (empty($names2['visit_template_clinica_map'])) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN visit_template_clinica_map TEXT");
        }
        if (empty($names2['visit_template_clinica_overlay_header'])) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN visit_template_clinica_overlay_header INTEGER NOT NULL DEFAULT 1");
        }
        if (empty($names2['visit_template_oftalmo_enabled'])) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN visit_template_oftalmo_enabled INTEGER NOT NULL DEFAULT 1");
        }
        if (empty($names2['visit_template_oftalmo_bg'])) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN visit_template_oftalmo_bg TEXT");
        }
        if (empty($names2['visit_template_oftalmo_map'])) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN visit_template_oftalmo_map TEXT");
        }
        if (empty($names2['visit_template_oftalmo_overlay_header'])) {
            $db->exec("ALTER TABLE vet_settings ADD COLUMN visit_template_oftalmo_overlay_header INTEGER NOT NULL DEFAULT 1");
        }
    } catch (Exception $e) {
        // ignore
    }

    // Ensure visits table has "title" column (added for CUSTOM visits and better PDF naming)
    try {
        $vcols = $db->query("PRAGMA table_info(visits)")->fetchAll(PDO::FETCH_ASSOC);
        $hasTitle = false;
        foreach ($vcols as $vc) {
            if (strcasecmp((string)$vc['name'], 'title') === 0) { $hasTitle = true; break; }
        }
        if (!$hasTitle) {
            $db->exec("ALTER TABLE visits ADD COLUMN title TEXT");
        }
    } catch (Exception $e) {
        // ignore
    }

    // Ensure documents table exists (already created above) - left for future migrations

    // Platform tables (Superuser + onboarding)
    $db->exec("CREATE TABLE IF NOT EXISTS platform_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL,
        twofa_enabled INTEGER NOT NULL DEFAULT 1,
        secret_question TEXT,
        secret_answer_hash TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS platform_2fa_cards (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        platform_user_id INTEGER NOT NULL,
        code_index INTEGER NOT NULL,
        code_enc TEXT NOT NULL,
        nonce TEXT,
        tag TEXT,
        created_at TEXT NOT NULL,
        UNIQUE(platform_user_id, code_index),
        FOREIGN KEY(platform_user_id) REFERENCES platform_users(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS cookie_consents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        consent_id TEXT NOT NULL UNIQUE,
        necessary INTEGER NOT NULL DEFAULT 1,
        profiling INTEGER NOT NULL DEFAULT 0,
        ip TEXT,
        user_agent TEXT,
        referrer TEXT,
        first_seen TEXT NOT NULL,
        last_seen TEXT NOT NULL,
        last_path TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS cookie_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        consent_id TEXT NOT NULL,
        event_type TEXT NOT NULL,
        page_path TEXT,
        x_percent REAL,
        y_percent REAL,
        viewport_w INTEGER,
        viewport_h INTEGER,
        doc_h INTEGER,
        scroll_y INTEGER,
        meta_json TEXT,
        created_at TEXT NOT NULL
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cookie_events_consent ON cookie_events(consent_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cookie_events_path ON cookie_events(page_path)");

    $db->exec("CREATE TABLE IF NOT EXISTS admin_owners (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_clinic_id INTEGER,
        source_owner_id INTEGER,
        name TEXT,
        surname TEXT,
        birth_date TEXT,
        fiscal_code TEXT,
        email TEXT,
        phone TEXT,
        address_street TEXT,
        address_number TEXT,
        address_city TEXT,
        address_province TEXT,
        address_state TEXT,
        address_zip TEXT,
        billing_is_different INTEGER,
        billing_street TEXT,
        billing_number TEXT,
        billing_city TEXT,
        billing_province TEXT,
        billing_state TEXT,
        billing_zip TEXT,
        created_at TEXT,
        updated_at TEXT,
        synced_at TEXT NOT NULL,
        source_deleted INTEGER NOT NULL DEFAULT 0,
        source_deleted_at TEXT,
        UNIQUE(source_clinic_id, source_owner_id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS admin_pets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_clinic_id INTEGER,
        source_pet_id INTEGER,
        source_owner_id INTEGER,
        name TEXT,
        species TEXT,
        breed TEXT,
        birth_date TEXT,
        sex TEXT,
        neuter_status TEXT,
        weight_kg REAL,
        microchip TEXT,
        notes TEXT,
        created_at TEXT,
        updated_at TEXT,
        synced_at TEXT NOT NULL,
        source_deleted INTEGER NOT NULL DEFAULT 0,
        source_deleted_at TEXT,
        UNIQUE(source_clinic_id, source_pet_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS invitations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        token TEXT NOT NULL UNIQUE,
        max_vets INTEGER NOT NULL DEFAULT 1,
        max_secretaries INTEGER NOT NULL DEFAULT 0,
        expires_at TEXT NOT NULL,
        used_at TEXT,
        created_at TEXT NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS clinic_applications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invitation_id INTEGER,
        email TEXT NOT NULL,
        clinic_id INTEGER,
        user_id INTEGER,
        status TEXT NOT NULL,
        data_json TEXT NOT NULL,
        created_at TEXT NOT NULL,
        reviewed_at TEXT,
        review_notes TEXT,
        FOREIGN KEY(invitation_id) REFERENCES invitations(id),
        FOREIGN KEY(clinic_id) REFERENCES clinics(id),
        FOREIGN KEY(user_id) REFERENCES users(id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS active_sessions (
        session_id TEXT PRIMARY KEY,
        type TEXT NOT NULL,
        user_id INTEGER NOT NULL,
        clinic_id INTEGER,
        ip TEXT,
        user_agent TEXT,
        created_at TEXT NOT NULL,
        last_seen TEXT NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS user_clinic (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        clinic_id INTEGER NOT NULL,
        role TEXT NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(user_id, clinic_id),
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
    )");

    // Secretary multi-clinic: membership tokens (QR/link) activated by CHIEF via OTP.
    $db->exec("CREATE TABLE IF NOT EXISTS secretary_membership_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        secretary_user_id INTEGER NOT NULL,
        chief_email TEXT,
        token_hash TEXT NOT NULL UNIQUE,
        expires_at TEXT NOT NULL,
        used_at TEXT,
        used_by_user_id INTEGER,
        used_clinic_id INTEGER,
        otp_code_hash TEXT,
        otp_expires_at TEXT,
        otp_sent_at TEXT,
        created_at TEXT NOT NULL,
        FOREIGN KEY(secretary_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(used_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY(used_clinic_id) REFERENCES clinics(id) ON DELETE SET NULL
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sec_memtok_user ON secretary_membership_tokens(secretary_user_id, used_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sec_memtok_exp ON secretary_membership_tokens(expires_at)");

    $db->exec("CREATE TABLE IF NOT EXISTS app_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)");

    // System clinic placeholder (used for PLATFORM-created secretaries not yet affiliated to any clinic).
    // Hidden from normal lists by filtering status='SYSTEM'.
    try {
        $sid = (int)($db->query("SELECT id FROM clinics WHERE UPPER(status)='SYSTEM' LIMIT 1")->fetchColumn() ?: 0);
        if ($sid <= 0) {
            $now = date('c');
            $stmt = $db->prepare("INSERT INTO clinics (name,is_active,status,max_vets,max_secretaries,created_at,updated_at) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute(['(SYSTEM) Segretarie non affiliate', 0, 'SYSTEM', 1, 0, $now, $now]);
        }
    } catch (Throwable $t) {
        // ignore
    }

}


/**
 * Ritorna true se l'applicazione è già installata (c'è almeno una clinica).
 */
function vetroom_is_installed(): bool {
    if (!file_exists(VETROOM_DB_PATH)) {
        return false;
    }
    try {
        $db = vetroom_db();
        // Platform install counts as installed
        $t = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='platform_users'");
        if ($t && $t->fetchColumn()) {
            $c = (int)$db->query("SELECT COUNT(*) FROM platform_users")->fetchColumn();
            if ($c > 0) return true;
        }
        $res = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='clinics'");
        if (!$res || $res->fetchColumn() === false) {
            return false;
        }
        $res2 = $db->query("SELECT COUNT(*) FROM clinics");
        $count = (int)$res2->fetchColumn();
        return $count > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Returns the internal SYSTEM clinic id (creates it if missing).
 * This clinic is used as a placeholder for staff accounts created by PLATFORM
 * before they are affiliated to a real clinic.
 */
function vetroom_system_clinic_id(PDO $db): int {
    try {
        // If clinics table is missing (not installed yet), bail.
        $t = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='clinics'");
        if (!$t || !$t->fetchColumn()) return 0;

        $sid = (int)($db->query("SELECT id FROM clinics WHERE UPPER(status)='SYSTEM' LIMIT 1")->fetchColumn() ?: 0);
        if ($sid > 0) return $sid;

        $now = date('c');
        $stmt = $db->prepare("INSERT INTO clinics (name,is_active,status,max_vets,max_secretaries,created_at,updated_at) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute(['(SYSTEM) Segretarie non affiliate', 0, 'SYSTEM', 1, 0, $now, $now]);
        return (int)$db->lastInsertId();
    } catch (Throwable $t) {
        return 0;
    }
}
