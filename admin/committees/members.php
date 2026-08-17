<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole(['admin']);
ensureWorkingCommitteesSchema();

$db = getDB();
$committeeId = (int) get('committee_id');
$committee = getWorkingCommitteeById($committeeId);
if (!$committee) {
    flash('error', 'Committee not found.');
    redirect(BASE_URL . '/admin/committees/index.php');
}

$errors = [];
$editMember = null;

if (isset($_GET['edit'])) {
    $editMember = getWorkingCommitteeMemberById((int) $_GET['edit']);
    if (!$editMember || (int) $editMember['committee_id'] !== $committeeId) {
        $editMember = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $action = post('action', 'create');

    if ($action === 'create' || $action === 'update') {
        $fullName = trim(post('full_name'));
        $position = trim(post('position_title'));
        $organization = trim(post('organization'));
        $sortOrder = (int) post('sort_order', '0');
        $id = (int) post('member_id');

        if ($fullName === '') {
            $errors[] = 'Member name is required.';
        } elseif (mb_strlen($fullName) > 150) {
            $errors[] = 'Member name must be 150 characters or fewer.';
        }

        if ($errors === []) {
            if ($action === 'create') {
                $db->prepare('INSERT INTO working_committee_members
                    (committee_id, full_name, position_title, organization, sort_order, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)')
                    ->execute([
                        $committeeId,
                        $fullName,
                        $position !== '' ? $position : null,
                        $organization !== '' ? $organization : null,
                        $sortOrder,
                    ]);
                $newId = (int) $db->lastInsertId();
                auditLog((int) $_SESSION['user_id'], 'create', 'working_committee_member', $newId, null, [
                    'committee_id' => $committeeId,
                    'full_name' => $fullName,
                ]);
                flash('success', 'Member added.');
                redirect(BASE_URL . '/admin/committees/members.php?committee_id=' . $committeeId);
            }

            $existing = getWorkingCommitteeMemberById($id);
            if (!$existing || (int) $existing['committee_id'] !== $committeeId) {
                $errors[] = 'Member not found.';
            } else {
                $db->prepare('UPDATE working_committee_members
                    SET full_name = ?, position_title = ?, organization = ?, sort_order = ?
                    WHERE id = ? AND committee_id = ?')
                    ->execute([
                        $fullName,
                        $position !== '' ? $position : null,
                        $organization !== '' ? $organization : null,
                        $sortOrder,
                        $id,
                        $committeeId,
                    ]);
                auditLog((int) $_SESSION['user_id'], 'update', 'working_committee_member', $id, [
                    'full_name' => $existing['full_name'],
                ], ['full_name' => $fullName]);
                flash('success', 'Member updated.');
                redirect(BASE_URL . '/admin/committees/members.php?committee_id=' . $committeeId);
            }
        } elseif ($action === 'update' && $id) {
            $editMember = [
                'id' => $id,
                'committee_id' => $committeeId,
                'full_name' => $fullName,
                'position_title' => $position,
                'organization' => $organization,
                'sort_order' => $sortOrder,
            ];
        }
    }

    if ($action === 'deactivate') {
        $id = (int) post('member_id');
        $member = getWorkingCommitteeMemberById($id);
        if ($member && (int) $member['committee_id'] === $committeeId) {
            $db->prepare('UPDATE working_committee_members SET is_active = 0 WHERE id = ?')->execute([$id]);
            auditLog((int) $_SESSION['user_id'], 'deactivate', 'working_committee_member', $id);
            flash('success', 'Member hidden from the public page.');
        }
        redirect(BASE_URL . '/admin/committees/members.php?committee_id=' . $committeeId);
    }

    if ($action === 'activate') {
        $id = (int) post('member_id');
        $member = getWorkingCommitteeMemberById($id);
        if ($member && (int) $member['committee_id'] === $committeeId) {
            $db->prepare('UPDATE working_committee_members SET is_active = 1 WHERE id = ?')->execute([$id]);
            auditLog((int) $_SESSION['user_id'], 'activate', 'working_committee_member', $id);
            flash('success', 'Member is now visible on the public page.');
        }
        redirect(BASE_URL . '/admin/committees/members.php?committee_id=' . $committeeId);
    }

    if ($action === 'delete') {
        $id = (int) post('member_id');
        $member = getWorkingCommitteeMemberById($id);
        if ($member && (int) $member['committee_id'] === $committeeId) {
            $db->prepare('DELETE FROM working_committee_members WHERE id = ?')->execute([$id]);
            auditLog((int) $_SESSION['user_id'], 'delete', 'working_committee_member', $id, [
                'full_name' => $member['full_name'],
            ]);
            flash('success', 'Member deleted.');
        }
        redirect(BASE_URL . '/admin/committees/members.php?committee_id=' . $committeeId);
    }
}

$members = getWorkingCommitteeMembers($committeeId, false);
$formName = $editMember['full_name'] ?? post('full_name');
$formPosition = $editMember['position_title'] ?? post('position_title');
$formOrg = $editMember['organization'] ?? post('organization');
$formSort = isset($editMember['sort_order']) ? (string) $editMember['sort_order'] : post('sort_order', (string) count($members));

$pageTitle = 'Committee Members';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-person-badge"></i> <?= sanitize($committee['name']) ?></h1>
        <p class="text-muted mb-0">Add and manage members for this working committee.</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/committees/index.php" class="btn btn-outline-secondary">Back to Committees</a>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-<?= $editMember ? 'pencil' : 'plus-circle' ?>"></i>
                <?= $editMember ? 'Edit Member' : 'New Member' ?>
            </div>
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
                </div>
                <?php endif; ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editMember ? 'update' : 'create' ?>">
                    <?php if ($editMember): ?>
                    <input type="hidden" name="member_id" value="<?= (int) $editMember['id'] ?>">
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label" for="full_name">Full name *</label>
                        <input type="text" name="full_name" id="full_name" class="form-control" required maxlength="150"
                               value="<?= sanitize((string) $formName) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="position_title">Position / title</label>
                        <input type="text" name="position_title" id="position_title" class="form-control" maxlength="120"
                               value="<?= sanitize((string) $formPosition) ?>"
                               placeholder="e.g. Chairperson, Member">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="organization">Office / organization</label>
                        <input type="text" name="organization" id="organization" class="form-control" maxlength="150"
                               value="<?= sanitize((string) $formOrg) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="sort_order">Sort order</label>
                        <input type="number" name="sort_order" id="sort_order" class="form-control"
                               value="<?= sanitize((string) $formSort) ?>">
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary"><?= $editMember ? 'Save Changes' : 'Add Member' ?></button>
                        <?php if ($editMember): ?>
                        <a href="<?= BASE_URL ?>/admin/committees/members.php?committee_id=<?= $committeeId ?>" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Members</div>
            <div class="card-body p-0">
                <?php if (empty($members)): ?>
                <div class="p-4 text-muted">No members yet.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Position</th>
                                <th>Organization</th>
                                <th>Order</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $m): ?>
                            <tr>
                                <td><strong><?= sanitize($m['full_name']) ?></strong></td>
                                <td><?= sanitize($m['position_title'] ?: '—') ?></td>
                                <td><?= sanitize($m['organization'] ?: '—') ?></td>
                                <td><?= (int) $m['sort_order'] ?></td>
                                <td>
                                    <?= !empty($m['is_active'])
                                        ? '<span class="badge bg-success">Visible</span>'
                                        : '<span class="badge bg-secondary">Hidden</span>' ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="?committee_id=<?= $committeeId ?>&edit=<?= (int) $m['id'] ?>"
                                       class="btn btn-sm btn-outline-secondary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if (!empty($m['is_active'])): ?>
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="deactivate">
                                        <input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-warning" title="Hide"><i class="bi bi-eye-slash"></i></button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Show"><i class="bi bi-eye"></i></button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="member_id" value="<?= (int) $m['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                                data-confirm="Delete this member?" title="Delete">
                                            <i class="bi bi-trash"></i>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
