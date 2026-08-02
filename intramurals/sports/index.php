<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$db = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && canManageIntramurals()) {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/intramurals/sports/index.php');
    }

    $action = post('action');

    if ($action === 'add' || $action === 'edit') {
        $id = (int) post('id');
        $name = post('name');
        $category = post('category', 'mixed');
        $description = post('description');
        $scoring = post('scoring_method', 'points');
        $rules = post('rules');
        $scheduleNotes = post('schedule_notes');
        $tournamentFormat = post('tournament_format', 'round_robin');
        $formatNotes = post('format_notes');
        $winPoints = max(0, (int) post('win_points', '3'));
        $drawPoints = max(0, (int) post('draw_points', '1'));
        $lossPoints = max(0, (int) post('loss_points', '0'));
        $pointSchemeId = (int) post('point_scheme_id') ?: null;

        if ($name === '') {
            $errors[] = 'Sport name is required.';
        }
        if (!in_array($category, ['men', 'women', 'mixed'], true)) {
            $errors[] = 'Invalid category.';
        }
        if (!array_key_exists($tournamentFormat, tournamentFormatLabels())) {
            $errors[] = 'Invalid tournament format.';
        }

        if (empty($errors)) {
            if ($action === 'add') {
                try {
                    $stmt = $db->prepare('INSERT INTO intramural_sports (name, description, category, scoring_method, rules, schedule_notes, tournament_format, format_notes, win_points, draw_points, loss_points, point_scheme_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$name, $description, $category, $scoring, $rules, $scheduleNotes, $tournamentFormat, $formatNotes ?: null, $winPoints, $drawPoints, $lossPoints, $pointSchemeId]);
                    auditLog($_SESSION['user_id'], 'create', 'intramural_sport', (int) $db->lastInsertId(), null, ['name' => $name, 'category' => $category, 'tournament_format' => $tournamentFormat]);
                    flash('success', 'Sport added successfully.');
                } catch (PDOException $e) {
                    flash('error', 'Sport with this name and category already exists.');
                }
            } else {
                try {
                    $stmt = $db->prepare('UPDATE intramural_sports SET name=?, description=?, category=?, scoring_method=?, rules=?, schedule_notes=?, tournament_format=?, format_notes=?, win_points=?, draw_points=?, loss_points=?, point_scheme_id=? WHERE id=?');
                    $stmt->execute([$name, $description, $category, $scoring, $rules, $scheduleNotes, $tournamentFormat, $formatNotes ?: null, $winPoints, $drawPoints, $lossPoints, $pointSchemeId, $id]);
                    auditLog($_SESSION['user_id'], 'update', 'intramural_sport', $id, null, ['name' => $name, 'tournament_format' => $tournamentFormat]);
                    flash('success', 'Sport updated successfully.');
                } catch (PDOException $e) {
                    flash('error', 'Could not update sport (duplicate name/category?).');
                }
            }
            redirect(BASE_URL . '/intramurals/sports/index.php');
        }
    }

    if ($action === 'delete') {
        $id = (int) post('id');
        $db->prepare('UPDATE intramural_sports SET is_active = 0 WHERE id = ?')->execute([$id]);
        auditLog($_SESSION['user_id'], 'delete', 'intramural_sport', $id);
        flash('success', 'Sport deactivated.');
        redirect(BASE_URL . '/intramurals/sports/index.php');
    }

    if ($action === 'restore') {
        $id = (int) post('id');
        $db->prepare('UPDATE intramural_sports SET is_active = 1 WHERE id = ?')->execute([$id]);
        flash('success', 'Sport restored.');
        redirect(BASE_URL . '/intramurals/sports/index.php');
    }
}

$editId = (int) get('edit');
$editSport = null;
if ($editId) {
    $stmt = $db->prepare('SELECT * FROM intramural_sports WHERE id = ?');
    $stmt->execute([$editId]);
    $editSport = $stmt->fetch() ?: null;
}

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
    ORDER BY s.is_active DESC, s.name, s.category")->fetchAll();
$pointSchemes = getAllPointSchemes(true);

$pageTitle = 'Sports Management';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-trophy"></i> Sports Management</h1>
        <p class="text-muted mb-0">Sports, agreed tournament styles, and placement point schemes</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/intramurals/points/index.php" class="btn btn-outline-primary"><i class="bi bi-calculator"></i> Point System</a>
        <a href="<?= BASE_URL ?>/intramurals/index.php" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

