<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
if (!canManageMatches()) {
    flash('error', 'You do not have permission to update brackets.');
    redirect(BASE_URL . '/intramurals/matches/index.php');
}
requireWritableSeason();

$seasonId = getCurrentSeasonId();
if (!$seasonId) {
    flash('error', 'No active season selected.');
    redirect(BASE_URL . '/intramurals/matches/index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf(post('csrf_token'))) {
    flash('error', 'Invalid request.');
    redirect(BASE_URL . '/intramurals/matches/index.php');
}

$sportId = (int) post('sport_id');
$returnTo = post('return_to', BASE_URL . '/intramurals/matches/index.php' . ($sportId ? '?sport=' . $sportId : ''));

if ($sportId <= 0) {
    flash('error', 'Select a sport/event to update the bracket.');
    redirect($returnTo);
}

requireEventMatchAccess($sportId);
requireUnlockedResults(null, $sportId);

$result = advanceBracketFromResults($sportId, $seasonId);
auditLog($_SESSION['user_id'], 'advance_bracket', 'intramural_sport', $sportId, null, $result);

if (!empty($result['error'])) {
    flash('error', $result['error']);
} elseif ((int) ($result['updated'] ?? 0) > 0) {
    $msg = 'Updated ' . (int) $result['updated'] . ' TBD team slot(s) from previous results.';
    if (!empty($result['details'])) {
        $msg .= ' ' . implode(' ', $result['details']);
    }
    flash('success', $msg);
} else {
    flash('info', !empty($result['details']) ? implode(' ', $result['details']) : 'No bracket slots were updated.');
}

redirect($returnTo);
