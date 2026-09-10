<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
requireRubricsAccess();
ensureEventRubricTables();
ensureSportEventGroupColumn();

$db = getDB();
$sportId = (int) (get('sport') ?: post('sport_id'));
$sports = filterSportsForUser($db->query('SELECT * FROM intramural_sports ORDER BY ' . intramuralSportsOrderBy())->fetchAll());
$sport = null;
foreach ($sports as $s) {
    if ((int) $s['id'] === $sportId) {
        $sport = $s;
        break;
    }
}

if ($sportId <= 0 || !$sport) {
    flash('error', 'Select a socio-cultural event to edit its rubric.');
    redirect(BASE_URL . '/intramurals/rubrics/index.php');
}
if (!isSocioCulturalSport($sport)) {
    flash('error', 'Rubrics are for socio-cultural events only.');
    redirect(BASE_URL . '/intramurals/rubrics/index.php');
}
if (!canManageEventRubricCriteria($sportId)) {
    flash('error', 'You do not have permission to edit this event’s rubric.');
    redirect(BASE_URL . '/intramurals/rubrics/index.php');
}

$errors = [];
$redirectTo = BASE_URL . '/intramurals/rubrics/edit.php?sport=' . $sportId;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    requireWritableSeason();
    $action = post('action');

    if ($action === 'save_criteria') {
        $rows = $_POST['criteria'] ?? [];
        if (!is_array($rows)) {
            $rows = [];
        }
        $seenNames = [];
        $clean = [];
        foreach ($rows as $cid => $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $description = trim((string) ($row['description'] ?? ''));
            $maxRaw = trim((string) ($row['max_points'] ?? ''));
            $sort = (int) ($row['sort_order'] ?? 0);
            if ($name === '' && $maxRaw === '' && $description === '') {
                continue;
            }
            if ($name === '') {
                $errors[] = 'Each criterion needs a name.';
                continue;
            }
            $key = strtolower($name);
            if (isset($seenNames[$key])) {
                $errors[] = 'Criterion names must be unique (' . $name . ').';
                continue;
            }
            $seenNames[$key] = true;
            if ($maxRaw === '' || !is_numeric($maxRaw) || (float) $maxRaw <= 0) {
                $errors[] = 'Maximum points for ' . $name . ' must be greater than 0.';
                continue;
            }
            $clean[] = [
                'id' => (int) $cid,
                'name' => $name,
                'description' => $description,
                'max_points' => round((float) $maxRaw, 2),
                'sort_order' => max(0, $sort),
            ];
        }

        $newName = trim(post('new_name'));
        $newDesc = trim(post('new_description'));
        $newMax = trim(post('new_max_points'));
        if ($newName !== '') {
            $key = strtolower($newName);
            if (isset($seenNames[$key])) {
                $errors[] = 'Criterion names must be unique (' . $newName . ').';
            } elseif ($newMax === '' || !is_numeric($newMax) || (float) $newMax <= 0) {
                $errors[] = 'Maximum points for the new criterion must be greater than 0.';
            } else {
                $clean[] = [
                    'id' => 0,
                    'name' => $newName,
                    'description' => $newDesc,
                    'max_points' => round((float) $newMax, 2),
                    'sort_order' => count($clean) + 1,
                ];
            }
        }

        if (!$errors) {
            $existingIds = array_map(static fn($c) => (int) $c['id'], getEventRubricCriteria($sportId));
            $keptIds = [];
            $update = $db->prepare('UPDATE intramural_event_rubric_criteria SET name=?, description=?, max_points=?, sort_order=? WHERE id=? AND sport_id=?');
            $insert = $db->prepare('INSERT INTO intramural_event_rubric_criteria (sport_id, name, description, max_points, sort_order) VALUES (?, ?, ?, ?, ?)');
            try {
                $db->beginTransaction();
                foreach ($clean as $i => $row) {
                    $sort = $row['sort_order'] > 0 ? $row['sort_order'] : ($i + 1);
                    $desc = $row['description'] !== '' ? $row['description'] : null;
                    if ($row['id'] > 0 && in_array($row['id'], $existingIds, true)) {
                        $update->execute([$row['name'], $desc, $row['max_points'], $sort, $row['id'], $sportId]);
                        $keptIds[] = $row['id'];
                    } else {
                        $insert->execute([$sportId, $row['name'], $desc, $row['max_points'], $sort]);
                    }
                }
                $deleteIds = array_values(array_diff($existingIds, $keptIds));
                if ($deleteIds) {
                    $ph = implode(',', array_fill(0, count($deleteIds), '?'));
                    $del = $db->prepare("DELETE FROM intramural_event_rubric_criteria WHERE sport_id = ? AND id IN ($ph)");
                    $del->execute(array_merge([$sportId], $deleteIds));
                }
                $db->commit();
                auditLog($_SESSION['user_id'], 'save_event_rubric', 'intramural_sport', $sportId, null, [
                    'criteria' => count($clean),
                ]);
                flash('success', 'Rubric saved for ' . sportLabel($sport) . '.');
                redirect($redirectTo);
            } catch (PDOException $e) {
                $db->rollBack();
                $errors[] = 'Could not save rubric (duplicate criterion name?).';
            }
        }
    }

    if ($action === 'delete_criterion') {
        $cid = (int) post('criterion_id');
        if ($cid > 0) {
            $db->prepare('DELETE FROM intramural_event_rubric_criteria WHERE id = ? AND sport_id = ?')->execute([$cid, $sportId]);
            auditLog($_SESSION['user_id'], 'delete_rubric_criterion', 'intramural_sport', $sportId, null, ['criterion_id' => $cid]);
            flash('success', 'Criterion removed.');
            redirect($redirectTo);
        }
    }

    if ($action === 'restore_defaults') {
        $db->prepare('DELETE FROM intramural_event_rubric_criteria WHERE sport_id = ?')->execute([$sportId]);
        $created = seedDefaultRubricForSport($sportId, (string) $sport['name']);
        auditLog($_SESSION['user_id'], 'restore_event_rubric', 'intramural_sport', $sportId, null, ['criteria' => $created]);
        flash('success', 'Default rubric restored for ' . sportLabel($sport) . '. Saved panel scores for removed criteria were cleared.');
        redirect($redirectTo);
    }
}

