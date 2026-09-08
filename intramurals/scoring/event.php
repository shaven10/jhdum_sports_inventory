<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
requireScoringDeskAccess();
ensureEventRanksTable();
ensureIntramuralDivisionsSchema();
ensureSportEventGroupColumn();

$db = getDB();
$seasonId = getCurrentSeasonId();
$season = getCurrentSeason();
$sportId = (int) (get('sport') ?: post('sport_id'));
$tabRequested = trim((string) (get('tab') !== '' && get('tab') !== null ? get('tab') : post('tab', '')));
$tab = in_array($tabRequested, ['scores', 'rankings'], true) ? $tabRequested : 'scores';
$divisionParam = get('division', post('division_id', ''));

$sports = filterSportsForUser($db->query('SELECT * FROM intramural_sports ORDER BY ' . intramuralSportsOrderBy())->fetchAll());
$sport = null;
foreach ($sports as $s) {
    if ((int) $s['id'] === $sportId) {
        $sport = $s;
        break;
    }
}

if ($sportId <= 0 || !$sport) {
    flash('error', 'Select an event to enter scores or ranks.');
    redirect(BASE_URL . '/intramurals/scoring/index.php');
}
if (!canViewEvent($sportId) || !canManageEventMatches($sportId)) {
    flash('error', 'You do not have permission to enter scores or ranks for this event.');
    redirect(BASE_URL . '/intramurals/scoring/index.php');
}

$isRankFormat = (($sport['tournament_format'] ?? '') === 'rank_first_to_last');
$isSocio = isSocioCulturalSport($sport);
$hasRubric = $isSocio && eventHasRubricCriteria($sportId);
$eventUrl = static function (string $tabName, ?int $divisionId = null) use ($sportId): string {
    $url = BASE_URL . '/intramurals/scoring/event.php?sport=' . $sportId . '&tab=' . urlencode($tabName);
    if ($divisionId !== null) {
        $url .= '&division=' . (int) $divisionId;
    }
    return $url;
};

