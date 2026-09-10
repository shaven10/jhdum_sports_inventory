<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();

if (!canManageTeamAthletes() && !canManageIntramurals()) {
    flash('error', 'You do not have permission to merge athlete accounts.');
    redirect(BASE_URL . '/intramurals/roster/index.php');
}

$db = getDB();
$seasonId = getCurrentSeasonId();
$season = getCurrentSeason();
$userTeamId = getUserTeamId();
$search = get('search');
$teamId = get('team');
$lockTeamFilter = hasRole('unit_manager') && !canManageIntramurals() && (bool) $userTeamId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (array_key_exists('search', $_POST)) {
        $search = trim((string) $_POST['search']);
    }
    if (array_key_exists('team', $_POST)) {
        $teamId = trim((string) $_POST['team']);
    }
}

if ($lockTeamFilter) {
    $teamId = $userTeamId ? (string) $userTeamId : '0';
}

$filterTeamId = null;
if ($teamId !== '') {
    $filterTeamId = (int) $teamId;
} elseif ($lockTeamFilter && $userTeamId) {
    $filterTeamId = (int) $userTeamId;
}

$errors = [];

$buildFilterQuery = static function (array $extra = []) use ($search, $teamId): string {
    $q = array_filter([
        'search' => trim((string) $search) !== '' ? trim((string) $search) : null,
        'team' => $teamId !== '' ? $teamId : null,
    ] + $extra, static fn($v) => $v !== null && $v !== '');

    return $q === [] ? '' : ('?' . http_build_query($q));
};

$loadFilteredGroups = static function () use ($seasonId, $filterTeamId, $search): array {
    $groups = getDuplicateAthleteNameGroups($seasonId, $filterTeamId);

    return filterDuplicateAthleteGroupsBySearch($groups, $search);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/intramurals/roster/duplicates.php' . $buildFilterQuery());
    }

    requireUnlockedRoster();

    $action = post('action');
    $redirectSuffix = $buildFilterQuery();

    if ($action === 'merge_one') {
        $keepId = (int) post('keep_id');
        $mergeId = (int) post('merge_id');

        $keepStmt = $db->prepare('SELECT * FROM intramural_athletes WHERE id = ?');
        $keepStmt->execute([$keepId]);
        $keep = $keepStmt->fetch();
        $mergeStmt = $db->prepare('SELECT * FROM intramural_athletes WHERE id = ?');
        $mergeStmt->execute([$mergeId]);
        $merge = $mergeStmt->fetch();

        if (!$keep || !$merge) {
            $errors[] = 'Athlete not found.';
        } elseif ($filterTeamId && ((int) ($keep['team_id'] ?? 0) !== $filterTeamId || (int) ($merge['team_id'] ?? 0) !== $filterTeamId)) {
            $errors[] = 'You can only merge athletes on your assigned team.';
        } elseif (!canManageTeamAthletes((int) ($keep['team_id'] ?? 0)) && !canManageIntramurals()) {
            $errors[] = 'You do not have permission to merge these athletes.';
        } else {
            $result = mergeAthleteAccounts($keepId, $mergeId);
            if ($result['ok']) {
                flash('success', $result['message']);
            } else {
                flash('error', $result['message']);
            }
        }
        redirect(BASE_URL . '/intramurals/roster/duplicates.php' . $redirectSuffix);
    }

    if ($action === 'merge_group') {
        $nameKey = trim((string) post('name_key'));
        $keepId = (int) post('keep_id');
        $group = null;
        foreach ($loadFilteredGroups() as $g) {
            if ($g['name_key'] === $nameKey) {
                $group = $g;
                break;
            }
        }

        if (!$group || $keepId <= 0) {
            $errors[] = 'Duplicate group not found in the current filter.';
        } else {
            $result = mergeDuplicateAthleteGroup($group, $keepId, $filterTeamId);
            if ($result['merged'] > 0 && empty($result['errors'])) {
                flash('success', 'Merged ' . $result['merged'] . ' duplicate account' . ($result['merged'] === 1 ? '' : 's') . ' into one profile.');
                redirect(BASE_URL . '/intramurals/roster/duplicates.php' . $redirectSuffix);
            } elseif ($result['merged'] > 0) {
                flash('warning', 'Merged ' . $result['merged'] . ' account(s) with some errors.');
                redirect(BASE_URL . '/intramurals/roster/duplicates.php' . $redirectSuffix);
            } else {
                $errors = array_merge($errors, $result['errors'] ?: ['No accounts were merged.']);
            }
        }
    }

    if ($action === 'merge_all') {
        $confirm = trim((string) post('confirm_text'));
        if ($confirm !== 'MERGE ALL') {
            $errors[] = 'Type MERGE ALL exactly to confirm merging every filtered duplicate group.';
        } else {
            $groups = $loadFilteredGroups();
            if ($groups === []) {
                flash('error', 'No duplicate groups match the current filters.');
                redirect(BASE_URL . '/intramurals/roster/duplicates.php' . $redirectSuffix);
            }

            $groupsMerged = 0;
            $accountsMerged = 0;
            $mergeErrors = [];

            foreach ($groups as $group) {
                $keep = pickDefaultAthleteAccountToKeep($group['athletes']);
                $keepId = (int) $keep['id'];
                $result = mergeDuplicateAthleteGroup($group, $keepId, $filterTeamId);
                if ($result['merged'] > 0) {
                    $groupsMerged++;
                    $accountsMerged += $result['merged'];
                }
                $mergeErrors = array_merge($mergeErrors, $result['errors']);
            }

            if ($accountsMerged > 0 && empty($mergeErrors)) {
                flash('success', 'Merged ' . $accountsMerged . ' duplicate account' . ($accountsMerged === 1 ? '' : 's')
                    . ' across ' . $groupsMerged . ' name group' . ($groupsMerged === 1 ? '' : 's') . '.');
                redirect(BASE_URL . '/intramurals/roster/duplicates.php' . $redirectSuffix);
            } elseif ($accountsMerged > 0) {
                flash('warning', 'Merged ' . $accountsMerged . ' account(s) across ' . $groupsMerged . ' group(s) with some errors.');
                redirect(BASE_URL . '/intramurals/roster/duplicates.php' . $redirectSuffix);
            } else {
                $errors = array_merge($errors, $mergeErrors ?: ['No accounts were merged.']);
            }
        }
    }
}

