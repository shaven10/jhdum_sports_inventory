<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
if (!canImportRoster()) {
    flash('error', 'Only administrators can import athlete rosters.');
    redirect(BASE_URL . '/intramurals/athletes/index.php');
}
redirect(BASE_URL . '/intramurals/roster/import.php');
