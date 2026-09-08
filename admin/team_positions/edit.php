<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole(['admin']);
ensureIntramuralDivisionsSchema();
ensureEventTeamPositionsTable();

$db = getDB();
$divisionId = (int) get('division_id');
$division = getDivisionById($divisionId);
if (!$division) {
    flash('error', 'Division not found.');
    redirect(BASE_URL . '/admin/team_positions/index.php');
}

if (!divisionHasPositioning($divisionId)) {
    flash('error', 'Team positioning is only available for divisions with more than 2 teams.');
    redirect(BASE_URL . '/admin/team_positions/index.php');
}

$teamRows = $db->prepare('SELECT id, name, short_name FROM intramural_teams WHERE division_id = ? AND is_active = 1 ORDER BY name');
$teamRows->execute([$divisionId]);
$divisionTeams = $teamRows->fetchAll() ?: [];
$positionSlots = getDivisionPositionSlots($divisionId);
$teamCount = count($divisionTeams);

$sportIds = getDivisionSportIds($divisionId);
$sports = [];
if ($sportIds) {
    $placeholders = implode(',', array_fill(0, count($sportIds), '?'));
    $stmt = $db->prepare("SELECT * FROM intramural_sports WHERE id IN ($placeholders) ORDER BY " . intramuralSportsOrderBy());
    $stmt->execute($sportIds);
    $sports = $stmt->fetchAll() ?: [];
}

$positionsBySport = [];
foreach ($sports as $s) {
    $positionsBySport[(int) $s['id']] = getEventTeamPositions($divisionId, (int) $s['id']);
}

$teamNameById = [];
foreach ($divisionTeams as $t) {
    $label = (string) $t['name'];
    if (!empty($t['short_name'])) {
        $label .= ' (' . $t['short_name'] . ')';
    }
    $teamNameById[(int) $t['id']] = $label;
}

$errors = [];
$savedCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $posted = $_POST['positions'] ?? [];
    if (!is_array($posted)) {
        $posted = [];
    }

    foreach ($sports as $s) {
        $sid = (int) $s['id'];
        $raw = is_array($posted[$sid] ?? null) ? $posted[$sid] : [];
        $slots = [];
        foreach ($positionSlots as $slot) {
            $slots[$slot] = (int) ($raw[$slot] ?? 0);
        }
        $eventErrors = saveEventTeamPositions($divisionId, $sid, $slots);
        if ($eventErrors) {
            foreach ($eventErrors as $e) {
                $errors[] = sportLabel($s) . ': ' . $e;
            }
        } else {
            $savedCount++;
            $positionsBySport[$sid] = getEventTeamPositions($divisionId, $sid);
        }
    }

    if (empty($errors)) {
        auditLog((int) $_SESSION['user_id'], 'update', 'event_team_positions', $divisionId, null, [
            'division_id' => $divisionId,
            'events_saved' => $savedCount,
            'slot_count' => $teamCount,
        ]);
        flash('success', 'Team positions saved for ' . $savedCount . ' event' . ($savedCount === 1 ? '' : 's') . '.');
        redirect(BASE_URL . '/admin/team_positions/edit.php?division_id=' . $divisionId);
    }
}

$season = getCurrentSeason();
$seasonMeta = $season
    ? ('Season: ' . ($season['year_label'] ?? ('#' . (int) ($season['id'] ?? 0))) . ' · Division: ' . $division['name'])
    : ('Division: ' . $division['name']);

