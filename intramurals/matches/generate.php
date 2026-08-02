<?php
require_once __DIR__ . '/../../includes/auth.php';
if (!canManageMatches()) {
    flash('error', 'You do not have permission to generate match fixtures.');
    redirect(BASE_URL . '/intramurals/matches/index.php');
}
requireWritableSeason();

$db = getDB();
$seasonId = getCurrentSeasonId();
$season = getCurrentSeason();

$sports = $db->query('SELECT * FROM intramural_sports WHERE is_active = 1 ORDER BY name, category')->fetchAll();
$teams = $db->query('SELECT * FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
$sportMap = [];
foreach ($sports as $s) {
    $sportMap[(int) $s['id']] = $s;
}

$selectedSportIds = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedSportIds = array_map('intval', $_POST['sport_ids'] ?? []);
} elseif (get('sport') !== '') {
    $selectedSportIds = [(int) get('sport')];
}

$teamMode = post('team_mode', 'shared'); // shared | roster
if (!in_array($teamMode, ['shared', 'roster'], true)) {
    $teamMode = 'shared';
}

$selectedTeams = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedTeams = array_map('intval', $_POST['team_ids'] ?? []);
} elseif (count($selectedSportIds) === 1 && $seasonId) {
    $stmt = $db->prepare('SELECT DISTINCT team_id FROM intramural_registrations WHERE sport_id = ? AND season_id = ?');
    $stmt->execute([$selectedSportIds[0], $seasonId]);
    $selectedTeams = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

$teamMap = [];
foreach ($teams as $t) {
    $teamMap[(int) $t['id']] = $t;
}

$errors = [];
$previewBySport = [];
$previewTotal = 0;

$regTeamsStmt = $db->prepare('SELECT DISTINCT team_id FROM intramural_registrations WHERE sport_id = ? AND season_id = ?');

foreach ($selectedSportIds as $sid) {
    if (!isset($sportMap[$sid])) {
        continue;
    }
    $sport = $sportMap[$sid];
    if ($teamMode === 'roster' && $seasonId) {
        $regTeamsStmt->execute([$sid, $seasonId]);
        $teamIds = array_map('intval', $regTeamsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } else {
        $teamIds = $selectedTeams;
    }
    $fixtures = count($teamIds) >= 2
        ? buildTournamentFixtures($sport['tournament_format'] ?? 'round_robin', $teamIds)
        : [];
    $previewBySport[$sid] = [
        'sport' => $sport,
        'team_ids' => $teamIds,
        'fixtures' => $fixtures,
    ];
    $previewTotal += count($fixtures);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'generate') {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/intramurals/matches/generate.php');
    }

    if (!$seasonId) {
        $errors[] = 'No active intramurals season configured.';
    }
    if (empty($selectedSportIds)) {
        $errors[] = 'Select at least one sport.';
    }
    if ($teamMode === 'shared' && count($selectedTeams) < 2) {
        $errors[] = 'Select at least 2 teams (or switch to “Use roster teams per sport”).';
    }

    if (empty($errors)) {
        $replace = post('replace_unscheduled') === '1';
        $shared = $teamMode === 'shared' ? $selectedTeams : null;
        $result = generateMatchesForSports($selectedSportIds, $seasonId, $shared, (int) $_SESSION['user_id'], $replace);

        auditLog($_SESSION['user_id'], 'generate_fixtures_multi', 'intramural_match', null, null, [
            'season_id' => $seasonId,
            'sport_ids' => $selectedSportIds,
            'team_mode' => $teamMode,
            'created' => $result['created'],
            'sports' => $result['sports'],
        ]);

        if ($result['created'] > 0) {
            $msg = $result['created'] . ' matches generated across ' . $result['sports'] . ' sport' . ($result['sports'] === 1 ? '' : 's') . '. Assign date & time next.';
            if ($result['errors']) {
                $msg .= ' Some sports skipped: ' . implode('; ', $result['errors']);
            }
            flash('success', $msg);
            redirect(BASE_URL . '/intramurals/matches/index.php?unscheduled=1');
        }

        $errors = $result['errors'] ?: ['No matches were generated.'];
    }
}

$pageTitle = 'Generate Match Fixtures';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-diagram-3"></i> Generate Matches</h1>
        <p class="text-muted mb-0">
            Create fixtures for one or many sports from each sport’s tournament style
            <?= $season ? ' · ' . sanitize(seasonLabel($season)) : '' ?>
        </p>
    </div>
    <a href="<?= BASE_URL ?>/intramurals/matches/index.php" class="btn btn-outline-secondary">Back to Matches</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="POST" id="generateForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="generate" id="formAction">
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><strong>1. Sports &amp; teams</strong></div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label mb-0">Sports *</label>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary" onclick="document.querySelectorAll('.sport-check').forEach(c=>c.checked=true); updatePreview()">Select all</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="document.querySelectorAll('.sport-check').forEach(c=>c.checked=false); updatePreview()">Clear</button>
                            </div>
                        </div>
                        <div class="border rounded p-2" style="max-height:220px;overflow:auto">
                            <?php foreach ($sports as $s): ?>
                            <div class="form-check">
                                <input class="form-check-input sport-check" type="checkbox" name="sport_ids[]" value="<?= $s['id'] ?>" id="sport<?= $s['id'] ?>"
                                    <?= in_array((int) $s['id'], $selectedSportIds, true) ? 'checked' : '' ?>
                                    onchange="updatePreview()">
                                <label class="form-check-label" for="sport<?= $s['id'] ?>">
                                    <?= sanitize(sportLabel($s)) ?>
                                    <span class="badge bg-info text-dark ms-1"><?= sanitize(tournamentFormatLabel($s['tournament_format'] ?? 'round_robin')) ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Team source *</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="team_mode" id="modeShared" value="shared" <?= $teamMode === 'shared' ? 'checked' : '' ?> onchange="toggleTeamMode()">
                            <label class="form-check-label" for="modeShared">Same teams for all selected sports</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="team_mode" id="modeRoster" value="roster" <?= $teamMode === 'roster' ? 'checked' : '' ?> onchange="toggleTeamMode()">
                            <label class="form-check-label" for="modeRoster">Use registered teams per sport (season roster)</label>
                        </div>
                    </div>

                    <div class="mb-3" id="sharedTeamsBlock">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label mb-0">Participating teams *</label>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.team-check').forEach(c=>c.checked=true); updatePreview()">Select all</button>
                        </div>
                        <div class="border rounded p-2" style="max-height:220px;overflow:auto">
                            <?php foreach ($teams as $t): ?>
                            <div class="form-check">
                                <input class="form-check-input team-check" type="checkbox" name="team_ids[]" value="<?= $t['id'] ?>" id="team<?= $t['id'] ?>"
                                    <?= in_array((int) $t['id'], $selectedTeams, true) ? 'checked' : '' ?>
                                    onchange="updatePreview()">
                                <label class="form-check-label" for="team<?= $t['id'] ?>" style="color:<?= sanitize($t['color']) ?>">
                                    <?= sanitize($t['name']) ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="replace_unscheduled" value="1" id="replaceUnscheduled">
                        <label class="form-check-label" for="replaceUnscheduled">
                            Replace existing unplayed generated matches for each selected sport
                        </label>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary" id="generateBtn" <?= $previewTotal ? '' : 'disabled' ?>>
                            <i class="bi bi-magic"></i> Generate <span id="generateCount"><?= (int) $previewTotal ?></span> Match<?= $previewTotal === 1 ? '' : 'es' ?>
                        </button>
                        <button type="submit" class="btn btn-outline-secondary" onclick="document.getElementById('formAction').value='preview'">
                            Refresh preview
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between">
                    <strong>2. Preview by sport</strong>
                    <span class="text-muted small"><span id="previewTotalLabel"><?= (int) $previewTotal ?></span> total · no dates yet</span>
                </div>
                <div class="card-body p-0" id="previewPanel">
                    <?php if (empty($selectedSportIds)): ?>
                    <p class="text-muted p-3 mb-0">Select one or more sports to preview fixtures.</p>
                    <?php elseif ($previewTotal === 0): ?>
                    <p class="text-muted p-3 mb-0">No fixtures yet. Choose at least 2 teams, or use roster mode with registered teams.</p>
                    <?php else: ?>
                        <?php foreach ($previewBySport as $block): ?>
                        <?php if (empty($block['fixtures'])) continue; ?>
                        <div class="border-bottom p-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <strong><?= sanitize(sportLabel($block['sport'])) ?></strong>
                                    <span class="badge bg-info text-dark ms-1"><?= sanitize(tournamentFormatLabel($block['sport']['tournament_format'] ?? 'round_robin')) ?></span>
                                </div>
                                <span class="text-muted small"><?= count($block['fixtures']) ?> matches · <?= count($block['team_ids']) ?> teams</span>
                            </div>
                            <div class="table-responsive" style="max-height:220px;overflow:auto">
                                <table class="table table-sm mb-0">
                                    <thead class="table-light sticky-top">
                                        <tr><th>#</th><th>Round</th><th>Team A</th><th>Team B</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($block['fixtures'] as $f): ?>
                                        <tr>
                                            <td><?= (int) $f['match_order'] ?></td>
                                            <td><?= sanitize($f['round_label']) ?></td>
                                            <td><?= $f['team_a_id'] && isset($teamMap[$f['team_a_id']]) ? sanitize($teamMap[$f['team_a_id']]['name']) : '<span class="text-muted">TBD</span>' ?></td>
                                            <td><?= $f['team_b_id'] && isset($teamMap[$f['team_b_id']]) ? sanitize($teamMap[$f['team_b_id']]['name']) : '<span class="text-muted">TBD</span>' ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function toggleTeamMode() {
    const shared = document.getElementById('modeShared').checked;
    document.getElementById('sharedTeamsBlock').style.display = shared ? '' : 'none';
}
function updatePreview() {
    // Client hint only — full preview refreshes on submit
    const sports = document.querySelectorAll('.sport-check:checked').length;
    const btn = document.getElementById('generateBtn');
    if (sports === 0) {
        btn.disabled = true;
    }
}
toggleTeamMode();
document.getElementById('generateForm').addEventListener('submit', function () {
    if (document.activeElement && document.activeElement.name === 'action') {
        // ok
    }
});
// Reset action to generate after preview clicks set it
document.querySelectorAll('button[type=submit]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        if (btn.getAttribute('onclick') && btn.getAttribute('onclick').includes('preview')) {
            document.getElementById('formAction').value = 'preview';
        } else {
            document.getElementById('formAction').value = 'generate';
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
