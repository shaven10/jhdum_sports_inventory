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

$sports = $db->query('SELECT * FROM intramural_sports ORDER BY name, category')->fetchAll();
$teams = $db->query('SELECT * FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
$slotLetters = ['A', 'B', 'C', 'D'];
$sportMap = [];
foreach ($sports as $s) {
    $sportMap[(int) $s['id']] = $s;
}

$schedulePresets = [
    'season' => [
        'label' => 'Use season dates (7:00 AM start)',
        'start_date' => $season['start_date'] ?? '',
        'end_date' => $season['end_date'] ?? '',
        'start_hour' => 7,
        'end_hour' => 17,
    ],
    'aug24_1pm' => [
        'label' => 'Start August 24, 2026 at 1:00 PM',
        'start_date' => '2026-08-24',
        'end_date' => !empty($season['end_date']) && $season['end_date'] >= '2026-08-24'
            ? $season['end_date']
            : '2026-08-31',
        'start_hour' => 13,
        'end_hour' => 17,
    ],
];

$schedulePreset = post('schedule_preset', 'aug24_1pm');
if ($schedulePreset !== 'custom' && !isset($schedulePresets[$schedulePreset])) {
    $schedulePreset = 'aug24_1pm';
}

if ($schedulePreset !== 'custom' && isset($schedulePresets[$schedulePreset])) {
    $preset = $schedulePresets[$schedulePreset];
    $scheduleStartDate = $preset['start_date'];
    $scheduleEndDate = $preset['end_date'];
    $scheduleStartHour = (int) $preset['start_hour'];
    $scheduleEndHour = (int) $preset['end_hour'];
} else {
    $scheduleStartDate = post('schedule_start_date', $season['start_date'] ?? '');
    $scheduleEndDate = post('schedule_end_date', $season['end_date'] ?? '');
    $scheduleStartHour = (int) post('schedule_start_hour', '7');
    $scheduleEndHour = (int) post('schedule_end_hour', '17');
}

if ($scheduleStartHour < 0 || $scheduleStartHour > 23) {
    $scheduleStartHour = 7;
}
if ($scheduleEndHour <= $scheduleStartHour || $scheduleEndHour > 24) {
    $scheduleEndHour = 17;
}

$scheduleSlots = buildScheduleSlots(
    $scheduleStartDate ?: null,
    $scheduleEndDate ?: null,
    $scheduleStartHour,
    $scheduleEndHour
);
$scheduleSlotPreview = getNextScheduleSlotIndex($seasonId ?: 0, $scheduleSlots);

$selectedSportIds = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedSportIds = array_map('intval', $_POST['sport_ids'] ?? []);
} elseif (get('sport') !== '') {
    $selectedSportIds = [(int) get('sport')];
}

$teamMode = post('team_mode', 'per_sport');
if (!in_array($teamMode, ['shared', 'roster', 'per_sport'], true)) {
    $teamMode = 'per_sport';
}

/**
 * Normalize Team A–D slot assignments into unique team IDs.
 *
 * @param array<string, mixed> $rawSlots letter => team id
 * @return array{slots: array<string, ?int>, team_ids: list<int>, duplicates: bool}
 */
$normalizeSlots = static function (array $rawSlots) use ($slotLetters): array {
    $slots = [];
    $seen = [];
    $duplicates = false;
    foreach ($slotLetters as $letter) {
        $id = (int) ($rawSlots[$letter] ?? 0);
        if ($id <= 0) {
            $slots[$letter] = null;
            continue;
        }
        if (isset($seen[$id])) {
            $duplicates = true;
            $slots[$letter] = null;
            continue;
        }
        $seen[$id] = true;
        $slots[$letter] = $id;
    }
    return [
        'slots' => $slots,
        'team_ids' => array_values(array_filter($slots)),
        'duplicates' => $duplicates,
    ];
};

$defaultSlotMap = [];
foreach ($slotLetters as $i => $letter) {
    $defaultSlotMap[$letter] = isset($teams[$i]) ? (int) $teams[$i]['id'] : null;
}

