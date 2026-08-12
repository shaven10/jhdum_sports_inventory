<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
ensurePlayersPerEventColumn();
ensureSportCategoryEnum();
ensureSportVenueColumn();

$db = getDB();
$formState = null;
if (!empty($_SESSION['sport_form'])) {
    $formState = $_SESSION['sport_form'];
    unset($_SESSION['sport_form']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && canManageIntramurals()) {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/intramurals/sports/index.php');
    }

    $action = post('action');

    if ($action === 'add' || $action === 'edit') {
        $id = (int) post('id');
        $name = post('name');
        $category = post('category', 'men');
        $description = post('description');
        $scoring = post('scoring_method', 'points');
        $rules = post('rules');
        $scheduleNotes = post('schedule_notes');
        $venue = post('venue');
        $tournamentFormat = post('tournament_format', 'round_robin');
        $formatNotes = post('format_notes');
        $winPoints = max(0, (int) post('win_points', '3'));
        $drawPoints = max(0, (int) post('draw_points', '1'));
        $lossPoints = max(0, (int) post('loss_points', '0'));
        $pointSchemeId = (int) post('point_scheme_id') ?: null;
        $playersPerEvent = post('players_per_event') !== '' ? max(1, (int) post('players_per_event')) : null;
        $errors = [];

        if ($name === '') {
            $errors[] = 'Sport name is required.';
        }
        if (!array_key_exists($category, sportCategoryOptions())) {
            $errors[] = 'Invalid category.';
        }
        if (!array_key_exists($tournamentFormat, tournamentFormatLabels())) {
            $errors[] = 'Invalid tournament format.';
        }
        if ($playersPerEvent !== null && $playersPerEvent < 1) {
            $errors[] = 'Players per event must be at least 1.';
        }

        if (empty($errors)) {
            if ($action === 'add') {
                try {
                    $stmt = $db->prepare('INSERT INTO intramural_sports (name, description, category, players_per_event, scoring_method, rules, schedule_notes, venue, tournament_format, format_notes, win_points, draw_points, loss_points, point_scheme_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$name, $description, $category, $playersPerEvent, $scoring, $rules, $scheduleNotes, $venue ?: null, $tournamentFormat, $formatNotes ?: null, $winPoints, $drawPoints, $lossPoints, $pointSchemeId]);
                    auditLog($_SESSION['user_id'], 'create', 'intramural_sport', (int) $db->lastInsertId(), null, ['name' => $name, 'category' => $category, 'tournament_format' => $tournamentFormat, 'venue' => $venue]);
                    flash('success', 'Sport added successfully.');
                    redirect(BASE_URL . '/intramurals/sports/index.php');
                } catch (PDOException $e) {
                    $errors[] = 'Sport with this name and category already exists.';
                }
            } else {
                try {
                    $stmt = $db->prepare('UPDATE intramural_sports SET name=?, description=?, category=?, players_per_event=?, scoring_method=?, rules=?, schedule_notes=?, venue=?, tournament_format=?, format_notes=?, win_points=?, draw_points=?, loss_points=?, point_scheme_id=? WHERE id=?');
                    $stmt->execute([$name, $description, $category, $playersPerEvent, $scoring, $rules, $scheduleNotes, $venue ?: null, $tournamentFormat, $formatNotes ?: null, $winPoints, $drawPoints, $lossPoints, $pointSchemeId, $id]);
                    auditLog($_SESSION['user_id'], 'update', 'intramural_sport', $id, null, ['name' => $name, 'tournament_format' => $tournamentFormat, 'venue' => $venue]);
                    flash('success', 'Sport updated successfully.');
                    redirect(BASE_URL . '/intramurals/sports/index.php');
                } catch (PDOException $e) {
                    $errors[] = 'Could not update sport (duplicate name/category?).';
                }
            }
        }

        if (!empty($errors)) {
            $_SESSION['sport_form'] = [
                'mode' => $action,
                'id' => $id,
                'errors' => $errors,
                'data' => [
                    'name' => $name,
                    'category' => $category,
                    'description' => $description,
                    'scoring_method' => $scoring,
                    'rules' => $rules,
                    'schedule_notes' => $scheduleNotes,
                    'venue' => $venue,
                    'tournament_format' => $tournamentFormat,
                    'format_notes' => $formatNotes,
                    'win_points' => $winPoints,
                    'draw_points' => $drawPoints,
                    'loss_points' => $lossPoints,
                    'point_scheme_id' => $pointSchemeId,
                    'players_per_event' => $playersPerEvent ?? '',
                ],
            ];
            redirect(BASE_URL . '/intramurals/sports/index.php' . ($action === 'edit' && $id ? '?edit=' . $id : ''));
        }
    }

    if ($action === 'delete') {
        $id = (int) post('id');
        $db->prepare('DELETE FROM intramural_sports WHERE id = ?')->execute([$id]);
        auditLog($_SESSION['user_id'], 'delete', 'intramural_sport', $id);
        flash('success', 'Sport removed.');
        redirect(BASE_URL . '/intramurals/sports/index.php');
    }
}

$editId = (int) get('edit');
$seasonId = getCurrentSeasonId();
$regCount = $seasonId
    ? '(SELECT COUNT(*) FROM intramural_registrations r WHERE r.sport_id = s.id AND r.season_id = ' . (int) $seasonId . ')'
    : '(SELECT COUNT(*) FROM intramural_registrations r WHERE r.sport_id = s.id)';
$matchCount = $seasonId
    ? '(SELECT COUNT(*) FROM intramural_matches m WHERE m.sport_id = s.id AND m.season_id = ' . (int) $seasonId . ')'
    : '(SELECT COUNT(*) FROM intramural_matches m WHERE m.sport_id = s.id)';
$sports = $db->query("SELECT s.*, ps.name as scheme_name, ps.points_1, ps.points_2, ps.points_3, ps.points_4, ps.points_5, ps.points_6,
    $regCount as athlete_count,
    $matchCount as match_count
    FROM intramural_sports s
    LEFT JOIN intramural_point_schemes ps ON s.point_scheme_id = ps.id
    ORDER BY s.name, s.category")->fetchAll();
$pointSchemes = getAllPointSchemes(true);

$formDefaults = [
    'name' => '',
    'category' => 'men',
    'description' => '',
    'scoring_method' => 'points',
    'rules' => '',
    'schedule_notes' => '',
    'venue' => '',
    'tournament_format' => 'round_robin',
    'format_notes' => '',
    'win_points' => 3,
    'draw_points' => 1,
    'loss_points' => 0,
    'point_scheme_id' => '',
    'players_per_event' => '',
];
$formData = $formDefaults;
$formErrors = [];
$openModal = '';

if ($formState) {
    $openModal = $formState['mode'];
    $formErrors = $formState['errors'] ?? [];
    $formData = array_merge($formDefaults, $formState['data'] ?? []);
    if ($openModal === 'edit' && !empty($formState['id'])) {
        $editId = (int) $formState['id'];
    }
} elseif ($editId) {
    $openModal = 'edit';
    foreach ($sports as $sport) {
        if ((int) $sport['id'] === $editId) {
            $formData = [
                'name' => $sport['name'],
                'category' => $sport['category'],
                'description' => $sport['description'] ?? '',
                'scoring_method' => $sport['scoring_method'],
                'rules' => $sport['rules'] ?? '',
                'schedule_notes' => $sport['schedule_notes'] ?? '',
                'venue' => $sport['venue'] ?? '',
                'tournament_format' => $sport['tournament_format'] ?? 'round_robin',
                'format_notes' => $sport['format_notes'] ?? '',
                'win_points' => (int) $sport['win_points'],
                'draw_points' => (int) $sport['draw_points'],
                'loss_points' => (int) $sport['loss_points'],
                'point_scheme_id' => $sport['point_scheme_id'] ?? '',
                'players_per_event' => $sport['players_per_event'] ?? '',
            ];
            break;
        }
    }
}

$pageTitle = 'Sports Management';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <div>
        <h1 class="mb-1"><i class="bi bi-trophy"></i> Sports Management</h1>
        <p class="text-muted mb-0"><?= count($sports) ?> sport<?= count($sports) === 1 ? '' : 's' ?> · tournament styles and placement point schemes</p>
    </div>
    <div class="d-flex flex-wrap gap-2 ms-auto">
        <a href="<?= BASE_URL ?>/intramurals/points/index.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-calculator"></i> Point System</a>
        <?php if (canManageIntramurals()): ?>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#sportModal" data-sport-mode="add">
            <i class="bi bi-plus-lg"></i> Add Sport
        </button>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/intramurals/index.php" class="btn btn-sm btn-outline-secondary">Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Sport</th>
                        <th>Category</th>
                        <th>Players/Event</th>
                        <th>Venue</th>
                        <th>Tournament Style</th>
                        <th>Placement Scheme</th>
                        <th>Match W/D/L</th>
                        <th>Athletes</th>
                        <th>Matches</th>
                        <?php if (canManageIntramurals()): ?><th class="text-end" style="width: 9rem;">Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sports)): ?>
                    <tr><td colspan="<?= canManageIntramurals() ? 10 : 9 ?>" class="text-center text-muted py-4">No sports yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($sports as $s): ?>
                    <?php
                    $sportPayload = htmlspecialchars(json_encode([
                        'id' => (int) $s['id'],
                        'name' => $s['name'],
                        'category' => $s['category'],
                        'description' => $s['description'] ?? '',
                        'scoring_method' => $s['scoring_method'],
                        'rules' => $s['rules'] ?? '',
                        'schedule_notes' => $s['schedule_notes'] ?? '',
                        'venue' => $s['venue'] ?? '',
                        'tournament_format' => $s['tournament_format'] ?? 'round_robin',
                        'format_notes' => $s['format_notes'] ?? '',
                        'win_points' => (int) $s['win_points'],
                        'draw_points' => (int) $s['draw_points'],
                        'loss_points' => (int) $s['loss_points'],
                        'point_scheme_id' => $s['point_scheme_id'] ?? '',
                        'players_per_event' => $s['players_per_event'] ?? '',
                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr>
                        <td>
                            <strong><?= sanitize($s['name']) ?></strong>
                            <?php if ($s['rules']): ?><br><small class="text-muted"><?= sanitize(strlen($s['rules']) > 60 ? substr($s['rules'], 0, 57) . '...' : $s['rules']) ?></small><?php endif; ?>
                        </td>
                        <td><?= ucfirst($s['category']) ?></td>
                        <td>
                            <?php if (!empty($s['players_per_event'])): ?>
                            <?= (int) $s['players_per_event'] ?>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= sanitize($s['venue'] ?: '—') ?></td>
                        <td>
                            <span class="badge bg-info text-dark"><?= sanitize(tournamentFormatLabel($s['tournament_format'] ?? 'round_robin')) ?></span>
                            <?php if (!empty($s['format_notes'])): ?>
                            <br><small class="text-muted"><?= sanitize(strlen($s['format_notes']) > 50 ? substr($s['format_notes'], 0, 47) . '...' : $s['format_notes']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= sanitize($s['scheme_name'] ?: 'Default') ?>
                            <br><small class="text-muted"><?= $s['scheme_name'] ? sanitize(formatSchemePoints($s)) : '10/7/5/3/2/1' ?></small>
                        </td>
                        <td><?= (int) $s['win_points'] ?>/<?= (int) $s['draw_points'] ?>/<?= (int) $s['loss_points'] ?></td>
                        <td><?= (int) $s['athlete_count'] ?></td>
                        <td><?= (int) $s['match_count'] ?></td>
                        <?php if (canManageIntramurals()): ?>
                        <td class="text-end text-nowrap">
                            <button type="button"
                                    class="btn btn-sm btn-outline-primary btn-edit-sport"
                                    data-bs-toggle="modal"
                                    data-bs-target="#sportModal"
                                    data-sport-mode="edit"
                                    data-sport="<?= $sportPayload ?>"
                                    title="Edit sport">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Remove this sport? Related registrations and matches will also be deleted." title="Remove"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (canManageIntramurals()): ?>
<div class="modal fade" id="sportModal" tabindex="-1" aria-labelledby="sportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" id="sportForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" id="sportAction" value="add">
                <input type="hidden" name="id" id="sportId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="sportModalLabel"><i class="bi bi-plus-lg"></i> Add Sport</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="sportFormErrors" class="alert alert-danger d-none"></div>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label" for="sportNameInput">Sport Name *</label>
                            <input type="text" name="name" id="sportNameInput" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="sportCategory">Category *</label>
                            <select name="category" id="sportCategory" class="form-select" required>
                                <?php foreach (sportCategoryOptions() as $val => $label): ?>
                                <option value="<?= $val ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="playersPerEvent">Players per Event</label>
                            <input type="number" name="players_per_event" id="playersPerEvent" class="form-control" min="1" placeholder="e.g. 12">
                            <div class="form-text">Max players per team for this event. Leave blank for no limit.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="scoringMethod">Scoring Method</label>
                            <select name="scoring_method" id="scoringMethod" class="form-select">
                                <?php foreach (['points', 'sets', 'games', 'time'] as $m): ?>
                                <option value="<?= $m ?>"><?= ucfirst($m) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="pointSchemeId">Placement Point Scheme</label>
                            <select name="point_scheme_id" id="pointSchemeId" class="form-select">
                                <option value="">Default (10/7/5/3/2/1)</option>
                                <?php foreach ($pointSchemes as $ps): ?>
                                <option value="<?= $ps['id'] ?>"><?= sanitize($ps['name']) ?> (<?= sanitize(formatSchemePoints($ps)) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Used for overall rankings (Champion → 5th Runner Up).</div>
                        </div>
                        <div class="col-4">
                            <label class="form-label" for="winPoints">Match Win</label>
                            <input type="number" name="win_points" id="winPoints" class="form-control" min="0" value="3">
                        </div>
                        <div class="col-4">
                            <label class="form-label" for="drawPoints">Match Draw</label>
                            <input type="number" name="draw_points" id="drawPoints" class="form-control" min="0" value="1">
                        </div>
                        <div class="col-4">
                            <label class="form-label" for="lossPoints">Match Loss</label>
                            <input type="number" name="loss_points" id="lossPoints" class="form-control" min="0" value="0">
                        </div>
                        <div class="col-12">
                            <div class="form-text">Match W/D/L points only rank teams within this event.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="sportDescription">Description</label>
                            <textarea name="description" id="sportDescription" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="sportRules">Game Rules</label>
                            <textarea name="rules" id="sportRules" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="sportVenue">Venue</label>
                            <input type="text" name="venue" id="sportVenue" class="form-control" placeholder="e.g. Gymnasium Court A">
                            <div class="form-text">Default venue used when generating matches for this event.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="scheduleNotes">Event Schedule Notes</label>
                            <textarea name="schedule_notes" id="scheduleNotes" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="tournamentFormatSelect">Agreed Tournament Style *</label>
                            <select name="tournament_format" class="form-select" required id="tournamentFormatSelect">
                                <?php foreach (tournamentFormatLabels() as $val => $label): ?>
                                <option value="<?= $val ?>"><?= sanitize($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" id="sdsFormatHint" style="display:none">
                                <strong>Team Play SDS (Single Elimination)</strong> — single-elim team bracket;
                                each tie is Singles → Doubles → Singles (best of 3).
                                Recommended for Badminton, Table Tennis, and Lawn Tennis.
                            </div>
                            <div class="form-text">Tabulators use this when generating match fixtures.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="formatNotes">Format Notes (agreed details)</label>
                            <textarea name="format_notes" id="formatNotes" class="form-control" rows="2" placeholder="e.g. Best of 3, top 4 advance, seeding rules"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="sportFormSubmit">Add Sport</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const modalEl = document.getElementById('sportModal');
    const form = document.getElementById('sportForm');
    const sportAction = document.getElementById('sportAction');
    const sportId = document.getElementById('sportId');
    const modalTitle = document.getElementById('sportModalLabel');
    const formSubmit = document.getElementById('sportFormSubmit');
    const errorsBox = document.getElementById('sportFormErrors');
    const nameInput = document.getElementById('sportNameInput');
    const formatSelect = document.getElementById('tournamentFormatSelect');
    const sdsHint = document.getElementById('sdsFormatHint');
    const racketSports = <?= json_encode(racketSdsSportNames()) ?>;
    let allowAutoSds = true;

    function syncSdsUi() {
        const name = (nameInput.value || '').trim();
        const isRacket = racketSports.some(function (s) { return s.toLowerCase() === name.toLowerCase(); });
        const isSds = formatSelect.value === 'team_play_sds';
        if (sdsHint) sdsHint.style.display = isSds ? '' : 'none';
        if (allowAutoSds && isRacket && formatSelect.value === 'round_robin') {
            formatSelect.value = 'team_play_sds';
            if (sdsHint) sdsHint.style.display = '';
        }
    }

    function showErrors(errors) {
        if (!errors || !errors.length) {
            errorsBox.classList.add('d-none');
            return;
        }
        errorsBox.innerHTML = '<ul class="mb-0">' + errors.map(function (e) {
            return '<li>' + String(e).replace(/</g, '&lt;') + '</li>';
        }).join('') + '</ul>';
        errorsBox.classList.remove('d-none');
    }

    function setMode(mode, data) {
        data = data || {};
        sportAction.value = mode;
        sportId.value = data.id || '';
        form.reset();
        allowAutoSds = mode === 'add';

        if (mode === 'add') {
            modalTitle.innerHTML = '<i class="bi bi-plus-lg"></i> Add Sport';
            formSubmit.textContent = 'Add Sport';
        } else {
            modalTitle.innerHTML = '<i class="bi bi-pencil"></i> Edit Sport';
            formSubmit.textContent = 'Update Sport';
            allowAutoSds = false;
        }

        nameInput.value = data.name || '';
        document.getElementById('sportCategory').value = data.category || 'men';
        document.getElementById('playersPerEvent').value = data.players_per_event ?? '';
        document.getElementById('scoringMethod').value = data.scoring_method || 'points';
        document.getElementById('pointSchemeId').value = data.point_scheme_id || '';
        document.getElementById('winPoints').value = data.win_points ?? 3;
        document.getElementById('drawPoints').value = data.draw_points ?? 1;
        document.getElementById('lossPoints').value = data.loss_points ?? 0;
        document.getElementById('sportDescription').value = data.description || '';
        document.getElementById('sportRules').value = data.rules || '';
        document.getElementById('scheduleNotes').value = data.schedule_notes || '';
        document.getElementById('sportVenue').value = data.venue || '';
        formatSelect.value = data.tournament_format || 'round_robin';
        document.getElementById('formatNotes').value = data.format_notes || '';

        syncSdsUi();
        showErrors([]);
    }

    document.querySelectorAll('[data-bs-target="#sportModal"]').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            const mode = this.dataset.sportMode || 'add';
            let data = {};
            if (mode === 'edit' && this.dataset.sport) {
                try {
                    data = JSON.parse(this.dataset.sport);
                } catch (e) {
                    data = {};
                }
            }
            setMode(mode, data);
        });
    });

    nameInput.addEventListener('input', syncSdsUi);
    formatSelect.addEventListener('change', function () {
        allowAutoSds = false;
        syncSdsUi();
    });

    modalEl.addEventListener('shown.bs.modal', function () {
        const body = modalEl.querySelector('.modal-body');
        if (body) {
            body.scrollTop = 0;
        }
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
        if (window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.delete('edit');
            window.history.replaceState({}, '', url.pathname + url.search);
        }
    });

    const bootMode = <?= json_encode($openModal) ?>;
    const bootData = <?= json_encode(array_merge(['id' => $editId ?: null], $formData), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const bootErrors = <?= json_encode($formErrors, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    if (bootMode) {
        setMode(bootMode, bootData);
        showErrors(bootErrors);
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