<div class="row g-4">
    <?php if (canManageIntramurals()): ?>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><?= $editSport ? 'Edit Sport' : 'Add Sport' ?></div>
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editSport ? 'edit' : 'add' ?>">
                    <?php if ($editSport): ?><input type="hidden" name="id" value="<?= $editSport['id'] ?>"><?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Sport Name *</label>
                        <input type="text" name="name" class="form-control" required value="<?= sanitize($editSport['name'] ?? post('name')) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category *</label>
                        <select name="category" class="form-select" required>
                            <?php foreach (['men' => 'Men', 'women' => 'Women', 'mixed' => 'Mixed'] as $val => $label): ?>
                            <option value="<?= $val ?>" <?= ($editSport['category'] ?? post('category', 'mixed')) === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Scoring Method</label>
                        <select name="scoring_method" class="form-select">
                            <?php foreach (['points', 'sets', 'games', 'time'] as $m): ?>
                            <option value="<?= $m ?>" <?= ($editSport['scoring_method'] ?? post('scoring_method', 'points')) === $m ? 'selected' : '' ?>><?= ucfirst($m) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Placement Point Scheme</label>
                        <select name="point_scheme_id" class="form-select">
                            <option value="">Default (10/7/5/3/2/1)</option>
                            <?php foreach ($pointSchemes as $ps): ?>
                            <option value="<?= $ps['id'] ?>" <?= (int) ($editSport['point_scheme_id'] ?? post('point_scheme_id')) === (int) $ps['id'] ? 'selected' : '' ?>>
                                <?= sanitize($ps['name']) ?> (<?= sanitize(formatSchemePoints($ps)) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Used for overall rankings (Champion → 5th Runner Up).</div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-4"><label class="form-label">Match Win</label><input type="number" name="win_points" class="form-control" min="0" value="<?= (int) ($editSport['win_points'] ?? post('win_points', '3')) ?>"></div>
                        <div class="col-4"><label class="form-label">Match Draw</label><input type="number" name="draw_points" class="form-control" min="0" value="<?= (int) ($editSport['draw_points'] ?? post('draw_points', '1')) ?>"></div>
                        <div class="col-4"><label class="form-label">Match Loss</label><input type="number" name="loss_points" class="form-control" min="0" value="<?= (int) ($editSport['loss_points'] ?? post('loss_points', '0')) ?>"></div>
                    </div>
                    <div class="form-text mb-3">Match W/D/L points only rank teams within this event.</div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"><?= sanitize($editSport['description'] ?? post('description')) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Game Rules</label>
                        <textarea name="rules" class="form-control" rows="3"><?= sanitize($editSport['rules'] ?? post('rules')) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Event Schedule Notes</label>
                        <textarea name="schedule_notes" class="form-control" rows="2"><?= sanitize($editSport['schedule_notes'] ?? post('schedule_notes')) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Agreed Tournament Style *</label>
                        <select name="tournament_format" class="form-select" required>
                            <?php foreach (tournamentFormatLabels() as $val => $label): ?>
                            <option value="<?= $val ?>" <?= ($editSport['tournament_format'] ?? post('tournament_format', 'round_robin')) === $val ? 'selected' : '' ?>><?= sanitize($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Tabulators use this when building the match schedule manually.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Format Notes (agreed details)</label>
                        <textarea name="format_notes" class="form-control" rows="2" placeholder="e.g. Best of 3, top 4 advance, seeding rules"><?= sanitize($editSport['format_notes'] ?? post('format_notes')) ?></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><?= $editSport ? 'Update' : 'Add Sport' ?></button>
                        <?php if ($editSport): ?><a href="<?= BASE_URL ?>/intramurals/sports/index.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="col-lg-<?= canManageIntramurals() ? '8' : '12' ?>">
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Sport</th>
                                <th>Category</th>
                                <th>Tournament Style</th>
                                <th>Placement Scheme</th>
                                <th>Match W/D/L</th>
                                <th>Athletes</th>
                                <th>Matches</th>
                                <th>Status</th>
                                <?php if (canManageIntramurals()): ?><th>Actions</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sports as $s): ?>
                            <tr class="<?= $s['is_active'] ? '' : 'table-secondary' ?>">
                                <td>
                                    <strong><?= sanitize($s['name']) ?></strong>
                                    <?php if ($s['rules']): ?><br><small class="text-muted"><?= sanitize(strlen($s['rules']) > 60 ? substr($s['rules'], 0, 57) . '...' : $s['rules']) ?></small><?php endif; ?>
                                </td>
                                <td><?= ucfirst($s['category']) ?></td>
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
                                <td><?= $s['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                                <?php if (canManageIntramurals()): ?>
                                <td class="text-nowrap">
                                    <a href="?edit=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                        <?php if ($s['is_active']): ?>
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Deactivate this sport?">Delete</button>
                                        <?php else: ?>
                                        <input type="hidden" name="action" value="restore">
                                        <button type="submit" class="btn btn-sm btn-outline-success">Restore</button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($sports)): ?>
                            <tr><td colspan="9" class="text-muted p-3">No sports yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
