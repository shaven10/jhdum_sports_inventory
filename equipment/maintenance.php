<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'coordinator', 'staff']);

$db = getDB();
$equipment = $db->query('SELECT id, name FROM equipment WHERE is_active = 1 ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $equipmentId = (int) post('equipment_id');
    $scheduledDate = post('scheduled_date');
    $type = post('maintenance_type');
    $description = post('description');

    if ($equipmentId && $scheduledDate && $type) {
        $stmt = $db->prepare('INSERT INTO maintenance_schedule (equipment_id, scheduled_date, maintenance_type, description, created_by) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$equipmentId, $scheduledDate, $type, $description, $_SESSION['user_id']]);

        $db->prepare('UPDATE equipment SET quantity_maintenance = quantity_maintenance + 1 WHERE id = ?')->execute([$equipmentId]);
        updateEquipmentQuantities($equipmentId);

        auditLog($_SESSION['user_id'], 'schedule_maintenance', 'equipment', $equipmentId);
        flash('success', 'Maintenance scheduled successfully.');
    }
    redirect(BASE_URL . '/equipment/maintenance.php');
}

if (isset($_GET['complete']) && verifyCsrf(get('token'))) {
    $maintId = (int) get('complete');
    $stmt = $db->prepare("UPDATE maintenance_schedule SET status = 'completed', completed_at = NOW() WHERE id = ?");
    $stmt->execute([$maintId]);

    $maint = $db->prepare('SELECT equipment_id FROM maintenance_schedule WHERE id = ?');
    $maint->execute([$maintId]);
    $eqId = $maint->fetchColumn();
    if ($eqId) {
        $db->prepare('UPDATE equipment SET quantity_maintenance = GREATEST(0, quantity_maintenance - 1) WHERE id = ?')->execute([$eqId]);
        updateEquipmentQuantities($eqId);
    }
    flash('success', 'Maintenance marked as completed.');
    redirect(BASE_URL . '/equipment/maintenance.php');
}

$schedules = $db->query("
    SELECT ms.*, e.name as equipment_name, u.first_name, u.last_name
    FROM maintenance_schedule ms
    JOIN equipment e ON ms.equipment_id = e.id
    JOIN users u ON ms.created_by = u.id
    ORDER BY ms.scheduled_date DESC
")->fetchAll();

$pageTitle = 'Maintenance Schedule';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="bi bi-tools"></i> Maintenance Schedule</h1>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Schedule Maintenance</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label">Equipment</label>
                        <select name="equipment_id" class="form-select" required>
                            <option value="">Select Equipment</option>
                            <?php foreach ($equipment as $eq): ?>
                            <option value="<?= $eq['id'] ?>"><?= sanitize($eq['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Scheduled Date</label>
                        <input type="date" name="scheduled_date" class="form-control" required min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select name="maintenance_type" class="form-select" required>
                            <option value="routine">Routine</option>
                            <option value="repair">Repair</option>
                            <option value="inspection">Inspection</option>
                            <option value="cleaning">Cleaning</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Schedule</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Maintenance Records</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Equipment</th><th>Type</th><th>Date</th><th>Status</th><th>Created By</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($schedules as $s): ?>
                            <tr>
                                <td><?= sanitize($s['equipment_name']) ?></td>
                                <td><?= ucfirst($s['maintenance_type']) ?></td>
                                <td><?= formatDate($s['scheduled_date']) ?></td>
                                <td><?= statusBadge($s['status']) ?></td>
                                <td><?= sanitize($s['first_name'] . ' ' . $s['last_name']) ?></td>
                                <td>
                                    <?php if ($s['status'] === 'scheduled'): ?>
                                    <a href="?complete=<?= $s['id'] ?>&token=<?= csrfToken() ?>" class="btn btn-sm btn-success" data-confirm="Mark as completed?">Complete</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
