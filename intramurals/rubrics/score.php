<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
requireRubricsAccess();
ensureEventRubricTables();
ensureEventRanksTable();
ensureIntramuralDivisionsSchema();
ensureSportEventGroupColumn();

$db = getDB();
$seasonId = getCurrentSeasonId();
$season = getCurrentSeason();
$sportId = (int) (get('sport') ?: post('sport_id'));
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
    flash('error', 'Select a socio-cultural event to judge.');
    redirect(BASE_URL . '/intramurals/rubrics/index.php');
}
if (!isSocioCulturalSport($sport)) {
    flash('error', 'Rubrics are for socio-cultural events only.');
    redirect(BASE_URL . '/intramurals/rubrics/index.php');
}
if (!canViewEvent($sportId) || !canScoreEventRubrics($sportId)) {
    flash('error', 'You do not have permission to enter rubric scores for this event.');
    redirect(BASE_URL . '/intramurals/rubrics/index.php');
}

$scoreUrl = static function (?int $divisionId = null) use ($sportId): string {
    $url = BASE_URL . '/intramurals/rubrics/score.php?sport=' . $sportId;
    if ($divisionId !== null) {
        $url .= '&division=' . (int) $divisionId;
    }
    return $url;
};
$eventUrl = static function (string $tabName, ?int $divisionId = null) use ($sportId): string {
    $url = BASE_URL . '/intramurals/scoring/event.php?sport=' . $sportId . '&tab=' . urlencode($tabName);
    if ($divisionId !== null) {
        $url .= '&division=' . (int) $divisionId;
    }
    return $url;
};