$teams = $db->query('SELECT * FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
$teamMap = [];
foreach ($teams as $t) {
    $teamMap[(int) $t['id']] = $t;
}

$divisionGroups = groupEligibleTeamsByDivisionForSport($sportId);
if ($divisionGroups === []) {
    $divisionGroups = [
        0 => [
            'division_id' => null,
            'division_name' => 'All teams',
            'team_ids' => array_map(static fn($t) => (int) $t['id'], $teams),
        ],
    ];
}
$divisionKeys = array_keys($divisionGroups);
$divisionId = 0;
if ($divisionParam !== '' && $divisionParam !== null) {
    $divisionId = (int) $divisionParam;
}
if (!isset($divisionGroups[$divisionId]) && $divisionKeys) {
    $divisionId = (int) $divisionKeys[0];
}
$activeDivision = $divisionGroups[$divisionId] ?? null;
$divisionLabel = $activeDivision['division_name'] ?? 'All teams';
$hasScheduledMatches = $seasonId && eventHasScheduledMatches($sportId, (int) $seasonId, $divisionId);
$canEditRanks = $seasonId && canManageEventRankings($sportId) && !isResultsLocked(null, $sportId) && !$hasScheduledMatches;
$scheme = getPointSchemeForSport($sport);
$labels = placementLabels();
$errors = [];

$matches = [];
if ($seasonId) {
    $matchStmt = $db->prepare("SELECT m.*,
            ta.name AS team_a_name, ta.color AS team_a_color,
            tb.name AS team_b_name, tb.color AS team_b_color
        FROM intramural_matches m
        LEFT JOIN intramural_teams ta ON m.team_a_id = ta.id
        LEFT JOIN intramural_teams tb ON m.team_b_id = tb.id
        WHERE m.sport_id = ? AND m.season_id = ?
        ORDER BY m.scheduled_at IS NULL, m.scheduled_at, m.round_number, m.match_order, m.id");
    $matchStmt->execute([$sportId, $seasonId]);
    $matches = $matchStmt->fetchAll();
}

if ($tabRequested === '' && $_SERVER['REQUEST_METHOD'] !== 'POST' && ($isRankFormat || $matches === [])) {
    $tab = 'rankings';
}

$registeredIds = $seasonId ? getEventParticipatingTeamIds($sportId, (int) $seasonId, $divisionId) : [];
$currentRanks = $seasonId ? getEventRanks($sportId, (int) $seasonId, $divisionId) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    requireWritableSeason();
    $action = post('action');

    if ($action === 'live_score') {
        requireUnlockedResults(null, $sportId);
        if (!canRecordScores($sportId) || !canManageEventMatches($sportId)) {
            flash('error', 'You do not have permission to update scores for this event.');
            redirect($eventUrl('scores', $divisionId));
        }
        $matchId = (int) post('match_id');
        $owned = false;
        foreach ($matches as $m) {
            if ((int) $m['id'] === $matchId) {
                $owned = true;
                break;
            }
        }
        if (!$owned) {
            flash('error', 'That match does not belong to this event.');
            redirect($eventUrl('scores', $divisionId));
        }
        $result = recordIntramuralMatchScore(
            $matchId,
            (int) post('score_a'),
            (int) post('score_b'),
            post('status', 'ongoing')
        );
        if (!empty($result['ok'])) {
            flash('success', $result['message'] ?: 'Score updated.');
        } else {
            flash('error', $result['error'] ?: 'Could not update score.');
        }
        redirect($eventUrl('scores', $divisionId));
    }

    if ($action === 'save' || $action === 'clear') {
        requireUnlockedResults(null, $sportId);
        if (!canManageEventRankings($sportId)) {
            flash('error', 'You do not have permission to save ranks for this event.');
            redirect($eventUrl('rankings', $divisionId));
        }
        $divisionId = eventRankDivisionKey((int) post('division_id', '0'));
        if (eventHasScheduledMatches($sportId, (int) $seasonId, $divisionId)) {
            flash('error', 'Manual ranking is not allowed for this division while it has scheduled matches. Placement follows match results.');
            redirect($eventUrl('rankings', $divisionId));
        }
        if ($action === 'clear') {
            $errors = saveEventRanks($sportId, (int) $seasonId, [], (int) $_SESSION['user_id'], $divisionId);
            if (!$errors) {
                auditLog($_SESSION['user_id'], 'clear_event_ranks', 'intramural_sport', $sportId, null, [
                    'season_id' => $seasonId,
                    'division_id' => $divisionId,
                ]);
                flash('success', 'Ranks cleared for this division.');
                redirect($eventUrl('rankings', $divisionId));
            }
        } else {
            $raw = $_POST['rank'] ?? [];
            $ranksByTeam = [];
            if (is_array($raw)) {
                foreach ($raw as $tid => $place) {
                    $ranksByTeam[(int) $tid] = (int) $place;
                }
            }
            $errors = saveEventRanks($sportId, (int) $seasonId, $ranksByTeam, (int) $_SESSION['user_id'], $divisionId);
            if (!$errors) {
                $saved = count(array_filter($ranksByTeam, static fn($p) => (int) $p > 0));
                auditLog($_SESSION['user_id'], 'save_event_ranks', 'intramural_sport', $sportId, null, [
                    'season_id' => $seasonId,
                    'division_id' => $divisionId,
                    'ranks' => $ranksByTeam,
                ]);
                flash('success', $saved . ' team rank' . ($saved === 1 ? '' : 's') . ' saved for ' . $divisionLabel . '.');
                redirect($eventUrl('rankings', $divisionId));
            }
            $currentRanks = [];
            foreach ($ranksByTeam as $tid => $place) {
                if ((int) $place > 0) {
                    $currentRanks[(int) $tid] = [
                        'team_id' => (int) $tid,
                        'place_rank' => (int) $place,
                        'notes' => null,
                        'division_id' => $divisionId,
                    ];
                }
            }
        }
        $tab = 'rankings';
    }
}

$groupTeamIds = $activeDivision['team_ids'] ?? [];
$listedIds = [];
foreach ($registeredIds as $tid) {
    if (in_array($tid, $groupTeamIds, true) || $groupTeamIds === []) {
        $listedIds[] = $tid;
    }
}
foreach ($groupTeamIds as $tid) {
    if (!in_array($tid, $listedIds, true)) {
        $listedIds[] = $tid;
    }
}
usort($listedIds, static function ($a, $b) use ($teamMap, $registeredIds) {
    $aOn = in_array($a, $registeredIds, true);
    $bOn = in_array($b, $registeredIds, true);
    if ($aOn !== $bOn) {
        return $aOn ? -1 : 1;
    }
    return strcasecmp($teamMap[$a]['name'] ?? '', $teamMap[$b]['name'] ?? '');
});
$maxPlace = max(6, count($listedIds));
$multiDivision = count($divisionGroups) > 1;
$canScore = $seasonId && canRecordScores($sportId) && canManageEventMatches($sportId);

$pageTitle = 'Scores & Rankings — ' . sportLabel($sport);
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
echo renderResultsLockAlerts();
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="mb-1"><i class="bi bi-pencil-square"></i> <?= sanitize($sport['name']) ?></h1>
        <p class="text-muted mb-0">
            <span class="badge bg-secondary"><?= sanitize(ucfirst((string) $sport['category'])) ?></span>
            <span class="badge bg-info text-dark"><?= sanitize(tournamentFormatLabel($sport['tournament_format'] ?? 'round_robin')) ?></span>
            <span class="badge bg-light text-dark border"><?= sanitize(sportEventGroupLabel(sportEventGroupOf($sport))) ?></span>
            <?php if ($season): ?> · <?= sanitize(seasonLabel($season)) ?><?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/intramurals/scoring/index.php" class="btn btn-outline-secondary">All events</a>
        <?php if ($isSocio): ?>
        <a href="<?= BASE_URL ?>/intramurals/rubrics/score.php?sport=<?= $sportId ?>&division=<?= (int) $divisionId ?>" class="btn btn-outline-warning"><i class="bi bi-clipboard-check"></i> Rubrics</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/intramurals/standings/index.php?sport=<?= $sportId ?>" class="btn btn-outline-primary">Standings</a>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if (!$seasonId): ?>
<div class="alert alert-warning">Set an active season before entering scores or ranks.</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'scores' ? 'active' : '' ?>" href="<?= sanitize($eventUrl('scores', $divisionId)) ?>">
            <i class="bi bi-trophy"></i> Scores
            <?php if ($matches): ?>
            <span class="badge bg-secondary ms-1"><?= count($matches) ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php if ($isSocio): ?>
    <li class="nav-item">
        <a class="nav-link" href="<?= BASE_URL ?>/intramurals/rubrics/score.php?sport=<?= $sportId ?>&division=<?= (int) $divisionId ?>">
            <i class="bi bi-clipboard-check"></i> Rubrics
        </a>
    </li>
    <?php endif; ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'rankings' ? 'active' : '' ?>" href="<?= sanitize($eventUrl('rankings', $divisionId)) ?>">
            <i class="bi bi-list-ol"></i> Rankings
        </a>
    </li>
</ul>

<?php if ($tab === 'scores'): ?>
<?php if (!$seasonId): ?>
<?php elseif (empty($matches)): ?>
<div class="alert alert-info">
    No matches for this event.
    <?php if ($hasRubric): ?>
    Enter panel scores on the <a href="<?= BASE_URL ?>/intramurals/rubrics/score.php?sport=<?= $sportId ?>&division=<?= (int) $divisionId ?>" class="alert-link">Rubrics</a> tab.
    <?php elseif ($isRankFormat || canManageEventRankings($sportId)): ?>
    Enter official places on the <a href="<?= sanitize($eventUrl('rankings', $divisionId)) ?>" class="alert-link">Rankings</a> tab.
    <?php endif; ?>
    <?php if (canGenerateMatches()): ?>
    Or <a href="<?= BASE_URL ?>/intramurals/matches/generate.php" class="alert-link">generate fixtures</a>.
    <?php endif; ?>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>Match scores</span>
        <?php if (isBracketTournamentFormat($sport['tournament_format'] ?? '') && !isResultsLocked(null, $sportId)): ?>
        <form method="POST" action="<?= BASE_URL ?>/intramurals/matches/advance.php" class="d-inline">
            <?= csrfField() ?>
            <input type="hidden" name="sport_id" value="<?= $sportId ?>">
            <input type="hidden" name="return_to" value="<?= sanitize($eventUrl('scores', $divisionId)) ?>">
            <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-diagram-3"></i> Update Bracket</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Game</th>
                    <th>When</th>
                    <th>Round</th>
                    <th>Match</th>
                    <th style="min-width:22rem;">Score / status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($matches as $m): ?>
                <?php
                $mid = (int) $m['id'];
                $teamAReady = (int) ($m['team_a_id'] ?? 0) > 0;
                $teamBReady = (int) ($m['team_b_id'] ?? 0) > 0;
                $teamsReady = $teamAReady && $teamBReady;
                $disabledRubber = isDecidingRubberDisabled($db, $m);
                $status = (string) ($m['status'] ?? 'scheduled');
                $canRowScore = $canScore && $teamsReady && $status !== 'cancelled' && !$disabledRubber;
                ?>
                <tr class="<?= $status === 'cancelled' ? 'table-secondary' : (empty($m['scheduled_at']) ? 'table-warning' : '') ?>">
                    <td>
                        <?php if (!empty($m['game_number'])): ?>
                        <span class="badge bg-dark"><?= (int) $m['game_number'] ?></span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($m['scheduled_at'])): ?>
                        <?= formatDateTime($m['scheduled_at']) ?>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark">Unscheduled</span>
                        <?php endif; ?>
                    </td>
                    <td><?= sanitize($m['round_label'] ?: '—') ?></td>
                    <td>
                        <span style="color:<?= sanitize($m['team_a_color'] ?? '#888') ?>"><?= sanitize($m['team_a_name'] ?: 'TBD') ?></span>
                        vs
                        <span style="color:<?= sanitize($m['team_b_color'] ?? '#888') ?>"><?= sanitize($m['team_b_name'] ?: 'TBD') ?></span>
                        <div class="mt-1"><?= statusBadge($status) ?></div>
                    </td>
                    <td>
                        <?php if ($disabledRubber): ?>
                        <span class="text-muted small"><?= sanitize(decidingRubberDisabledAlert($db, $m) ?: 'Deciding rubber not required.') ?></span>
                        <?php elseif ($status === 'cancelled'): ?>
                        <span class="text-muted">Cancelled</span>
                        <?php elseif (!$teamsReady): ?>
                        <span class="text-muted small">Assign both teams before scoring.</span>
                        <?php elseif ($canRowScore): ?>
                        <form method="POST" class="row g-1 align-items-end">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="live_score">
                            <input type="hidden" name="match_id" value="<?= $mid ?>">
                            <input type="hidden" name="tab" value="scores">
                            <div class="col-auto">
                                <label class="form-label small mb-0"><?= sanitize($m['team_a_name'] ?: 'A') ?></label>
                                <input type="number" name="score_a" class="form-control form-control-sm" min="0" style="width:4.5rem"
                                       value="<?= $m['score_a'] !== null ? (int) $m['score_a'] : '' ?>">
                            </div>
                            <div class="col-auto">
                                <label class="form-label small mb-0"><?= sanitize($m['team_b_name'] ?: 'B') ?></label>
                                <input type="number" name="score_b" class="form-control form-control-sm" min="0" style="width:4.5rem"
                                       value="<?= $m['score_b'] !== null ? (int) $m['score_b'] : '' ?>">
                            </div>
                            <div class="col-auto">
                                <label class="form-label small mb-0">Status</label>
                                <select name="status" class="form-select form-select-sm">
                                    <option value="ongoing" <?= $status === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                                    <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                                    <option value="forfeit" <?= $status === 'forfeit' ? 'selected' : '' ?>>Forfeit</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <button class="btn btn-sm btn-primary">Save</button>
                            </div>
                            <div class="col-auto">
                                <a href="<?= BASE_URL ?>/intramurals/matches/view.php?id=<?= $mid ?>" class="btn btn-sm btn-outline-secondary">Details</a>
                            </div>
                        </form>
                        <?php else: ?>
                        <?php if ($m['score_a'] !== null && $m['score_b'] !== null): ?>
                        <strong><?= (int) $m['score_a'] ?>–<?= (int) $m['score_b'] ?></strong>
                        <?php endif; ?>
                        <a href="<?= BASE_URL ?>/intramurals/matches/view.php?id=<?= $mid ?>" class="btn btn-sm btn-outline-secondary ms-1">View</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<div class="alert alert-secondary py-2">
    <i class="bi bi-info-circle"></i>
    Rank each division separately. Placement points follow this event’s scheme
    (<strong><?= sanitize($scheme['name'] ?? 'Default') ?></strong> · <?= sanitize(formatSchemePoints($scheme)) ?>)
    and count in overall standing and the medal tally.
    <?php if ($hasRubric): ?>
    Socio-cultural placement should come from the
    <a href="<?= BASE_URL ?>/intramurals/rubrics/score.php?sport=<?= $sportId ?>&division=<?= (int) $divisionId ?>" class="alert-link">judging sheet</a>
    (Save scores &amp; apply ranks).
    <?php endif; ?>
