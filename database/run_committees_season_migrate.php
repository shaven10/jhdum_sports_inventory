<?php
/**
 * Bind existing working committees to the active season.
 * Visit once as admin, then ignore.
 */
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

ensureWorkingCommitteesSchema();

$db = getDB();
$active = getActiveSeason();
$activeId = $active ? (int) $active['id'] : 0;

header('Content-Type: text/plain; charset=utf-8');
echo "Working committees season binding\n\n";
echo 'Active season: ' . ($active ? seasonLabel($active) : '(none)') . "\n";

$col = $db->query("SHOW COLUMNS FROM working_committees LIKE 'season_id'")->fetch();
echo 'season_id column: ' . ($col ? 'yes' : 'no') . "\n";

if ($activeId) {
    $nullCount = (int) $db->query('SELECT COUNT(*) FROM working_committees WHERE season_id IS NULL')->fetchColumn();
    echo "Unassigned rows before: {$nullCount}\n";
    $db->prepare('UPDATE working_committees SET season_id = ? WHERE season_id IS NULL')->execute([$activeId]);
}

$counts = $db->query('SELECT season_id, COUNT(*) AS total FROM working_committees GROUP BY season_id ORDER BY season_id')->fetchAll();
echo "\nCommittees by season_id:\n";
foreach ($counts as $row) {
    echo '  ' . ($row['season_id'] ?? 'NULL') . ': ' . $row['total'] . "\n";
}
echo "\nDONE\n";
