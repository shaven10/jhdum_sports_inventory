<?php
/**
 * Add event_group (Sports Competition vs Socio-Cultural) and seed socio-cultural events.
 * Visit once as admin, then ignore.
 */
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

ensureSportEventGroupColumn();

$db = getDB();
header('Content-Type: text/plain; charset=utf-8');
echo "Sport event group migration\n\n";

$col = $db->query("SHOW COLUMNS FROM intramural_sports LIKE 'event_group'")->fetch();
echo 'event_group column: ' . ($col ? 'yes' : 'no') . "\n";

$counts = $db->query('SELECT event_group, COUNT(*) AS total FROM intramural_sports GROUP BY event_group')->fetchAll();
echo "\nEvents by group:\n";
foreach ($counts as $row) {
    echo '  ' . ($row['event_group'] ?? 'NULL') . ': ' . $row['total'] . "\n";
}

$socio = $db->query("SELECT name, category FROM intramural_sports WHERE event_group = 'socio_cultural' ORDER BY name, category")->fetchAll();
echo "\nSocio-cultural events:\n";
foreach ($socio as $row) {
    echo '  ' . $row['name'] . ' (' . $row['category'] . ")\n";
}
echo "\nDONE\n";
