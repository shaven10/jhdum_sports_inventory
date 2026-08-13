<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
requireIntramuralsAccess();

if (!canDeleteAllMatches()) {
    flash('error', 'Only administrators can delete matches.');
    redirect(getHomeUrl());
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf(post('csrf_token'))) {
    flash('error', 'Invalid request.');
    redirect(BASE_URL . '/intramurals/matches/index.php');
}

$action = post('action', 'bulk');
$seasonId = getCurrentSeasonId();
$return = post('return') ?: BASE_URL . '/intramurals/matches/index.php';
if ($return !== '' && !str_starts_with($return, BASE_URL)) {
    $return = BASE_URL . '/intramurals/matches/index.php';
}

if (!$seasonId) {
    flash('error', 'No active intramurals season configured.');
    redirect($return);
}

if ($action === 'single') {
    $matchId = (int) post('match_id');
    $stmt = getDB()->prepare('SELECT id, sport_id, season_id, is_generated FROM intramural_matches WHERE id = ?');
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();

    if (!$match || (int) $match['season_id'] !== $seasonId) {
        flash('error', 'Match not found for the current season.');
        redirect($return);
    }

    $deleted = !empty($match['is_generated'])
        ? deleteGeneratedMatchById($matchId)
        : deleteMatchById($matchId);

    if ($deleted) {
        auditLog((int) $_SESSION['user_id'], 'delete_match', 'intramural_match', $matchId, null, [
            'sport_id' => (int) $match['sport_id'],
            'season_id' => $seasonId,
            'is_generated' => (int) ($match['is_generated'] ?? 0),
        ]);
        flash('success', 'Match deleted.');
    } else {
        flash('error', 'Could not delete match.');
    }
    redirect($return);
}

$sportId = post('sport_id') !== '' ? (int) post('sport_id') : null;
$deleteScope = post('delete_scope', 'generated');
$includeCompleted = post('include_completed') === '1';
$confirm = post('confirm_delete') === '1';

if (!$confirm) {
    flash('error', 'Please confirm that you want to delete matches.');
    redirect($return);
}

if ($deleteScope === 'all') {
    if (trim((string) post('confirm_phrase')) !== 'DELETE ALL') {
        flash('error', 'Type DELETE ALL to confirm deleting every match in this scope.');
        redirect($return);
    }

    $toDelete = countSeasonMatches($seasonId, $sportId);
    if ($toDelete === 0) {
        flash('error', 'No matches matched your selection.');
        redirect($return);
    }

    $result = deleteAllMatches($seasonId, $sportId);

    auditLog((int) $_SESSION['user_id'], 'delete_all_matches', 'intramural_match', null, null, [
        'season_id' => $seasonId,
        'sport_id' => $sportId,
        'deleted' => $result['deleted'],
        'scope' => $result['scope'],
    ]);

    $sportLabel = resolveSportScopeLabel($sportId);
    flash('success', $result['deleted'] . ' match' . ($result['deleted'] === 1 ? '' : 'es') . ' deleted (all matches) for ' . $sportLabel . '.');
    redirect($return);
}

$toDelete = countGeneratedMatches($seasonId, $sportId, $includeCompleted);
if ($toDelete === 0) {
    flash('error', 'No generated matches matched your selection.');
    redirect($return);
}

$result = deleteGeneratedMatches($seasonId, $sportId, $includeCompleted);

auditLog((int) $_SESSION['user_id'], 'delete_generated_matches', 'intramural_match', null, null, [
    'season_id' => $seasonId,
    'sport_id' => $sportId,
    'include_completed' => $includeCompleted,
    'deleted' => $result['deleted'],
    'scope' => $result['scope'],
]);

$sportLabel = resolveSportScopeLabel($sportId);
$scopeLabel = $includeCompleted ? 'all generated' : 'unplayed generated';
flash('success', $result['deleted'] . ' ' . $scopeLabel . ' match' . ($result['deleted'] === 1 ? '' : 'es') . ' deleted for ' . $sportLabel . '.');
redirect($return);

function resolveSportScopeLabel(?int $sportId): string
{
    if (!$sportId) {
        return 'all events';
    }

    $sportStmt = getDB()->prepare('SELECT name, category FROM intramural_sports WHERE id = ?');
    $sportStmt->execute([$sportId]);
    $sportRow = $sportStmt->fetch();

    return $sportRow ? sportLabel($sportRow) : 'event #' . $sportId;
}