$selectedTeams = [];
$teamsBySport = [];
$slotsBySport = [];
$sharedSlots = $defaultSlotMap;
$slotErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawShared = $_POST['shared_slots'] ?? [];
    $sharedParsed = $normalizeSlots(is_array($rawShared) ? $rawShared : []);
    $sharedSlots = $sharedParsed['slots'];
    $selectedTeams = $sharedParsed['team_ids'];
    if ($sharedParsed['duplicates']) {
        $slotErrors[] = 'Shared Team A–D: each house can only be assigned once.';
    }

    $rawSportSlots = $_POST['sport_slots'] ?? [];
    foreach ($selectedSportIds as $sid) {
        $parsed = $normalizeSlots(is_array($rawSportSlots[$sid] ?? null) ? $rawSportSlots[$sid] : []);
        $slotsBySport[$sid] = $parsed['slots'];
        $teamsBySport[$sid] = $parsed['team_ids'];
        if ($parsed['duplicates'] && isset($sportMap[$sid])) {
            $slotErrors[] = sportLabel($sportMap[$sid]) . ': each house can only be assigned once across Team A–D.';
        }
    }
} else {
    $selectedTeams = array_values(array_filter($defaultSlotMap));
    $sharedSlots = $defaultSlotMap;
    foreach ($selectedSportIds as $sid) {
        $slotMap = $defaultSlotMap;
        if ($seasonId) {
            $stmt = $db->prepare('SELECT DISTINCT team_id FROM intramural_registrations WHERE sport_id = ? AND season_id = ? ORDER BY team_id');
            $stmt->execute([$sid, $seasonId]);
            $regTeams = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            if (count($regTeams) >= 2) {
                $slotMap = ['A' => null, 'B' => null, 'C' => null, 'D' => null];
                foreach ($slotLetters as $i => $letter) {
                    $slotMap[$letter] = $regTeams[$i] ?? null;
                }
            }
        }
        $slotsBySport[$sid] = $slotMap;
        $teamsBySport[$sid] = array_values(array_filter($slotMap));
    }
}

$teamMap = [];
foreach ($teams as $t) {
    $teamMap[(int) $t['id']] = $t;
}

$errors = $slotErrors;
$previewBySport = [];
$previewTotal = 0;

$regTeamsStmt = $db->prepare('SELECT DISTINCT team_id FROM intramural_registrations WHERE sport_id = ? AND season_id = ?');
$previewSlotIndex = $scheduleSlotPreview;

