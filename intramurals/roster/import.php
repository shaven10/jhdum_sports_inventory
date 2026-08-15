<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
ensureSportCategoryEnum();

if (!canManageTeamAthletes() && !canManageTeamRoster()) {
    flash('error', 'You do not have permission to import athlete rosters.');
    redirect(BASE_URL . '/intramurals/roster/index.php');
}

if (isCoach() && !canManageIntramurals() && !hasCoachAssignments()) {
    flash('error', 'You have no event coach assignments. Ask your unit manager to assign you under Teams → Event Coaches.');
    redirect(BASE_URL . '/intramurals/index.php');
}

$download = get('download');
$scopedTeamId = null;
if (isTeamScopedRole() && getUserTeamId()) {
    $scopedTeamId = (int) getUserTeamId();
}
if ($download === 'template' || $download === 'xlsx') {
    downloadRosterImportTemplate('xlsx', $scopedTeamId);
}
if ($download === 'csv') {
    downloadRosterImportTemplate('csv', $scopedTeamId);
}

requireWritableSeason();
requireUnlockedRoster();

$db = getDB();
$seasonId = getCurrentSeasonId();
$season = getCurrentSeason();
$userTeamId = getUserTeamId();
$scoped = isTeamScopedRole();
$isCoach = hasRole('coach') && !canManageIntramurals();

