<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$db = getDB();
$id = (int) get('id');
$stmt = $db->prepare('SELECT * FROM intramural_athletes WHERE id = ?');
$stmt->execute([$id]);
$athlete = $stmt->fetch();

if (!$athlete) {
    flash('error', 'Athlete not found.');
    redirect(BASE_URL . '/intramurals/athletes/index.php');
}

$athleteTeamId = !empty($athlete['team_id']) ? (int) $athlete['team_id'] : null;
$canEditProfile = canManageIntramurals() || ($athleteTeamId !== null && canManageTeamAthletes($athleteTeamId));
$canEditRoster = canManageIntramurals()
    || ($athleteTeamId !== null && canManageTeamRoster($athleteTeamId));

if (!$canEditProfile && !$canEditRoster) {
    flash('error', 'You can only manage athletes for your assigned team/events.');
    redirect(BASE_URL . '/intramurals/athletes/view.php?id=' . $id);
}

$userTeamId = getUserTeamId();
$seasonId = getCurrentSeasonId();
$scoped = isTeamScopedRole();
$isCoach = hasRole('coach') && !canManageIntramurals();
$coachTeamIds = $isCoach ? getCoachTeamIds() : [];

if (canManageIntramurals()) {
    $teams = $db->query('SELECT * FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
} elseif (hasRole('unit_manager') && $userTeamId) {
    $teams = $db->prepare('SELECT * FROM intramural_teams WHERE is_active = 1 AND id = ?');
    $teams->execute([$userTeamId]);
    $teams = $teams->fetchAll();
} elseif ($isCoach && $coachTeamIds) {
    $placeholders = implode(',', array_fill(0, count($coachTeamIds), '?'));
    $teams = $db->prepare("SELECT * FROM intramural_teams WHERE is_active = 1 AND id IN ($placeholders) ORDER BY name");
    $teams->execute($coachTeamIds);
    $teams = $teams->fetchAll();
} else {
    $teams = [];
}

$allSports = $db->query('SELECT * FROM intramural_sports WHERE is_active = 1 ORDER BY name, category')->fetchAll();
// Coaches may only assign their coached events
$sports = $allSports;
if ($isCoach && $athleteTeamId) {
    $allowedSportIds = getCoachSportIdsForTeam($athleteTeamId);
    $sports = array_values(array_filter($allSports, fn($s) => in_array((int) $s['id'], $allowedSportIds, true)));
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/intramurals/athletes/edit.php?id=' . $id);
    }

    $action = post('action', 'save');

    if ($action === 'deactivate') {
        if (!$canEditProfile) {
            flash('error', 'Permission denied.');
            redirect(BASE_URL . '/intramurals/athletes/edit.php?id=' . $id);
        }
        $db->prepare('UPDATE intramural_athletes SET is_active = 0 WHERE id = ?')->execute([$id]);
        auditLog($_SESSION['user_id'], 'delete', 'intramural_athlete', $id);
        flash('success', 'Athlete deactivated.');
        redirect(BASE_URL . '/intramurals/athletes/index.php');
    }

    if ($action === 'add_sport') {
        requireWritableSeason();
        $sportId = (int) post('sport_id');
        $regTeamId = (int) post('reg_team_id');
        if (hasRole('unit_manager') && $userTeamId) {
            $regTeamId = $userTeamId;
        }
        if ($isCoach && $athleteTeamId) {
            $regTeamId = $athleteTeamId;
        }
        $eventCategory = post('event_category');
        $jersey = post('jersey_number');
        $position = post('position');
        if (!$sportId || !$regTeamId || !$seasonId) {
            flash('error', 'Sport, team, and an active season are required.');
        } elseif (!canManageTeamRoster($regTeamId, $sportId)) {
            flash('error', 'You can only manage events you are assigned to coach.');
        } else {
            try {
                $db->prepare('INSERT INTO intramural_registrations (season_id, athlete_id, sport_id, team_id, event_category, jersey_number, position) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$seasonId, $id, $sportId, $regTeamId, $eventCategory ?: null, $jersey ?: null, $position ?: null]);
                auditLog($_SESSION['user_id'], 'assign_sport', 'intramural_athlete', $id, null, ['sport_id' => $sportId, 'team_id' => $regTeamId, 'season_id' => $seasonId]);
                flash('success', 'Sport assignment added.');
            } catch (PDOException $e) {
                flash('error', 'Athlete is already registered for this sport in the current season.');
            }
        }
        redirect(BASE_URL . '/intramurals/athletes/edit.php?id=' . $id);
    }

    if ($action === 'remove_sport') {
        requireWritableSeason();
        $regId = (int) post('reg_id');
        $reg = $db->prepare('SELECT * FROM intramural_registrations WHERE id = ? AND athlete_id = ?');
        $reg->execute([$regId, $id]);
        $reg = $reg->fetch();
        if (!$reg || !canManageTeamRoster((int) $reg['team_id'], (int) $reg['sport_id'])) {
            flash('error', 'You can only remove assignments for your coached events.');
            redirect(BASE_URL . '/intramurals/athletes/edit.php?id=' . $id);
        }
        if ($seasonId && (int) ($reg['season_id'] ?? 0) !== $seasonId) {
            flash('error', 'Switch to that season before removing the registration.');
            redirect(BASE_URL . '/intramurals/athletes/edit.php?id=' . $id);
        }
        $db->prepare('DELETE FROM intramural_registrations WHERE id = ? AND athlete_id = ?')->execute([$regId, $id]);
        flash('success', 'Sport assignment removed.');
        redirect(BASE_URL . '/intramurals/athletes/edit.php?id=' . $id);
    }

    if (!$canEditProfile) {
        flash('error', 'Coaches can update sport assignments only. Ask the unit manager to edit profile details.');
        redirect(BASE_URL . '/intramurals/athletes/edit.php?id=' . $id);
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

    if (hasRole('unit_manager') && $userTeamId) {
        $teamId = $userTeamId;
    }

    if ($studentId === '') $errors[] = 'Student ID is required.';
    if ($firstName === '') $errors[] = 'First name is required.';
    if ($lastName === '') $errors[] = 'Last name is required.';
    if ($teamId && !canManageTeamAthletes($teamId)) $errors[] = 'You can only assign athletes to your team.';

    $dup = $db->prepare('SELECT id FROM intramural_athletes WHERE student_id = ? AND id != ?');
    $dup->execute([$studentId, $id]);
    if ($dup->fetch()) {
        $errors[] = 'Another athlete already uses this Student ID.';
    }

    $photo = $athlete['photo'];
    if (!empty($_FILES['photo']['name'])) {
        $newPhoto = uploadAthletePhoto($_FILES['photo']);
        if (!$newPhoto) {
            $errors[] = 'Invalid photo file.';
        } else {
            deleteUploadedFile($photo, UPLOAD_PATH_ATHLETES);
            $photo = $newPhoto;
        }
    }

    if (empty($errors)) {
        $stmt = $db->prepare('UPDATE intramural_athletes SET student_id=?, first_name=?, last_name=?, gender=?, birthdate=?, department=?, year_level=?, team_id=?, photo=?, email=?, phone=? WHERE id=?');
        $stmt->execute([$studentId, $firstName, $lastName, $gender, $birthdate, $department, $yearLevel, $teamId, $photo, $email, $phone, $id]);
        auditLog($_SESSION['user_id'], 'update', 'intramural_athlete', $id, null, ['student_id' => $studentId]);
        flash('success', 'Athlete updated.');
        redirect(BASE_URL . '/intramurals/athletes/view.php?id=' . $id);
    }
}

if ($seasonId) {
    $regs = $db->prepare('SELECT r.*, s.name as sport_name, s.category, t.name as team_name FROM intramural_registrations r JOIN intramural_sports s ON r.sport_id = s.id JOIN intramural_teams t ON r.team_id = t.id WHERE r.athlete_id = ? AND r.season_id = ? ORDER BY s.name');
    $regs->execute([$id, $seasonId]);
} else {
    $regs = $db->prepare('SELECT r.*, s.name as sport_name, s.category, t.name as team_name FROM intramural_registrations r JOIN intramural_sports s ON r.sport_id = s.id JOIN intramural_teams t ON r.team_id = t.id WHERE r.athlete_id = ? ORDER BY s.name');
    $regs->execute([$id]);
}
$regs = $regs->fetchAll();

$pageTitle = 'Edit Athlete';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header"><h1><i class="bi bi-pencil"></i> Edit Athlete</h1></div>

<div class="row g-4">
    <?php if ($canEditProfile): ?>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Student ID *</label>
                            <input type="text" name="student_id" class="form-control" required value="<?= sanitize($athlete['student_id']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">First Name *</label>
                            <input type="text" name="first_name" class="form-control" required value="<?= sanitize($athlete['first_name']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Last Name *</label>
                            <input type="text" name="last_name" class="form-control" required value="<?= sanitize($athlete['last_name']) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Gender</label>
                            <select name="gender" class="form-select">
                                <?php foreach (['male', 'female', 'other'] as $g): ?>
                                <option value="<?= $g ?>" <?= $athlete['gender'] === $g ? 'selected' : '' ?>><?= ucfirst($g) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Birthdate</label>
                            <input type="date" name="birthdate" class="form-control" value="<?= sanitize($athlete['birthdate'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Year Level</label>
                            <input type="text" name="year_level" class="form-control" value="<?= sanitize($athlete['year_level'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Team / House</label>
                            <select name="team_id" class="form-select" <?= $scoped ? 'required' : '' ?>>
                                <?php if (!$scoped): ?><option value="">Unassigned</option><?php endif; ?>
                                <?php foreach ($teams as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= (int) $athlete['team_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department</label>
                            <input type="text" name="department" class="form-control" value="<?= sanitize($athlete['department'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= sanitize($athlete['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= sanitize($athlete['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Photo</label>
                            <input type="file" name="photo" class="form-control" accept="image/*">
                            <?php if ($athlete['photo']): ?>
                            <img src="<?= UPLOAD_URL_ATHLETES . sanitize($athlete['photo']) ?>" alt="" class="mt-2 rounded" style="height:64px">
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="<?= BASE_URL ?>/intramurals/athletes/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
                        <?php if (canManageIntramurals() || hasRole('unit_manager')): ?>
                        <button type="submit" name="action" value="deactivate" class="btn btn-outline-danger ms-auto" data-confirm="Deactivate this athlete?">Deactivate</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="col-lg-7">
        <div class="alert alert-info">As an event coach, you can manage roster details only for events you are assigned to. Profile edits are handled by the unit manager.</div>
    </div>
    <?php endif; ?>
    <div class="col-lg-5">
        <?php if ($canEditRoster): ?>
        <div class="card mb-3">
            <div class="card-header">Sport Assignments</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($regs as $r): ?>
                <?php $canManageThis = canManageTeamRoster((int) $r['team_id'], (int) $r['sport_id']); ?>
                <?php if ($isCoach && !$canManageThis) continue; ?>
                <li class="list-group-item">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <strong><?= sanitize($r['sport_name']) ?> (<?= ucfirst($r['category']) ?>)</strong><br>
                            <small class="text-muted"><?= sanitize($r['team_name']) ?> · #<?= sanitize($r['jersey_number'] ?: '-') ?> · <?= sanitize($r['position'] ?: 'No position') ?></small>
                        </div>
                        <?php if ($canManageThis): ?>
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="remove_sport">
                            <input type="hidden" name="reg_id" value="<?= $r['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" data-confirm="Remove this sport assignment?">Remove</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
                <?php if (empty($regs)): ?>
                <li class="list-group-item text-muted">No sports assigned.</li>
                <?php endif; ?>
            </ul>
        </div>
        <div class="card">
            <div class="card-header">Assign to <?= $isCoach ? 'Your Event' : 'Sport' ?></div>
            <div class="card-body">
                <?php if ($isCoach && empty($sports)): ?>
                <p class="text-muted mb-0">You have no event coach assignments for this athlete’s team. Ask the unit manager to assign you under Teams → Event Coaches.</p>
                <?php else: ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_sport">
                    <div class="mb-2">
                        <label class="form-label">Event / Sport</label>
                        <select name="sport_id" class="form-select" required>
                            <option value="">Select</option>
                            <?php foreach ($sports as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= sanitize(sportLabel($s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Team</label>
                        <select name="reg_team_id" class="form-select" required>
                            <?php foreach ($teams as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= (int) $athlete['team_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2"><label class="form-label">Event / Category</label><input type="text" name="event_category" class="form-control"></div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label">Jersey</label><input type="text" name="jersey_number" class="form-control"></div>
                        <div class="col-6"><label class="form-label">Position</label><input type="text" name="position" class="form-control"></div>
                    </div>
                    <button class="btn btn-primary w-100">Assign</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
