<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireIntramuralsAccess();

if (!canLockRoster()) {
    flash('error', 'Only administrators can lock or unlock rosters.');
    redirect(getHomeUrl());
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf(post('csrf_token'))) {
    flash('error', 'Invalid request.');
    redirect(BASE_URL . '/intramurals/seasons/index.php');
}

$action = post('action');
$seasonId = (int) post('season_id');
$return = post('return') ?: BASE_URL . '/intramurals/roster/index.php';
if ($return !== '' && !str_starts_with($return, BASE_URL)) {
    $return = BASE_URL . '/intramurals/roster/index.php';
}

$season = getSeasonById($seasonId);
if (!$season) {
    flash('error', 'Season not found.');
    redirect($return);
}

if ($action === 'lock') {
    $lockDate = trim((string) post('lock_date', date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lockDate)) {
        flash('error', 'Please choose a valid lock date.');
        redirect($return);
    }

    if (setSeasonRosterLockDate($seasonId, (int) $_SESSION['user_id'], $lockDate)) {
        auditLog((int) $_SESSION['user_id'], 'lock_roster', 'intramural_season', $seasonId, null, [
            'year_label' => $season['year_label'],
            'lock_date' => $lockDate,
        ]);
        if ($lockDate > date('Y-m-d')) {
            flash('success', 'Roster lock scheduled for ' . formatDate($lockDate) . ' (' . $season['year_label'] . ').');
        } else {
            flash('success', 'Roster locked for ' . $season['year_label'] . ' (effective ' . formatDate($lockDate) . '). Modifications are now restricted.');
        }
    } else {
        flash('error', 'Could not set roster lock date.');
    }
} elseif ($action === 'unlock') {
    if (unlockSeasonRoster($seasonId)) {
        auditLog((int) $_SESSION['user_id'], 'unlock_roster', 'intramural_season', $seasonId, null, ['year_label' => $season['year_label']]);
        flash('success', 'Roster unlocked for ' . $season['year_label'] . '. Teams can modify rosters again.');
    } else {
        flash('error', 'Could not unlock roster.');
    }
} else {
    flash('error', 'Unknown action.');
}

redirect($return);
