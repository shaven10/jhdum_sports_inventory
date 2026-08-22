<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);
require_once __DIR__ . '/../includes/database_tools.php';

$db = getDB();
$action = post('action', get('action'));
$info = getDatabaseToolInfo();
$defaultSnapshot = getDefaultDatabaseSnapshotInfo();
$importResult = null;
$resetResult = null;

if ($action === 'export' && $_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    auditLog((int) $_SESSION['user_id'], 'export_database', 'database', null, null, [
        'database' => DB_NAME,
        'tables' => $info['tables'],
    ]);
    sendDatabaseExport($db);
}

if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $confirm = post('confirm_replace') === '1';
    $confirmDb = trim((string) post('confirm_database'));

    if (!$confirm) {
        flash('error', 'Please confirm that you understand the import will replace existing data.');
        redirect(BASE_URL . '/settings/database.php');
    }

    if ($confirmDb !== DB_NAME) {
        flash('error', 'Database name confirmation does not match. Type the exact database name to continue.');
        redirect(BASE_URL . '/settings/database.php');
    }

    $uploadError = validateDatabaseImportUpload($_FILES['sql_file'] ?? []);
    if ($uploadError) {
        flash('error', $uploadError);
        redirect(BASE_URL . '/settings/database.php');
    }

    $sql = file_get_contents($_FILES['sql_file']['tmp_name']);
    if ($sql === false || trim($sql) === '') {
        flash('error', 'Could not read the uploaded SQL file.');
        redirect(BASE_URL . '/settings/database.php');
    }

    $importResult = importDatabaseSql($db, $sql);

    auditLog((int) $_SESSION['user_id'], 'import_database', 'database', null, null, [
        'database' => DB_NAME,
        'success' => $importResult['success'],
        'executed' => $importResult['executed'],
        'errors' => count($importResult['errors']),
        'filename' => $_FILES['sql_file']['name'] ?? '',
    ]);

    if ($importResult['success']) {
        flash('success', $importResult['message'] . ' Executed ' . $importResult['executed'] . ' statements.');
        redirect(BASE_URL . '/settings/database.php');
    }
}

if ($action === 'save_default' && $_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $confirm = post('confirm_save_default') === '1';
    $confirmDb = trim((string) post('confirm_database'));

    if (!$confirm) {
        flash('error', 'Please confirm that you want to replace the saved default snapshot.');
        redirect(BASE_URL . '/settings/database.php');
    }

    if ($confirmDb !== DB_NAME) {
        flash('error', 'Database name confirmation does not match. Type the exact database name to continue.');
        redirect(BASE_URL . '/settings/database.php');
    }

    $saveResult = saveDefaultDatabaseSnapshot($db, (int) ($_SESSION['user_id'] ?? 0), (string) ($_SESSION['username'] ?? ''));
    auditLog((int) $_SESSION['user_id'], 'save_default_database', 'database', null, null, [
        'database' => DB_NAME,
        'success' => $saveResult['success'],
        'tables' => $saveResult['info']['tables'] ?? null,
        'total_rows' => $saveResult['info']['total_rows'] ?? null,
    ]);

    if ($saveResult['success']) {
        flash('success', $saveResult['message']);
    } else {
        flash('error', $saveResult['message']);
    }
    redirect(BASE_URL . '/settings/database.php');
}

if ($action === 'reset_default' && $_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $confirm = post('confirm_reset_default') === '1';
    $confirmDb = trim((string) post('confirm_database'));
    $confirmPhrase = trim((string) post('confirm_phrase'));

    if (!$confirm) {
        flash('error', 'Please confirm that you understand the database will be reset to the saved default snapshot.');
        redirect(BASE_URL . '/settings/database.php');
    }

    if ($confirmDb !== DB_NAME) {
        flash('error', 'Database name confirmation does not match. Type the exact database name to continue.');
        redirect(BASE_URL . '/settings/database.php');
    }

    if ($confirmPhrase !== 'RESET DEFAULT') {
        flash('error', 'Confirmation phrase incorrect. Type RESET DEFAULT to continue.');
        redirect(BASE_URL . '/settings/database.php');
    }

    if (!defaultDatabaseSnapshotExists()) {
        flash('error', 'No default database snapshot is saved yet. Save the current database as default first.');
        redirect(BASE_URL . '/settings/database.php');
    }

    $resetResult = resetDatabaseToDefault($db);

    auditLog((int) $_SESSION['user_id'], 'reset_default_database', 'database', null, null, [
        'database' => DB_NAME,
        'success' => $resetResult['success'],
        'executed' => $resetResult['executed'],
        'errors' => count($resetResult['errors']),
        'snapshot_saved_at' => $resetResult['info']['saved_at'] ?? null,
    ]);

    if ($resetResult['success']) {
        flash('success', $resetResult['message'] . ' Executed ' . $resetResult['executed'] . ' statements.');
        redirect(BASE_URL . '/settings/database.php');
    }
}