$pageTitle = 'Team Positions — ' . $division['name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
    <div>
        <h1><i class="bi bi-list-ol"></i> <?= sanitize($division['name']) ?> — Team Positions</h1>
        <p class="text-muted mb-0">
            Assign Team 1–<?= (int) $teamCount ?> for each event (one slot per team in this division).
            Match generation uses this exact order: Team 1 vs Team 2, Team 3 vs Team 4, and so on for first-round fixtures.
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (!empty($sports)): ?>
        <button type="button" class="btn btn-outline-secondary" onclick="printReport()">
            <i class="bi bi-printer"></i> Print / PDF
        </button>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/admin/team_positions/index.php" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

<?= renderReportHeader('Team Positions — ' . $division['name'], [
    'subtitle' => 'Event seeding order (Team 1 vs Team 2, Team 3 vs Team 4, …)',
    'meta' => $seasonMeta,
]) ?>

<?php if ($errors): ?>
<div class="alert alert-danger no-print">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if (empty($sports)): ?>
<div class="alert alert-warning no-print">
    This division has no events yet.
    <a href="<?= BASE_URL ?>/admin/divisions/edit.php?id=<?= $divisionId ?>">Assign events</a> first.
</div>
<?php else: ?>
<form method="POST" class="no-print">
    <?= csrfField() ?>
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div class="text-muted small">
            <?= (int) $teamCount ?> position slot<?= $teamCount === 1 ? '' : 's' ?>
            (matches team count) · <?= count($sports) ?> event<?= count($sports) === 1 ? '' : 's' ?>
        </div>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save all positions</button>
    </div>

    <div class="row g-3">
        <?php foreach ($sports as $s): ?>
        <?php
            $sid = (int) $s['id'];
            $pos = $positionsBySport[$sid] ?? [];
        ?>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-1">
                    <span><?= sanitize(sportLabel($s)) ?></span>
                    <span class="d-flex align-items-center gap-1">
                        <span class="badge bg-secondary" data-assigned-count>0 / <?= (int) $teamCount ?> set</span>
                        <span class="badge bg-info text-dark"><?= sanitize(tournamentFormatLabel($s['tournament_format'] ?? 'round_robin')) ?></span>
                    </span>
                </div>
                <div class="card-body" data-position-group="<?= $sid ?>">
                    <div class="row g-2">
                        <?php foreach ($positionSlots as $slot): ?>
                        <div class="col-6 col-md-4">
                            <label class="form-label small mb-1" for="pos_<?= $sid ?>_<?= $slot ?>">Team <?= (int) $slot ?></label>
                            <select class="form-select form-select-sm team-position-select" name="positions[<?= $sid ?>][<?= $slot ?>]" id="pos_<?= $sid ?>_<?= $slot ?>">
                                <option value="">— Not set —</option>
                                <?php foreach ($divisionTeams as $t): ?>
                                <option value="<?= (int) $t['id'] ?>" <?= (int) ($pos[$slot] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($t['name']) ?><?= !empty($t['short_name']) ? ' (' . sanitize($t['short_name']) . ')' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex gap-2 flex-wrap mt-2">
                        <button type="button" class="btn btn-sm btn-outline-primary" data-autofill>Auto-fill remaining</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-clear-group>Clear</button>
                    </div>
                    <div class="form-text mt-2">
                        A team picked for one position disappears from the other dropdowns in this event, so it cannot take two positions.
                        Leave a slot blank if it is unused.
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="mt-4">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save all positions</button>
        <a href="<?= BASE_URL ?>/admin/team_positions/index.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>

<div class="team-positions-print-report d-none d-print-block">
    <?php foreach ($sports as $s): ?>
    <?php
        $sid = (int) $s['id'];
        $pos = $positionsBySport[$sid] ?? [];
    ?>
    <section class="team-positions-print-event mb-4">
        <h2 class="h5 mb-2"><?= sanitize(sportLabel($s)) ?>
            <span class="text-muted fw-normal">· <?= sanitize(tournamentFormatLabel($s['tournament_format'] ?? 'round_robin')) ?></span>
        </h2>
        <table class="table table-sm table-bordered mb-0">
            <thead>
                <tr>
                    <th style="width: 7rem;">Position</th>
                    <th>Team</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($positionSlots as $slot): ?>
                <?php
                    $tid = (int) ($pos[$slot] ?? 0);
                    $teamLabel = $tid > 0 ? ($teamNameById[$tid] ?? ('Team #' . $tid)) : '— Not set —';
                ?>
                <tr>
                    <td>Team <?= (int) $slot ?></td>
                    <td><?= sanitize($teamLabel) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endforeach; ?>
</div>

<p class="text-muted small mt-3 no-print">For PDF: click Print / PDF and choose “Save as PDF”.</p>

<script>
(function () {
    function groupSelects(group) {
        return Array.prototype.slice.call(group.querySelectorAll('.team-position-select'));
    }

    function syncGroup(group) {
        var selects = groupSelects(group);
        var taken = {};
        var assigned = 0;

        selects.forEach(function (select) {
            if (select.value !== '') {
                taken[select.value] = true;
                assigned++;
            }
        });

        selects.forEach(function (select) {
            Array.prototype.forEach.call(select.options, function (option) {
                if (option.value === '') {
                    return;
                }
                var usedElsewhere = !!taken[option.value] && option.value !== select.value;
                option.disabled = usedElsewhere;
                option.hidden = usedElsewhere;
            });
        });

        var badge = group.parentElement.querySelector('[data-assigned-count]');
        if (badge) {
            badge.textContent = assigned + ' / ' + selects.length + ' set';
            badge.classList.toggle('bg-success', assigned === selects.length);
            badge.classList.toggle('bg-secondary', assigned !== selects.length);
        }
    }

    function fillRemaining(group) {
        var selects = groupSelects(group);
        var taken = {};

        selects.forEach(function (select) {
            if (select.value !== '') {
                taken[select.value] = true;
            }
        });

        selects.forEach(function (select) {
            if (select.value !== '') {
                return;
            }
            var next = Array.prototype.find.call(select.options, function (option) {
                return option.value !== '' && !taken[option.value];
            });
            if (next) {
                select.value = next.value;
                taken[next.value] = true;
            }
        });

        syncGroup(group);
    }

    document.querySelectorAll('[data-position-group]').forEach(function (group) {
        group.addEventListener('change', function (event) {
            if (event.target.classList.contains('team-position-select')) {
                syncGroup(group);
            }
        });

        var autofill = group.querySelector('[data-autofill]');
        if (autofill) {
            autofill.addEventListener('click', function () {
                fillRemaining(group);
            });
        }

        var clear = group.querySelector('[data-clear-group]');
        if (clear) {
            clear.addEventListener('click', function () {
                groupSelects(group).forEach(function (select) {
                    select.value = '';
                });
                syncGroup(group);
            });
        }

        syncGroup(group);
    });
})();
</script>
<?php endif; ?>

<?= renderReportFooter('Team Positions — ' . $division['name']) ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
