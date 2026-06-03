<?php
declare(strict_types=1);

/**
 * Backup/Restore utilities for VetRoom.
 *
 * The app stores data in:
 * - SQLite DB: data/vetroom2.sqlite
 * - Public-ish files (templates, logos, backgrounds): data/uploads/
 * - Private files (visit PDFs, attachments, onboarding docs): data/private_uploads/
 */

/**
 * True if the server has ZipArchive enabled.
 */
function vr_backup_zip_available(): bool {
    return class_exists('ZipArchive');
}

/**
 * Create a temporary file path (not yet created) with a predictable extension.
 */
function vr_backup_temp_path(string $prefix, string $ext): string {
    $base = tempnam(sys_get_temp_dir(), $prefix);
    if ($base === false) {
        throw new Exception('Impossibile creare un file temporaneo.');
    }
    // tempnam creates the file. We want to rename it.
    $path = $base . $ext;
    @unlink($base);
    return $path;
}

/**
 * Add a directory recursively to a zip archive.
 */
function vr_backup_zip_add_dir(ZipArchive $zip, string $dirAbs, string $zipPrefix): void {
    if (!is_dir($dirAbs)) {
        return;
    }
    $dirAbs = rtrim($dirAbs, '/\\');
    $zipPrefix = trim($zipPrefix, '/');

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dirAbs, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $fileInfo) {
        /** @var SplFileInfo $fileInfo */
        $abs = $fileInfo->getPathname();
        if ($fileInfo->isDir()) {
            continue;
        }
        $rel = substr($abs, strlen($dirAbs) + 1);
        $rel = str_replace('\\', '/', $rel);
        $local = ($zipPrefix !== '' ? ($zipPrefix . '/') : '') . $rel;
        $zip->addFile($abs, $local);
    }
}

/**
 * Stream a file as download and delete it afterwards.
 */
function vr_backup_stream_download_and_delete(string $filePath, string $downloadName, string $contentType): void {
    if (!is_file($filePath)) {
        throw new Exception('File non trovato.');
    }
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    @unlink($filePath);
    exit;
}

/**
 * Export a full backup as ZIP.
 * Returns the path to the generated zip.
 */
function vr_backup_export_zip(string $dbPath, string $uploadsDirAbs, array $manifest, ?string $privateDirAbs = null): string {
    if (!vr_backup_zip_available()) {
        throw new Exception('ZipArchive non disponibile sul server.');
    }
    if (!is_file($dbPath)) {
        throw new Exception('Database non trovato.');
    }

    $zipPath = vr_backup_temp_path('vr_backup_', '.zip');
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Impossibile creare il file ZIP.');
    }

    $zip->addFromString('backup_manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $zip->addFile($dbPath, 'db/vetroom2.sqlite');
    vr_backup_zip_add_dir($zip, $uploadsDirAbs, 'uploads');
    if ($privateDirAbs !== null) {
        vr_backup_zip_add_dir($zip, $privateDirAbs, 'private_uploads');
    }

    $zip->close();
    return $zipPath;
}

/**
 * Capture current credentials before a restore so an imported backup cannot overwrite passwords.
 */
function vr_backup_capture_credentials_from_db(string $dbPath): array {
    $snap = ['users'=>[], 'platform_users'=>[]];
    if (!is_file($dbPath)) return $snap;
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $hasUsers = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
        if ($hasUsers) {
            $rows = $pdo->query("SELECT email, password_hash FROM users")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $email = (string)($r['email'] ?? '');
                $hash = (string)($r['password_hash'] ?? '');
                if ($email !== '' && $hash !== '') {
                    $snap['users'][$email] = $hash;
                }
            }
        }

        $hasPU = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='platform_users'")->fetchColumn();
        if ($hasPU) {
            $rows = $pdo->query("SELECT email, password_hash FROM platform_users")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $email = (string)($r['email'] ?? '');
                $hash = (string)($r['password_hash'] ?? '');
                if ($email !== '' && $hash !== '') {
                    $snap['platform_users'][$email] = $hash;
                }
            }
        }
    } catch (Throwable $t) {
        // ignore
    }
    return $snap;
}