$duplicateGroups = $loadFilteredGroups();
$duplicateCount = 0;
foreach ($duplicateGroups as $group) {
    $duplicateCount += count($group['athletes']);
}

$teams = filterTeamsForCoach($db->query('SELECT id, name FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll());
$canMerge = canManageTeamAthletes() || canManageIntramurals();
$hasFilters = trim((string) $search) !== '' || ($teamId !== '' && !$lockTeamFilter);

$pageTitle = 'Duplicate Athletes';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-people-fill"></i> Duplicate Athlete Names</h1>
        <p class="text-muted mb-0">
            Same name with different student IDs — merge into one account so all events stay on a single profile.
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/intramurals/roster/index.php" class="btn btn-outline-secondary">Back to Roster</a>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="filter-bar">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Search</label>
            <input type="text" name="search" class="form-control" value="<?= sanitize($search) ?>" placeholder="Name, student ID, team...">
        </div>
        <div class="col-md-3">
            <label class="form-label">Team / House</label>
            <select name="team" class="form-select" <?= !empty($lockTeamFilter) ? 'disabled' : '' ?>>
                <?php if (empty($lockTeamFilter)): ?>
                <option value="">All Teams</option>
                <?php endif; ?>
                <?php foreach ($teams as $t): ?>
                <option value="<?= $t['id'] ?>" <?= $teamId === (string) $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($lockTeamFilter)): ?>
            <input type="hidden" name="team" value="<?= (int) $userTeamId ?>">
            <?php endif; ?>
        </div>
        <div class="col-md-2">
            <button class="btn btn-primary w-100">Filter</button>
        </div>
        <?php if ($hasFilters): ?>
        <div class="col-md-2">
            <a href="<?= BASE_URL ?>/intramurals/roster/duplicates.php" class="btn btn-outline-secondary w-100">Clear</a>
        </div>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($duplicateGroups)): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle"></i> No duplicate athlete names found<?= $seasonId ? ' for the current season' : '' ?><?= $hasFilters ? ' matching the current filters' : '' ?>.
    Each person should have one account; multiple events attach as registrations on that account.
