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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/intramurals/athletes/add.php');
    }

    $studentId = post('student_id');
    $firstName = post('first_name');
    $lastName = post('last_name');
    $gender = post('gender', 'male');
    $birthdate = post('birthdate') ?: null;
    $department = post('department');
    $yearLevel = post('year_level');
    $teamId = (int) post('team_id') ?: null;
    $email = post('email');
    $phone = post('phone');
    $sportId = (int) post('sport_id') ?: null;
    $eventCategory = post('event_category');
    $jersey = post('jersey_number');
    $position = post('position');
    $regTeamId = (int) post('reg_team_id') ?: $teamId;

    if ($scoped) {
        $teamId = $userTeamId;
        $regTeamId = $userTeamId;
    }

    if ($studentId === '') $errors[] = 'Student ID is required.';
    if ($firstName === '') $errors[] = 'First name is required.';
    if ($lastName === '') $errors[] = 'Last name is required.';
    if ($scoped && !$teamId) $errors[] = 'Your account is not assigned to a team.';
    if ($teamId && !canManageTeamAthletes($teamId)) $errors[] = 'You can only register athletes for your team.';
    if ($department !== '' && !in_array($department, athleteCourseOptions(), true)) {
        $errors[] = 'Please select a valid course.';
    }
    if ($yearLevel !== '' && !in_array($yearLevel, athleteYearLevelOptions(), true)) {
        $errors[] = 'Please select a valid year level.';
    }

    if ($studentId !== '') {
        $dup = $db->prepare('SELECT id FROM intramural_athletes WHERE student_id = ?');
        $dup->execute([$studentId]);
        if ($dup->fetch()) {
            $errors[] = 'An athlete with this Student ID is already registered.';
        }
    }

    $photo = null;
    if (!empty($_FILES['photo']['name'])) {
        $photo = uploadAthletePhoto($_FILES['photo']);
        if (!$photo) $errors[] = 'Invalid photo file.';
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
                $photo,
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
            flash('success', 'Athlete registered successfully.');
            redirect(BASE_URL . '/intramurals/athletes/view.php?id=' . $id);
        } catch (PDOException $e) {
            $errors[] = 'Could not save athlete information. Please check the fields and try again.';
        }
    }
}

$pageTitle = 'Register Athlete';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header"><h1><i class="bi bi-person-plus"></i> Register Athlete</h1></div>

<div class="row">
    <div class="col-lg-10">
        <div class="card">
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <h5 class="mb-3">Personal Information</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Student ID *</label>
                            <input type="text" name="student_id" class="form-control" required value="<?= sanitize(post('student_id')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">First Name *</label>
                            <input type="text" name="first_name" class="form-control" required value="<?= sanitize(post('first_name')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Last Name *</label>
                            <input type="text" name="last_name" class="form-control" required value="<?= sanitize(post('last_name')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Gender</label>
                            <select name="gender" class="form-select">
                                <?php foreach (['male', 'female', 'other'] as $g): ?>
                                <option value="<?= $g ?>" <?= post('gender', 'male') === $g ? 'selected' : '' ?>><?= ucfirst($g) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Birthdate</label>
                            <input type="date" name="birthdate" class="form-control" value="<?= sanitize(post('birthdate')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Year Level</label>
                            <select name="year_level" class="form-select">
                                <option value="">Select year</option>
                                <?php foreach (athleteYearLevelOptions() as $yl): ?>
                                <option value="<?= sanitize($yl) ?>" <?= post('year_level') === $yl ? 'selected' : '' ?>><?= sanitize($yl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Team / House<?= $scoped ? ' *' : '' ?></label>
                            <select name="team_id" class="form-select" <?= $scoped ? 'required' : '' ?>>
                                <?php if (!$scoped): ?><option value="">Unassigned</option><?php endif; ?>
                                <?php foreach ($teams as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= (post('team_id', (string) ($userTeamId ?? '')) == $t['id'] || $scoped) ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Course</label>
                            <?= renderAthleteCourseSelect(post('department')) ?>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= sanitize(post('email')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= sanitize(post('phone')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Athlete Photo</label>
                            <input type="file" name="photo" class="form-control" accept="image/*">
                        </div>
                    </div>

                    <h5 class="mb-3">Sport Assignment <small class="text-muted fw-normal">(optional)</small></h5>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Sport</label>
                            <select name="sport_id" class="form-select">
                                <option value="">Select sport</option>
                                <?php foreach ($sports as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= post('sport_id') == $s['id'] ? 'selected' : '' ?>><?= sanitize(sportLabel($s)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Team for Sport</label>
                            <select name="reg_team_id" class="form-select">
                                <?php if (!$scoped): ?><option value="">Same as house / select</option><?php endif; ?>
                                <?php foreach ($teams as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= (post('reg_team_id', (string) ($userTeamId ?? '')) == $t['id'] || $scoped) ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Event / Category</label>
                            <input type="text" name="event_category" class="form-control" value="<?= sanitize(post('event_category')) ?>" placeholder="e.g. Open, Juniors">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Jersey No.</label>
                            <input type="text" name="jersey_number" class="form-control" value="<?= sanitize(post('jersey_number')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Position</label>
                            <input type="text" name="position" class="form-control" value="<?= sanitize(post('position')) ?>" placeholder="e.g. Guard, Setter">
                        </div>
                    </div>

                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Register Athlete</button>
                        <a href="<?= BASE_URL ?>/intramurals/athletes/index.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
