<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

if (!canManageTeamAthletes()) {
    flash('error', 'You do not have permission to import athletes.');
    redirect(BASE_URL . '/intramurals/athletes/index.php');
}

$userTeamId = getUserTeamId();
$scoped = isTeamScopedRole() && hasRole('unit_manager');

$download = get('download');
if ($download === 'template' || $download === 'xlsx') {
    downloadAthleteImportTemplate('xlsx', $scoped ? $userTeamId : null);
}
if ($download === 'csv') {
    downloadAthleteImportTemplate('csv', $scoped ? $userTeamId : null);
}

requireWritableSeason();

$db = getDB();
$seasonId = getCurrentSeasonId();
$season = getCurrentSeason();
$lookups = buildRosterImportLookups();

$teams = $db->query('SELECT id, name, short_name FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
if ($scoped && $userTeamId) {
    $teams = array_values(array_filter($teams, fn($t) => (int) $t['id'] === $userTeamId));
}
$sports = $db->query('SELECT id, name, category FROM intramural_sports WHERE is_active = 1 ORDER BY name, category')->fetchAll();

$userTeamName = '';
if ($userTeamId) {
    foreach ($teams as $t) {
        if ((int) $t['id'] === $userTeamId) {
            $userTeamName = $t['name'];
            break;
        }
    }
}

$results = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/intramurals/athletes/import.php');
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
        $parsed = parseAthleteImportFile($_FILES['import_file']['tmp_name'], (string) $_FILES['import_file']['name']);
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

            $findAthlete = $db->prepare('SELECT * FROM intramural_athletes WHERE student_id = ? LIMIT 1');
            $insertAthlete = $db->prepare('INSERT INTO intramural_athletes (athlete_code, student_id, first_name, last_name, gender, birthdate, department, year_level, team_id, email, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $updateAthlete = $db->prepare('UPDATE intramural_athletes SET first_name=?, last_name=?, gender=?, birthdate=COALESCE(?, birthdate), department=COALESCE(NULLIF(?, ""), department), year_level=COALESCE(NULLIF(?, ""), year_level), team_id=?, email=COALESCE(NULLIF(?, ""), email), phone=COALESCE(NULLIF(?, ""), phone), is_active=1 WHERE id=?');
            $findReg = $db->prepare('SELECT id FROM intramural_registrations WHERE athlete_id = ? AND sport_id = ? AND season_id = ? LIMIT 1');
            $insertReg = $db->prepare('INSERT INTO intramural_registrations (season_id, athlete_id, sport_id, team_id, event_category, jersey_number, position) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $updateReg = $db->prepare('UPDATE intramural_registrations SET team_id=?, event_category=?, jersey_number=?, position=? WHERE id=?');

            foreach ($parsed['rows'] as $index => $row) {
                $line = $index + 2;
                $studentId = trim($row['student_id'] ?? '');
                $firstName = trim($row['first_name'] ?? '');
                $lastName = trim($row['last_name'] ?? '');
                $gender = strtolower(trim($row['gender'] ?? 'male'));
                $birthdate = trim($row['birthdate'] ?? '');
                $department = trim($row['department'] ?? '');
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

                $teamId = null;
                if ($teamName !== '') {
                    $team = $lookups['teams'][strtolower($teamName)] ?? null;
                    if (!$team) {
                        $rowErrors[] = "Row $line: unknown team \"$teamName\".";
                        $skipped++;
                        continue;
                    }
                    $teamId = (int) $team['id'];
                } elseif ($scoped && $userTeamId) {
                    $teamId = $userTeamId;
                } elseif (!$scoped) {
                    $rowErrors[] = "Row $line: team is required.";
                    $skipped++;
                    continue;
                } else {
                    $rowErrors[] = "Row $line: your account is not assigned to a team.";
                    $skipped++;
                    continue;
                }

                if ($scoped && $userTeamId && $teamId !== $userTeamId) {
                    $rowErrors[] = "Row $line: you can only import athletes for your assigned team.";
                    $skipped++;
                    continue;
                }

                if (!canManageTeamAthletes($teamId)) {
                    $rowErrors[] = "Row $line: no permission for this team.";
                    $skipped++;
                    continue;
                }

                $sportId = null;
                if ($sportName !== '') {
                    $sport = resolveImportSport($lookups, $sportName, $sportCategory);
                    if (!$sport) {
                        $hint = $sportCategory !== '' ? " ($sportCategory)" : '';
                        $rowErrors[] = "Row $line: unknown sport \"$sportName\"$hint.";
                        $skipped++;
                        continue;
                    }
                    $sportId = (int) $sport['id'];
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

                    if ($sportId && $seasonId) {
                        $findReg->execute([$athleteId, $sportId, $seasonId]);
                        $existingReg = $findReg->fetch();
                        if ($existingReg) {
                            $updateReg->execute([
                                $teamId,
                                $eventCategory ?: null,
                                $jersey ?: null,
                                $position ?: null,
                                (int) $existingReg['id'],
                            ]);
                        } else {
                            $insertReg->execute([
                                $seasonId,
                                $athleteId,
                                $sportId,
                                $teamId,
                                $eventCategory ?: null,
                                $jersey ?: null,
                                $position ?: null,
                            ]);
                            $registered++;
                        }
                    }
                } catch (Throwable $e) {
                    $rowErrors[] = "Row $line: could not save ($studentId).";
                    $skipped++;
                }
            }

            auditLog($_SESSION['user_id'], 'import_athletes', 'intramural_athlete', null, null, [
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
                flash('success', "Import finished: $created new athletes, $updated updated, $registered sport registrations.");
            } elseif ($skipped) {
                flash('error', 'Import finished with no successful rows. Check the error list below.');
            }
        }
    }
}

$pageTitle = 'Import Athletes';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-file-earmark-arrow-up"></i> Import Athletes</h1>
        <p class="text-muted mb-0">
            Bulk-register athletes<?= $season ? ' for ' . sanitize(seasonLabel($season)) : '' ?>
            <?php if ($scoped && $userTeamName): ?>
            — <span class="badge bg-warning text-dark"><?= sanitize($userTeamName) ?></span>
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?download=template" class="btn btn-success"><i class="bi bi-file-earmark-excel"></i> Download Excel Template</a>
        <a href="?download=csv" class="btn btn-outline-success"><i class="bi bi-filetype-csv"></i> CSV Template</a>
        <a href="<?= BASE_URL ?>/intramurals/athletes/index.php" class="btn btn-outline-secondary">Back to Athletes</a>
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
            <div class="col-6 col-md-3"><div class="fw-bold fs-4 text-info"><?= (int) $results['updated'] ?></div><div class="text-muted small">Updated profiles</div></div>
            <div class="col-6 col-md-3"><div class="fw-bold fs-4 text-warning"><?= (int) $results['skipped'] ?></div><div class="text-muted small">Skipped / errors</div></div>
        </div>
        <?php if ((int) $results['registered'] > 0): ?>
        <p class="text-center text-muted small mt-2 mb-0"><?= (int) $results['registered'] ?> new sport registration(s) added.</p>
        <?php endif; ?>
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
                    <li>Download the <strong>Excel template</strong> (.xlsx).</li>
                    <li>Open it in Microsoft Excel or Google Sheets.</li>
                    <li>Fill the <strong>Athletes</strong> sheet (keep the header row).</li>
                    <?php if ($scoped && $userTeamName): ?>
                    <li>Leave the <strong>team</strong> column blank to use <strong><?= sanitize($userTeamName) ?></strong>, or enter your team name.</li>
                    <?php else: ?>
                    <li>Use exact team names from the <strong>Teams</strong> sheet.</li>
                    <?php endif; ?>
                    <li>Sport columns are optional — add them to register athletes for a sport in one step.</li>
                </ol>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="?download=template" class="btn btn-success"><i class="bi bi-file-earmark-excel"></i> Download Excel Template (.xlsx)</a>
                    <a href="?download=csv" class="btn btn-outline-success">CSV version</a>
                </div>
                <p class="text-muted small mt-3 mb-0">
                    Required: <code>student_id</code>, <code>first_name</code>, <code>last_name</code>.
                    Existing Student IDs are updated. Optional sport columns register the athlete for the active season.
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
                    <?php if ($scoped && $userTeamName): ?>
                    <div class="alert alert-info small mb-3">
                        Imports are limited to <strong><?= sanitize($userTeamName) ?></strong>. Rows for other teams will be skipped.
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Import Athletes</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if (!$scoped || count($teams) > 1): ?>
<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Valid team &amp; sport names</strong>
        <span class="text-muted small">Reference for the template</span>
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
                <h6 class="text-muted">Sports (optional)</h6>
                <div class="table-responsive" style="max-height:260px;overflow:auto">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Sport</th><th>Category</th></tr></thead>
                        <tbody>
                            <?php foreach ($sports as $s): ?>
                            <tr>
                                <td><?= sanitize($s['name']) ?></td>
                                <td><?= sanitize($s['category']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