$criteria = getEventRubricCriteria($sportId);
$maxTotal = eventRubricMaxTotal($criteria);

$pageTitle = 'Rubric — ' . sportLabel($sport);
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="mb-1"><i class="bi bi-sliders"></i> <?= sanitize($sport['name']) ?> rubric</h1>
        <p class="text-muted mb-0">
            <span class="badge bg-info text-dark"><?= sanitize(sportEventGroupLabel(sportEventGroupOf($sport))) ?></span>
            Criteria total <?= formatRubricPoints($maxTotal) ?> pts
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/intramurals/rubrics/index.php" class="btn btn-outline-secondary">All rubrics</a>
        <a href="<?= BASE_URL ?>/intramurals/rubrics/score.php?sport=<?= $sportId ?>" class="btn btn-primary"><i class="bi bi-pencil-square"></i> Judging sheet</a>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="sport_id" value="<?= $sportId ?>">
    <input type="hidden" name="action" value="save_criteria">
    <div class="card mb-3">
        <div class="card-header">Criteria</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:4.5rem;">Order</th>
                        <th>Criterion</th>
                        <th>Description</th>
                        <th style="width:8rem;">Max pts</th>
                        <th style="width:4rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$criteria): ?>
                    <tr><td colspan="5" class="text-muted p-4">No criteria yet. Add one below or restore the default rubric.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($criteria as $c): ?>
                    <?php $cid = (int) $c['id']; ?>
                    <tr>
                        <td>
                            <input type="number" name="criteria[<?= $cid ?>][sort_order]" class="form-control form-control-sm" min="1" value="<?= (int) $c['sort_order'] ?>">
                        </td>
                        <td>
                            <input type="text" name="criteria[<?= $cid ?>][name]" class="form-control form-control-sm" required maxlength="150" value="<?= sanitize($c['name']) ?>">
                        </td>
                        <td>
                            <input type="text" name="criteria[<?= $cid ?>][description]" class="form-control form-control-sm" maxlength="255" value="<?= sanitize((string) ($c['description'] ?? '')) ?>">
                        </td>
                        <td>
                            <input type="number" name="criteria[<?= $cid ?>][max_points]" class="form-control form-control-sm" min="0.01" step="0.01" required value="<?= sanitize(formatRubricPoints($c['max_points'])) ?>">
                        </td>
                        <td>
                            <button type="submit" class="btn btn-sm btn-outline-danger" form="deleteCriterionForm" name="criterion_id" value="<?= $cid ?>" data-confirm="Remove this criterion? Related panel scores will also be deleted.">&times;</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="table-light">
                        <td class="text-muted small pt-3">New</td>
                        <td>
                            <input type="text" name="new_name" class="form-control form-control-sm" maxlength="150" placeholder="Add criterion">
                        </td>
                        <td>
                            <input type="text" name="new_description" class="form-control form-control-sm" maxlength="255" placeholder="Optional">
                        </td>
                        <td>
                            <input type="number" name="new_max_points" class="form-control form-control-sm" min="0.01" step="0.01" placeholder="e.g. 25">
                        </td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between flex-wrap gap-2">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save rubric</button>
            <button type="submit" class="btn btn-outline-secondary" form="restoreRubricForm" data-confirm="Replace this rubric with the default criteria? Existing panel scores will be cleared.">
                Restore default rubric
            </button>
        </div>
    </div>
</form>

<form method="POST" id="deleteCriterionForm">
    <?= csrfField() ?>
    <input type="hidden" name="sport_id" value="<?= $sportId ?>">
    <input type="hidden" name="action" value="delete_criterion">
</form>
<form method="POST" id="restoreRubricForm">
    <?= csrfField() ?>
    <input type="hidden" name="sport_id" value="<?= $sportId ?>">
    <input type="hidden" name="action" value="restore_defaults">
</form>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