$teams = $db->query('SELECT * FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
$teamMap = [];
$teamNames = [];
foreach ($teams as $t) {
    $teamMap[(int) $t['id']] = $t;
    $teamNames[(int) $t['id']] = (string) $t['name'];
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
$scheme = getPointSchemeForSport($sport);
$labels = placementLabels();
$errors = [];

$criteria = getEventRubricCriteria($sportId);
if ($criteria === []) {
    seedDefaultRubricForSport($sportId, (string) $sport['name']);
    $criteria = getEventRubricCriteria($sportId);
}
$maxTotal = eventRubricMaxTotal($criteria);

$registeredIds = $seasonId ? getEventParticipatingTeamIds($sportId, (int) $seasonId, $divisionId) : [];
$currentRanks = $seasonId ? getEventRanks($sportId, (int) $seasonId, $divisionId) : [];
$scores = $seasonId ? getEventRubricScores($sportId, (int) $seasonId, $divisionId) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    requireWritableSeason();
    requireUnlockedResults(null, $sportId);
    $action = post('action');
    $divisionId = eventRankDivisionKey((int) post('division_id', '0'));

    if ($action === 'save_scores' || $action === 'save_and_rank') {
        if (!canScoreEventRubrics($sportId)) {
            flash('error', 'You do not have permission to save rubric scores.');
            redirect($scoreUrl($divisionId));
        }
        $raw = $_POST['score'] ?? [];
        $posted = is_array($raw) ? $raw : [];
        $errors = saveEventRubricScores($sportId, (int) $seasonId, $posted, (int) $_SESSION['user_id'], $divisionId);
        if (!$errors) {
            auditLog($_SESSION['user_id'], 'save_event_rubric_scores', 'intramural_sport', $sportId, null, [
                'season_id' => $seasonId,
                'division_id' => $divisionId,
            ]);
            if ($action === 'save_and_rank') {
                $rankErrors = applyEventRanksFromRubric($sportId, (int) $seasonId, $divisionId, (int) $_SESSION['user_id'], $teamNames);
                if ($rankErrors) {
                    flash('success', 'Rubric scores saved.');
                    flash('error', implode(' ', $rankErrors));
                } else {
                    flash('success', 'Rubric scores saved and official ranks applied for ' . $divisionLabel . '.');
                }
            } else {
                flash('success', 'Rubric scores saved.');
            }
            redirect($scoreUrl($divisionId));
        }
        $scores = [];
        foreach ($posted as $tid => $by) {
            if (!is_array($by)) {
                continue;
            }
            foreach ($by as $cid => $val) {
                if (trim((string) $val) === '') {
                    continue;
                }
                $scores[(int) $tid][(int) $cid] = (float) $val;
            }
        }
    }

    if ($action === 'clear_scores') {
        $db->prepare('DELETE FROM intramural_event_rubric_scores WHERE sport_id = ? AND season_id = ? AND division_id = ?')
            ->execute([$sportId, $seasonId, $divisionId]);
        auditLog($_SESSION['user_id'], 'clear_event_rubric_scores', 'intramural_sport', $sportId, null, [
            'season_id' => $seasonId,
            'division_id' => $divisionId,
        ]);
        flash('success', 'Rubric scores cleared for this division.');
        redirect($scoreUrl($divisionId));
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

$totals = eventRubricTotalsFromScores($criteria, $scores);
$computedRanks = ranksFromRubricTotals(
    array_filter($totals, static fn($tid) => isset($scores[$tid]), ARRAY_FILTER_USE_KEY),
    $teamNames
);
$multiDivision = count($divisionGroups) > 1;
$canScoreSheet = $seasonId && canScoreEventRubrics($sportId) && !isResultsLocked(null, $sportId) && isViewingActiveSeason();
$canApplyRanks = $canScoreSheet && !$hasScheduledMatches;
$canEdit = $canScoreSheet;
$colCount = 2 + count($criteria) + 3;

$pageTitle = 'Judge — ' . sportLabel($sport);
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
echo renderResultsLockAlerts();
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="mb-1"><i class="bi bi-clipboard-check"></i> <?= sanitize($sport['name']) ?></h1>
        <p class="text-muted mb-0">
            <span class="badge bg-secondary"><?= sanitize(ucfirst((string) $sport['category'])) ?></span>
            <span class="badge bg-info text-dark"><?= sanitize(sportEventGroupLabel(sportEventGroupOf($sport))) ?></span>
            Max <?= formatRubricPoints($maxTotal) ?> pts
            <?php if ($season): ?> · <?= sanitize(seasonLabel($season)) ?><?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/intramurals/rubrics/index.php" class="btn btn-outline-secondary">All rubrics</a>
        <?php if (canManageEventRubricCriteria($sportId)): ?>
        <a href="<?= BASE_URL ?>/intramurals/rubrics/edit.php?sport=<?= $sportId ?>" class="btn btn-outline-primary"><i class="bi bi-sliders"></i> Edit criteria</a>
        <?php endif; ?>
        <a href="<?= sanitize($eventUrl('rankings', $divisionId)) ?>" class="btn btn-outline-warning">Official ranks</a>
    </div>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link" href="<?= sanitize($eventUrl('scores', $divisionId)) ?>"><i class="bi bi-trophy"></i> Scores</a>
    </li>
    <li class="nav-item">
        <a class="nav-link active" href="<?= sanitize($scoreUrl($divisionId)) ?>"><i class="bi bi-clipboard-check"></i> Rubrics</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="<?= sanitize($eventUrl('rankings', $divisionId)) ?>"><i class="bi bi-list-ol"></i> Rankings</a>
    </li>
</ul>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if (!$seasonId): ?>
<div class="alert alert-warning">Set an active season before entering rubric scores.</div>
<?php elseif ($criteria === []): ?>
<div class="alert alert-warning">
    This event has no rubric criteria.
    <?php if (canManageEventRubricCriteria($sportId)): ?>
    <a href="<?= BASE_URL ?>/intramurals/rubrics/edit.php?sport=<?= $sportId ?>" class="alert-link">Add criteria</a> first.
    <?php endif; ?>
</div>
<?php else: ?>

<div class="alert alert-secondary py-2">
    <i class="bi bi-info-circle"></i>
    Enter panel scores per criterion (0–max). Totals rank teams automatically when you save and apply ranks.
    Placement points follow <strong><?= sanitize($scheme['name'] ?? 'Default') ?></strong>
    (<?= sanitize(formatSchemePoints($scheme)) ?>).
</div>

<?php if (!empty($hasScheduledMatches)): ?>
<div class="alert alert-warning">
    Manual ranking is locked for <strong><?= sanitize($divisionLabel) ?></strong> because this division already has scheduled matches.
    Rubric scores can still be recorded, but they will not overwrite match-based placement.
</div>
<?php endif; ?>

<?php if ($multiDivision): ?>
<ul class="nav nav-pills flex-wrap gap-2 mb-3">
    <?php foreach ($divisionGroups as $key => $group): ?>
    <?php $key = (int) $key; ?>
    <li class="nav-item">
        <a class="nav-link <?= $divisionId === $key ? 'active' : '' ?>" href="<?= sanitize($scoreUrl($key)) ?>">
            <?= sanitize($group['division_name']) ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<form method="POST" id="rubricScoreForm">
    <?= csrfField() ?>
    <input type="hidden" name="sport_id" value="<?= $sportId ?>">
    <input type="hidden" name="division_id" value="<?= (int) $divisionId ?>">
            <input type="hidden" name="action" id="rubricAction" value="<?= $canApplyRanks ? 'save_and_rank' : 'save_scores' ?>">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span>Judging sheet · <strong><?= sanitize($divisionLabel) ?></strong></span>
            <small class="text-muted"><?= count($criteria) ?> criteria · <?= formatRubricPoints($maxTotal) ?> pts max</small>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0 rubric-sheet">
                <thead class="table-light">
                    <tr>
                        <th class="text-nowrap">Team</th>
                        <?php foreach ($criteria as $c): ?>
                        <th class="text-center" style="min-width:6.5rem;" title="<?= sanitize((string) ($c['description'] ?? '')) ?>">
                            <?= sanitize($c['name']) ?>
                            <div class="small fw-normal text-muted">/ <?= formatRubricPoints($c['max_points']) ?></div>
                        </th>
                        <?php endforeach; ?>
                        <th class="text-center">Total</th>
                        <th class="text-center">From rubric</th>
                        <th class="text-center">Official</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$listedIds): ?>
                    <tr><td colspan="<?= (int) $colCount ?>" class="text-muted p-4">No teams in this division for the selected event.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($listedIds as $tid): ?>
                    <?php
                    if (!isset($teamMap[$tid])) {
                        continue;
                    }
                    $t = $teamMap[$tid];
                    $onRoster = in_array($tid, $registeredIds, true);
                    $total = $totals[$tid] ?? 0.0;
                    $fromRubric = $computedRanks[$tid] ?? 0;
                    $official = (int) ($currentRanks[$tid]['place_rank'] ?? 0);
                    $medal = $official ? medalForRank($official) : ($fromRubric ? medalForRank($fromRubric) : null);
                    ?>
                    <tr data-team-row>
                        <td class="text-nowrap">
                            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= sanitize($t['color'] ?? '#888') ?>"></span>
                            <strong><?= sanitize($t['name']) ?></strong>
                            <?php if ($onRoster): ?>
                            <span class="badge bg-success ms-1">Roster</span>
                            <?php elseif ($registeredIds): ?>
                            <span class="badge bg-light text-muted border ms-1">Not on roster</span>
                            <?php endif; ?>
                        </td>
                        <?php foreach ($criteria as $c): ?>
                        <?php
                        $cid = (int) $c['id'];
                        $val = $scores[$tid][$cid] ?? '';
                        $valDisp = $val === '' ? '' : formatRubricPoints($val);
                        ?>
                        <td>
                            <input type="number" name="score[<?= $tid ?>][<?= $cid ?>]"
                                   class="form-control form-control-sm text-center rubric-score"
                                   min="0" max="<?= sanitize(formatRubricPoints($c['max_points'])) ?>" step="0.01"
                                   value="<?= sanitize($valDisp) ?>"
                                   <?= $canEdit ? '' : 'disabled' ?>
                                   data-max="<?= (float) $c['max_points'] ?>">
                        </td>
                        <?php endforeach; ?>
                        <td class="text-center fw-semibold rubric-total"><?= isset($scores[$tid]) ? formatRubricPoints($total) : '—' ?></td>
                        <td class="text-center">
                            <?php if ($fromRubric): ?>
                            <?= (int) $fromRubric ?>. <?= sanitize($labels[$fromRubric] ?? ('Rank ' . $fromRubric)) ?>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($official): ?>
                            <strong><?= $official ?></strong>
                            <?php if ($medal === 'gold'): ?><span class="badge bg-warning text-dark ms-1">Gold</span>
                            <?php elseif ($medal === 'silver'): ?><span class="badge bg-secondary ms-1">Silver</span>
                            <?php elseif ($medal === 'bronze'): ?><span class="badge bg-danger ms-1">Bronze</span>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canEdit): ?>
        <div class="card-footer d-flex justify-content-between flex-wrap gap-2">
            <div class="d-flex gap-2 flex-wrap">
                <?php if ($canApplyRanks): ?>
                <button type="submit" class="btn btn-primary" onclick="document.getElementById('rubricAction').value='save_and_rank'">
                    <i class="bi bi-save"></i> Save scores &amp; apply ranks
                </button>
                <?php endif; ?>
                <button type="submit" class="btn btn-<?= $canApplyRanks ? 'outline-primary' : 'primary' ?>" onclick="document.getElementById('rubricAction').value='save_scores'">
                    Save scores<?= $canApplyRanks ? ' only' : '' ?>
                </button>
            </div>
            <button type="submit" class="btn btn-outline-danger" onclick="document.getElementById('rubricAction').value='clear_scores'; return confirm('Clear rubric scores for this division?');">
                Clear scores
            </button>
        </div>
        <?php else: ?>
        <div class="card-footer text-muted small">
            <?php if (!isViewingActiveSeason()): ?>
            Historical seasons are view-only.
            <?php elseif (isResultsLocked(null, $sportId)): ?>
            Results are locked for this event.
            <?php elseif (!empty($hasScheduledMatches)): ?>
            Official ranks follow match results while this division has scheduled matches.
            <?php else: ?>
            You can view this judging sheet.
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</form>
<script>
(function () {
    function rowTotal(row) {
        let sum = 0;
        let any = false;
        row.querySelectorAll('.rubric-score').forEach(function (input) {
            const v = input.value.trim();
            if (v === '') return;
            const n = parseFloat(v);
            if (isNaN(n)) return;
            any = true;
            sum += n;
            const max = parseFloat(input.getAttribute('data-max') || '0');
            input.classList.toggle('is-invalid', n < 0 || (max && n - max > 0.001));
        });
        const cell = row.querySelector('.rubric-total');
        if (cell) cell.textContent = any ? (Math.round(sum * 100) / 100).toString() : '—';
    }
    document.querySelectorAll('[data-team-row]').forEach(function (row) {
        row.querySelectorAll('.rubric-score').forEach(function (input) {
            input.addEventListener('input', function () { rowTotal(row); });
        });
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
