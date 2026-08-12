<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$db = getDB();
$id = (int) get('id');
$stmt = $db->prepare('SELECT a.*, t.name as team_name, t.color as team_color FROM intramural_athletes a LEFT JOIN intramural_teams t ON a.team_id = t.id WHERE a.id = ?');
$stmt->execute([$id]);
$athlete = $stmt->fetch();

if (!$athlete) {
    flash('error', 'Athlete not found.');
    redirect(BASE_URL . '/intramurals/athletes/index.php');
}

$seasonId = getCurrentSeasonId();
if ($seasonId) {
    $regs = $db->prepare('SELECT r.*, s.name as sport_name, s.category, t.name as team_name, t.color FROM intramural_registrations r JOIN intramural_sports s ON r.sport_id = s.id JOIN intramural_teams t ON r.team_id = t.id WHERE r.athlete_id = ? AND r.season_id = ? ORDER BY s.name');
    $regs->execute([$id, $seasonId]);
} else {
    $regs = $db->prepare('SELECT r.*, s.name as sport_name, s.category, t.name as team_name, t.color FROM intramural_registrations r JOIN intramural_sports s ON r.sport_id = s.id JOIN intramural_teams t ON r.team_id = t.id WHERE r.athlete_id = ? ORDER BY s.name');
    $regs->execute([$id]);
}
$regs = $regs->fetchAll();

$pageTitle = athleteFullName($athlete);
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div class="d-flex gap-3 align-items-center">
        <?php if ($athlete['photo']): ?>
        <img src="<?= UPLOAD_URL_ATHLETES . sanitize($athlete['photo']) ?>" alt="" class="rounded" style="width:88px;height:88px;object-fit:cover">
        <?php else: ?>
        <div class="rounded bg-secondary bg-opacity-25 d-flex align-items-center justify-content-center" style="width:88px;height:88px"><i class="bi bi-person fs-1"></i></div>
        <?php endif; ?>
        <div>
            <h1 class="mb-1"><?= sanitize(athleteFullName($athlete)) ?></h1>
            <div class="text-muted"><?= sanitize($athlete['athlete_code']) ?> · <?= sanitize($athlete['student_id']) ?></div>
            <?php if ($athlete['team_name']): ?>
            <div class="mt-1" style="color:<?= sanitize($athlete['team_color']) ?>"><i class="bi bi-shield"></i> <?= sanitize($athlete['team_name']) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <?php if (canManageTeamRoster((int) ($athlete['team_id'] ?? 0) ?: null) || canManageTeamAthletes((int) ($athlete['team_id'] ?? 0) ?: null)): ?>
        <a href="<?= BASE_URL ?>/intramurals/athletes/edit.php?id=<?= $id ?>" class="btn btn-primary">Edit</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/intramurals/athletes/index.php" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header">Profile</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-5">Gender</dt><dd class="col-7"><?= ucfirst($athlete['gender']) ?></dd>
                    <dt class="col-5">Birthdate</dt><dd class="col-7"><?= $athlete['birthdate'] ? formatDate($athlete['birthdate']) : '-' ?></dd>
                    <dt class="col-5">Department</dt><dd class="col-7"><?= sanitize($athlete['department'] ?: '-') ?></dd>
                    <dt class="col-5">Year Level</dt><dd class="col-7"><?= sanitize($athlete['year_level'] ?: '-') ?></dd>
                    <dt class="col-5">Email</dt><dd class="col-7"><?= sanitize($athlete['email'] ?: '-') ?></dd>
                    <dt class="col-5">Phone</dt><dd class="col-7"><?= sanitize($athlete['phone'] ?: '-') ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">Sports & Events</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead class="table-light"><tr><th>Event</th><th>Category</th><th>Team</th><th>Jersey</th><th>Position</th><th>Division</th></tr></thead>
                    <tbody>
                        <?php foreach ($regs as $r): ?>
                        <tr>
                            <td><strong><?= sanitize($r['sport_name']) ?></strong></td>
                            <td><span class="badge bg-secondary"><?= sanitize(ucfirst($r['category'])) ?></span></td>
                            <td style="color:<?= sanitize($r['color']) ?>"><?= sanitize($r['team_name']) ?></td>
                            <td><?= sanitize($r['jersey_number'] ?: '—') ?></td>
                            <td><?= sanitize($r['position'] ?: '—') ?></td>
                            <td><?= sanitize($r['event_category'] ?: '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($regs)): ?>
                        <tr><td colspan="6" class="text-muted p-3">Not assigned to any event yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
