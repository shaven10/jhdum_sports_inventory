<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

require_once __DIR__ . '/../includes/functions.php';

echo "Repairing system_settings primary key / auto_increment...\n";

ensureSystemSettingsSchema();

$maxId = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM system_settings')->fetchColumn();
echo "Max id after repair: {$maxId}\n";

foreach (['sdo_about_title', 'sdo_about_content'] as $key) {
    $stmt = $db->prepare('SELECT id FROM system_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    echo $key . ': ' . ($row ? 'exists (id=' . $row['id'] . ')' : 'missing') . "\n";
}

echo "DONE\n";
