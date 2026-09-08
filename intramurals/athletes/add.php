<?php
require_once __DIR__ . '/../../includes/auth.php';
requireAthletesDirectoryAccess();

if (!canManageTeamAthletes()) {
    flash('error', 'You do not have permission to register athletes.');
    redirect(BASE_URL . '/intramurals/athletes/index.php');
}

requireWritableSeason();
requireUnlockedRoster();

$db = getDB();
$seasonId = getCurrentSeasonId();
$season = function_exists('getCurrentSeason') ? getCurrentSeason() : null;
$userTeamId = getUserTeamId();
$scoped = isTeamScopedRole();

if (!$seasonId) {
    flash('error', 'Set an active season before registering athletes.');
    redirect(BASE_URL . '/intramurals/seasons/index.php');
}

if ($scoped) {
    $teams = $db->prepare('SELECT * FROM intramural_teams WHERE is_active = 1 AND id = ?');
    $teams->execute([$userTeamId]);
    $teams = $teams->fetchAll();
} else {
    $teams = $db->query('SELECT * FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
}

$errors = [];
$nameResults = [];
$nameQuery = '';
$lookupMode = 'id';

$teamId = (int) get('team_id');
$sportId = (int) get('sport_id');
if ($scoped) {
    $teamId = (int) $userTeamId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/intramurals/athletes/add.php');
    }

    $action = post('action', 'add_athlete');

    if ($action === 'select_team') {
        $pickedTeam = $scoped ? (int) $userTeamId : (int) post('team_id');
        if ($pickedTeam <= 0 || !canManageTeamAthletes($pickedTeam)) {
            $errors[] = 'Please select a school / team.';
        } else {
            redirect(BASE_URL . '/intramurals/athletes/add.php?team_id=' . $pickedTeam);
        }
    }

    if ($action === 'select_event') {
        $pickedTeam = $scoped ? (int) $userTeamId : (int) post('team_id');
        $pickedSport = (int) post('sport_id');
        if ($pickedTeam <= 0 || !canManageTeamAthletes($pickedTeam)) {
            $errors[] = 'Please select a school / team.';
        } elseif ($pickedSport <= 0) {
            $errors[] = 'Please select an event.';
            $teamId = $pickedTeam;
        } elseif (!teamCanPlaySport($pickedTeam, $pickedSport)) {
            $errors[] = 'That event is not available for this team\'s division.';
            $teamId = $pickedTeam;
        } else {
            redirect(BASE_URL . '/intramurals/athletes/add.php?team_id=' . $pickedTeam . '&sport_id=' . $pickedSport);
        }
    }

    if ($action === 'add_athlete') {
        $pickedTeam = $scoped ? (int) $userTeamId : (int) post('team_id');
        $pickedSport = (int) post('sport_id');
        $studentId = trim(post('student_id'));
        $teamId = $pickedTeam;
        $sportId = $pickedSport;
        $lookupMode = post('lookup_mode', 'id') === 'name' ? 'name' : 'id';
        $nameQuery = trim(post('name_query'));
        $result = registerAthleteFromRegistrarToEvent($studentId, $pickedTeam, $pickedSport, (int) $seasonId);
        if ($result['ok']) {
            flash('success', $result['message']);
            $redirect = BASE_URL . '/intramurals/athletes/add.php?team_id=' . $pickedTeam . '&sport_id=' . $pickedSport;
            if ($lookupMode === 'name') {
                $redirect .= '&lookup=name';
                if ($nameQuery !== '') {
                    $redirect .= '&q=' . rawurlencode($nameQuery);
                }
            }
            redirect($redirect);
        } else {
            $errors[] = $result['message'];
            if ($lookupMode === 'name' && $nameQuery !== '') {
                $search = searchActiveStudentsByName($nameQuery);
                if ($search['ok']) {
                    $nameResults = $search['students'];
                }
            }
        }
    }

    if ($action === 'search_name') {
        $pickedTeam = $scoped ? (int) $userTeamId : (int) post('team_id');
        $pickedSport = (int) post('sport_id');
        $teamId = $pickedTeam;
        $sportId = $pickedSport;
        $lookupMode = 'name';
        $nameQuery = trim(post('name_query'));
        $search = searchActiveStudentsByName($nameQuery);
        if (!$search['ok']) {
            $errors[] = $search['message'];
        } else {
            $nameResults = $search['students'];
            if ($nameResults === []) {
                $errors[] = $search['message'] !== 'OK' ? $search['message'] : 'No enrolled students matched that name.';
            }
        }
    }

    if ($action === 'remove_athlete') {
        $pickedTeam = $scoped ? (int) $userTeamId : (int) post('team_id');
        $pickedSport = (int) post('sport_id');
        $regId = (int) post('registration_id');
        $teamId = $pickedTeam;
        $sportId = $pickedSport;
        if ($regId > 0 && $pickedTeam > 0 && $pickedSport > 0) {
            $stmt = $db->prepare('DELETE FROM intramural_registrations
                WHERE id = ? AND team_id = ? AND sport_id = ? AND season_id = ?');
            $stmt->execute([$regId, $pickedTeam, $pickedSport, $seasonId]);
            if ($stmt->rowCount() > 0) {
                flash('success', 'Athlete removed from this event roster.');
            }
        }
        redirect(BASE_URL . '/intramurals/athletes/add.php?team_id=' . $pickedTeam . '&sport_id=' . $pickedSport);
    }
}

$selectedTeam = null;
if ($teamId > 0) {
    foreach ($teams as $t) {
        if ((int) $t['id'] === $teamId) {
            $selectedTeam = $t;
            break;
        }
    }
    if (!$selectedTeam || !canManageTeamAthletes($teamId)) {
        $teamId = 0;
        $sportId = 0;
        $selectedTeam = null;
        if ($scoped) {
            $errors[] = 'Your account is not assigned to a team.';
        }
    }
}

$sports = [];
$selectedSport = null;
if ($selectedTeam) {
    $sports = $db->query('SELECT * FROM intramural_sports ORDER BY ' . intramuralSportsOrderBy())->fetchAll();
    $sports = filterSportsForTeamDivision($sports, $teamId);
    $sports = filterSportsForCoach($sports, $teamId);
    if ($sportId > 0) {
        foreach ($sports as $s) {
            if ((int) $s['id'] === $sportId) {
                $selectedSport = $s;
                break;
            }
        }
        if (!$selectedSport) {
            $sportId = 0;
        }
    }
}

$step = 1;
if ($selectedTeam) {
    $step = 2;
}
if ($selectedTeam && $selectedSport) {
    $step = 3;
}

$roster = [];
$capacity = ['ok' => true, 'limit' => null, 'current' => 0, 'remaining' => null, 'message' => ''];
if ($step === 3) {
    $roster = getOfficialEventRoster($sportId, $teamId, (int) $seasonId);
    $capacity = checkTeamEventRosterCapacity($teamId, $sportId, (int) $seasonId, 0);
    if (get('lookup') === 'name' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $lookupMode = 'name';
        $nameQuery = trim(get('q'));
        if ($nameQuery !== '' && $nameResults === []) {
            $search = searchActiveStudentsByName($nameQuery);
            if ($search['ok']) {
                $nameResults = $search['students'];
            }
        }
    }
}

$eventCaps = [];
if ($step === 2) {
    foreach ($sports as $s) {
        $eventCaps[(int) $s['id']] = checkTeamEventRosterCapacity($teamId, (int) $s['id'], (int) $seasonId, 0);
    }
}

$apiReady = isRegistrarStudentLoginConfigured();
$slotsFull = $capacity['limit'] !== null && (int) $capacity['remaining'] <= 0;

$teamLabel = static function (array $team): string {
    $name = trim((string) ($team['name'] ?? ''));
    $school = trim((string) ($team['department'] ?? ''));
    return $school !== '' ? $name . ' — ' . $school : $name;
};

$pageTitle = 'Register Athlete';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="athlete-register-page">
    <div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1><i class="bi bi-person-plus"></i> Register Athlete</h1>
            <p class="text-muted mb-0">
                Add athletes to an event roster<?= $season ? ' for <strong>' . sanitize(seasonLabel($season)) . '</strong>' : '' ?>
                using an enrolled Student ID.
            </p>
        </div>
        <a href="<?= BASE_URL ?>/intramurals/athletes/index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Athletes</a>
    </div>

    <ol class="athlete-wizard-steps">
        <li class="<?= $step === 1 ? 'is-current' : ($step > 1 ? 'is-done' : '') ?>">
            <span class="step-badge"><?= $step > 1 ? '✓' : '1' ?></span>
            <span>School / team</span>
        </li>
        <li class="<?= $step === 2 ? 'is-current' : ($step > 2 ? 'is-done' : '') ?>">
            <span class="step-badge"><?= $step > 2 ? '✓' : '2' ?></span>
            <span>Event</span>
        </li>
        <li class="<?= $step === 3 ? 'is-current' : '' ?>">
            <span class="step-badge">3</span>
            <span>Add athletes</span>
        </li>
    </ol>

    <?php if ($errors): ?>
    <div class="alert alert-danger athlete-register-errors">
        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle"></i> Please fix the following:</div>
        <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
    <div class="card athlete-register-card">
        <div class="card-header athlete-register-step">
            <span class="step-badge">1</span>
            <div>
                <strong>Select school / team</strong>
                <div class="small text-muted">Athletes will be added to this house for the chosen event</div>
            </div>
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3 align-items-end">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="select_team">
                <div class="col-md-8">
                    <label class="form-label" for="team_id">School / team <span class="text-danger">*</span></label>
                    <select name="team_id" id="team_id" class="form-select form-select-lg" required <?= $scoped ? 'disabled' : '' ?>>
                        <?php if (!$scoped): ?><option value="">Select team</option><?php endif; ?>
                        <?php foreach ($teams as $t): ?>
                        <option value="<?= (int) $t['id'] ?>" <?= ((string) post('team_id', (string) $teamId) === (string) $t['id'] || $scoped) ? 'selected' : '' ?>>
                            <?= sanitize($teamLabel($t)) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($scoped): ?>
                    <input type="hidden" name="team_id" value="<?= (int) $userTeamId ?>">
                    <div class="form-text">Locked to your assigned team.</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary btn-lg w-100">Continue <i class="bi bi-arrow-right"></i></button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($step === 2 && $selectedTeam): ?>
    <div class="card athlete-register-card mb-3">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <div class="small text-muted">School / team</div>
                <strong><?= sanitize($teamLabel($selectedTeam)) ?></strong>
            </div>
            <?php if (!$scoped): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/intramurals/athletes/add.php">Change team</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card athlete-register-card">
        <div class="card-header athlete-register-step">
            <span class="step-badge">2</span>
            <div>
                <strong>Select an event</strong>
                <div class="small text-muted">Roster size follows the event’s allowable player count</div>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($sports)): ?>
            <p class="text-muted mb-0">No events are available for this team’s division.</p>
            <?php else: ?>
            <form method="POST" class="row g-3 align-items-end">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="select_event">
                <input type="hidden" name="team_id" value="<?= $teamId ?>">
                <div class="col-md-8">
                    <label class="form-label" for="sport_id">Event <span class="text-danger">*</span></label>
                    <select name="sport_id" id="sport_id" class="form-select form-select-lg" required>
                        <?= renderSportSelectOptions($sports, post('sport_id', (string) $sportId), true, 'Select event', static function (array $s) use ($eventCaps): string {
                            $cap = $eventCaps[(int) $s['id']] ?? ['current' => 0, 'limit' => null];
                            $label = sportLabel($s);
                            if (!empty($cap['limit'])) {
                                $label .= ' — ' . (int) $cap['current'] . '/' . (int) $cap['limit'] . ' players';
                            }
                            return $label;
                        }) ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary btn-lg w-100">Continue <i class="bi bi-arrow-right"></i></button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($step === 3 && $selectedTeam && $selectedSport): ?>
    <div class="card athlete-register-card mb-3">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <div class="small text-muted">School / team</div>
                <strong><?= sanitize($teamLabel($selectedTeam)) ?></strong>
            </div>
            <div>
                <div class="small text-muted">Event</div>
                <strong><?= sanitize(sportLabel($selectedSport)) ?></strong>
            </div>
            <div>
                <div class="small text-muted">Roster</div>
                <strong>
                    <?= (int) $capacity['current'] ?>
                    <?php if ($capacity['limit'] !== null): ?>
                    / <?= (int) $capacity['limit'] ?> players
                    <?php else: ?>
                    athletes (no cap)
                    <?php endif; ?>
                </strong>
            </div>
            <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/intramurals/athletes/add.php?team_id=<?= $teamId ?>">Change event</a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card athlete-register-card">
                <div class="card-header athlete-register-step">
                    <span class="step-badge">3</span>
                    <div>
                        <strong>Add athletes</strong>
                        <div class="small text-muted">Look up enrolled students from the Registrar API</div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!$apiReady): ?>
                    <div class="alert alert-warning mb-3">
                        Registrar API is not configured. Add the API key under System Settings → Student Login (Registrar API).
                    </div>
                    <?php endif; ?>
                    <?php if ($slotsFull): ?>
                    <div class="alert alert-success mb-0">
                        This event roster is full (<?= (int) $capacity['limit'] ?> players).
                    </div>
                    <?php else: ?>
                    <ul class="nav nav-pills mb-3" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link <?= $lookupMode !== 'name' ? 'active' : '' ?>"
                               href="<?= BASE_URL ?>/intramurals/athletes/add.php?team_id=<?= $teamId ?>&sport_id=<?= $sportId ?>">
                                Student ID
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $lookupMode === 'name' ? 'active' : '' ?>"
                               href="<?= BASE_URL ?>/intramurals/athletes/add.php?team_id=<?= $teamId ?>&sport_id=<?= $sportId ?>&lookup=name">
                                Search by name
                            </a>
                        </li>
                    </ul>

                    <?php if ($lookupMode !== 'name'): ?>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="add_athlete">
                        <input type="hidden" name="lookup_mode" value="id">
                        <input type="hidden" name="team_id" value="<?= $teamId ?>">
                        <input type="hidden" name="sport_id" value="<?= $sportId ?>">
                        <label class="form-label" for="student_id">Student ID <span class="text-danger">*</span></label>
                        <div class="input-group input-group-lg mb-2">
                            <span class="input-group-text"><i class="bi bi-card-text"></i></span>
                            <input type="text" name="student_id" id="student_id" class="form-control" required
                                   autocomplete="off" autofocus placeholder="e.g. 2024-00123"
                                   value="<?= ($errors && $lookupMode !== 'name') ? sanitize(post('student_id')) : '' ?>"
                                   <?= $apiReady ? '' : 'disabled' ?>>
                        </div>
                        <div class="form-text mb-3">
                            <?php if ($capacity['limit'] !== null): ?>
                            <?= (int) $capacity['remaining'] ?> slot<?= (int) $capacity['remaining'] === 1 ? '' : 's' ?> remaining.
                            <?php else: ?>
                            No player limit is set for this event.
                            <?php endif; ?>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" <?= $apiReady ? '' : 'disabled' ?>>
                            <i class="bi bi-person-plus"></i> Add athlete to event
                        </button>
                    </form>
                    <?php else: ?>
                    <form method="POST" class="mb-3">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="search_name">
                        <input type="hidden" name="team_id" value="<?= $teamId ?>">
                        <input type="hidden" name="sport_id" value="<?= $sportId ?>">
                        <label class="form-label" for="name_query">Student name <span class="text-danger">*</span></label>
                        <div class="input-group mb-2">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" name="name_query" id="name_query" class="form-control" required
                                   autocomplete="off" autofocus minlength="2"
                                   placeholder="e.g. Dela Cruz"
                                   value="<?= sanitize($nameQuery) ?>"
                                   <?= $apiReady ? '' : 'disabled' ?>>
                            <button type="submit" class="btn btn-outline-primary" <?= $apiReady ? '' : 'disabled' ?>>Search</button>
                        </div>
                        <div class="form-text">
                            Search enrolled students by name when the student ID is not available.
                            <?php if ($capacity['limit'] !== null): ?>
                            <?= (int) $capacity['remaining'] ?> slot<?= (int) $capacity['remaining'] === 1 ? '' : 's' ?> remaining.
                            <?php endif; ?>
                        </div>
                    </form>

                    <?php if ($nameResults): ?>
                    <div class="table-responsive athlete-name-results">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($nameResults as $student): ?>
                                <?php
                                $sid = (string) ($student['student_id'] ?? '');
                                $displayName = trim((string) ($student['full_name'] ?? ''));
                                if ($displayName === '') {
                                    $displayName = trim(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? ''));
                                }
                                $meta = trim(implode(' · ', array_filter([
                                    (string) ($student['course'] ?? ''),
                                    (string) ($student['year_level'] ?? ''),
                                ])));
                                ?>
                                <tr>
                                    <td><code><?= sanitize($sid) ?></code></td>
                                    <td>
                                        <div><?= sanitize($displayName) ?></div>
                                        <?php if ($meta !== ''): ?>
                                        <div class="small text-muted"><?= sanitize($meta) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="add_athlete">
                                            <input type="hidden" name="lookup_mode" value="name">
                                            <input type="hidden" name="name_query" value="<?= sanitize($nameQuery) ?>">
                                            <input type="hidden" name="team_id" value="<?= $teamId ?>">
                                            <input type="hidden" name="sport_id" value="<?= $sportId ?>">
                                            <input type="hidden" name="student_id" value="<?= sanitize($sid) ?>">
                                            <button type="submit" class="btn btn-sm btn-primary" <?= $sid === '' ? 'disabled' : '' ?>>
                                                Add
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php elseif ($nameQuery !== '' && !$errors): ?>
                    <p class="text-muted small mb-0">No enrolled students matched that name.</p>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card athlete-register-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Event roster</span>
                    <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>/intramurals/roster/index.php?sport=<?= $sportId ?>&team=<?= $teamId ?>">Open full roster</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($roster)): ?>
                    <p class="text-muted p-4 mb-0">No athletes on this event yet. Add a player by Student ID or search by name.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Course</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($roster as $i => $row): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><code><?= sanitize($row['student_id']) ?></code></td>
                                    <td>
                                        <a href="<?= BASE_URL ?>/intramurals/athletes/view.php?id=<?= (int) $row['athlete_id'] ?>">
                                            <?= sanitize(athleteFullName($row)) ?>
                                        </a>
                                    </td>
                                    <td class="small"><?= sanitize($row['department'] ?? '') ?></td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="remove_athlete">
                                            <input type="hidden" name="team_id" value="<?= $teamId ?>">
                                            <input type="hidden" name="sport_id" value="<?= $sportId ?>">
                                            <input type="hidden" name="registration_id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove from this event"
                                                    data-confirm="Remove this athlete from the event roster?">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
