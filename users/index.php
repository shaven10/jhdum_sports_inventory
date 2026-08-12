<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);
ensurePasswordPlainColumn();

$db = getDB();
$teams = [];
try {
    $teams = $db->query('SELECT id, name FROM intramural_teams WHERE is_active = 1 ORDER BY name')->fetchAll();
} catch (Throwable $e) {
    $teams = [];
}

$formState = null;
if (!empty($_SESSION['user_form'])) {
    $formState = $_SESSION['user_form'];
    unset($_SESSION['user_form']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $formMode = post('form_mode', 'add');
    $errors = [];
    $firstName = post('first_name');
    $lastName = post('last_name');
    $email = post('email');
    $role = post('role', 'student');
    $studentId = post('student_id');
    $department = post('department');
    $phone = post('phone');
    $teamId = (int) post('team_id') ?: null;
    $password = post('password');
    $isActive = post('is_active') ? 1 : 0;

    if (!array_key_exists($role, getAllRoles())) {
        $errors[] = 'Invalid role selected.';
    }
    if ($role === 'unit_manager' && !$teamId) {
        $errors[] = 'Unit managers must be assigned to a team.';
    }
    if (!in_array($role, ['unit_manager', 'coach'], true)) {
        $teamId = null;
    }

    if ($formMode === 'add') {
        $username = post('username');

        if (empty($username) || empty($email) || empty($password) || empty($firstName) || empty($lastName)) {
            $errors[] = 'All required fields must be filled.';
        }
        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        }

        if (empty($errors)) {
            $check = $db->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
            $check->execute([$username, $email]);
            if ($check->fetch()) {
                $errors[] = 'Username or email already exists.';
            }
        }

        if (empty($errors)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare('INSERT INTO users (username, email, password, password_plain, first_name, last_name, student_id, department, phone, role, team_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$username, $email, $hash, $password, $firstName, $lastName, $studentId, $department, $phone, $role, $teamId]);
            $id = (int) $db->lastInsertId();
            syncUserTeamAssignment($id, $role, $teamId);
            auditLog($_SESSION['user_id'], 'create_user', 'user', $id);
            flash('success', 'User created successfully.');
            redirect(BASE_URL . '/users/index.php');
        }

        $_SESSION['user_form'] = [
            'mode' => 'add',
            'errors' => $errors,
            'data' => [
                'username' => $username,
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role' => $role,
                'student_id' => $studentId,
                'department' => $department,
                'phone' => $phone,
                'team_id' => $teamId,
            ],
        ];
        redirect(BASE_URL . '/users/index.php');
    }

    if ($formMode === 'edit') {
        $id = (int) post('user_id');
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            flash('error', 'User not found.');
            redirect(BASE_URL . '/users/index.php');
        }

        if (empty($firstName) || empty($lastName) || empty($email)) {
            $errors[] = 'Name and email are required.';
        }

        if (empty($errors)) {
            $stmt = $db->prepare('UPDATE users SET first_name=?, last_name=?, email=?, role=?, student_id=?, department=?, phone=?, is_active=?, team_id=? WHERE id=?');
            $stmt->execute([$firstName, $lastName, $email, $role, $studentId, $department, $phone, $isActive, $teamId, $id]);

            if (!empty($password)) {
                if (strlen($password) >= 6) {
                    $db->prepare('UPDATE users SET password = ?, password_plain = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $password, $id]);
                } else {
                    $errors[] = 'Password must be at least 6 characters.';
                }
            }
        }

        if (empty($errors)) {
            syncUserTeamAssignment($id, $role, $teamId);
            auditLog($_SESSION['user_id'], 'update_user', 'user', $id);
            flash('success', 'User updated successfully.');
            redirect(BASE_URL . '/users/index.php');
        }

        $_SESSION['user_form'] = [
            'mode' => 'edit',
            'user_id' => $id,
            'errors' => $errors,
            'data' => [
                'username' => $existing['username'],
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role' => $role,
                'student_id' => $studentId,
                'department' => $department,
                'phone' => $phone,
                'team_id' => $teamId,
                'is_active' => $isActive,
                'password_plain' => $existing['password_plain'] ?? '',
            ],
        ];
        redirect(BASE_URL . '/users/index.php?edit=' . $id);
    }
}

$search = get('search');
$roleFilter = get('role');
$editId = (int) get('edit');

$where = ['1=1'];
$params = [];
if ($search) {
    $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}
if ($roleFilter) {
    $where[] = 'u.role = ?';
    $params[] = $roleFilter;
}

$whereClause = implode(' AND ', $where);
$stmt = $db->prepare("SELECT u.*, t.name as team_name FROM users u LEFT JOIN intramural_teams t ON u.team_id = t.id WHERE $whereClause ORDER BY u.created_at DESC");
$stmt->execute($params);
$users = $stmt->fetchAll();

$pageTitle = 'User Management';
require_once __DIR__ . '/../includes/header.php';

$formDefaults = [
    'username' => '',
    'email' => '',
    'first_name' => '',
    'last_name' => '',
    'role' => 'student',
    'student_id' => '',
    'department' => '',
    'phone' => '',
    'team_id' => '',
    'is_active' => 1,
    'password_plain' => '',
];
$formData = $formDefaults;
$formErrors = [];
$openModal = '';

if ($formState) {
    $openModal = $formState['mode'];
    $formErrors = $formState['errors'] ?? [];
    $formData = array_merge($formDefaults, $formState['data'] ?? []);
    if ($openModal === 'edit' && !empty($formState['user_id'])) {
        $editId = (int) $formState['user_id'];
    }
} elseif ($editId) {
    $openModal = 'edit';
    foreach ($users as $user) {
        if ((int) $user['id'] === $editId) {
            $formData = [
                'username' => $user['username'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'role' => $user['role'],
                'student_id' => $user['student_id'] ?? '',
                'department' => $user['department'] ?? '',
                'phone' => $user['phone'] ?? '',
                'team_id' => $user['team_id'] ?? '',
                'is_active' => $user['is_active'],
                'password_plain' => $user['password_plain'] ?? '',
            ];
            break;
        }
    }
}
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <div>
        <h1 class="mb-1"><i class="bi bi-people"></i> User Management</h1>
        <p class="text-muted mb-0"><?= count($users) ?> user<?= count($users) === 1 ? '' : 's' ?></p>
    </div>
    <div class="d-flex flex-wrap align-items-end gap-2 ms-auto">
        <form method="GET" class="d-flex flex-wrap align-items-end gap-2">
            <div>
                <label class="form-label visually-hidden" for="userSearch">Search</label>
                <input type="text" id="userSearch" name="search" class="form-control form-control-sm" placeholder="Search users..." value="<?= sanitize($search) ?>">
            </div>
            <div>
                <label class="form-label visually-hidden" for="userRoleFilter">Role</label>
                <select id="userRoleFilter" name="role" class="form-select form-select-sm">
                    <option value="">All Roles</option>
                    <?php foreach (getAllRoles() as $value => $label): ?>
                    <option value="<?= $value ?>" <?= $roleFilter === $value ? 'selected' : '' ?>><?= sanitize($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i> Filter</button>
            <?php if ($search || $roleFilter): ?>
            <a href="<?= BASE_URL ?>/users/index.php" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#userModal" data-user-mode="add">
            <i class="bi bi-person-plus"></i> Add User
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Password</th>
                        <th>Role</th>
                        <th>Team</th>
                        <th>Status</th>
                        <th class="text-end" style="width: 5rem;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No users found.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($users as $user): ?>
                    <?php
                    $userPayload = htmlspecialchars(json_encode([
                        'id' => (int) $user['id'],
                        'username' => $user['username'],
                        'email' => $user['email'],
                        'first_name' => $user['first_name'],
                        'last_name' => $user['last_name'],
                        'role' => $user['role'],
                        'student_id' => $user['student_id'] ?? '',
                        'department' => $user['department'] ?? '',
                        'phone' => $user['phone'] ?? '',
                        'team_id' => $user['team_id'] ?? '',
                        'is_active' => (int) $user['is_active'],
                        'password_plain' => $user['password_plain'] ?? '',
                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr>
                        <td><?= sanitize($user['first_name'] . ' ' . $user['last_name']) ?></td>
                        <td><?= sanitize($user['username']) ?></td>
                        <td><?= sanitize($user['email']) ?></td>
                        <td>
                            <?php if (!empty($user['password_plain'])): ?>
                            <code class="user-password-plain"><?= sanitize($user['password_plain']) ?></code>
                            <?php else: ?>
                            <span class="text-muted" title="Password not stored. Edit the user and set a new password to record it.">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= roleBadge($user['role']) ?></td>
                        <td><?= sanitize($user['team_name'] ?? '-') ?></td>
                        <td><?= $user['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                        <td class="text-end">
                            <button type="button"
                                    class="btn btn-sm btn-outline-primary btn-edit-user"
                                    data-bs-toggle="modal"
                                    data-bs-target="#userModal"
                                    data-user-mode="edit"
                                    data-user="<?= $userPayload ?>"
                                    title="Edit user">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" id="userForm">
                <?= csrfField() ?>
                <input type="hidden" name="form_mode" id="formMode" value="add">
                <input type="hidden" name="user_id" id="userId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalLabel"><i class="bi bi-person-plus"></i> Add User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="userFormErrors" class="alert alert-danger d-none"></div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="first_name">First Name *</label>
                            <input type="text" name="first_name" id="first_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="last_name">Last Name *</label>
                            <input type="text" name="last_name" id="last_name" class="form-control" required>
                        </div>
                        <div class="col-md-6" id="usernameField">
                            <label class="form-label" for="username">Username *</label>
                            <input type="text" name="username" id="username" class="form-control" required>
                        </div>
                        <div class="col-md-6" id="usernameReadonlyField" style="display: none;">
                            <label class="form-label">Username</label>
                            <input type="text" id="usernameReadonly" class="form-control" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email">Email *</label>
                            <input type="email" name="email" id="email" class="form-control" required>
                        </div>
                        <div class="col-md-6" id="currentPasswordField" style="display: none;">
                            <label class="form-label" for="current_password_display">Current Password</label>
                            <input type="text" id="current_password_display" class="form-control font-monospace" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password" id="passwordLabel">Password *</label>
                            <div class="input-group">
                                <input type="password" name="password" id="password" class="form-control" minlength="6">
                                <button type="button" class="btn btn-outline-secondary" data-toggle-password="#password" title="Show password" aria-label="Show password"><i class="bi bi-eye"></i></button>
                            </div>
                            <div class="form-text" id="passwordHelp" style="display: none;">Leave blank to keep the current password.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="roleSelect">Role *</label>
                            <select name="role" id="roleSelect" class="form-select">
                                <?php foreach (getAllRoles() as $value => $label): ?>
                                <option value="<?= $value ?>"><?= sanitize($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6" id="teamField">
                            <label class="form-label" for="team_id">Assigned Team</label>
                            <select name="team_id" id="team_id" class="form-select">
                                <option value="">Select team</option>
                                <?php foreach ($teams as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= sanitize($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" id="teamHelp">Unit managers require a team. Coaches: set a home team, then assign them per event under Teams → Event Coaches.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="student_id">Student ID</label>
                            <input type="text" name="student_id" id="student_id" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="department">Department</label>
                            <input type="text" name="department" id="department" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="phone">Phone</label>
                            <input type="text" name="phone" id="phone" class="form-control">
                        </div>
                        <div class="col-md-6" id="activeField" style="display: none;">
                            <div class="form-check mt-4">
                                <input type="checkbox" name="is_active" class="form-check-input" id="is_active" value="1">
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="userFormSubmit">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const modalEl = document.getElementById('userModal');
    const form = document.getElementById('userForm');
    const formMode = document.getElementById('formMode');
    const userId = document.getElementById('userId');
    const modalTitle = document.getElementById('userModalLabel');
    const formSubmit = document.getElementById('userFormSubmit');
    const errorsBox = document.getElementById('userFormErrors');
    const roleSelect = document.getElementById('roleSelect');
    const teamField = document.getElementById('teamField');
    const usernameField = document.getElementById('usernameField');
    const usernameReadonlyField = document.getElementById('usernameReadonlyField');
    const usernameInput = document.getElementById('username');
    const usernameReadonly = document.getElementById('usernameReadonly');
    const currentPasswordField = document.getElementById('currentPasswordField');
    const currentPasswordDisplay = document.getElementById('current_password_display');
    const passwordInput = document.getElementById('password');
    const passwordLabel = document.getElementById('passwordLabel');
    const passwordHelp = document.getElementById('passwordHelp');
    const activeField = document.getElementById('activeField');
    const isActive = document.getElementById('is_active');

    function toggleTeamField() {
        const needsTeam = roleSelect.value === 'unit_manager' || roleSelect.value === 'coach';
        teamField.style.display = needsTeam ? '' : 'none';
    }

    function setMode(mode, data) {
        data = data || {};
        formMode.value = mode;
        userId.value = data.id || '';
        form.reset();

        if (mode === 'add') {
            modalTitle.innerHTML = '<i class="bi bi-person-plus"></i> Add User';
            formSubmit.textContent = 'Create User';
            usernameField.style.display = '';
            usernameReadonlyField.style.display = 'none';
            usernameInput.required = true;
            currentPasswordField.style.display = 'none';
            passwordLabel.textContent = 'Password *';
            passwordInput.required = true;
            passwordHelp.style.display = 'none';
            activeField.style.display = 'none';
            isActive.checked = true;
        } else {
            modalTitle.innerHTML = '<i class="bi bi-pencil"></i> Edit User';
            formSubmit.textContent = 'Update User';
            usernameField.style.display = 'none';
            usernameReadonlyField.style.display = '';
            usernameReadonly.value = data.username || '';
            usernameInput.required = false;
            currentPasswordField.style.display = '';
            currentPasswordDisplay.value = data.password_plain || '—';
            passwordLabel.textContent = 'New Password';
            passwordInput.required = false;
            passwordHelp.style.display = '';
            activeField.style.display = '';
            isActive.checked = !!data.is_active;
        }

        document.getElementById('first_name').value = data.first_name || '';
        document.getElementById('last_name').value = data.last_name || '';
        document.getElementById('email').value = data.email || '';
        document.getElementById('student_id').value = data.student_id || '';
        document.getElementById('department').value = data.department || '';
        document.getElementById('phone').value = data.phone || '';
        document.getElementById('team_id').value = data.team_id || '';
        roleSelect.value = data.role || 'student';

        if (mode === 'add' && data.username) {
            usernameInput.value = data.username;
        }

        toggleTeamField();
        errorsBox.classList.add('d-none');
        errorsBox.innerHTML = '';
    }

    function showErrors(errors) {
        if (!errors || !errors.length) {
            errorsBox.classList.add('d-none');
            return;
        }
        errorsBox.innerHTML = '<ul class="mb-0">' + errors.map(function (e) {
            return '<li>' + e.replace(/</g, '&lt;') + '</li>';
        }).join('') + '</ul>';
        errorsBox.classList.remove('d-none');
    }

    document.querySelectorAll('[data-bs-target="#userModal"]').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            const mode = this.dataset.userMode || 'add';
            let data = {};
            if (mode === 'edit' && this.dataset.user) {
                try {
                    data = JSON.parse(this.dataset.user);
                } catch (e) {
                    data = {};
                }
            }
            setMode(mode, data);
        });
    });

    roleSelect.addEventListener('change', toggleTeamField);

    modalEl.addEventListener('hidden.bs.modal', function () {
        if (window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.delete('edit');
            window.history.replaceState({}, '', url.pathname + url.search);
        }
    });

    const bootMode = <?= json_encode($openModal) ?>;
    const bootData = <?= json_encode([
        'id' => $editId ?: null,
        'username' => $formData['username'] ?? '',
        'email' => $formData['email'] ?? '',
        'first_name' => $formData['first_name'] ?? '',
        'last_name' => $formData['last_name'] ?? '',
        'role' => $formData['role'] ?? 'student',
        'student_id' => $formData['student_id'] ?? '',
        'department' => $formData['department'] ?? '',
        'phone' => $formData['phone'] ?? '',
        'team_id' => $formData['team_id'] ?? '',
        'is_active' => !empty($formData['is_active']),
        'password_plain' => $formData['password_plain'] ?? '',
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const bootErrors = <?= json_encode($formErrors, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    if (bootMode) {
        setMode(bootMode, bootData);
        showErrors(bootErrors);
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