</div>

<?php if (!empty($hasScheduledMatches)): ?>
<div class="alert alert-warning">
    <i class="bi bi-lock"></i>
    Manual ranking is locked for <strong><?= sanitize($divisionLabel) ?></strong> because this division already has scheduled matches.
    Record scores on the <a href="<?= sanitize($eventUrl('scores', $divisionId)) ?>" class="alert-link">Scores</a> tab instead.
</div>
<?php endif; ?>

<?php if ($multiDivision): ?>
<ul class="nav nav-pills flex-wrap gap-2 mb-3">
    <?php foreach ($divisionGroups as $key => $group): ?>
    <?php
    $key = (int) $key;
    $divHasMatches = $seasonId && eventHasScheduledMatches($sportId, (int) $seasonId, $key);
    $divHasRanks = $seasonId && eventHasManualRanks($sportId, (int) $seasonId, $key);
    ?>
    <li class="nav-item">
        <a class="nav-link <?= $divisionId === $key ? 'active' : '' ?>" href="<?= sanitize($eventUrl('rankings', $key)) ?>">
            <?= sanitize($group['division_name']) ?>
            <?php if ($divHasRanks): ?><span class="badge text-bg-light text-success border ms-1">Ranked</span><?php endif; ?>
            <?php if ($divHasMatches): ?><span class="badge text-bg-warning ms-1">Matches</span><?php endif; ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($seasonId): ?>
