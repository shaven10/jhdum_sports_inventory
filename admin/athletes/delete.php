<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole(['admin']);

$db = getDB();
$errors = [];
$search = trim((string) (($_SERVER['REQUEST_METHOD'] === 'POST') ? post('search') : get('search')));
$teamFilter = trim((string) (($_SERVER['REQUEST_METHOD'] === 'POST') ? post('team') : get('team')));
$page = max(1, (int) get('page', '1'));
$perPage = 20;

$filterQuery = static function (array $extra = []) use ($search, $teamFilter): string {
    $q = array_filter([
        'search' => $search !== '' ? $search : null,
        'team' => $teamFilter !== '' ? $teamFilter : null,
    ] + $extra, static fn($v) => $v !== null && $v !== '');
    return $q === [] ? '' : ('?' . http_build_query($q));
};

$buildAthleteFilter = static function (string $search, string $teamFilter): array {
    $where = ['1=1'];
    $params = [];
    if ($search !== '') {
        $where[] = '(a.first_name LIKE ? OR a.last_name LIKE ? OR a.student_id LIKE ? OR a.athlete_code LIKE ?)';
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like]);
    }
    if ($teamFilter !== '') {
        if ($teamFilter === '0') {
            $where[] = 'a.team_id IS NULL';
        } else {
            $where[] = 'a.team_id = ?';
            $params[] = (int) $teamFilter;
        }
    }
    return [implode(' AND ', $where), $params];
};

/**
 * Permanently remove an athlete, registrations (CASCADE), and photo file.
 */
$hardDeleteAthlete = static function (PDO $db, array $athlete): void {
    $id = (int) $athlete['id'];
    $photo = $athlete['photo'] ?? null;
    $db->prepare('DELETE FROM intramural_athletes WHERE id = ?')->execute([$id]);
    if (!empty($photo) && function_exists('deleteUploadedFile')) {
        deleteUploadedFile((string) $photo, UPLOAD_PATH_ATHLETES);
    }
};

[$whereClause, $params] = $buildAthleteFilter($search, $teamFilter);
$hasActiveFilter = ($search !== '' || $teamFilter !== '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $action = post('action');

    if ($action === 'delete_one') {
        $id = (int) post('athlete_id');
        $stmt = $db->prepare('SELECT id, student_id, first_name, last_name, photo FROM intramural_athletes WHERE id = ?');
        $stmt->execute([$id]);
        $athlete = $stmt->fetch();
        if (!$athlete) {
            flash('danger', 'Athlete not found.');
        } else {
            try {
                $db->beginTransaction();
                $hardDeleteAthlete($db, $athlete);
                $db->commit();
                auditLog((int) $_SESSION['user_id'], 'hard_delete', 'intramural_athlete', $id, [
                    'student_id' => $athlete['student_id'],
                    'name' => trim($athlete['first_name'] . ' ' . $athlete['last_name']),
                ]);
                flash('success', 'Athlete permanently deleted.');
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                flash('danger', 'Could not delete athlete. Check related records and try again.');
            }
        }
        redirect(BASE_URL . '/admin/athletes/delete.php' . $filterQuery($page > 1 ? ['page' => (string) $page] : []));
    }

    if ($action === 'delete_filtered') {
        $confirm = trim((string) post('confirm_text'));
        $expectedConfirm = $hasActiveFilter ? 'DELETE FILTERED' : 'DELETE ALL';
        if ($confirm !== $expectedConfirm) {
            $errors[] = 'Type ' . $expectedConfirm . ' exactly to confirm.';
        } else {
            try {
                $countStmt = $db->prepare("SELECT COUNT(*) FROM intramural_athletes a WHERE $whereClause");
                $countStmt->execute($params);
                $count = (int) $countStmt->fetchColumn();

                if ($count === 0) {
                    flash('danger', 'No athletes match the current filters.');
                    redirect(BASE_URL . '/admin/athletes/delete.php' . $filterQuery());
                }

                $photoStmt = $db->prepare(
                    "SELECT a.photo FROM intramural_athletes a
                     WHERE $whereClause AND a.photo IS NOT NULL AND a.photo != ''"
                );
                $photoStmt->execute($params);
                $photos = $photoStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

                $db->beginTransaction();
                // Registrations cascade from athletes; clear explicitly for older DBs.
                $db->prepare(
                    "DELETE r FROM intramural_registrations r
                     INNER JOIN intramural_athletes a ON a.id = r.athlete_id
                     WHERE $whereClause"
                )->execute($params);
                $db->prepare("DELETE a FROM intramural_athletes a WHERE $whereClause")->execute($params);
                $db->commit();

                foreach ($photos as $photo) {
                    if (function_exists('deleteUploadedFile')) {
                        deleteUploadedFile((string) $photo, UPLOAD_PATH_ATHLETES);
                    }
                }

                auditLog((int) $_SESSION['user_id'], 'hard_delete_filtered', 'intramural_athlete', null, null, [
                    'deleted_count' => $count,
                    'search' => $search,
                    'team' => $teamFilter,
                ]);
                flash('success', $count . ' athlete(s) permanently deleted'
                    . ($hasActiveFilter ? ' matching the current filters.' : '.'));
                redirect(BASE_URL . '/admin/athletes/delete.php' . $filterQuery());
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $errors[] = 'Delete failed. Some records may still be linked. Try again or check the database.';
            }
        }
    }
}

