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

if ($scoped) {
    $teams = $db->prepare('SELECT * FROM intramural_teams WHERE is_active = 1 AND id = ?');
    $teams->execute([$userTeamId]);
    $teams = $teams->fetchAll();
} else {
    $teams = $db->query('SELECT * FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
}
$sports = $db->query('SELECT * FROM intramural_sports ORDER BY name, category')->fetchAll();
if ($scoped && $userTeamId) {
    $sports = filterSportsForTeamDivision($sports, (int) $userTeamId);
}
$errors = [];
ensureIntramuralDivisionsSchema();

$postedSport = (string) post('sport_id');
$showSportSection = $postedSport !== '' || post('assign_sport') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/intramurals/athletes/add.php');
    }

    $studentId = trim(post('student_id'));
    $firstName = formatAthleteName(trim(post('first_name')));
    $lastName = formatAthleteName(trim(post('last_name')));
    $gender = post('gender', 'male');
    $birthdate = post('birthdate') ?: null;
    $department = post('department');
    $yearLevel = post('year_level');
    $teamId = (int) post('team_id') ?: null;
    $email = trim(post('email'));
    $phone = trim(post('phone'));
    $sportId = (int) post('sport_id') ?: null;
    $eventCategory = trim(post('event_category'));
    $jersey = trim(post('jersey_number'));
    $position = trim(post('position'));
    $regTeamId = (int) post('reg_team_id') ?: $teamId;
    $afterSave = post('after_save', 'view');

    if ($scoped) {
        $teamId = $userTeamId;
        $regTeamId = $userTeamId;
    }

    if ($studentId === '') $errors[] = 'Student ID is required.';
    if ($firstName === '') $errors[] = 'First name is required.';
    if ($lastName === '') $errors[] = 'Last name is required.';
    if (!in_array($gender, ['male', 'female', 'other'], true)) {
        $errors[] = 'Please select a valid gender.';
    }
    if ($scoped && !$teamId) $errors[] = 'Your account is not assigned to a team.';
    if ($teamId && !canManageTeamAthletes($teamId)) $errors[] = 'You can only register athletes for your team.';
    if ($department === '') {
        $errors[] = 'Course / program is required.';
    } elseif (!in_array($department, athleteCourseOptions(), true)) {
        $errors[] = 'Please select a valid course.';
    }
    if ($yearLevel === '') {
        $errors[] = 'Year level is required.';
    } elseif (!in_array($yearLevel, athleteYearLevelOptions(), true)) {
        $errors[] = 'Please select a valid year level.';
    }

    if ($studentId !== '') {
        $dup = $db->prepare('SELECT id FROM intramural_athletes WHERE student_id = ?');
        $dup->execute([$studentId]);
        if ($dup->fetch()) {
            $errors[] = 'An athlete with this Student ID is already registered.';
        }
    }

    if ($sportId && !$regTeamId) {
        $errors[] = 'Assign a team when registering for a sport.';
    }
    if ($sportId && $regTeamId && !teamCanPlaySport($regTeamId, $sportId)) {
        $errors[] = 'That event is not available for this team\'s division.';
    }
    if ($sportId && $regTeamId && $seasonId) {
        $cap = checkTeamEventRosterCapacity($regTeamId, $sportId, (int) $seasonId, 1);
        if (!$cap['ok']) {
            $errors[] = $cap['message'];
        }
    }

    if (empty($errors)) {
        try {
            $code = generateAthleteCode();
            $stmt = $db->prepare('INSERT INTO intramural_athletes (athlete_code, student_id, first_name, last_name, gender, birthdate, department, year_level, team_id, photo, email, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $code,
                $studentId,
                $firstName,
                $lastName,
                $gender,
                $birthdate ?: null,
                $department !== '' ? $department : null,
                $yearLevel !== '' ? $yearLevel : null,
                $teamId,
                null,
                $email !== '' ? $email : null,
                $phone !== '' ? $phone : null,
            ]);
            $id = (int) $db->lastInsertId();

            if ($sportId && $regTeamId && $seasonId) {
                try {
                    $db->prepare('INSERT INTO intramural_registrations (season_id, athlete_id, sport_id, team_id, event_category, jersey_number, position) VALUES (?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$seasonId, $id, $sportId, $regTeamId, $eventCategory ?: null, $jersey ?: null, $position ?: null]);
                } catch (PDOException $e) {
                    // ignore duplicate sport assignment
                }
            }

            auditLog($_SESSION['user_id'], 'create', 'intramural_athlete', $id, null, ['student_id' => $studentId, 'name' => "$firstName $lastName"]);

            if ($afterSave === 'another') {
                flash('success', 'Athlete registered. You can register the next athlete below.');
                redirect(BASE_URL . '/intramurals/athletes/add.php?registered=' . $id);
            }

            flash('success', 'Athlete registered successfully.');
            redirect(BASE_URL . '/intramurals/athletes/view.php?id=' . $id);
        } catch (PDOException $e) {
            $errors[] = 'Could not save athlete information. Please check the fields and try again.';
        }
    }

    $showSportSection = $sportId || post('assign_sport') === '1';
}