</div>
<?php else: ?>
<div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
        <strong><?= count($duplicateGroups) ?></strong> duplicate name group<?= count($duplicateGroups) === 1 ? '' : 's' ?>
        (<?= (int) $duplicateCount ?> accounts)<?= $hasFilters ? ' matching filters' : '' ?>.
        Choose the account to keep (usually the correct student ID or the one with the most events), then merge the others into it.
    </div>
    <?php if ($canMerge): ?>
    <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#mergeAllModal">
        <i class="bi bi-link-45deg"></i> Merge all filtered
    </button>
    <?php endif; ?>
</div>

<?php foreach ($duplicateGroups as $group): ?>
<?php $defaultKeep = pickDefaultAthleteAccountToKeep($group['athletes']); ?>
<div class="card mb-4 border-warning">
    <div class="card-header bg-warning-subtle d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong><?= sanitize($group['display_name']) ?></strong>
        <span class="badge bg-warning text-dark"><?= count($group['athletes']) ?> accounts</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Keep</th>
                        <th>Student ID</th>
                        <th>Team</th>
                        <th>Events</th>
                        <th>Registrations</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($group['athletes'] as $athlete): ?>
                    <tr>
                        <td>
                            <input type="radio" form="merge-group-<?= sanitize(md5($group['name_key'])) ?>" name="keep_id"
                                   value="<?= (int) $athlete['id'] ?>" <?= (int) $athlete['id'] === (int) $defaultKeep['id'] ? 'checked' : '' ?>>
                        </td>
                        <td><code><?= sanitize($athlete['student_id']) ?></code></td>
                        <td><?= sanitize($athlete['team_name'] ?: '—') ?></td>
                        <td>
                            <?php if (!empty($athlete['events'])): ?>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($athlete['events'] as $eventLabel): ?>
                                <span class="badge bg-primary-subtle text-primary-emphasis border"><?= sanitize($eventLabel) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) ($athlete['event_count'] ?? 0) ?></td>
                        <td class="text-end">
                            <a href="<?= BASE_URL ?>/intramurals/athletes/view.php?id=<?= (int) $athlete['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($canMerge): ?>
    <div class="card-footer">
        <form id="merge-group-<?= sanitize(md5($group['name_key'])) ?>" method="POST" class="d-inline"
              onsubmit="return confirm('Merge all other accounts into the selected profile? Event registrations will move to one account.');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="merge_group">
            <input type="hidden" name="name_key" value="<?= sanitize($group['name_key']) ?>">
            <input type="hidden" name="search" value="<?= sanitize($search) ?>">
            <input type="hidden" name="team" value="<?= sanitize($teamId) ?>">
            <button type="submit" class="btn btn-warning">
                <i class="bi bi-link-45deg"></i> Merge into selected account
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php if ($canMerge): ?>
<div class="modal fade" id="mergeAllModal" tabindex="-1" aria-labelledby="mergeAllModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST"
                  onsubmit="return confirm('Merge every duplicate group shown in the current filter? This cannot be undone.');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="merge_all">
                <input type="hidden" name="search" value="<?= sanitize($search) ?>">
                <input type="hidden" name="team" value="<?= sanitize($teamId) ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="mergeAllModalLabel">Merge all filtered duplicates</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>
                        This will merge <strong><?= count($duplicateGroups) ?></strong> name group<?= count($duplicateGroups) === 1 ? '' : 's' ?>
                        (<?= (int) $duplicateCount ?> accounts<?= $hasFilters ? ', filtered' : '' ?>).
                    </p>
                    <p class="mb-2">
                        For each group, the account with the <strong>most event registrations</strong> is kept automatically.
                        All other accounts in that group are merged into it.
                    </p>
                    <?php if ($hasFilters): ?>
                    <p class="small text-muted mb-3">
                        Filters: <?= trim((string) $search) !== '' ? 'search “' . sanitize($search) . '”' : '' ?>
                        <?= trim((string) $search) !== '' && $teamId !== '' ? ' · ' : '' ?>
                        <?= $teamId !== '' ? 'team filter applied' : '' ?>
                    </p>
                    <?php endif; ?>
                    <label class="form-label" for="confirm_merge_all">Type <code>MERGE ALL</code> to confirm</label>
                    <input type="text" name="confirm_text" id="confirm_merge_all" class="form-control" autocomplete="off" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Merge all filtered</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
