<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
requireStandingsAccess();
ensureEventRanksTable();

$db = getDB();
$seasonId = getCurrentSeasonId();
$season = getCurrentSeason();
$sportId = (int) (get('sport') ?: post('sport_id'));
$sports = filterSportsForUser($db->query('SELECT * FROM intramural_sports ORDER BY name, category')->fetchAll());
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
}

$sport = null;
foreach ($sports as $s) {
    if ((int) $s['id'] === $sportId) {
        $sport = $s;
        break;
    }
}

$canEdit = $sportId && canManageEventMatches($sportId) && $seasonId;
$hasScheduledMatches = $sportId && $seasonId && eventHasScheduledMatches($sportId, (int) $seasonId);
if ($hasScheduledMatches) {
    $canEdit = false;
}
$scheme = $sport ? getPointSchemeForSport($sport) : defaultPointScheme();
$labels = placementLabels();
$errors = [];

$registeredIds = $sportId && $seasonId ? getEventParticipatingTeamIds($sportId, (int) $seasonId) : [];
$currentRanks = $sportId && $seasonId ? getEventRanks($sportId, (int) $seasonId) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/intramurals/rankings/index.php' . ($sportId ? '?sport=' . $sportId : ''));
    }
    requireWritableSeason();
    $sportId = (int) post('sport_id');
    if (!canManageEventMatches($sportId)) {
        flash('error', 'You do not have permission to save ranks for this event.');
        redirect(BASE_URL . '/intramurals/rankings/index.php');
    }
    if (eventHasScheduledMatches($sportId, (int) $seasonId)) {
        flash('error', 'Manual ranking is not allowed for events that already have scheduled matches. Placement follows match results.');
        redirect(BASE_URL . '/intramurals/rankings/index.php?sport=' . $sportId);
    }

    $action = post('action', 'save');
    if ($action === 'clear') {
        $errors = saveEventRanks($sportId, (int) $seasonId, [], (int) $_SESSION['user_id']);
        if (!$errors) {
            auditLog($_SESSION['user_id'], 'clear_event_ranks', 'intramural_sport', $sportId, null, ['season_id' => $seasonId]);
            flash('success', 'Event ranks cleared. Medal tally will use match results again if any exist.');
            redirect(BASE_URL . '/intramurals/rankings/index.php?sport=' . $sportId);
        }
    } else {
        $raw = $_POST['rank'] ?? [];
        $ranksByTeam = [];
        if (is_array($raw)) {
            foreach ($raw as $tid => $place) {
                $ranksByTeam[(int) $tid] = (int) $place;
            }
        }
        $errors = saveEventRanks($sportId, (int) $seasonId, $ranksByTeam, (int) $_SESSION['user_id']);
        if (!$errors) {
            $saved = count(array_filter($ranksByTeam, static fn($p) => (int) $p > 0));
            auditLog($_SESSION['user_id'], 'save_event_ranks', 'intramural_sport', $sportId, null, [
                'season_id' => $seasonId,
                'ranks' => $ranksByTeam,
            ]);
            flash('success', $saved . ' team rank' . ($saved === 1 ? '' : 's') . ' saved. Medal tally and overall standing are updated.');
            redirect(BASE_URL . '/intramurals/rankings/index.php?sport=' . $sportId);
        }
        $currentRanks = [];
        foreach ($ranksByTeam as $tid => $place) {
            if ((int) $place > 0) {
                $currentRanks[(int) $tid] = ['team_id' => (int) $tid, 'place_rank' => (int) $place, 'notes' => null];
            }
        }
    }
}

$listedIds = $registeredIds;
foreach ($teams as $t) {
    $tid = (int) $t['id'];
    if (!in_array($tid, $listedIds, true)) {
        $listedIds[] = $tid;
    }
}
$maxPlace = max(6, count($listedIds));

$pageTitle = 'Event Rankings';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-list-ol"></i> Event Rankings</h1>
        <p class="text-muted mb-0">Manually rank teams for events without a match schedule — Champion / runners-up feed the medal tally</p>
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
    Assign <strong>1st (Champion / Gold)</strong>, <strong>2nd (Silver)</strong>, and <strong>3rd (Bronze)</strong> for events that do not have scheduled matches.
    Placement points follow the event’s point scheme and are included in Overall Standing.
    Saved ranks override automatic match-based order for medals.
</div>

<?php if (!empty($hasScheduledMatches)): ?>
<div class="alert alert-warning">
    <i class="bi bi-lock"></i>
    Manual ranking is locked for this event because it already has scheduled matches.
    Placement follows <a href="<?= BASE_URL ?>/intramurals/matches/index.php?sport=<?= (int) $sportId ?>" class="alert-link">match results</a>.
</div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label" for="rankSport">Event</label>
                <select name="sport" id="rankSport" class="form-select" onchange="this.form.submit()" <?= empty($sports) ? 'disabled' : '' ?>>
                    <?php foreach ($sports as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $sportId === (int) $s['id'] ? 'selected' : '' ?>><?= sanitize(sportLabel($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($sport): ?>
            <div class="col-md-6">
                <div class="form-text mb-0">
                    Scheme: <strong><?= sanitize($scheme['name'] ?? 'Default') ?></strong>
                    (<?= sanitize(formatSchemePoints($scheme)) ?>)
                    · Style: <?= sanitize(tournamentFormatLabel($sport['tournament_format'] ?? 'round_robin')) ?>
                    <?php if ($season): ?> · <?= sanitize(seasonLabel($season)) ?><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if (!$sport): ?>
<div class="alert alert-info">No events available.</div>
<?php elseif (!$seasonId): ?>
<div class="alert alert-warning">Set an active season before entering ranks.</div>
<?php else: ?>
<form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="sport_id" value="<?= (int) $sportId ?>">
    <input type="hidden" name="action" id="rankAction" value="save">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span>Participating teams — <?= sanitize(sportLabel($sport)) ?></span>
            <small class="text-muted">
                <?php if ($registeredIds): ?>
                <?= count($registeredIds) ?> team<?= count($registeredIds) === 1 ? '' : 's' ?> on roster
                <?php else: ?>
                No roster yet — rank any participating house
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
                <i class="bi bi-save"></i> Save ranks to medal tally
            </button>
            <button type="submit" class="btn btn-outline-danger" onclick="document.getElementById('rankAction').value='clear'; return confirm('Clear all saved ranks for this event?');">
                Clear ranks
            </button>
        </div>
        <?php else: ?>
        <div class="card-footer text-muted small">
            <?php if (!isViewingActiveSeason()): ?>
            Historical seasons are view-only.
            <?php elseif (!empty($hasScheduledMatches)): ?>
            Manual ranking is disabled while this event has scheduled matches.
            <?php else: ?>
            You can view ranks. Tournament managers and intramurals staff can edit this event.
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
                alert('Each place can only be assigned to one team.');
            }
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
