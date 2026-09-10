<?php
/** @var array $team */
/** @var string $sideLabel */
/** @var list<array> $players */
/** @var bool $canOpenAthlete */
$teamName = (string) ($team['name'] ?? 'TBD');
$teamColor = (string) ($team['color'] ?? '#666');
$sideLabel = $sideLabel ?? 'Team';
$players = $players ?? [];
$canOpenAthlete = $canOpenAthlete ?? true;
?>
<div class="card h-100">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-1">
        <span>
            <span class="badge bg-dark me-1"><?= sanitize($sideLabel) ?></span>
            <strong style="color:<?= sanitize($teamColor) ?>"><?= sanitize($teamName) ?></strong>
        </span>
        <span class="badge <?= $players ? 'bg-success' : 'bg-warning text-dark' ?>">
            <?= count($players) ?> listed
        </span>
    </div>
    <?php if (empty($players)): ?>
    <div class="card-body">
        <div class="alert alert-warning mb-0 py-2">
            <i class="bi bi-exclamation-triangle"></i>
            No athletes on the official roster for this team and event. Unlisted players are not eligible.
        </div>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Jersey</th>
                    <th>Player</th>
                    <th>Student ID</th>
                    <th>Year</th>
                    <th>Pos.</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($players as $i => $p): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= sanitize($p['jersey_number'] ?: '—') ?></strong></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?php if (!empty($p['photo'])): ?>
                            <img src="<?= UPLOAD_URL_ATHLETES . sanitize($p['photo']) ?>" alt="" class="rounded-circle d-print-none" style="width:28px;height:28px;object-fit:cover">
                            <?php endif; ?>
                            <div>
                                <?php if ($canOpenAthlete && !empty($p['athlete_id'])): ?>
                                <a href="<?= BASE_URL ?>/intramurals/athletes/view.php?id=<?= (int) $p['athlete_id'] ?>"><?= sanitize(athleteFullNameReport($p)) ?></a>
                                <?php else: ?>
                                <?= sanitize(athleteFullNameReport($p)) ?>
                                <?php endif; ?>
                                <div class="small text-muted"><?= sanitize(ucfirst((string) ($p['gender'] ?? ''))) ?><?php if (!empty($p['event_category'])): ?> · <?= sanitize($p['event_category']) ?><?php endif; ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?= sanitize($p['student_id'] ?? '—') ?></td>
                    <td><?= sanitize($p['year_level'] ?: '—') ?></td>
                    <td><?= sanitize($p['position'] ?: '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
