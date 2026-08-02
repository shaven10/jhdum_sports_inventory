<?php
/**
 * One-time migrator for Intramurals tables on an existing install.
 * Open: http://localhost/sports_inventory/database/install_intramurals.php
 * Delete after use.
 */
require_once __DIR__ . '/../config/database.php';

$messages = [];
$error = null;

try {
    $db = getDB();
    $sql = file_get_contents(__DIR__ . '/migrate_intramurals.sql');
    // Strip USE statement; connection already selects DB
    $sql = preg_replace('/^USE\s+\w+\s*;/mi', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $statement) {
        if ($statement === '' || stripos($statement, '--') === 0) {
            continue;
        }
        // Skip pure comment blocks
        $clean = trim(preg_replace('/--.*$/m', '', $statement));
        if ($clean === '') {
            continue;
        }
        try {
            $db->exec($statement);
            $preview = preg_replace('/\s+/', ' ', $clean);
            $messages[] = 'OK: ' . (strlen($preview) > 80 ? substr($preview, 0, 77) . '...' : $preview);
        } catch (PDOException $e) {
            // Ignore duplicates on re-run
            if (str_contains($e->getMessage(), 'already exists') || (int) $e->errorInfo[1] === 1050 || (int) $e->errorInfo[1] === 1062) {
                $messages[] = 'Skip: ' . $e->getMessage();
            } else {
                throw $e;
            }
        }
    }

    foreach (['athletes', 'teams'] as $dir) {
        $path = __DIR__ . '/../uploads/' . $dir;
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
            $messages[] = "Created uploads/$dir";
        }
    }

    $messages[] = 'Intramurals module installed. You can delete this file.';
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Install Intramurals</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="card shadow">
        <div class="card-header bg-primary text-white"><h4 class="mb-0">Install Intramurals Module</h4></div>
        <div class="card-body">
            <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php foreach ($messages as $m): ?>
            <div class="small text-muted"><?= htmlspecialchars($m) ?></div>
            <?php endforeach; ?>
            <?php if (!$error): ?>
            <a class="btn btn-primary mt-3" href="../intramurals/index.php">Open Intramurals</a>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
