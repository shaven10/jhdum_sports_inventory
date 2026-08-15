<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();

$db = getDB();
$id = (int) get('id');

$stmt = $db->prepare('SELECT * FROM intramural_teams WHERE id = ?');
$stmt->execute([$id]);
$team = $stmt->fetch();

if (!$team) {
    flash('error', 'Team not found.');
    redirect(BASE_URL . '/intramurals/teams/index.php');
}

if (!canEditOwnTeam($id) && !canManageIntramurals()) {
    flash('error', 'Only unit managers or intramurals staff can assign event coaches.');
    redirect(BASE_URL . '/intramurals/teams/view.php?id=' . $id);
}

$canAddCoach = canCreateCoachAccounts($id);
$seasonId = getCurrentSeasonId();
$sports = $db->query('SELECT * FROM intramural_sports ORDER BY name, category')->fetchAll();
$coaches = $db->query("SELECT id, first_name, last_name, username, team_id FROM users WHERE role = 'coach' AND is_active = 1 ORDER BY first_name, last_name")->fetchAll();

$coachFormDefaults = [
    'username' => '',
    'email' => '',
    'first_name' => '',
    'last_name' => '',
];
$coachFormData = $coachFormDefaults;
$coachFormErrors = [];
$openCoachModal = get('add_coach') === '1';

if (!empty($_SESSION['coach_form'])) {
    $coachFormState = $_SESSION['coach_form'];
    unset($_SESSION['coach_form']);
    $coachFormData = array_merge($coachFormDefaults, $coachFormState['data'] ?? []);
    $coachFormErrors = $coachFormState['errors'] ?? [];
    $openCoachModal = true;
}

$current = [];
try {
    if ($seasonId) {
        $rows = $db->prepare('SELECT sport_id, coach_user_id FROM intramural_event_coaches WHERE team_id = ? AND season_id = ?');
        $rows->execute([$id, $seasonId]);
    } else {
        $rows = $db->prepare('SELECT sport_id, coach_user_id FROM intramural_event_coaches WHERE team_id = ?');
        $rows->execute([$id]);
    }
    foreach ($rows->fetchAll() as $r) {
        $current[(int) $r['sport_id']] = (int) $r['coach_user_id'];
    }
} catch (Throwable $e) {
    $current = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $action = post('action');

    if ($action === 'create_coach' && $canAddCoach) {
        $result = createCoachAccount([
            'username' => post('username'),
            'email' => post('email'),
            'password' => post('password'),
            'first_name' => post('first_name'),
            'last_name' => post('last_name'),
        ], $id);

        if ($result['success']) {
            flash('success', $result['message']);
            redirect(BASE_URL . '/intramurals/teams/coaches.php?id=' . $id);
        }

        $_SESSION['coach_form'] = [
            'errors' => [$result['message'] ?? 'Could not create coach account.'],
            'data' => [
                'username' => post('username'),
                'email' => post('email'),
                'first_name' => post('first_name'),
                'last_name' => post('last_name'),
            ],
        ];
        redirect(BASE_URL . '/intramurals/teams/coaches.php?id=' . $id . '&add_coach=1');
    }

    if ($action === 'assign_coaches') {
        requireWritableSeason();
        $assignments = $_POST['coach'] ?? [];
        if (!is_array($assignments)) {
            $assignments = [];
        }

        foreach ($sports as $sport) {
            $sportId = (int) $sport['id'];
            $coachId = isset($assignments[$sportId]) ? ((int) $assignments[$sportId] ?: null) : null;
            assignEventCoach($id, $sportId, $coachId, $seasonId);
        }

        auditLog($_SESSION['user_id'], 'assign_event_coaches', 'intramural_team', $id, null, ['season_id' => $seasonId]);
        flash('success', 'Event coaches updated for ' . $team['name'] . '.');
        redirect(BASE_URL . '/intramurals/teams/coaches.php?id=' . $id);
    }
}

$pageTitle = 'Event Coaches — ' . $team['name'];
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-person-badge"></i> Event Coaches</h1>
        <p class="text-muted mb-0">
            Assign one coach per event for
            <strong style="color:<?= sanitize($team['color']) ?>"><?= sanitize($team['name']) ?></strong>
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($canAddCoach): ?>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCoachModal">
            <i class="bi bi-person-plus"></i> Add Coach
        </button>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/intramurals/teams/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Back to Team</a>
    </div>
</div>

<div class="alert alert-info">
    Each event on this team must have a coach assigned for that team + event. Coaches can manage roster details (jersey, position, sport assignment) only for events they are assigned to.
    <?php if ($canAddCoach): ?>
    Use <strong>Add Coach</strong> to create a new coach login for this team, then assign them to events below.
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="assign_coaches">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Event / Sport</th>
                            <th>Category</th>
                            <th>Coach Account</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sports as $sport): ?>
                        <tr>
                            <td><strong><?= sanitize($sport['name']) ?></strong></td>
                            <td><?= ucfirst($sport['category']) ?></td>
                            <td style="min-width:260px">
                                <select name="coach[<?= $sport['id'] ?>]" class="form-select">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($coaches as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= ($current[(int) $sport['id']] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                                        <?= sanitize($c['first_name'] . ' ' . $c['last_name'] . ' (' . $c['username'] . ')') ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($sports)): ?>
                        <tr><td colspan="3" class="text-muted">No active sports/events. Add them under Sports Management first.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (empty($coaches)): ?>
            <div class="alert alert-warning mb-3">
                No coach accounts found.
                <?php if ($canAddCoach): ?>
                Click <strong>Add Coach</strong> above to create one for this team.
                <?php else: ?>
                Ask an administrator to create coach user accounts first.
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary" <?= empty($sports) ? 'disabled' : '' ?>>Save Event Coaches</button>
        </form>
    </div>
</div>

<?php if ($canAddCoach): ?>
<div class="modal fade" id="addCoachModal" tabindex="-1" aria-labelledby="addCoachModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="addCoachForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create_coach">
                <div class="modal-header">
                    <h5 class="modal-title" id="addCoachModalLabel"><i class="bi bi-person-plus"></i> Add Coach Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (!empty($coachFormErrors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($coachFormErrors as $err): ?>
                            <li><?= sanitize($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    <p class="text-muted">Creates a login for a coach linked to <strong><?= sanitize($team['name']) ?></strong>. After saving, assign them to events in the table above.</p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="coachUsername">Username *</label>
                            <input type="text" name="username" id="coachUsername" class="form-control" required value="<?= sanitize($coachFormData['username']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="coachEmail">Email *</label>
                            <input type="email" name="email" id="coachEmail" class="form-control" required value="<?= sanitize($coachFormData['email']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="coachFirstName">First Name *</label>
                            <input type="text" name="first_name" id="coachFirstName" class="form-control" required value="<?= sanitize($coachFormData['first_name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="coachLastName">Last Name *</label>
                            <input type="text" name="last_name" id="coachLastName" class="form-control" required value="<?= sanitize($coachFormData['last_name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="coachPassword">Password *</label>
                            <input type="text" name="password" id="coachPassword" class="form-control" required minlength="6" autocomplete="new-password" placeholder="Min. 6 characters">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Coach</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($openCoachModal): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('addCoachModal')).show();
});
</script>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
