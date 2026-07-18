<?php
/**
 * Database Installation Script
 * Run once: http://localhost/sports_inventory/install.php
 * Delete this file after installation for security.
 */

$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'jhcsc_sports_inventory';

$messages = [];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        $sql = file_get_contents(__DIR__ . '/database/schema.sql');
        $statements = array_filter(array_map('trim', explode(';', $sql)));

        foreach ($statements as $statement) {
            if (!empty($statement) && stripos($statement, '--') !== 0) {
                $pdo->exec($statement);
            }
        }

        $uploadDir = __DIR__ . '/uploads/equipment';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $pdo->exec('USE ' . $dbname);
        $passwordHash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET password = ?');
        $stmt->execute([$passwordHash]);

        $messages[] = 'Database installed successfully!';
        $messages[] = 'Default login: admin / admin123';
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
    <title>Install - JHCSC Sports Inventory</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">JHCSC Sports Inventory - Installation</h4>
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
                        <li>Database: <strong><?= $dbname ?></strong></li>
                        <li>Host: <strong><?= $host ?></strong></li>
                        <li>User: <strong><?= $user ?></strong></li>
                    </ul>
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