$teams = $db->query('SELECT id, name FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll() ?: [];

$countStmt = $db->prepare("SELECT COUNT(*) FROM intramural_athletes a WHERE $whereClause");
$countStmt->execute($params);
$totalFiltered = (int) $countStmt->fetchColumn();
$pagination = paginate($totalFiltered, $perPage, $page);

$listStmt = $db->prepare(
    "SELECT a.*, t.name AS team_name,
        (SELECT COUNT(*) FROM intramural_registrations r WHERE r.athlete_id = a.id) AS reg_count
     FROM intramural_athletes a
     LEFT JOIN intramural_teams t ON t.id = a.team_id
     WHERE $whereClause
     ORDER BY a.last_name, a.first_name
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}"
);
$listStmt->execute($params);
$athletes = $listStmt->fetchAll() ?: [];

$totalAthletes = (int) $db->query('SELECT COUNT(*) FROM intramural_athletes')->fetchColumn();
$inactiveCount = (int) $db->query('SELECT COUNT(*) FROM intramural_athletes WHERE is_active = 0')->fetchColumn();

$filterSummaryParts = [];
if ($search !== '') {
    $filterSummaryParts[] = 'search “' . $search . '”';
}
if ($teamFilter === '0') {
    $filterSummaryParts[] = 'no team';
} elseif ($teamFilter !== '') {
    $teamName = 'team #' . $teamFilter;
    foreach ($teams as $t) {
        if ((string) $t['id'] === $teamFilter) {
            $teamName = (string) $t['name'];
            break;
        }
    }
    $filterSummaryParts[] = 'team “' . $teamName . '”';
}
$filterSummary = $filterSummaryParts === []
    ? 'all athletes (no filters applied)'
    : implode(' and ', $filterSummaryParts);
$confirmPhrase = $hasActiveFilter ? 'DELETE FILTERED' : 'DELETE ALL';

$pageTitle = 'Delete Athletes';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-person-x"></i> Delete Athletes</h1>
        <p class="text-muted mb-0">Permanently remove athlete records and their event registrations. This cannot be undone.</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-outline-secondary">Back to Admin</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">Total athletes</div>
                <div class="fs-4 fw-semibold"><?= $totalAthletes ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">Matching filters</div>
                <div class="fs-4 fw-semibold"><?= $totalFiltered ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">Inactive (soft-deleted)</div>
                <div class="fs-4 fw-semibold"><?= $inactiveCount ?></div>
            </div>
        </div>
    </div>
</div>