$justRegisteredId = (int) get('registered');
$defaultTeam = (string) ($userTeamId ?? '');
$selectedGender = post('gender', 'male');

$pageTitle = 'Register Athlete';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="athlete-register-page">
    <div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1><i class="bi bi-person-plus"></i> Register Athlete</h1>
            <p class="text-muted mb-0">
                Add one athlete to the directory<?= $season ? ' for <strong>' . sanitize(seasonLabel($season)) . '</strong>' : '' ?>.
                Required fields are marked with *.
            </p>
        </div>
        <a href="<?= BASE_URL ?>/intramurals/athletes/index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Athletes</a>
    </div>

    <?php if ($justRegisteredId && empty($errors) && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
    <div class="alert alert-success d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span><i class="bi bi-check-circle"></i> Last athlete saved. Continue registering below, or open their profile.</span>
        <a class="btn btn-sm btn-success" href="<?= BASE_URL ?>/intramurals/athletes/view.php?id=<?= $justRegisteredId ?>">View profile</a>
    </div>
    <?php endif; ?>

    <?php if ($errors): ?>
    <div class="alert alert-danger athlete-register-errors">
        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle"></i> Please fix the following:</div>
        <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <form method="POST" id="athleteRegisterForm" class="athlete-register-form" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="assign_sport" id="assignSportFlag" value="<?= $showSportSection ? '1' : '0' ?>">

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card athlete-register-card mb-4">
                    <div class="card-header athlete-register-step">
                        <span class="step-badge">1</span>
                        <div>
                            <strong>Identity</strong>
                            <div class="small text-muted">Student ID and legal name as used on campus records</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="student_id">Student ID <span class="text-danger">*</span></label>
                                <input type="text" name="student_id" id="student_id" class="form-control form-control-lg" required
                                       autocomplete="off" placeholder="e.g. 2024-00123"
                                       value="<?= sanitize(post('student_id')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="birthdate">Birthdate</label>
                                <input type="date" name="birthdate" id="birthdate" class="form-control form-control-lg"
                                       value="<?= sanitize(post('birthdate')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="first_name">First name <span class="text-danger">*</span></label>
                                <input type="text" name="first_name" id="first_name" class="form-control form-control-lg text-uppercase" required
                                       autocomplete="given-name" style="text-transform: uppercase;"
                                       value="<?= sanitize(post('first_name')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="last_name">Last name <span class="text-danger">*</span></label>
                                <input type="text" name="last_name" id="last_name" class="form-control form-control-lg text-uppercase" required
                                       autocomplete="family-name" style="text-transform: uppercase;"
                                       value="<?= sanitize(post('last_name')) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label d-block">Gender</label>
                                <div class="athlete-gender-group" role="group" aria-label="Gender">
                                    <?php foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $gVal => $gLabel): ?>
                                    <input type="radio" class="btn-check" name="gender" id="gender_<?= $gVal ?>"
                                           value="<?= $gVal ?>" <?= $selectedGender === $gVal ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-primary" for="gender_<?= $gVal ?>"><?= $gLabel ?></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card athlete-register-card mb-4">
                    <div class="card-header athlete-register-step">
                        <span class="step-badge">2</span>
                        <div>
                            <strong>School &amp; team</strong>
                            <div class="small text-muted">Course, year level, and house assignment</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label" for="department">Course / program <span class="text-danger">*</span></label>
                                <?= renderAthleteCourseSelect(post('department'), 'department', 'department', true) ?>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="year_level">Year level <span class="text-danger">*</span></label>
                                <select name="year_level" id="year_level" class="form-select" required>
                                    <option value="">Select year</option>
                                    <?php foreach (athleteYearLevelOptions() as $yl): ?>
                                    <option value="<?= sanitize($yl) ?>" <?= post('year_level') === $yl ? 'selected' : '' ?>><?= sanitize($yl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="team_id">Team / House<?= $scoped ? ' <span class="text-danger">*</span>' : '' ?></label>
                                <select name="team_id" id="team_id" class="form-select" <?= $scoped ? 'required' : '' ?>>
                                    <?php if (!$scoped): ?><option value="">Unassigned</option><?php endif; ?>
                                    <?php foreach ($teams as $t): ?>
                                    <option value="<?= (int) $t['id'] ?>" <?= ((string) post('team_id', $defaultTeam) === (string) $t['id'] || $scoped) ? 'selected' : '' ?>>
                                        <?= sanitize($t['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($scoped): ?>
                                <div class="form-text">Locked to your assigned team.</div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="email">Email</label>
                                <input type="email" name="email" id="email" class="form-control"
                                       autocomplete="email" value="<?= sanitize(post('email')) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="phone">Phone</label>
                                <input type="tel" name="phone" id="phone" class="form-control"
                                       autocomplete="tel" value="<?= sanitize(post('phone')) ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card athlete-register-card mb-4">
                    <div class="card-header athlete-register-step d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-3">
                            <span class="step-badge">3</span>
                            <div>
                                <strong>Event roster <span class="badge text-bg-light text-muted border fw-normal">Optional</span></strong>
                                <div class="small text-muted">Assign to a sport now, or skip and add later</div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="toggleSportBtn"
                                aria-expanded="<?= $showSportSection ? 'true' : 'false' ?>">
                            <?= $showSportSection ? 'Hide event fields' : 'Assign to an event' ?>
                        </button>
                    </div>
                    <div class="card-body <?= $showSportSection ? '' : 'd-none' ?>" id="sportAssignPanel">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="sport_id">Sport / event</label>
                                <select name="sport_id" id="sport_id" class="form-select">
                                    <option value="">Select sport</option>
                                    <?php foreach ($sports as $s): ?>
                                    <option value="<?= (int) $s['id'] ?>" <?= post('sport_id') == $s['id'] ? 'selected' : '' ?>><?= sanitize(sportLabel($s)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="reg_team_id">Team for this event</label>
                                <select name="reg_team_id" id="reg_team_id" class="form-select">
                                    <?php if (!$scoped): ?><option value="">Same as house / select</option><?php endif; ?>
                                    <?php foreach ($teams as $t): ?>
                                    <option value="<?= (int) $t['id'] ?>" <?= ((string) post('reg_team_id', $defaultTeam) === (string) $t['id'] || $scoped) ? 'selected' : '' ?>>
                                        <?= sanitize($t['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="event_category">Event category</label>
                                <input type="text" name="event_category" id="event_category" class="form-control"
                                       value="<?= sanitize(post('event_category')) ?>" placeholder="e.g. Open, Juniors">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="jersey_number">Jersey no.</label>
                                <input type="text" name="jersey_number" id="jersey_number" class="form-control"
                                       value="<?= sanitize(post('jersey_number')) ?>" placeholder="e.g. 10">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="position">Position</label>
                                <input type="text" name="position" id="position" class="form-control"
                                       value="<?= sanitize(post('position')) ?>" placeholder="e.g. Guard, Setter">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card athlete-register-card athlete-register-actions athlete-register-side sticky-lg-top">
                    <div class="card-body d-grid gap-2">
                        <button type="submit" name="after_save" value="view" class="btn btn-primary btn-lg">
                            <i class="bi bi-check2-circle"></i> Register athlete
                        </button>
                        <button type="submit" name="after_save" value="another" class="btn btn-outline-primary">
                            <i class="bi bi-person-plus"></i> Register &amp; add another
                        </button>
                        <a href="<?= BASE_URL ?>/intramurals/athletes/index.php" class="btn btn-outline-secondary">Cancel</a>
                        <p class="small text-muted mb-0 mt-1">
                            Rosters must stay unlocked. You can assign more events later from the athlete profile.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    var teamSelect = document.getElementById('team_id');
    var regTeamSelect = document.getElementById('reg_team_id');
    var sportPanel = document.getElementById('sportAssignPanel');
    var toggleBtn = document.getElementById('toggleSportBtn');
    var assignFlag = document.getElementById('assignSportFlag');
    var sportSelect = document.getElementById('sport_id');

    if (teamSelect && regTeamSelect && !regTeamSelect.disabled) {
        teamSelect.addEventListener('change', function () {
            if (!regTeamSelect.value || regTeamSelect.dataset.synced !== '0') {
                regTeamSelect.value = teamSelect.value;
            }
        });
        regTeamSelect.addEventListener('change', function () {
            regTeamSelect.dataset.synced = regTeamSelect.value === teamSelect.value ? '1' : '0';
        });
    }

    function setSportOpen(open) {
        if (!sportPanel || !toggleBtn || !assignFlag) return;
        sportPanel.classList.toggle('d-none', !open);
        toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggleBtn.textContent = open ? 'Hide event fields' : 'Assign to an event';
        assignFlag.value = open ? '1' : '0';
        if (!open && sportSelect) {
            sportSelect.value = '';
        }
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            setSportOpen(sportPanel.classList.contains('d-none'));
        });
    }

    var firstInvalid = document.querySelector('.athlete-register-errors');
    if (firstInvalid) {
        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
        var sid = document.getElementById('student_id');
        if (sid && !sid.value) sid.focus();
    }
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