<form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="sport_id" value="<?= $sportId ?>">
    <input type="hidden" name="division_id" value="<?= (int) $divisionId ?>">
    <input type="hidden" name="tab" value="rankings">
    <input type="hidden" name="action" id="rankAction" value="save">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span>Official ranks · <strong><?= sanitize($divisionLabel) ?></strong></span>
            <small class="text-muted">
                <?php if ($registeredIds): ?>
                <?= count($registeredIds) ?> team<?= count($registeredIds) === 1 ? '' : 's' ?> on roster
                <?php else: ?>
                Rank houses in this division
                <?php endif; ?>
            </small>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Team</th>
                        <th style="min-width:220px">Official rank</th>
                        <th>Medal</th>
                        <th>Event pts</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$listedIds): ?>
                    <tr><td colspan="4" class="text-muted p-4">No teams in this division for the selected event.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($listedIds as $tid): ?>
                    <?php
                    if (!isset($teamMap[$tid])) {
                        continue;
                    }
                    $t = $teamMap[$tid];
                    $onRoster = in_array($tid, $registeredIds, true);
                    $place = (int) ($currentRanks[$tid]['place_rank'] ?? 0);
                    $medal = $place ? medalForRank($place) : null;
                    $pts = $place ? getPlacementPoints($scheme, $place) : 0;
                    ?>
                    <tr>
                        <td>
                            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= sanitize($t['color'] ?? '#888') ?>"></span>
                            <strong><?= sanitize($t['name']) ?></strong>
                            <?php if ($onRoster): ?>
                            <span class="badge bg-success ms-1">Roster</span>
                            <?php elseif ($registeredIds): ?>
                            <span class="badge bg-light text-muted border ms-1">Not on roster</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <select name="rank[<?= $tid ?>]" class="form-select form-select-sm rank-select" <?= $canEditRanks ? '' : 'disabled' ?>>
                                <option value="0">— Not ranked —</option>
                                <?php for ($p = 1; $p <= $maxPlace; $p++): ?>
                                <option value="<?= $p ?>" <?= $place === $p ? 'selected' : '' ?>>
                                    <?= $p ?>. <?= sanitize($labels[$p] ?? ('Rank ' . $p)) ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </td>
                        <td>
                            <?php if ($medal === 'gold'): ?><span class="badge bg-warning text-dark">Gold</span>
                            <?php elseif ($medal === 'silver'): ?><span class="badge bg-secondary">Silver</span>
                            <?php elseif ($medal === 'bronze'): ?><span class="badge bg-danger">Bronze</span>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td><?= $pts ? '<strong>' . (int) $pts . '</strong>' : '<span class="text-muted">0</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canEditRanks): ?>
        <div class="card-footer d-flex justify-content-between flex-wrap gap-2">
            <button type="submit" class="btn btn-primary" onclick="document.getElementById('rankAction').value='save'">
                <i class="bi bi-save"></i> Save ranks for <?= sanitize($divisionLabel) ?>
            </button>
            <button type="submit" class="btn btn-outline-danger" onclick="document.getElementById('rankAction').value='clear'; return confirm('Clear saved ranks for this division?');">
                Clear this division
            </button>
        </div>
        <?php else: ?>
        <div class="card-footer text-muted small">
            <?php if (!isViewingActiveSeason()): ?>
            Historical seasons are view-only.
            <?php elseif (isResultsLocked(null, $sportId)): ?>
            Results are locked for this event.
            <?php elseif (!empty($hasScheduledMatches)): ?>
            Manual ranking is disabled while this division has scheduled matches.
            <?php else: ?>
            You can view ranks for this event.
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</form>
<script>
(function () {
    const selects = document.querySelectorAll('.rank-select');
    function warnDuplicates() {
        const used = {};
        selects.forEach(function (sel) { sel.classList.remove('is-invalid'); });
        let ok = true;
        selects.forEach(function (sel) {
            const v = sel.value;
            if (!v || v === '0') return;
            if (used[v]) {
                sel.classList.add('is-invalid');
                used[v].classList.add('is-invalid');
                ok = false;
            } else {
                used[v] = sel;
            }
        });
        return ok;
    }
    selects.forEach(function (sel) { sel.addEventListener('change', warnDuplicates); });
    const form = document.querySelector('form[method=POST]');
    if (form) {
        form.addEventListener('submit', function (e) {
            if (document.getElementById('rankAction').value !== 'save') return;
            if (!warnDuplicates()) {
                e.preventDefault();
                alert('Each place can only be assigned to one team in this division.');
            }
        });
    }
})();
</script>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