/**
 * Restore captured credentials back into the restored database.
 */
function vr_backup_restore_credentials_to_db(string $dbPath, array $snap): void {
    if (!is_file($dbPath)) return;
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');

        if (!empty($snap['users']) && (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn()) {
            $st = $pdo->prepare("UPDATE users SET password_hash=? WHERE email=?");
            foreach ($snap['users'] as $email => $hash) {
                $st->execute([$hash, $email]);
            }
        }

        if (!empty($snap['platform_users']) && (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='platform_users'")->fetchColumn()) {
            $st = $pdo->prepare("UPDATE platform_users SET password_hash=? WHERE email=?");
            foreach ($snap['platform_users'] as $email => $hash) {
                $st->execute([$hash, $email]);
            }
        }
    } catch (Throwable $t) {
        // ignore
    }
}

/**
 * Export DB only (SQLite file copy). Returns the path to the copy.
 */
function vr_backup_export_db_copy(string $dbPath): string {
    if (!is_file($dbPath)) {
        throw new Exception('Database non trovato.');
    }
    $out = vr_backup_temp_path('vr_db_', '.sqlite');
    if (!@copy($dbPath, $out)) {
        throw new Exception('Impossibile copiare il database.');
    }
    return $out;
}

/**
 * Import a full backup from ZIP.
 * Overwrites the database file and uploads directory.
 * Returns the decoded manifest (if any).
 */
function vr_backup_import_zip(string $zipTmpPath, string $destDbPath, string $destUploadsDirAbs, ?string $destPrivateDirAbs = null, bool $keepCredentials = true): array {
    if (!vr_backup_zip_available()) {
        throw new Exception('ZipArchive non disponibile sul server.');
    }
    if (!is_file($zipTmpPath)) {
        throw new Exception('File ZIP non trovato.');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipTmpPath) !== true) {
        throw new Exception('Impossibile aprire il file ZIP.');
    }

    $manifest = [];
    $manRaw = $zip->getFromName('backup_manifest.json');
    if ($manRaw !== false) {
        $decoded = json_decode((string)$manRaw, true);
        if (is_array($decoded)) {
            $manifest = $decoded;
        }
    }

    // Optional: capture current credentials (security)
    $credSnap = $keepCredentials ? vr_backup_capture_credentials_from_db($destDbPath) : ['users'=>[], 'platform_users'=>[]];

    // DB
    $dbRaw = $zip->getFromName('db/vetroom2.sqlite');
    if ($dbRaw === false) {
        // backward compat (root file)
        $dbRaw = $zip->getFromName('vetroom2.sqlite');
    }
    if ($dbRaw === false) {
        $zip->close();
        throw new Exception('Backup non valido: manca il database.');
    }

    // Ensure directories
    $destDir = dirname($destDbPath);
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0775, true);
    }
    if (!is_dir($destUploadsDirAbs)) {
        @mkdir($destUploadsDirAbs, 0775, true);
    }
    if ($destPrivateDirAbs === null) {
        $destPrivateDirAbs = dirname($destDbPath) . '/private_uploads';
    }
    if (!is_dir($destPrivateDirAbs)) {
        @mkdir($destPrivateDirAbs, 0775, true);
    }

    // Replace DB atomically
    $tmpDb = $destDbPath . '.import_tmp_' . uniqid('', true);
    file_put_contents($tmpDb, $dbRaw);
    @chmod($tmpDb, 0664);
    if (!@rename($tmpDb, $destDbPath)) {
        // If rename fails (e.g. cross-device), fall back to copy
        if (!@copy($tmpDb, $destDbPath)) {
            @unlink($tmpDb);
            $zip->close();
            throw new Exception('Impossibile ripristinare il database.');
        }
        @unlink($tmpDb);
    }

    // Replace uploads: extract files under uploads/ prefix
    // We'll first collect entries to write; we don't call extractTo to avoid path traversal issues.
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat)) continue;
        $name = (string)($stat['name'] ?? '');
        if ($name === '' || substr($name, -1) === '/') continue; // directory
        if (strpos($name, 'uploads/') !== 0) continue;
        if (strpos($name, '..') !== false || strpos($name, ':') !== false || (isset($name[0]) && $name[0] === '/')) continue;
        $entries[] = $name;
    }

    // If the backup has uploads, we clear the current folder content to keep consistency.
    if (!empty($entries)) {
        // Remove files in uploads dir (non recursive? we do recursive)
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($destUploadsDirAbs, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $fi) {
            /** @var SplFileInfo $fi */
            if ($fi->isDir()) {
                @rmdir($fi->getPathname());
            } else {
                @unlink($fi->getPathname());
            }
        }
    }

    foreach ($entries as $name) {
        $raw = $zip->getFromName($name);
        if ($raw === false) continue;
        $rel = substr($name, strlen('uploads/'));
        $rel = str_replace('\\', '/', $rel);
        if ($rel === '' || strpos($rel, '..') !== false) continue;
        $dest = rtrim($destUploadsDirAbs, '/\\') . '/' . $rel;
        $dDir = dirname($dest);
        if (!is_dir($dDir)) {
            @mkdir($dDir, 0775, true);
        }
        file_put_contents($dest, $raw);
        @chmod($dest, 0664);
    }

    // Private uploads: extract files under private_uploads/ prefix (if present)
    $pEntries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat)) continue;
        $name = (string)($stat['name'] ?? '');
        if ($name === '' || substr($name, -1) === '/') continue;
        if (strpos($name, 'private_uploads/') !== 0) continue;
        if (strpos($name, '..') !== false || strpos($name, ':') !== false || (isset($name[0]) && $name[0] === '/')) continue;
        $pEntries[] = $name;
    }
    if (!empty($pEntries)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($destPrivateDirAbs, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $fi) {
            /** @var SplFileInfo $fi */
            if ($fi->isDir()) {
                @rmdir($fi->getPathname());
            } else {
                @unlink($fi->getPathname());
            }
        }
    }
    foreach ($pEntries as $name) {
        $raw = $zip->getFromName($name);
        if ($raw === false) continue;
        $rel = substr($name, strlen('private_uploads/'));
        $rel = str_replace('\\', '/', $rel);
        if ($rel === '' || strpos($rel, '..') !== false) continue;
        $dest = rtrim($destPrivateDirAbs, '/\\') . '/' . $rel;
        $dDir = dirname($dest);
        if (!is_dir($dDir)) {
            @mkdir($dDir, 0775, true);
        }
        file_put_contents($dest, $raw);
        @chmod($dest, 0664);
    }

    $zip->close();

    // Restore captured credentials (prevents old backups from changing current passwords)
    if ($keepCredentials && (!empty($credSnap['users']) || !empty($credSnap['platform_users']))) {
        vr_backup_restore_credentials_to_db($destDbPath, $credSnap);
    }

    return $manifest;
}

/**
 * Import a DB-only backup (SQLite file).
 */
function vr_backup_import_sqlite(string $sqliteTmpPath, string $destDbPath): void {
    if (!is_file($sqliteTmpPath)) {
        throw new Exception('File SQLite non trovato.');
    }
    $destDir = dirname($destDbPath);
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0775, true);
    }
    $tmpDb = $destDbPath . '.import_tmp_' . uniqid('', true);
    if (!@copy($sqliteTmpPath, $tmpDb)) {
        throw new Exception('Impossibile copiare il database importato.');
    }
    if (!@rename($tmpDb, $destDbPath)) {
        if (!@copy($tmpDb, $destDbPath)) {
            @unlink($tmpDb);
            throw new Exception('Impossibile ripristinare il database.');
        }
        @unlink($tmpDb);
    }
}
