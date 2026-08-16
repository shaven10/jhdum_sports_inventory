<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
ensureTmRankingAccessTable();
ensureEventRanksTable();
ensureIntramuralDivisionsSchema();

if (!canManageEventRankings()) {
    flash('error', 'Event rankings are not available for your account. Ask an administrator to activate ranking for your assigned events.');
    redirect(getHomeUrl());
}

$db = getDB();
$seasonId = getCurrentSeasonId();
$season = getCurrentSeason();
$sportId = (int) (get('sport') ?: post('sport_id'));
$divisionParam = get('division', post('division_id', ''));
$sports = filterSportsForUser($db->query('SELECT * FROM intramural_sports ORDER BY name, category')->fetchAll());

if (isTournamentManager() && !isAdmin() && !isSecretariat()) {
    $enabledIds = getTmRankingEnabledSportIds($seasonId ? (int) $seasonId : null);
    $sports = array_values(array_filter(
        $sports,
        static fn(array $s): bool => in_array((int) $s['id'], $enabledIds, true)
    ));
}

$teams = $db->query('SELECT * FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
$teamMap = [];
foreach ($teams as $t) {
    $teamMap[(int) $t['id']] = $t;
}

if (!$sportId && $sports) {
    $sportId = (int) $sports[0]['id'];
} elseif ($sportId && !canViewEvent($sportId)) {
    flash('error', 'You do not have permission to rank this event.');
    redirect(BASE_URL . '/intramurals/rankings/index.php');
} elseif ($sportId && !canManageEventRankings($sportId) && isTournamentManager() && !isAdmin() && !isSecretariat()) {
    flash('error', 'Ranking is not activated for this event. Contact an administrator.');
    redirect(BASE_URL . '/intramurals/rankings/index.php');
}

$sport = null;
foreach ($sports as $s) {
    if ((int) $s['id'] === $sportId) {
        $sport = $s;
        break;
    }
}

$divisionGroups = $sportId ? groupEligibleTeamsByDivisionForSport($sportId) : [];
if ($divisionGroups === [] && $sportId) {
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

$hasScheduledMatches = $sportId && $seasonId && eventHasScheduledMatches($sportId, (int) $seasonId, $divisionId);
$canEdit = $sportId && canManageEventRankings($sportId) && $seasonId && !isResultsLocked(null, $sportId) && !$hasScheduledMatches;
$scheme = $sport ? getPointSchemeForSport($sport) : defaultPointScheme();
$labels = placementLabels();
$errors = [];

$registeredIds = $sportId && $seasonId ? getEventParticipatingTeamIds($sportId, (int) $seasonId, $divisionId) : [];
$currentRanks = $sportId && $seasonId ? getEventRanks($sportId, (int) $seasonId, $divisionId) : [];

$rankRedirect = static function (int $sportId, int $divisionId): string {
    return BASE_URL . '/intramurals/rankings/index.php?sport=' . $sportId . '&division=' . $divisionId;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect($rankRedirect($sportId ?: (int) post('sport_id'), (int) post('division_id', '0')));
    }
    requireWritableSeason();
    requireUnlockedResults(null, (int) post('sport_id'));
    $sportId = (int) post('sport_id');
    $divisionId = eventRankDivisionKey((int) post('division_id', '0'));
    if (!canManageEventRankings($sportId)) {
        flash('error', 'You do not have permission to save event ranks for this event.');
        redirect(BASE_URL . '/intramurals/rankings/index.php');
    }
    if (eventHasScheduledMatches($sportId, (int) $seasonId, $divisionId)) {
        flash('error', 'Manual ranking is not allowed for this division while it has scheduled matches. Placement follows match results.');
        redirect($rankRedirect($sportId, $divisionId));
    }

    $action = post('action', 'save');
    if ($action === 'clear') {
        $errors = saveEventRanks($sportId, (int) $seasonId, [], (int) $_SESSION['user_id'], $divisionId);
        if (!$errors) {
            auditLog($_SESSION['user_id'], 'clear_event_ranks', 'intramural_sport', $sportId, null, [
                'season_id' => $seasonId,
                'division_id' => $divisionId,
            ]);
            flash('success', 'Ranks cleared for this division. Medal tally will use match results again if any exist.');
            redirect($rankRedirect($sportId, $divisionId));
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
            flash('success', $saved . ' team rank' . ($saved === 1 ? '' : 's') . ' saved for ' . $divisionLabel . '. Medal tally and overall standing are updated.');
            redirect($rankRedirect($sportId, $divisionId));
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
// Prefer roster order, then alphabetically by team name
usort($listedIds, static function ($a, $b) use ($teamMap, $registeredIds) {
    $aOn = in_array($a, $registeredIds, true);
    $bOn = in_array($b, $registeredIds, true);
    if ($aOn !== $bOn) {
        return $aOn ? -1 : 1;
    }
    $an = $teamMap[$a]['name'] ?? '';
    $bn = $teamMap[$b]['name'] ?? '';
    return strcasecmp($an, $bn);
});
$maxPlace = max(6, count($listedIds));
$multiDivision = count($divisionGroups) > 1;

$pageTitle = 'Event Rankings';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
echo renderResultsLockAlerts();
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-list-ol"></i> Event Rankings</h1>
        <p class="text-muted mb-0">Rank teams per division for events without a match schedule — Champion / runners-up feed the medal tally</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/intramurals/standings/overall.php" class="btn btn-outline-warning"><i class="bi bi-trophy"></i> Medal Tally</a>
        <a href="<?= BASE_URL ?>/intramurals/standings/index.php<?= $sportId ? '?sport=' . $sportId : '' ?>" class="btn btn-outline-primary">Per-Sport Standings</a>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="alert alert-secondary py-2">
    <i class="bi bi-info-circle"></i>
    Assign places <strong>separately for each division</strong> (e.g. High School and College can each have a Champion).
    Placement points follow the event’s point scheme and are included in Overall Standing.
</div>

<?php if (!empty($hasScheduledMatches)): ?>
<div class="alert alert-warning">
    <i class="bi bi-lock"></i>
    Manual ranking is locked for <strong><?= sanitize($divisionLabel) ?></strong> because this division already has scheduled matches.
    Placement follows <a href="<?= BASE_URL ?>/intramurals/matches/index.php?sport=<?= (int) $sportId ?>" class="alert-link">match results</a>.
</div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end" id="rankFilterForm">
            <div class="col-md-5">
                <label class="form-label" for="rankSport">Event</label>
                <select name="sport" id="rankSport" class="form-select" onchange="this.form.submit()" <?= empty($sports) ? 'disabled' : '' ?>>
                    <?php foreach ($sports as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $sportId === (int) $s['id'] ? 'selected' : '' ?>><?= sanitize(sportLabel($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="rankDivision">Division</label>
                <select name="division" id="rankDivision" class="form-select" onchange="this.form.submit()" <?= empty($divisionGroups) ? 'disabled' : '' ?>>
                    <?php foreach ($divisionGroups as $key => $group): ?>
                    <option value="<?= (int) $key ?>" <?= $divisionId === (int) $key ? 'selected' : '' ?>>
                        <?= sanitize($group['division_name']) ?>
                        (<?= count($group['team_ids']) ?> team<?= count($group['team_ids']) === 1 ? '' : 's' ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($sport): ?>
            <div class="col-md-3">
                <div class="form-text mb-0">
                    Scheme: <strong><?= sanitize($scheme['name'] ?? 'Default') ?></strong>
                    (<?= sanitize(formatSchemePoints($scheme)) ?>)
                    <?php if ($season): ?><br><?= sanitize(seasonLabel($season)) ?><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($multiDivision && $sportId): ?>
<ul class="nav nav-pills flex-wrap gap-2 mb-3">
    <?php foreach ($divisionGroups as $key => $group): ?>
    <?php
    $key = (int) $key;
    $divHasMatches = $seasonId && eventHasScheduledMatches($sportId, (int) $seasonId, $key);
    $divHasRanks = $seasonId && eventHasManualRanks($sportId, (int) $seasonId, $key);
    ?>
    <li class="nav-item">
        <a class="nav-link <?= $divisionId === $key ? 'active' : '' ?>"
           href="<?= BASE_URL ?>/intramurals/rankings/index.php?sport=<?= $sportId ?>&division=<?= $key ?>">
            <?= sanitize($group['division_name']) ?>
            <?php if ($divHasRanks): ?><span class="badge text-bg-light text-success border ms-1">Ranked</span><?php endif; ?>
            <?php if ($divHasMatches): ?><span class="badge text-bg-warning ms-1">Matches</span><?php endif; ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if (!$sport): ?>
<div class="alert alert-info">No events available.</div>
<?php elseif (!$seasonId): ?>
<div class="alert alert-warning">Set an active season before entering ranks.</div>
<?php else: ?>
<form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="sport_id" value="<?= (int) $sportId ?>">
    <input type="hidden" name="division_id" value="<?= (int) $divisionId ?>">
    <input type="hidden" name="action" id="rankAction" value="save">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span>
                <?= sanitize(sportLabel($sport)) ?>
                <span class="text-muted">·</span>
                <strong><?= sanitize($divisionLabel) ?></strong>
            </span>
            <small class="text-muted">
                <?php if ($registeredIds): ?>
                <?= count($registeredIds) ?> team<?= count($registeredIds) === 1 ? '' : 's' ?> on roster in this division
                <?php else: ?>
                No roster yet — rank houses in this division
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
                    <tr class="<?= $onRoster || $place ? '' : 'table-light' ?>">
                        <td>
                            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= sanitize($t['color'] ?? '#888') ?>"></span>
                            <strong><?= sanitize($t['name']) ?></strong>
                            <?php if ($onRoster): ?>
                            <span class="badge bg-success ms-1">Roster</span>
                            <?php elseif (!$registeredIds): ?>
                            <?php else: ?>
                            <span class="badge bg-light text-muted border ms-1">Not on roster</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <select name="rank[<?= $tid ?>]" class="form-select form-select-sm rank-select" <?= $canEdit ? '' : 'disabled' ?>>
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
        <?php if ($canEdit): ?>
        <div class="card-footer d-flex justify-content-between flex-wrap gap-2">
            <button type="submit" class="btn btn-primary" onclick="document.getElementById('rankAction').value='save'">
                <i class="bi bi-save"></i> Save ranks for <?= sanitize($divisionLabel) ?>
            </button>
            <button type="submit" class="btn btn-outline-danger" onclick="document.getElementById('rankAction').value='clear'; return confirm('Clear saved ranks for <?= sanitize($divisionLabel) ?> only?');">
                Clear this division
            </button>
        </div>
        <?php else: ?>
        <div class="card-footer text-muted small">
            <?php if (!isViewingActiveSeason()): ?>
            Historical seasons are view-only.
            <?php elseif (isResultsLocked(null, $sportId ?: null)): ?>
            Results are locked<?= $sportId ? ' for this event' : '' ?>. Unlock in Admin → Lock Results to edit ranks.
            <?php elseif (!empty($hasScheduledMatches)): ?>
            Manual ranking is disabled while this division has scheduled matches.
            <?php elseif (isTournamentManager() && !isAdmin() && !isSecretariat()): ?>
            Ranking is not activated for this event. Ask an administrator to enable it under Admin → TM Ranking Access.
            <?php else: ?>
            You can view ranks. Only administrators, secretariat, or activated tournament managers can edit event rankings.
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</form>
<?php endif; ?>

<script>
(function () {
    const selects = document.querySelectorAll('.rank-select');
    function warnDuplicates() {
        const used = {};
        let dup = false;
        selects.forEach(function (sel) {
            sel.classList.remove('is-invalid');
            const v = sel.value;
            if (!v || v === '0') return;
            if (used[v]) {
                sel.classList.add('is-invalid');
                used[v].classList.add('is-invalid');
                dup = true;
            } else {
                used[v] = sel;
            }
        });
        return !dup;
    }
    selects.forEach(function (sel) {
        sel.addEventListener('change', warnDuplicates);
    });
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
