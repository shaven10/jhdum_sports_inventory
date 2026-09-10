<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole(['admin']);
ensureAthleteCoursesSchema();

$db = getDB();
$errors = [];
$editCourse = null;

if (isset($_GET['edit'])) {
    $editCourse = getAthleteCourseById((int) $_GET['edit']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $action = post('action', 'create');

    if ($action === 'create' || $action === 'update') {
        $name = trim(post('name'));
        $code = trim(post('code'));
        $sortOrder = (int) post('sort_order', '0');
        $id = (int) post('course_id');

        if ($name === '') {
            $errors[] = 'Course name is required.';
        } elseif (mb_strlen($name) > 150) {
            $errors[] = 'Course name must be 150 characters or fewer.';
        }

        if ($errors === []) {
            try {
                if ($action === 'create') {
                    $db->prepare('INSERT INTO athlete_courses (name, code, sort_order, is_active) VALUES (?, ?, ?, 1)')
                        ->execute([$name, $code !== '' ? $code : null, $sortOrder]);
                    $newId = (int) $db->lastInsertId();
                    auditLog((int) $_SESSION['user_id'], 'create', 'athlete_course', $newId, null, ['name' => $name]);
                    flash('success', 'Course added. It is now available in athlete Course dropdowns.');
                    redirect(BASE_URL . '/admin/courses/index.php');
                }

                $existing = getAthleteCourseById($id);
                if (!$existing) {
                    $errors[] = 'Course not found.';
                } else {
                    $oldName = (string) $existing['name'];
                    $db->prepare('UPDATE athlete_courses SET name = ?, code = ?, sort_order = ? WHERE id = ?')
                        ->execute([$name, $code !== '' ? $code : null, $sortOrder, $id]);
                    if ($oldName !== $name) {
                        $db->prepare('UPDATE intramural_athletes SET department = ? WHERE department = ?')
                            ->execute([$name, $oldName]);
                    }
                    auditLog((int) $_SESSION['user_id'], 'update', 'athlete_course', $id, ['name' => $oldName], ['name' => $name]);
                    flash('success', 'Course updated.');
                    redirect(BASE_URL . '/admin/courses/index.php');
                }
            } catch (PDOException $e) {
                $errors[] = 'A course with this name already exists.';
                if ($action === 'update' && $id) {
                    $editCourse = getAthleteCourseById($id);
                }
            }
        } elseif ($action === 'update' && $id) {
            $editCourse = getAthleteCourseById($id) ?: [
                'id' => $id,
                'name' => $name,
                'code' => $code,
                'sort_order' => $sortOrder,
            ];
        }
    }

    if ($action === 'deactivate') {
        $id = (int) post('course_id');
        if ($id && getAthleteCourseById($id)) {
            $db->prepare('UPDATE athlete_courses SET is_active = 0 WHERE id = ?')->execute([$id]);
            auditLog((int) $_SESSION['user_id'], 'deactivate', 'athlete_course', $id);
            flash('success', 'Course deactivated (hidden from dropdowns).');
        }
        redirect(BASE_URL . '/admin/courses/index.php');
    }

    if ($action === 'activate') {
        $id = (int) post('course_id');
        if ($id && getAthleteCourseById($id)) {
            $db->prepare('UPDATE athlete_courses SET is_active = 1 WHERE id = ?')->execute([$id]);
            auditLog((int) $_SESSION['user_id'], 'activate', 'athlete_course', $id);
            flash('success', 'Course activated.');
        }
        redirect(BASE_URL . '/admin/courses/index.php');
    }

    if ($action === 'delete') {
        $id = (int) post('course_id');
        $course = getAthleteCourseById($id);
        if ($course) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM intramural_athletes WHERE department = ?');
            $stmt->execute([(string) $course['name']]);
            $usage = (int) $stmt->fetchColumn();
            if ($usage > 0) {
                flash('danger', 'Cannot delete: ' . $usage . ' athlete(s) still use this course. Deactivate instead.');
            } else {
                $db->prepare('DELETE FROM athlete_courses WHERE id = ?')->execute([$id]);
                auditLog((int) $_SESSION['user_id'], 'delete', 'athlete_course', $id, ['name' => $course['name']]);
                flash('success', 'Course deleted.');
            }
        }
        redirect(BASE_URL . '/admin/courses/index.php');
    }
}

$courses = getAthleteCourses(false);
$formName = $editCourse['name'] ?? post('name');
$formCode = $editCourse['code'] ?? post('code');
$formSort = isset($editCourse['sort_order']) ? (string) $editCourse['sort_order'] : post('sort_order', (string) count($courses));

$pageTitle = 'Courses';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-mortarboard"></i> Courses</h1>
        <p class="text-muted mb-0">Manage course / program options used in athlete Course dropdowns.</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-outline-secondary">Back to Admin</a>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-<?= $editCourse ? 'pencil' : 'plus-circle' ?>"></i>
                <?= $editCourse ? 'Edit Course' : 'New Course' ?>
            </div>
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
                </div>
                <?php endif; ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editCourse ? 'update' : 'create' ?>">
                    <?php if ($editCourse): ?>
                    <input type="hidden" name="course_id" value="<?= (int) $editCourse['id'] ?>">
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label" for="name">Course name *</label>
                        <input type="text" name="name" id="name" class="form-control" required maxlength="150"
                               value="<?= sanitize((string) $formName) ?>"
                               placeholder="e.g. Bachelor of Science in Information Technology (BSIT)">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="code">Short code</label>
                        <input type="text" name="code" id="code" class="form-control" maxlength="40"
                               value="<?= sanitize((string) $formCode) ?>" placeholder="e.g. BSIT">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="sort_order">Sort order</label>
                        <input type="number" name="sort_order" id="sort_order" class="form-control"
                               value="<?= sanitize((string) $formSort) ?>">
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary"><?= $editCourse ? 'Save Changes' : 'Add Course' ?></button>
                        <?php if ($editCourse): ?>
                        <a href="<?= BASE_URL ?>/admin/courses/index.php" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">All Courses</div>
            <div class="card-body p-0">
                <?php if (empty($courses)): ?>
                <div class="p-4 text-muted">No courses yet. Add programs that athletes can select.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Code</th>
                                <th>Athletes</th>
                                <th>Order</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($courses as $c): ?>
                            <tr>
                                <td><strong><?= sanitize($c['name']) ?></strong></td>
                                <td><?= sanitize($c['code'] ?: '—') ?></td>
                                <td><?= (int) ($c['athlete_count'] ?? 0) ?></td>
                                <td><?= (int) $c['sort_order'] ?></td>
                                <td>
                                    <?= !empty($c['is_active'])
                                        ? '<span class="badge bg-success">Active</span>'
                                        : '<span class="badge bg-secondary">Inactive</span>' ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="<?= BASE_URL ?>/admin/courses/index.php?edit=<?= (int) $c['id'] ?>"
                                       class="btn btn-sm btn-outline-primary">Edit</a>
                                    <form method="POST" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="course_id" value="<?= (int) $c['id'] ?>">
                                        <input type="hidden" name="action" value="<?= !empty($c['is_active']) ? 'deactivate' : 'activate' ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                                            <?= !empty($c['is_active']) ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                    <?php if ((int) ($c['athlete_count'] ?? 0) === 0): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this course permanently?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="course_id" value="<?= (int) $c['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                    <?php endif; ?>
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
