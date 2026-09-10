<?php
/**
 * Admin database backup and restore helpers.
 */

function getDefaultDatabaseSnapshotDir(): string
{
    return dirname(__DIR__) . '/storage/database';
}

function getDefaultDatabaseSnapshotSqlPath(): string
{
    return getDefaultDatabaseSnapshotDir() . '/default_snapshot.sql';
}

function getDefaultDatabaseSnapshotMetaPath(): string
{
    return getDefaultDatabaseSnapshotDir() . '/default_snapshot.json';
}

function ensureDefaultDatabaseSnapshotDir(): void
{
    $dir = getDefaultDatabaseSnapshotDir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
}

function defaultDatabaseSnapshotExists(): bool
{
    $sqlPath = getDefaultDatabaseSnapshotSqlPath();
    return is_file($sqlPath) && filesize($sqlPath) > 0;
}

/** @return array<string,mixed>|null */
function getDefaultDatabaseSnapshotInfo(): ?array
{
    if (!defaultDatabaseSnapshotExists()) {
        return null;
    }

    $meta = null;
    $metaPath = getDefaultDatabaseSnapshotMetaPath();
    if (is_file($metaPath)) {
        $decoded = json_decode((string) file_get_contents($metaPath), true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }

    $sqlPath = getDefaultDatabaseSnapshotSqlPath();
    $fileSize = (int) filesize($sqlPath);
    $savedAt = $meta['saved_at'] ?? date('Y-m-d H:i:s', (int) filemtime($sqlPath));

    return array_merge([
        'database' => DB_NAME,
        'host' => DB_HOST,
        'saved_at' => $savedAt,
        'saved_by_user_id' => null,
        'saved_by_username' => null,
        'tables' => null,
        'total_rows' => null,
        'file_size' => $fileSize,
    ], $meta ?? []);
}

/**
 * Save the current database as the default restore snapshot.
 *
 * @return array{success:bool,message:string,info:?array<string,mixed>}
 */
function saveDefaultDatabaseSnapshot(PDO $db, ?int $userId = null, ?string $username = null): array
{
    ensureDefaultDatabaseSnapshotDir();

    $info = getDatabaseToolInfo();
    $sql = exportDatabaseSql($db);
    $sqlPath = getDefaultDatabaseSnapshotSqlPath();
    $metaPath = getDefaultDatabaseSnapshotMetaPath();

    $tmpPath = $sqlPath . '.tmp';
    if (file_put_contents($tmpPath, $sql) === false) {
        return [
            'success' => false,
            'message' => 'Could not write the default database snapshot file.',
            'info' => null,
        ];
    }

    if (!rename($tmpPath, $sqlPath)) {
        @unlink($tmpPath);
        return [
            'success' => false,
            'message' => 'Could not finalize the default database snapshot file.',
            'info' => null,
        ];
    }

    $meta = [
        'database' => DB_NAME,
        'host' => DB_HOST,
        'charset' => DB_CHARSET,
        'saved_at' => date('Y-m-d H:i:s'),
        'saved_by_user_id' => $userId,
        'saved_by_username' => $username,
        'tables' => $info['tables'],
        'total_rows' => $info['total_rows'],
        'file_size' => strlen($sql),
    ];

    file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return [
        'success' => true,
        'message' => 'Current database saved as the default restore point.',
        'info' => $meta,
    ];
}

/**
 * Restore the database from the saved default snapshot.
 *
 * @return array{success:bool,message:string,executed:int,errors:array<int,string>,info:?array<string,mixed>}
 */
function resetDatabaseToDefault(PDO $db): array
{
    if (!defaultDatabaseSnapshotExists()) {
        return [
            'success' => false,
            'message' => 'No default database snapshot is saved yet. Save the current database as default first.',
            'executed' => 0,
            'errors' => [],
            'info' => null,
        ];
    }

    $sql = file_get_contents(getDefaultDatabaseSnapshotSqlPath());
    if ($sql === false || trim($sql) === '') {
        return [
            'success' => false,
            'message' => 'Default database snapshot file is missing or empty.',
            'executed' => 0,
            'errors' => [],
            'info' => getDefaultDatabaseSnapshotInfo(),
        ];
    }

    $result = importDatabaseSql($db, $sql);
    $result['info'] = getDefaultDatabaseSnapshotInfo();

    if ($result['success']) {
        $result['message'] = 'Database reset to the saved default snapshot.';
    }

    return $result;
}

function getDatabaseToolInfo(): array
{
    $db = getDB();
    $tables = $db->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);

    $tableStats = [];
    $totalRows = 0;

    foreach ($tables as $row) {
        $table = $row[0];
        $countStmt = $db->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`');
        $rows = (int) $countStmt->fetchColumn();
        $totalRows += $rows;
        $tableStats[] = [
            'name' => $table,
            'rows' => $rows,
        ];
    }

    usort($tableStats, fn($a, $b) => strcmp($a['name'], $b['name']));

    return [
        'host' => DB_HOST,
        'name' => DB_NAME,
        'charset' => DB_CHARSET,
        'tables' => count($tableStats),
        'total_rows' => $totalRows,
        'table_stats' => $tableStats,
        'generated_at' => date('Y-m-d H:i:s'),
    ];
}

function exportDatabaseSql(PDO $db): string
{
    $info = getDatabaseToolInfo();
    $lines = [];
    $lines[] = '-- JHCSC Sports Development IMIS — Database Export';
    $lines[] = '-- Database: ' . DB_NAME;
    $lines[] = '-- Generated: ' . $info['generated_at'];
    $lines[] = '-- Tables: ' . $info['tables'];
    $lines[] = '';
    $lines[] = 'SET NAMES ' . DB_CHARSET . ';';
    $lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
    $lines[] = "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';";
    $lines[] = '';

    $tables = $db->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);

    foreach ($tables as $row) {
        $table = $row[0];
        $safeTable = str_replace('`', '``', $table);

        $create = $db->query('SHOW CREATE TABLE `' . $safeTable . '`')->fetch(PDO::FETCH_ASSOC);
        if (!$create || empty($create['Create Table'])) {
            continue;
        }

        $lines[] = '-- --------------------------------------------------------';
        $lines[] = '-- Table: `' . $table . '`';
        $lines[] = '-- --------------------------------------------------------';
        $lines[] = 'DROP TABLE IF EXISTS `' . $safeTable . '`;';
        $lines[] = $create['Create Table'] . ';';
        $lines[] = '';

        $data = $db->query('SELECT * FROM `' . $safeTable . '`');
        while ($record = $data->fetch(PDO::FETCH_ASSOC)) {
            $columns = array_map(static fn($col) => '`' . str_replace('`', '``', $col) . '`', array_keys($record));
            $values = [];
            foreach ($record as $value) {
                $values[] = $value === null ? 'NULL' : $db->quote((string) $value);
            }
            $lines[] = 'INSERT INTO `' . $safeTable . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ');';
        }

        $lines[] = '';
    }

    $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
    $lines[] = '';

    return implode("\n", $lines);
}