foreach ($selectedSportIds as $sid) {
    if (!isset($sportMap[$sid])) {
        continue;
    }
    $sport = $sportMap[$sid];
    if ($teamMode === 'per_sport') {
        $teamIds = $teamsBySport[$sid] ?? [];
        $slotMap = $slotsBySport[$sid] ?? $defaultSlotMap;
    } elseif ($teamMode === 'roster' && $seasonId) {
        $regTeamsStmt->execute([$sid, $seasonId]);
        $teamIds = array_map('intval', $regTeamsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $slotMap = ['A' => null, 'B' => null, 'C' => null, 'D' => null];
        foreach ($slotLetters as $i => $letter) {
            $slotMap[$letter] = $teamIds[$i] ?? null;
        }
    } else {
        $teamIds = $selectedTeams;
        $slotMap = $sharedSlots;
    }
    $fixtures = count($teamIds) >= 2
        ? buildTournamentFixtures($sport['tournament_format'] ?? 'round_robin', $teamIds)
        : [];
    if ($fixtures && $scheduleSlots) {
        applyAutoScheduleToFixtures($fixtures, $scheduleSlots, $previewSlotIndex);
    }
    $previewBySport[$sid] = [
        'sport' => $sport,
        'team_ids' => $teamIds,
        'slots' => $slotMap,
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
        $errors[] = 'Assign at least 2 teams in Team A–D (or switch team source).';
    }
    if ($teamMode === 'per_sport') {
        foreach ($selectedSportIds as $sid) {
            $sportTeams = $teamsBySport[$sid] ?? [];
            if (count($sportTeams) < 2) {
                $label = isset($sportMap[$sid]) ? sportLabel($sportMap[$sid]) : 'Sport #' . $sid;
                $errors[] = $label . ': assign at least 2 teams in Team A–D.';
            }
        }
    }

    if (empty($errors)) {
        $replace = post('replace_unscheduled') === '1';
        $shared = $teamMode === 'shared' ? $selectedTeams : null;
        $perSport = $teamMode === 'per_sport' ? $teamsBySport : null;
        $scheduleOptions = [
            'start_date' => $scheduleStartDate ?: null,
            'end_date' => $scheduleEndDate ?: null,
            'start_hour' => $scheduleStartHour,
            'end_hour' => $scheduleEndHour,
        ];
        $result = generateMatchesForSports($selectedSportIds, $seasonId, $shared, (int) $_SESSION['user_id'], $replace, $perSport, $scheduleOptions);

        auditLog($_SESSION['user_id'], 'generate_fixtures_multi', 'intramural_match', null, null, [
            'season_id' => $seasonId,
            'sport_ids' => $selectedSportIds,
            'team_mode' => $teamMode,
            'slots_by_sport' => $slotsBySport,
            'schedule_options' => $scheduleOptions,
            'created' => $result['created'],
            'scheduled' => $result['scheduled'],
            'sports' => $result['sports'],
        ]);

        if ($result['created'] > 0) {
            $msg = $result['created'] . ' matches generated across ' . $result['sports'] . ' sport' . ($result['sports'] === 1 ? '' : 's');
            if ($result['scheduled'] > 0) {
                $msg .= ', ' . $result['scheduled'] . ' auto-scheduled (you can edit any date/time afterward)';
            }
            if ($result['errors']) {
                $msg .= '. Some sports skipped: ' . implode('; ', $result['errors']);
            }
            flash('success', $msg . '.');
            redirect(BASE_URL . '/intramurals/matches/index.php');
        }

        $errors = array_merge($errors, $result['errors'] ?: ['No matches were generated.']);
    }
}

$pageTitle = 'Generate Match Fixtures';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';

$renderSlotSelects = static function (string $namePrefix, array $slotMap, array $teams, array $slotLetters) {
    ob_start();
    ?>
    <div class="row g-2">
        <?php foreach ($slotLetters as $letter): ?>
        <div class="col-6">
            <label class="form-label small mb-1">Team <?= sanitize($letter) ?></label>
            <select class="form-select form-select-sm sport-slot-select" name="<?= sanitize($namePrefix) ?>[<?= sanitize($letter) ?>]" onchange="updatePreview()">
                <option value="">— Not playing —</option>
                <?php foreach ($teams as $t): ?>
                <option value="<?= (int) $t['id'] ?>" <?= (int) ($slotMap[$letter] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>>
                    <?= sanitize($t['name']) ?><?= $t['short_name'] ? ' (' . sanitize($t['short_name']) . ')' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
};
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

<?php if ($scheduleStartDate && $scheduleEndDate): ?>
<div class="alert alert-info py-2">
    <i class="bi bi-calendar-range"></i>
    Auto-schedule window:
    <strong><?= formatDate($scheduleStartDate) ?></strong> → <strong><?= formatDate($scheduleEndDate) ?></strong>,
    hourly <strong><?= sprintf('%02d:00', $scheduleStartHour) ?>–<?= sprintf('%02d:00', $scheduleEndHour) ?></strong>
    (<?= count($scheduleSlots) ?> slot<?= count($scheduleSlots) === 1 ? '' : 's' ?>).
    You can change this window below, and edit any match date/time later — it does not have to stay within the season dates.
</div>
<?php else: ?>
<div class="alert alert-warning py-2">
    <i class="bi bi-exclamation-triangle"></i>
    Set a schedule start/end date below (or on the season) so matches can be auto-timed. You can still edit each match to any date afterward.
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="POST" id="generateForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="generate" id="formAction">
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><strong>1. Sports &amp; Team A–D</strong></div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label mb-0">Sports / Events *</label>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary" onclick="document.querySelectorAll('.sport-check').forEach(c=>c.checked=true); toggleSportTeams(); updatePreview()">Select all</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="document.querySelectorAll('.sport-check').forEach(c=>c.checked=false); toggleSportTeams(); updatePreview()">Clear</button>
                            </div>
                        </div>
                        <div class="border rounded p-2" style="max-height:220px;overflow:auto">
                            <?php foreach ($sports as $s): ?>
                            <div class="form-check">
                                <input class="form-check-input sport-check" type="checkbox" name="sport_ids[]" value="<?= $s['id'] ?>" id="sport<?= $s['id'] ?>"
                                    <?= in_array((int) $s['id'], $selectedSportIds, true) ? 'checked' : '' ?>
                                    onchange="toggleSportTeams(); updatePreview()">
                                <label class="form-check-label" for="sport<?= $s['id'] ?>">
                                    <?= sanitize(sportLabel($s)) ?>
                                    <span class="badge bg-info text-dark ms-1"><?= sanitize(tournamentFormatLabel($s['tournament_format'] ?? 'round_robin')) ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">How to assign Team A–D *</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="team_mode" id="modePerSport" value="per_sport" <?= $teamMode === 'per_sport' ? 'checked' : '' ?> onchange="toggleTeamMode()">
                            <label class="form-check-label" for="modePerSport"><strong>Assign Team A–D per event</strong> (any house per slot)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="team_mode" id="modeShared" value="shared" <?= $teamMode === 'shared' ? 'checked' : '' ?> onchange="toggleTeamMode()">
                            <label class="form-check-label" for="modeShared">Same Team A–D for all selected events</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="team_mode" id="modeRoster" value="roster" <?= $teamMode === 'roster' ? 'checked' : '' ?> onchange="toggleTeamMode()">
                            <label class="form-check-label" for="modeRoster">Use registered teams per event (season roster)</label>
                        </div>
                    </div>

                    <div class="mb-3" id="perSportTeamsBlock">
                        <label class="form-label mb-1">Team A–D per event</label>
                        <div class="form-text mb-2">
                            Each slot (A–D) can be any house. Leave a slot empty if that team is not playing this event.
                        </div>
                        <div id="perSportTeamsList" style="max-height:360px;overflow:auto">
                            <?php foreach ($sports as $s): ?>
                            <?php
                            $sid = (int) $s['id'];
                            $slotMap = $slotsBySport[$sid] ?? $defaultSlotMap;
                            ?>
                            <div class="sport-teams-panel card mb-2" data-sport-id="<?= $sid ?>" style="display:none">
                                <div class="card-body py-2 px-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
                                        <div>
                                            <div class="fw-semibold"><?= sanitize(sportLabel($s)) ?></div>
                                            <span class="badge bg-info text-dark"><?= sanitize(tournamentFormatLabel($s['tournament_format'] ?? 'round_robin')) ?></span>
                                        </div>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary" onclick="fillSportSlots(<?= $sid ?>)">Fill A–D</button>
                                            <button type="button" class="btn btn-outline-secondary" onclick="clearSportSlots(<?= $sid ?>)">Clear</button>
                                        </div>
                                    </div>
                                    <?= $renderSlotSelects("sport_slots[$sid]", $slotMap, $teams, $slotLetters) ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <p class="text-muted small mb-0 border rounded p-3" id="perSportEmptyHint">Select one or more events above to assign Team A–D for each.</p>
                        </div>
                    </div>

                    <div class="mb-3" id="sharedTeamsBlock" style="display:none">
                        <label class="form-label mb-1">Shared Team A–D (all events)</label>
                        <div class="form-text mb-2">Pick any house for each slot. These apply to every selected event.</div>
                        <?= $renderSlotSelects('shared_slots', $sharedSlots, $teams, $slotLetters) ?>
                    </div>

                    <div class="mb-3 border rounded p-3 bg-light">
                        <label class="form-label mb-1"><i class="bi bi-clock"></i> Auto-schedule window</label>
                        <div class="form-text mb-2">
                            Choose a preset or set custom dates/hours. After generating, each match date/time can still be edited freely.
                        </div>
                        <div class="mb-2">
                            <?php foreach ($schedulePresets as $key => $preset): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="schedule_preset" id="preset_<?= sanitize($key) ?>"
                                    value="<?= sanitize($key) ?>" <?= $schedulePreset === $key ? 'checked' : '' ?>
                                    onchange="applySchedulePreset('<?= sanitize($key) ?>')">
                                <label class="form-check-label" for="preset_<?= sanitize($key) ?>">
                                    <?= sanitize($preset['label']) ?>
                                    <?php if ($key === 'aug24_1pm'): ?>
                                    <span class="badge bg-primary ms-1">1:00 PM</span>
                                    <?php endif; ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="schedule_preset" id="preset_custom"
                                    value="custom" <?= $schedulePreset === 'custom' ? 'checked' : '' ?>
                                    onchange="applySchedulePreset('custom')">
                                <label class="form-check-label" for="preset_custom">Custom dates &amp; hours</label>
                            </div>
                        </div>
                        <div id="customScheduleFields" class="row g-2">
                            <div class="col-6">
                                <label class="form-label small mb-0">Start date</label>
                                <input type="date" name="schedule_start_date" id="scheduleStartDate" class="form-control form-control-sm" value="<?= sanitize($scheduleStartDate) ?>" oninput="markCustomSchedule()">
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-0">End date</label>
                                <input type="date" name="schedule_end_date" id="scheduleEndDate" class="form-control form-control-sm" value="<?= sanitize($scheduleEndDate) ?>" oninput="markCustomSchedule()">
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-0">From hour</label>
                                <select name="schedule_start_hour" id="scheduleStartHour" class="form-select form-select-sm" onchange="markCustomSchedule()">
                                    <?php for ($h = 0; $h <= 22; $h++): ?>
                                    <option value="<?= $h ?>" <?= $scheduleStartHour === $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-0">Until hour</label>
                                <select name="schedule_end_hour" id="scheduleEndHour" class="form-select form-select-sm" onchange="markCustomSchedule()">
                                    <?php for ($h = 1; $h <= 24; $h++): ?>
                                    <option value="<?= $h ?>" <?= $scheduleEndHour === $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
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
                    <strong>2. Preview by event</strong>
                    <span class="text-muted small">
                        <span id="previewTotalLabel"><?= (int) $previewTotal ?></span> total
                        <?php if ($scheduleSlots): ?> · auto times (editable later)<?php else: ?> · set schedule window for auto times<?php endif; ?>
                    </span>
                </div>
                <div class="card-body p-0" id="previewPanel">
                    <?php if (empty($selectedSportIds)): ?>
                    <p class="text-muted p-3 mb-0">Select one or more events to preview fixtures.</p>
                    <?php elseif ($previewTotal === 0): ?>
                    <p class="text-muted p-3 mb-0">No fixtures yet. Assign at least 2 teams in Team A–D per event, then refresh preview.</p>
                    <?php else: ?>
                        <?php foreach ($previewBySport as $block): ?>
                        <?php if (empty($block['fixtures'])) continue; ?>
                        <?php
                        $participantLabels = [];
                        foreach ($slotLetters as $letter) {
                            $tid = (int) ($block['slots'][$letter] ?? 0);
                            if ($tid && isset($teamMap[$tid])) {
                                $participantLabels[] = 'Team ' . $letter . ' = ' . $teamMap[$tid]['name'];
                            }
                        }
                        ?>
                        <div class="border-bottom p-3">
                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-1">
                                <div>
                                    <strong><?= sanitize(sportLabel($block['sport'])) ?></strong>
                                    <span class="badge bg-info text-dark ms-1"><?= sanitize(tournamentFormatLabel($block['sport']['tournament_format'] ?? 'round_robin')) ?></span>
                                    <?php if ($participantLabels): ?>
                                    <div class="small text-muted mt-1"><?= sanitize(implode(' · ', $participantLabels)) ?></div>
                                    <?php endif; ?>
                                </div>
                                <span class="text-muted small"><?= count($block['fixtures']) ?> matches · <?= count($block['team_ids']) ?> teams</span>
                            </div>
                            <div class="table-responsive" style="max-height:220px;overflow:auto">
                                <table class="table table-sm mb-0">
                                    <thead class="table-light sticky-top">
                                        <tr><th>#</th><th>Round</th><th>Schedule</th><th>Side 1</th><th>Side 2</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($block['fixtures'] as $f): ?>
                                        <tr>
                                            <td><?= (int) $f['match_order'] ?></td>
                                            <td><?= sanitize($f['round_label']) ?></td>
                                            <td class="small text-nowrap">
                                                <?php if (!empty($f['scheduled_at'])): ?>
                                                <?= formatDateTime($f['scheduled_at']) ?>
                                                <?php else: ?>
                                                <span class="text-muted">TBD</span>
                                                <?php endif; ?>
                                            </td>
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
const defaultTeamOrder = <?= json_encode(array_map(static fn($t) => (int) $t['id'], $teams)) ?>;
const slotLetters = <?= json_encode($slotLetters) ?>;
const schedulePresets = <?= json_encode($schedulePresets) ?>;

function applySchedulePreset(key) {
    const customRadio = document.getElementById('preset_custom');
    if (key === 'custom') {
        if (customRadio) customRadio.checked = true;
        return;
    }
    const preset = schedulePresets[key];
    if (!preset) return;
    const radio = document.getElementById('preset_' + key);
    if (radio) radio.checked = true;
    document.getElementById('scheduleStartDate').value = preset.start_date || '';
    document.getElementById('scheduleEndDate').value = preset.end_date || '';
    document.getElementById('scheduleStartHour').value = String(preset.start_hour);
    document.getElementById('scheduleEndHour').value = String(preset.end_hour);
}
function markCustomSchedule() {
    const custom = document.getElementById('preset_custom');
    if (custom) custom.checked = true;
}

function toggleTeamMode() {
    const perSport = document.getElementById('modePerSport').checked;
    const shared = document.getElementById('modeShared').checked;
    document.getElementById('perSportTeamsBlock').style.display = perSport ? '' : 'none';
    document.getElementById('sharedTeamsBlock').style.display = shared ? '' : 'none';
    if (perSport) toggleSportTeams();
}
function toggleSportTeams() {
    const selected = new Set(Array.from(document.querySelectorAll('.sport-check:checked')).map(c => c.value));
    let any = false;
    document.querySelectorAll('.sport-teams-panel').forEach(function (panel) {
        const show = selected.has(panel.getAttribute('data-sport-id'));
        panel.style.display = show ? '' : 'none';
        if (show) any = true;
    });
    const hint = document.getElementById('perSportEmptyHint');
    if (hint) hint.style.display = any ? 'none' : '';
}
function fillSportSlots(sportId) {
    const panel = document.querySelector('.sport-teams-panel[data-sport-id="' + sportId + '"]');
    if (!panel) return;
    const selects = panel.querySelectorAll('select.sport-slot-select');
    selects.forEach(function (sel, i) {
        sel.value = defaultTeamOrder[i] ? String(defaultTeamOrder[i]) : '';
    });
    updatePreview();
}
function clearSportSlots(sportId) {
    const panel = document.querySelector('.sport-teams-panel[data-sport-id="' + sportId + '"]');
    if (!panel) return;
    panel.querySelectorAll('select.sport-slot-select').forEach(function (sel) {
        sel.value = '';
    });
    updatePreview();
}
function updatePreview() {
    const sports = document.querySelectorAll('.sport-check:checked').length;
    const btn = document.getElementById('generateBtn');
    if (sports === 0) {
        btn.disabled = true;
    }
}
toggleTeamMode();
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