$pageTitle = 'Database Tools';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-database-gear"></i> Database Tools</h1>
        <p class="text-muted mb-0">Admin backup, restore, and reset utilities for the application database</p>
    </div>
    <a href="<?= BASE_URL ?>/settings/index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Settings</a>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-info-circle"></i> Database Overview</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5">Host</dt>
                    <dd class="col-7"><?= sanitize(DB_HOST) ?></dd>
                    <dt class="col-5">Database</dt>
                    <dd class="col-7"><code><?= sanitize(DB_NAME) ?></code></dd>
                    <dt class="col-5">Charset</dt>
                    <dd class="col-7"><?= sanitize(DB_CHARSET) ?></dd>
                    <dt class="col-5">Tables</dt>
                    <dd class="col-7"><?= (int) $info['tables'] ?></dd>
                    <dt class="col-5">Total Rows</dt>
                    <dd class="col-7"><?= number_format($info['total_rows']) ?></dd>
                    <dt class="col-5">Checked</dt>
                    <dd class="col-7"><?= sanitize(formatDateTime($info['generated_at'])) ?></dd>
                    <?php if ($defaultSnapshot): ?>
                    <dt class="col-5">Default saved</dt>
                    <dd class="col-7"><?= sanitize(formatDateTime($defaultSnapshot['saved_at'])) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header bg-success text-white"><i class="bi bi-download"></i> Export Database</div>
            <div class="card-body">
                <p class="text-muted">Download a full SQL backup of <strong><?= sanitize(DB_NAME) ?></strong>, including table structure and data. Use this before major updates or when moving to another server.</p>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="export">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-file-earmark-arrow-down"></i> Export SQL Backup
                    </button>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-primary text-white"><i class="bi bi-bookmark-star"></i> Default Database Snapshot</div>
            <div class="card-body">
                <p class="text-muted mb-3">
                    Save the <strong>current database</strong> as the default restore point. Resetting will reload that saved snapshot and replace all current tables and data.
                </p>

                <?php if ($defaultSnapshot): ?>
                <div class="alert alert-info py-2">
                    <div class="small">
                        <strong>Saved default:</strong>
                        <?= sanitize(formatDateTime($defaultSnapshot['saved_at'])) ?>
                        <?php if (!empty($defaultSnapshot['saved_by_username'])): ?>
                        · by <?= sanitize($defaultSnapshot['saved_by_username']) ?>
                        <?php endif; ?>
                        · <?= number_format((int) ($defaultSnapshot['tables'] ?? 0)) ?> tables
                        · <?= number_format((int) ($defaultSnapshot['total_rows'] ?? 0)) ?> rows
                        · <?= number_format(((int) ($defaultSnapshot['file_size'] ?? 0)) / 1024, 1) ?> KB
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-secondary py-2">
                    <i class="bi bi-info-circle"></i> No default snapshot saved yet. Save the current database first before using reset.
                </div>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-2">Save current as default</h6>
                            <p class="small text-muted">Captures the database exactly as it is now. This overwrites any previously saved default snapshot.</p>
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="save_default">
                                <div class="mb-2">
                                    <label class="form-label small mb-1">Confirm database name *</label>
                                    <input type="text" name="confirm_database" class="form-control form-control-sm" placeholder="<?= sanitize(DB_NAME) ?>" required autocomplete="off">
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="confirm_save_default" value="1" id="confirmSaveDefault" required>
                                    <label class="form-check-label small" for="confirmSaveDefault">
                                        Replace the saved default snapshot with the current database.
                                    </label>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm" data-confirm="Save the current database as the default restore point?">
                                    <i class="bi bi-bookmark-check"></i> Save as Default
                                </button>
                            </form>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <h6 class="mb-2">Reset to default</h6>
                            <p class="small text-muted">Restores the database from the saved default snapshot. Export a backup first if you need to keep current changes.</p>
                            <?php if ($resetResult && !$resetResult['success']): ?>
                            <div class="alert alert-danger py-2 small">
                                <strong><?= sanitize($resetResult['message']) ?></strong>
                                <?php if (!empty($resetResult['errors'])): ?>
                                <ul class="mb-0 mt-2">
                                    <?php foreach ($resetResult['errors'] as $err): ?>
                                    <li><?= sanitize($err) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="reset_default">
                                <div class="mb-2">
                                    <label class="form-label small mb-1">Confirm database name *</label>
                                    <input type="text" name="confirm_database" class="form-control form-control-sm" placeholder="<?= sanitize(DB_NAME) ?>" required autocomplete="off" <?= $defaultSnapshot ? '' : 'disabled' ?>>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small mb-1">Type <code>RESET DEFAULT</code> *</label>
                                    <input type="text" name="confirm_phrase" class="form-control form-control-sm" placeholder="RESET DEFAULT" required autocomplete="off" <?= $defaultSnapshot ? '' : 'disabled' ?>>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="confirm_reset_default" value="1" id="confirmResetDefault" required <?= $defaultSnapshot ? '' : 'disabled' ?>>
                                    <label class="form-check-label small" for="confirmResetDefault">
                                        I understand this will replace all current database tables and data with the saved default snapshot.
                                    </label>
                                </div>
                                <button type="submit" class="btn btn-danger btn-sm" <?= $defaultSnapshot ? '' : 'disabled' ?> data-confirm="Reset database to the saved default snapshot? This cannot be undone without a backup.">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset to Default
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-danger text-white"><i class="bi bi-upload"></i> Import Database</div>
            <div class="card-body">
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <strong>Warning:</strong> Importing a SQL file will overwrite existing tables and data in this database. Export a backup first.
                </div>

                <?php if ($importResult && !$importResult['success']): ?>
                <div class="alert alert-danger">
                    <strong><?= sanitize($importResult['message']) ?></strong>
                    <div class="small mt-2">Executed <?= (int) $importResult['executed'] ?> statement(s) before errors occurred.</div>
                    <?php if (!empty($importResult['errors'])): ?>
                    <ul class="mb-0 mt-2 small">
                        <?php foreach ($importResult['errors'] as $err): ?>
                        <li><?= sanitize($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="import">
                    <div class="mb-3">
                        <label class="form-label">SQL File *</label>
                        <input type="file" name="sql_file" class="form-control" accept=".sql" required>
                        <div class="form-text">Accepted format: .sql (max 100 MB). Use a backup exported from this system or compatible MySQL dump.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm Database Name *</label>
                        <input type="text" name="confirm_database" class="form-control" placeholder="<?= sanitize(DB_NAME) ?>" required autocomplete="off">
                        <div class="form-text">Type <code><?= sanitize(DB_NAME) ?></code> to confirm you are importing into the correct database.</div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="confirm_replace" value="1" id="confirmReplace" required>
                        <label class="form-check-label" for="confirmReplace">
                            I understand this import will replace existing database tables and data.
                        </label>
                    </div>
                    <button type="submit" class="btn btn-danger" data-confirm="Import SQL file into <?= sanitize(DB_NAME) ?>? This cannot be undone without a backup.">
                        <i class="bi bi-database-up"></i> Import SQL Backup
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header"><i class="bi bi-table"></i> Tables</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Table</th>
                        <th class="text-end">Rows</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($info['table_stats'] as $table): ?>
                    <tr>
                        <td><code><?= sanitize($table['name']) ?></code></td>
                        <td class="text-end"><?= number_format($table['rows']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($info['table_stats'])): ?>
                    <tr><td colspan="2" class="text-muted p-3">No tables found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