function sendDatabaseExport(PDO $db): void
{
    $filename = DB_NAME . '-' . date('Ymd-His') . '.sql';
    $sql = exportDatabaseSql($db);

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    echo $sql;
    exit;
}

/**
 * @return array{success:bool,message:string,executed:int,errors:array<int,string>}
 */
function importDatabaseSql(PDO $db, string $sql): array
{
    $statements = splitSqlStatements($sql);
    if (empty($statements)) {
        return [
            'success' => false,
            'message' => 'No SQL statements found in the uploaded file.',
            'executed' => 0,
            'errors' => [],
        ];
    }

    $executed = 0;
    $errors = [];

    $db->exec('SET FOREIGN_KEY_CHECKS = 0');

    foreach ($statements as $index => $statement) {
        if ($statement === '') {
            continue;
        }

        try {
            $db->exec($statement);
            $executed++;
        } catch (PDOException $e) {
            $errors[] = 'Statement #' . ($index + 1) . ': ' . $e->getMessage();
            if (count($errors) >= 10) {
                $errors[] = 'Import stopped after 10 errors.';
                break;
            }
        }
    }

    $db->exec('SET FOREIGN_KEY_CHECKS = 1');

    if (!empty($errors)) {
        return [
            'success' => false,
            'message' => 'Import completed with errors. Review the details below.',
            'executed' => $executed,
            'errors' => $errors,
        ];
    }

    return [
        'success' => true,
        'message' => 'Database imported successfully.',
        'executed' => $executed,
        'errors' => [],
    ];
}

/** @return list<string> */
function splitSqlStatements(string $sql): array
{
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);

    $statements = [];
    $buffer = '';
    $inString = false;
    $stringChar = '';
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $char = $sql[$i];
        $buffer .= $char;

        if (!$inString && ($char === '"' || $char === "'")) {
            $inString = true;
            $stringChar = $char;
            continue;
        }

        if ($inString) {
            if ($char === $stringChar) {
                $escapes = 0;
                for ($j = $i - 1; $j >= 0 && $sql[$j] === '\\'; $j--) {
                    $escapes++;
                }
                if ($escapes % 2 === 0) {
                    $inString = false;
                }
            }
            continue;
        }

        if ($char === ';') {
            $statement = trim($buffer);
            if ($statement !== '' && !isSqlCommentOnly($statement)) {
                $statements[] = $statement;
            }
            $buffer = '';
        }
    }

    $tail = trim($buffer);
    if ($tail !== '' && !isSqlCommentOnly($tail)) {
        $statements[] = $tail;
    }

    return $statements;
}

function isSqlCommentOnly(string $statement): bool
{
    $lines = preg_split('/\R/', $statement) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (str_starts_with($line, '--') || str_starts_with($line, '#')) {
            continue;
        }
        return false;
    }

    return true;
}

function validateDatabaseImportUpload(array $file): ?string
{
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'Please choose a valid .sql file to upload.';
    }

    $maxBytes = 100 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        return 'SQL file is too large. Maximum upload size is 100 MB.';
    }

    $name = strtolower((string) $file['name']);
    if (!str_ends_with($name, '.sql')) {
        return 'Only .sql files are allowed.';
    }

    return null;
}
