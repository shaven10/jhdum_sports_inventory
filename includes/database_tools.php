<?php
/**
 * Admin database backup and restore helpers.
 */

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
