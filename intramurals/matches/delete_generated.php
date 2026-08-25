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
$sportIdsRaw = $_POST['sport_ids'] ?? null;
$sportFilter = null;
if (is_array($sportIdsRaw)) {
    $sportFilter = array_values(array_unique(array_filter(array_map('intval', $sportIdsRaw))));
    if ($sportFilter === []) {
        $sportFilter = null;
    } elseif (count($sportFilter) === 1) {
        $sportFilter = $sportFilter[0];
    }
} elseif ($sportId) {
    $sportFilter = $sportId;
}
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

    $toDelete = countSeasonMatches($seasonId, $sportFilter);
    if ($toDelete === 0) {
        flash('error', 'No matches matched your selection.');
        redirect($return);
    }

    $result = deleteAllMatches($seasonId, $sportFilter);

    auditLog((int) $_SESSION['user_id'], 'delete_all_matches', 'intramural_match', null, null, [
        'season_id' => $seasonId,
        'sport_id' => is_array($sportFilter) ? null : $sportFilter,
        'sport_ids' => is_array($sportFilter) ? $sportFilter : ($sportFilter ? [$sportFilter] : null),
        'deleted' => $result['deleted'],
        'scope' => $result['scope'],
    ]);

    $sportLabel = resolveSportScopeLabel($sportFilter);
    flash('success', $result['deleted'] . ' match' . ($result['deleted'] === 1 ? '' : 'es') . ' deleted (all matches) for ' . $sportLabel . '.');
    redirect($return);
}

$toDelete = countGeneratedMatches($seasonId, $sportFilter, $includeCompleted);
if ($toDelete === 0) {
    flash('error', 'No generated matches matched your selection.');
    redirect($return);
}

$result = deleteGeneratedMatches($seasonId, $sportFilter, $includeCompleted);

auditLog((int) $_SESSION['user_id'], 'delete_generated_matches', 'intramural_match', null, null, [
    'season_id' => $seasonId,
    'sport_id' => is_array($sportFilter) ? null : $sportFilter,
    'sport_ids' => is_array($sportFilter) ? $sportFilter : ($sportFilter ? [$sportFilter] : null),
    'include_completed' => $includeCompleted,
    'deleted' => $result['deleted'],
    'scope' => $result['scope'],
]);

$sportLabel = resolveSportScopeLabel($sportFilter);
$scopeLabel = $includeCompleted ? 'all generated' : 'unplayed generated';
flash('success', $result['deleted'] . ' ' . $scopeLabel . ' match' . ($result['deleted'] === 1 ? '' : 'es') . ' deleted for ' . $sportLabel . '.');
redirect($return);

/**
 * @param int|list<int>|null $sportFilter
 */
function resolveSportScopeLabel($sportFilter): string
{
    if ($sportFilter === null || $sportFilter === '' || $sportFilter === []) {
        return 'all events';
    }

    $ids = is_array($sportFilter)
        ? array_values(array_unique(array_filter(array_map('intval', $sportFilter))))
        : [(int) $sportFilter];
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
    if ($ids === []) {
        return 'all events';
    }

    $db = getDB();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sportStmt = $db->prepare("SELECT name, category FROM intramural_sports WHERE id IN ($placeholders) ORDER BY name, category");
    $sportStmt->execute($ids);
    $rows = $sportStmt->fetchAll();
    if (!$rows) {
        return count($ids) === 1 ? ('event #' . $ids[0]) : (count($ids) . ' selected events');
    }
    if (count($rows) === 1) {
        return sportLabel($rows[0]);
    }

    $labels = array_map(static fn($row) => sportLabel($row), $rows);
    if (count($labels) <= 3) {
        return implode(', ', $labels);
    }

    return count($labels) . ' selected events';
}
