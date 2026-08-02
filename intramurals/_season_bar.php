<?php
$__season = getCurrentSeason();
$__seasons = getAllSeasons(true);
$__active = getActiveSeason();
$__writable = isViewingActiveSeason();
?>
<div class="card mb-3 border-0 shadow-sm season-bar">
    <div class="card-body py-2 px-3">
        <form method="GET" class="row g-2 align-items-center">
            <?php
            // Preserve other GET filters on season switch
            foreach ($_GET as $key => $value) {
                if ($key === 'season_id' || is_array($value)) {
                    continue;
                }
                echo '<input type="hidden" name="' . sanitize($key) . '" value="' . sanitize((string) $value) . '">';
            }
            ?>
            <div class="col-auto">
                <span class="text-muted small"><i class="bi bi-calendar3"></i> Intramurals Year</span>
            </div>
            <div class="col-md-4 col-lg-3">
                <select name="season_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($__seasons as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $__season && (int) $__season['id'] === (int) $s['id'] ? 'selected' : '' ?>>
                        <?= sanitize($s['year_label']) ?> — <?= sanitize($s['name']) ?>
                        <?= !empty($s['is_active']) ? '(Active)' : '' ?>
                        <?= !empty($s['is_archived']) ? '(Archived)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                    <?php if (empty($__seasons)): ?>
                    <option value="">No seasons configured</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-auto">
                <?php if ($__season && !empty($__season['is_active'])): ?>
                <span class="badge bg-success">Active Season</span>
                <?php else: ?>
                <span class="badge bg-secondary">Viewing Historical</span>
                <?php endif; ?>
            </div>
            <div class="col-auto ms-auto d-flex gap-2">
                <?php if (!$__writable): ?>
                <span class="small text-muted align-self-center">Read-only (not active year)</span>
                <?php endif; ?>
                <?php if (canManageIntramurals()): ?>
                <a href="<?= BASE_URL ?>/intramurals/seasons/index.php" class="btn btn-sm btn-outline-primary">Manage Seasons</a>
                <?php endif; ?>
            </div>
        </form>
        <?php if ($__active && $__season && (int) $__active['id'] !== (int) $__season['id']): ?>
        <div class="small text-warning mt-1 mb-0">
            Active year is <strong><?= sanitize($__active['year_label']) ?></strong>.
            Results below are for <strong><?= sanitize($__season['year_label']) ?></strong> only.
        </div>
        <?php endif; ?>
    </div>
</div>