$lookups = buildRosterImportLookups();
$teams = filterTeamsForCoach($db->query('SELECT id, name, short_name FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll());
$sports = filterSportsForCoach($db->query('SELECT id, name, category, players_per_event, venue FROM intramural_sports ORDER BY name, category')->fetchAll());

$results = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/intramurals/roster/import.php');
    }

    if (!$seasonId) {
        $errors[] = 'No active intramurals season configured.';
    }

    if (empty($_FILES['import_file']['name']) || ($_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Please choose an Excel (.xlsx) or CSV file to upload.';
    } else {
        $name = strtolower((string) $_FILES['import_file']['name']);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        if (!in_array($ext, ['xlsx', 'xlsm', 'csv', 'txt'], true)) {
            $errors[] = 'Upload the downloadable Excel template (.xlsx) or a CSV UTF-8 file.';
        }
    }

    if (empty($errors)) {
        $parsed = parseRosterImportFile($_FILES['import_file']['tmp_name'], (string) $_FILES['import_file']['name']);
        if (!empty($parsed['error'])) {
            $errors[] = $parsed['error'];
        } elseif (empty($parsed['rows'])) {
            $errors[] = 'No data rows found in the file.';
        } else {
            $created = 0;
            $registered = 0;
            $updated = 0;
            $skipped = 0;
            $rowErrors = [];

            // Abort import when the file would exceed players_per_event for any team + event.
            $plannedNewByRoster = [];
            foreach ($parsed['rows'] as $row) {
                $studentId = trim($row['student_id'] ?? '');
                $firstName = trim($row['first_name'] ?? '');
                $lastName = trim($row['last_name'] ?? '');
                $teamName = trim($row['team'] ?? '');
                $sportName = trim($row['sport'] ?? '');
                $sportCategory = trim($row['sport_category'] ?? '');
                if ($studentId === '' || $firstName === '' || $lastName === '') {
                    continue;
                }
                $team = $lookups['teams'][strtolower($teamName)] ?? null;
                $sport = resolveImportSport($lookups, $sportName, $sportCategory);
                if (!$team || !$sport) {
                    continue;
                }
                $key = (int) $team['id'] . ':' . (int) $sport['id'];
                if (!isset($plannedNewByRoster[$key])) {
                    $plannedNewByRoster[$key] = [
                        'team_id' => (int) $team['id'],
                        'sport_id' => (int) $sport['id'],
                        'sport' => $sport,
                        'students' => [],
                    ];
                }
                $plannedNewByRoster[$key]['students'][$studentId] = true;
            }

            $findExistingRegByStudent = $db->prepare(
                'SELECT r.id
                 FROM intramural_registrations r
                 JOIN intramural_athletes a ON a.id = r.athlete_id
                 WHERE a.student_id = ? AND r.sport_id = ? AND r.season_id = ?
                 LIMIT 1'
            );
            $capacityErrors = [];
            foreach ($plannedNewByRoster as $group) {
                $limit = getSportPlayersPerEventLimit((int) $group['sport_id']);
                if ($limit === null) {
                    continue;
                }
                $current = countTeamEventRoster((int) $group['team_id'], (int) $group['sport_id'], (int) $seasonId);
                $newStudents = 0;
                foreach (array_keys($group['students']) as $studentId) {
                    $findExistingRegByStudent->execute([$studentId, (int) $group['sport_id'], (int) $seasonId]);
                    if (!$findExistingRegByStudent->fetch()) {
                        $newStudents++;
                    }
                }
                if ($current + $newStudents > $limit) {
                    $capacityErrors[] = sportLabel($group['sport'])
                        . ' allows only ' . $limit . ' athlete' . ($limit === 1 ? '' : 's')
                        . ' per team. Import needs ' . $newStudents . ' new registration'
                        . ($newStudents === 1 ? '' : 's')
                        . ' but the roster already has ' . $current . '.';
                }
            }

            if ($capacityErrors) {
                $errors = array_merge($errors, $capacityErrors);
                $errors[] = 'Import stopped. Reduce athletes per event in the file to match players per event, then try again.';
            } else {

            $findAthlete = $db->prepare('SELECT * FROM intramural_athletes WHERE student_id = ? LIMIT 1');
            $insertAthlete = $db->prepare('INSERT INTO intramural_athletes (athlete_code, student_id, first_name, last_name, gender, birthdate, department, year_level, team_id, email, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $updateAthlete = $db->prepare('UPDATE intramural_athletes SET first_name=?, last_name=?, gender=?, birthdate=COALESCE(?, birthdate), department=COALESCE(NULLIF(?, ""), department), year_level=COALESCE(NULLIF(?, ""), year_level), team_id=?, email=COALESCE(NULLIF(?, ""), email), phone=COALESCE(NULLIF(?, ""), phone), is_active=1 WHERE id=?');
            $findReg = $db->prepare('SELECT id, team_id FROM intramural_registrations WHERE athlete_id = ? AND sport_id = ? AND season_id = ? LIMIT 1');
            $insertReg = $db->prepare('INSERT INTO intramural_registrations (season_id, athlete_id, sport_id, team_id, event_category, jersey_number, position) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $updateReg = $db->prepare('UPDATE intramural_registrations SET team_id=?, event_category=?, jersey_number=?, position=? WHERE id=?');
            $rosterCounts = [];

            foreach ($parsed['rows'] as $index => $row) {
                $line = $index + 2; // header is line 1
                $studentId = trim($row['student_id'] ?? '');
                $firstName = trim($row['first_name'] ?? '');
                $lastName = trim($row['last_name'] ?? '');
                $gender = strtolower(trim($row['gender'] ?? 'male'));
                $birthdate = trim($row['birthdate'] ?? '');
                $department = trim($row['course'] ?? $row['department'] ?? '');
                $yearLevel = trim($row['year_level'] ?? '');
                $teamName = trim($row['team'] ?? '');
                $email = trim($row['email'] ?? '');
                $phone = trim($row['phone'] ?? '');
                $sportName = trim($row['sport'] ?? '');
                $sportCategory = trim($row['sport_category'] ?? '');
                $jersey = trim($row['jersey_number'] ?? '');
                $position = trim($row['position'] ?? '');
                $eventCategory = trim($row['event_category'] ?? '');

                if ($studentId === '' || $firstName === '' || $lastName === '') {
                    $rowErrors[] = "Row $line: student_id, first_name, and last_name are required.";
                    $skipped++;
                    continue;
                }

                if (!in_array($gender, ['male', 'female', 'other'], true)) {
                    $gender = 'male';
                }

                $birthdateVal = null;
                if ($birthdate !== '') {
                    $ts = strtotime($birthdate);
                    if ($ts === false) {
                        $rowErrors[] = "Row $line: invalid birthdate \"$birthdate\" (use YYYY-MM-DD).";
                        $skipped++;
                        continue;
                    }
                    $birthdateVal = date('Y-m-d', $ts);
                }

                $team = $lookups['teams'][strtolower($teamName)] ?? null;
                if (!$team) {
                    $rowErrors[] = "Row $line: unknown team \"$teamName\".";
                    $skipped++;
                    continue;
                }
                $teamId = (int) $team['id'];

                if ($scoped && $userTeamId && $teamId !== $userTeamId) {
                    $rowErrors[] = "Row $line: you can only import athletes for your assigned team.";
                    $skipped++;
                    continue;
                }

                $sport = resolveImportSport($lookups, $sportName, $sportCategory);
                if (!$sport) {
                    $hint = $sportCategory !== '' ? " ($sportCategory)" : '';
                    $rowErrors[] = "Row $line: unknown sport \"$sportName\"$hint. Check the spelling or add sport_category.";
                    $skipped++;
                    continue;
                }
                $sportId = (int) $sport['id'];

                if (!canManageTeamRoster($teamId, $sportId) && !canManageTeamAthletes($teamId)) {
                    $rowErrors[] = "Row $line: no permission for team/sport combination.";
                    $skipped++;
                    continue;
                }

                if ($isCoach && !canManageTeamRoster($teamId, $sportId)) {
                    $rowErrors[] = "Row $line: coaches can only import for their assigned events.";
                    $skipped++;
                    continue;
                }

                try {
                    $findAthlete->execute([$studentId]);
                    $athlete = $findAthlete->fetch();

                    if (!$athlete) {
                        $code = generateAthleteCode();
                        $insertAthlete->execute([
                            $code,
                            $studentId,
                            $firstName,
                            $lastName,
                            $gender,
                            $birthdateVal,
                            $department ?: null,
                            $yearLevel ?: null,
                            $teamId,
                            $email ?: null,
                            $phone ?: null,
                        ]);
                        $athleteId = (int) $db->lastInsertId();
                        $created++;
                        auditLog($_SESSION['user_id'], 'import_create', 'intramural_athlete', $athleteId, null, [
                            'student_id' => $studentId,
                            'season_id' => $seasonId,
                        ]);
                    } else {
                        $athleteId = (int) $athlete['id'];
                        // Scoped users cannot reassign another team's athlete
                        if ($scoped && $userTeamId && !empty($athlete['team_id']) && (int) $athlete['team_id'] !== $userTeamId) {
                            $rowErrors[] = "Row $line: student ID $studentId belongs to another team.";
                            $skipped++;
                            continue;
                        }
                        $updateAthlete->execute([
                            $firstName,
                            $lastName,
                            $gender,
                            $birthdateVal,
                            $department,
                            $yearLevel,
                            $teamId,
                            $email,
                            $phone,
                            $athleteId,
                        ]);
                        $updated++;
                    }

                    $findReg->execute([$athleteId, $sportId, $seasonId]);
                    $existingReg = $findReg->fetch();
                    if ($existingReg) {
                        $oldTeamId = (int) ($existingReg['team_id'] ?? 0);
                        if ($oldTeamId !== $teamId) {
                            $capKey = $teamId . ':' . $sportId;
                            if (!isset($rosterCounts[$capKey])) {
                                $rosterCounts[$capKey] = countTeamEventRoster($teamId, $sportId, (int) $seasonId);
                            }
                            $limit = getSportPlayersPerEventLimit($sportId);
                            if ($limit !== null && $rosterCounts[$capKey] >= $limit) {
                                $rowErrors[] = "Row $line: " . sportLabel($sport)
                                    . " already has the maximum of $limit athlete"
                                    . ($limit === 1 ? '' : 's') . ' for this team.';
                                $skipped++;
                                continue;
                            }
                            $rosterCounts[$capKey]++;
                        }
                        $updateReg->execute([
                            $teamId,
                            $eventCategory ?: null,
                            $jersey ?: null,
                            $position ?: null,
                            (int) $existingReg['id'],
                        ]);
                    } else {
                        $capKey = $teamId . ':' . $sportId;
                        if (!isset($rosterCounts[$capKey])) {
                            $rosterCounts[$capKey] = countTeamEventRoster($teamId, $sportId, (int) $seasonId);
                        }
                        $limit = getSportPlayersPerEventLimit($sportId);
                        if ($limit !== null && $rosterCounts[$capKey] >= $limit) {
                            $rowErrors[] = "Row $line: " . sportLabel($sport)
                                . " allows only $limit athlete" . ($limit === 1 ? '' : 's')
                                . ' per team. Roster is full.';
                            $skipped++;
                            continue;
                        }
                        $insertReg->execute([
                            $seasonId,
                            $athleteId,
                            $sportId,
                            $teamId,
                            $eventCategory ?: null,
                            $jersey ?: null,
                            $position ?: null,
                        ]);
                        $rosterCounts[$capKey]++;
                        $registered++;
                    }
                } catch (Throwable $e) {
                    $rowErrors[] = "Row $line: could not save ($studentId).";
                    $skipped++;
                }
            }

            auditLog($_SESSION['user_id'], 'import_roster', 'intramural_registration', null, null, [
                'season_id' => $seasonId,
                'created' => $created,
                'registered' => $registered,
                'updated' => $updated,
                'skipped' => $skipped,
            ]);

            $results = [
                'created' => $created,
                'registered' => $registered,
                'updated' => $updated,
                'skipped' => $skipped,
                'row_errors' => $rowErrors,
                'total' => count($parsed['rows']),
            ];

            if ($created || $registered || $updated) {
                flash('success', "Import finished: $created new athletes, $registered sport registrations, $updated profiles refreshed.");
            } elseif ($skipped) {
                flash('error', 'Import finished with no successful rows. Check the error list below.');
            }

            } // end capacity OK
        }
    }
}

$pageTitle = 'Import Athlete Roster';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-file-earmark-arrow-up"></i> Import Athlete Roster</h1>
        <p class="text-muted mb-0">
            Bulk-register athletes for the active season. Athlete records are created from this roster import.
            Imports that exceed <strong>players per event</strong> for any team are rejected.
            <?= $season ? sanitize(seasonLabel($season)) : '' ?>
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?download=template" class="btn btn-success"><i class="bi bi-file-earmark-excel"></i> Download Excel Template</a>
        <a href="?download=csv" class="btn btn-outline-success"><i class="bi bi-filetype-csv"></i> CSV Template</a>
        <a href="<?= BASE_URL ?>/intramurals/roster/index.php" class="btn btn-outline-secondary">Back to Roster</a>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if ($results): ?>
<div class="card mb-4 border-primary">
    <div class="card-header bg-primary text-white">Import Summary</div>
    <div class="card-body">
        <div class="row g-3 text-center">
            <div class="col-6 col-md-3"><div class="fw-bold fs-4"><?= (int) $results['total'] ?></div><div class="text-muted small">Rows processed</div></div>
            <div class="col-6 col-md-3"><div class="fw-bold fs-4 text-success"><?= (int) $results['created'] ?></div><div class="text-muted small">New athletes</div></div>
            <div class="col-6 col-md-3"><div class="fw-bold fs-4 text-primary"><?= (int) $results['registered'] ?></div><div class="text-muted small">New sport entries</div></div>
            <div class="col-6 col-md-3"><div class="fw-bold fs-4 text-warning"><?= (int) $results['skipped'] ?></div><div class="text-muted small">Skipped / errors</div></div>
        </div>
        <?php if (!empty($results['row_errors'])): ?>
        <hr>
        <p class="fw-semibold mb-2">Row issues</p>
        <ul class="small mb-0" style="max-height:220px;overflow:auto">
            <?php foreach ($results['row_errors'] as $msg): ?>
            <li><?= sanitize($msg) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><strong>1. Download &amp; fill template</strong></div>
            <div class="card-body">
                <ol class="mb-3">
                    <li>Download the <strong>Excel template</strong> (.xlsx) — rows are pre-filled per team, event, category, and <strong>players per event</strong> from Sports Management.</li>
                    <li>Open it in Microsoft Excel or Google Sheets.</li>
                    <li>Fill athlete details on the <strong>Roster</strong> sheet (keep the header row). Use <strong>Teams</strong> and <strong>Events</strong> sheets for reference.</li>
                    <li>Leave unused slots blank. Only rows with student_id, first name, and last name are imported.</li>
                    <li>Save the file, then upload it here (Excel .xlsx or CSV UTF-8).</li>
                </ol>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="?download=template" class="btn btn-success"><i class="bi bi-file-earmark-excel"></i> Download Excel Template (.xlsx)</a>
                    <a href="?download=csv" class="btn btn-outline-success">CSV version</a>
                </div>
                <p class="text-muted small mt-3 mb-0">
                    Required columns: <code>student_id</code>, <code>first_name</code>, <code>last_name</code>, <code>team</code>, <code>sport</code>.
                    Existing Student IDs will be updated and registered for the sport in the active season.
                </p>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><strong>2. Upload filled file</strong></div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label">Excel or CSV file</label>
                        <input type="file" name="import_file" class="form-control" accept=".xlsx,.xlsm,.csv,text/csv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                    </div>
                    <?php if ($scoped && $userTeamId): ?>
                    <div class="alert alert-info small">
                        Your account is limited to your assigned team. Rows for other teams will be skipped.
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Import Roster</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Valid teams &amp; events</strong>
        <span class="text-muted small">Template rows follow players_per_event from Sports Management</span>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-md-5">
                <h6 class="text-muted">Teams</h6>
                <ul class="mb-0 small">
                    <?php foreach ($teams as $t): ?>
                    <li>
                        <strong><?= sanitize($t['name']) ?></strong>
                        <?php if ($t['short_name']): ?>
                        <span class="text-muted">(or <?= sanitize($t['short_name']) ?>)</span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="col-md-7">
                <h6 class="text-muted">Events</h6>
                <div class="table-responsive" style="max-height:260px;overflow:auto">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Sport</th><th>Category</th><th>Players/Event</th><th>Venue</th></tr></thead>
                        <tbody>
                            <?php foreach ($sports as $s): ?>
                            <tr>
                                <td><?= sanitize($s['name']) ?></td>
                                <td><?= sanitize($s['category']) ?></td>
                                <td><?= !empty($s['players_per_event']) ? (int) $s['players_per_event'] : '—' ?></td>
                                <td><?= sanitize($s['venue'] ?: '—') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
