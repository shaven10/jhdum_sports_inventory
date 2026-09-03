<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

function tableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function settingExists(PDO $db, string $key): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM system_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    return (int) $stmt->fetchColumn() > 0;
}

echo "Creating intramural_season_gallery table...\n";

if (!tableExists($db, 'intramural_season_gallery')) {
    $db->exec("CREATE TABLE intramural_season_gallery (
        id INT AUTO_INCREMENT PRIMARY KEY,
        season_id INT NOT NULL,
        filename VARCHAR(255) NOT NULL,
        caption VARCHAR(255) DEFAULT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        uploaded_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_gallery_season (season_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Created intramural_season_gallery\n";
}

$defaults = [
    'sdo_about_title' => ['About the Sports Development Office', 'string', 'Landing page SDO section title'],
    'sdo_about_content' => [
        "The Sports Development Office (SDO) leads the campus sports program at J.H. Cerilles State College — organizing intramurals, supporting varsity teams, managing sports facilities and equipment, and promoting wellness through physical activity.\n\nOur office works with coaches, unit managers, and student-athletes to deliver fair competition, meaningful recreation, and opportunities for leadership on and off the field.",
        'string',
        'Landing page SDO about text',
    ],
];

foreach ($defaults as $key => [$value, $type, $description]) {
    if (!settingExists($db, $key)) {
        $stmt = $db->prepare('INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES (?, ?, ?, ?)');
        $stmt->execute([$key, $value, $type, $description]);
        echo "Added setting: {$key}\n";
    }
}

$galleryDir = __DIR__ . '/../uploads/gallery';
if (!is_dir($galleryDir)) {
    mkdir($galleryDir, 0755, true);
    echo "Created uploads/gallery directory\n";
}

echo "DONE\n";
