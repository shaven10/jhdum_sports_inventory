<?php
/**
 * Database Installation Script
 * Run once: http://localhost/sports_inventory/install.php
 * Delete this file after installation for security.
 */

require_once __DIR__ . '/config/config.php';

$messages = [];
$error = null;

function columnExists(PDO $pdo, string $schema, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$schema, $table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function splitSqlStatements(string $sql): array
{
    $statements = [];
    $current = '';
    $inString = false;
    $stringChar = '';
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if (!$inString && $char === '-' && $next === '-') {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            $current .= "\n";
            continue;
        }

        if (!$inString && ($char === '"' || $char === "'")) {
            $inString = true;
            $stringChar = $char;
            $current .= $char;
            continue;
        }

        if ($inString) {
            $current .= $char;
            if ($char === $stringChar && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $inString = false;
                $stringChar = '';
            }
            continue;
        }

        if ($char === ';') {
            $statement = trim($current);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $current = '';
            continue;
        }

        $current .= $char;
    }

    $statement = trim($current);
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', DB_NAME) . '`');

        $sql = file_get_contents(__DIR__ . '/database/schema.sql');
        $statements = splitSqlStatements($sql);

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }

        foreach (['equipment', 'athletes', 'teams'] as $dir) {
            $uploadDir = __DIR__ . '/uploads/' . $dir;
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
        }

        $pdo->exec('USE `' . str_replace('`', '``', DB_NAME) . '`');

        if (!columnExists($pdo, DB_NAME, 'users', 'password_plain')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN password_plain VARCHAR(255) DEFAULT NULL AFTER password');
        }

        $defaultPassword = 'admin123';
        $passwordHash = password_hash($defaultPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET password = ?, password_plain = ?');
        $stmt->execute([$passwordHash, $defaultPassword]);

        $messages[] = 'Database installed successfully!';
        $messages[] = 'Default password for all seed users: admin123';
        $messages[] = 'Login as admin / admin123 to manage users and view passwords under Users.';
        $messages[] = 'Sample accounts: admin, coordinator, staff, student, um_blue, coach_blue, um_red, coach_red, um_green, coach_green, um_gold, coach_gold';
        $messages[] = 'Please delete install.php for security.';
    } catch (PDOException $e) {
        $error = 'Installation failed: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install - <?= htmlspecialchars(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow">
                <div class="card-header bg-primary text-white text-center">
                    <img src="<?= htmlspecialchars(APP_LOGO) ?>" alt="JHCSC" style="height:64px;width:auto;background:#fff;border-radius:50%;padding:4px;margin-bottom:0.5rem">
                    <h4 class="mb-0"><?= htmlspecialchars(APP_NAME) ?></h4>
                    <small>Installation</small>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <?php if ($messages): ?>
                    <div class="alert alert-success">
                        <?php foreach ($messages as $msg): ?>
                        <p class="mb-1"><?= htmlspecialchars($msg) ?></p>
                        <?php endforeach; ?>
                    </div>
                    <a href="login.php" class="btn btn-primary w-100">Go to Login</a>
                    <?php else: ?>
                    <p>This will create the database and all required tables with sample data.</p>
                    <ul>
                        <li>Database: <strong><?= htmlspecialchars(DB_NAME) ?></strong></li>
                        <li>Host: <strong><?= htmlspecialchars(DB_HOST) ?></strong></li>
                        <li>User: <strong><?= htmlspecialchars(DB_USER) ?></strong></li>
                    </ul>
                    <p class="text-danger"><strong>Warning:</strong> Installing will drop and recreate the <strong><?= htmlspecialchars(DB_NAME) ?></strong> database. All existing data in that database will be deleted.</p>
                    <p>Seed users are created with password <strong>admin123</strong>. Admins can view stored passwords in the Users module after login.</p>
                    <p class="text-warning"><strong>Make sure XAMPP MySQL is running before proceeding.</strong></p>
                    <form method="POST">
                        <button type="submit" class="btn btn-primary w-100">Install Database</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