<div class="filter-bar filter-bar-inline mb-4">
    <form method="GET" class="filter-bar-inline__row">
        <div class="filter-bar-inline__field filter-bar-inline__team">
            <label class="visually-hidden" for="teamFilter">Team</label>
            <select name="team" id="teamFilter" class="form-select">
                <option value="">All teams</option>
                <option value="0" <?= $teamFilter === '0' ? 'selected' : '' ?>>No team</option>
                <?php foreach ($teams as $t): ?>
                <option value="<?= (int) $t['id'] ?>" <?= $teamFilter === (string) $t['id'] ? 'selected' : '' ?>>
                    <?= sanitize($t['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-bar-inline__field filter-bar-inline__search">
            <label class="visually-hidden" for="athleteSearch">Search</label>
            <input type="search" name="search" id="athleteSearch" class="form-control" placeholder="Search name, student ID, or athlete code"
                   value="<?= sanitize($search) ?>">
        </div>
        <div class="filter-bar-inline__actions">
            <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Filter</button>
            <?php if ($hasActiveFilter): ?>
            <a href="<?= BASE_URL ?>/admin/athletes/delete.php" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </div>
    </form>
    <?php if ($hasActiveFilter): ?>
    <div class="small text-muted mt-2 mb-0">
        Showing <?= (int) $totalFiltered ?> of <?= (int) $totalAthletes ?> · <?= sanitize($filterSummary) ?>
    </div>
    <?php endif; ?>
</div>

<div class="card border-danger mb-4">
    <div class="card-header bg-danger text-white">
        <i class="bi bi-exclamation-triangle"></i>
        <?= $hasActiveFilter ? 'Delete filtered athletes' : 'Delete all athletes' ?>
    </div>
    <div class="card-body">
        <p class="mb-2">
            <?php if ($hasActiveFilter): ?>
            Removes <strong><?= (int) $totalFiltered ?></strong> athlete(s) matching
            <strong><?= sanitize($filterSummary) ?></strong>, including their sport registrations and photos.
            Athletes outside this filter are left untouched.
            <?php else: ?>
            No filters are applied, so this removes <strong>every</strong> athlete (<?= (int) $totalAthletes ?>)
            and all sport registrations. Photos on disk are removed too.
            <?php endif; ?>
            Match results are kept (they do not store athlete IDs).
        </p>
        <p class="small text-muted mb-3">Current filter: <?= sanitize($filterSummary) ?>.</p>
        <form method="POST" class="row g-2 align-items-end"
              onsubmit="return confirm('Permanently delete <?= (int) $totalFiltered ?> athlete(s) matching the current filters? This cannot be undone.');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_filtered">
            <input type="hidden" name="search" value="<?= sanitize($search) ?>">
            <input type="hidden" name="team" value="<?= sanitize($teamFilter) ?>">
            <div class="col-md-6">
                <label class="form-label" for="confirm_text">Type <code><?= sanitize($confirmPhrase) ?></code> to confirm</label>
                <input type="text" name="confirm_text" id="confirm_text" class="form-control" autocomplete="off"
                       <?= $totalFiltered === 0 ? 'disabled' : '' ?>>
            </div>
            <div class="col-md-auto">
                <button type="submit" class="btn btn-danger" <?= $totalFiltered === 0 ? 'disabled' : '' ?>>
                    <?= $hasActiveFilter ? 'Delete filtered athletes' : 'Delete all athletes' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        Delete individual athletes
        <span class="text-muted small ms-1">(<?= (int) $totalFiltered ?> matching)</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($athletes)): ?>
        <div class="p-4 text-muted">No athletes found.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Team</th>
                        <th>Regs</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($athletes as $a): ?>
                    <tr>
                        <td><?= sanitize($a['athlete_code']) ?></td>
                        <td><?= sanitize($a['student_id']) ?></td>
                        <td><?= sanitize(formatAthleteName(trim($a['last_name'] . ', ' . $a['first_name']))) ?></td>
                        <td><?= sanitize($a['team_name'] ?: '—') ?></td>
                        <td><?= (int) ($a['reg_count'] ?? 0) ?></td>
                        <td>
                            <?= !empty($a['is_active'])
                                ? '<span class="badge bg-success">Active</span>'
                                : '<span class="badge bg-secondary">Inactive</span>' ?>
                        </td>
                        <td class="text-end">
                            <form method="POST" class="d-inline" onsubmit="return confirm('Permanently delete this athlete and their registrations?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_one">
                                <input type="hidden" name="athlete_id" value="<?= (int) $a['id'] ?>">
                                <input type="hidden" name="search" value="<?= sanitize($search) ?>">
                                <input type="hidden" name="team" value="<?= sanitize($teamFilter) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (($pagination['total_pages'] ?? 1) > 1): ?>
        <div class="p-3 border-top">
            <?= paginationLinks($pagination, BASE_URL . '/admin/athletes/delete.php' . $filterQuery()) ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
