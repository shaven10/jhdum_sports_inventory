<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$db = getDB();
$seasonId = getCurrentSeasonId();
$stats = getIntramuralsStats();
$overall = [];
$upcoming = [];
$champions = [];

try {
    $overallData = computeOverallStandings();
    $overall = $overallData['standings'];
    $champions = array_slice(array_filter($overall, fn($r) => $r['total'] > 0), 0, 3);

    if ($seasonId) {
        $upcomingSql = "
            SELECT m.*, s.name as sport_name, s.category as sport_category,
                   ta.name as team_a_name, ta.color as team_a_color,
                   tb.name as team_b_name, tb.color as team_b_color
            FROM intramural_matches m
            JOIN intramural_sports s ON m.sport_id = s.id
            JOIN intramural_teams ta ON m.team_a_id = ta.id
            JOIN intramural_teams tb ON m.team_b_id = tb.id
            WHERE m.season_id = ? AND m.status IN ('scheduled', 'ongoing') AND m.scheduled_at >= NOW()
        ";
        $upcomingParams = [$seasonId];
        $tmSportIds = isTournamentManager() && !canManageMatches() ? getTournamentManagerSportIds() : [];
        if ($tmSportIds) {
            $upcomingSql .= ' AND m.sport_id IN (' . implode(',', array_fill(0, count($tmSportIds), '?')) . ')';
            $upcomingParams = array_merge($upcomingParams, $tmSportIds);
        } elseif (isTournamentManager() && !canManageMatches()) {
            $upcomingSql .= ' AND 1=0';
        }
        $upcomingSql .= ' ORDER BY m.scheduled_at ASC LIMIT 8';
        $stmt = $db->prepare($upcomingSql);
        $stmt->execute($upcomingParams);
        $upcoming = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    flash('error', 'Intramurals tables are not installed yet. Run database/migrate_intramurals.sql or database/run_seasons_migrate.php.');
}

$pageTitle = 'Intramurals Dashboard';
require_once __DIR__ . '/../includes/header.php';
require __DIR__ . '/_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-trophy-fill"></i> Intramurals</h1>
        <p class="text-muted mb-0"><?= isTournamentManager() && !canManageMatches() ? 'Record scores and standings for your assigned events' : 'Athlete, team, match, and standings management' ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (canManageTeamAthletes()): ?>
        <a href="<?= BASE_URL ?>/intramurals/athletes/add.php" class="btn btn-primary"><i class="bi bi-person-plus"></i> Register Athlete</a>
        <a href="<?= BASE_URL ?>/intramurals/athletes/import.php" class="btn btn-success"><i class="bi bi-file-earmark-arrow-up"></i> Import Athletes</a>
        <?php endif; ?>
        <?php if (canManageMatches()): ?>
        <a href="<?= BASE_URL ?>/intramurals/matches/generate.php" class="btn btn-primary"><i class="bi bi-magic"></i> Generate Matches</a>
        <?php endif; ?>
        <?php if (isTournamentManager()): ?>
        <a href="<?= BASE_URL ?>/intramurals/matches/index.php" class="btn btn-primary"><i class="bi bi-clipboard-data"></i> Record Scores</a>
        <a href="<?= BASE_URL ?>/intramurals/standings/index.php" class="btn btn-outline-primary"><i class="bi bi-bar-chart-steps"></i> Standings</a>
        <?php endif; ?>
        <?php if (canManageIntramurals()): ?>
        <a href="<?= BASE_URL ?>/intramurals/sports/managers.php" class="btn btn-outline-primary"><i class="bi bi-person-gear"></i> Event TMs</a>
        <?php endif; ?>
        <?php if (hasRole('unit_manager') && getUserTeamId()): ?>
        <a href="<?= BASE_URL ?>/intramurals/teams/view.php?id=<?= (int) getUserTeamId() ?>" class="btn btn-outline-secondary"><i class="bi bi-shield"></i> My Team</a>
        <a href="<?= BASE_URL ?>/intramurals/teams/coaches.php?id=<?= (int) getUserTeamId() ?>" class="btn btn-outline-primary"><i class="bi bi-person-badge"></i> Event Coaches</a>
        <?php elseif (hasRole('coach') && getCoachTeamIds()): ?>
        <a href="<?= BASE_URL ?>/intramurals/teams/view.php?id=<?= (int) getCoachTeamIds()[0] ?>" class="btn btn-outline-secondary"><i class="bi bi-shield"></i> My Events</a>
        <?php endif; ?>
    </div>
</div>

<?php if (isTournamentManager() && !canManageMatches()): ?>
<?php
$assignedSports = [];
$tmIds = getTournamentManagerSportIds();
if ($tmIds) {
    $ph = implode(',', array_fill(0, count($tmIds), '?'));
    $as = $db->prepare("SELECT name, category FROM intramural_sports WHERE id IN ($ph) ORDER BY name, category");
    $as->execute($tmIds);
    $assignedSports = $as->fetchAll();
}
?>
<div class="alert alert-primary">
    <?php if ($assignedSports): ?>
    Your assigned events:
    <?php foreach ($assignedSports as $asport): ?>
    <span class="badge bg-dark"><?= sanitize(sportLabel($asport)) ?></span>
    <?php endforeach; ?>
    — update match scores; standings refresh automatically.
    <?php else: ?>
    No events are assigned to you for this season. Ask an administrator to assign you under Sports → Tournament Managers.
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-people"></i></div>
                <div>
                    <div class="stat-value"><?= $stats['total_athletes'] ?></div>
                    <div class="stat-label">Athletes</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-danger bg-opacity-10 text-danger"><i class="bi bi-shield"></i></div>
                <div>
                    <div class="stat-value"><?= $stats['total_teams'] ?></div>
                    <div class="stat-label">Teams</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-success bg-opacity-10 text-success"><i class="bi bi-dribbble"></i></div>
                <div>
                    <div class="stat-value"><?= $stats['total_sports'] ?></div>
                    <div class="stat-label">Sports</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-info bg-opacity-10 text-info"><i class="bi bi-calendar-event"></i></div>
                <div>
                    <div class="stat-value"><?= $stats['scheduled_games'] ?></div>
                    <div class="stat-label">Scheduled</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-play-circle"></i></div>
                <div>
                    <div class="stat-value"><?= $stats['ongoing_games'] ?></div>
                    <div class="stat-label">Ongoing</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-secondary bg-opacity-10 text-secondary"><i class="bi bi-flag"></i></div>
                <div>
                    <div class="stat-value"><?= $stats['completed_games'] ?></div>
                    <div class="stat-label">Completed</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-3 col-6"><a class="btn btn-outline-primary w-100" href="<?= BASE_URL ?>/intramurals/athletes/index.php"><i class="bi bi-person-badge"></i> Athletes</a></div>
    <div class="col-lg-3 col-6"><a class="btn btn-outline-primary w-100" href="<?= BASE_URL ?>/intramurals/teams/index.php"><i class="bi bi-shield-shaded"></i> Teams</a></div>
    <div class="col-lg-3 col-6"><a class="btn btn-outline-primary w-100" href="<?= BASE_URL ?>/intramurals/sports/index.php"><i class="bi bi-trophy"></i> Sports</a></div>
    <div class="col-lg-3 col-6"><a class="btn btn-outline-primary w-100" href="<?= BASE_URL ?>/intramurals/roster/index.php"><i class="bi bi-list-ul"></i> Rosters</a></div>
    <div class="col-lg-3 col-6"><a class="btn btn-outline-primary w-100" href="<?= BASE_URL ?>/intramurals/matches/index.php"><i class="bi bi-calendar3"></i> Matches</a></div>
    <div class="col-lg-3 col-6"><a class="btn btn-outline-primary w-100" href="<?= BASE_URL ?>/intramurals/standings/index.php"><i class="bi bi-bar-chart-steps"></i> Standings</a></div>
    <div class="col-lg-3 col-6"><a class="btn btn-outline-primary w-100" href="<?= BASE_URL ?>/intramurals/standings/overall.php"><i class="bi bi-award"></i> Overall</a></div>
    <div class="col-lg-3 col-6"><a class="btn btn-outline-primary w-100" href="<?= BASE_URL ?>/intramurals/points/index.php"><i class="bi bi-calculator"></i> Point System</a></div>
    <div class="col-lg-3 col-6"><a class="btn btn-outline-primary w-100" href="<?= BASE_URL ?>/intramurals/reports/index.php"><i class="bi bi-printer"></i> Reports</a></div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-calendar-week"></i> Upcoming Matches</span>
                <a href="<?= BASE_URL ?>/intramurals/matches/calendar.php" class="btn btn-sm btn-outline-secondary">Calendar</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($upcoming)): ?>
                <p class="text-muted p-3 mb-0">No upcoming matches scheduled.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>When</th><th>Sport</th><th>Match</th><th>Venue</th><th></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcoming as $m): ?>
                            <tr>
                                <td><?= formatDateTime($m['scheduled_at']) ?></td>
                                <td><?= sanitize($m['sport_name']) ?> <small class="text-muted">(<?= ucfirst($m['sport_category']) ?>)</small></td>
                                <td>
                                    <span style="color:<?= sanitize($m['team_a_color']) ?>"><?= sanitize($m['team_a_name']) ?></span>
                                    vs
                                    <span style="color:<?= sanitize($m['team_b_color']) ?>"><?= sanitize($m['team_b_name']) ?></span>
                                </td>
                                <td><?= sanitize($m['venue'] ?: '-') ?></td>
                                <td><a href="<?= BASE_URL ?>/intramurals/matches/view.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-award-fill"></i> Current Champions (Overall)</div>
            <div class="card-body">
                <?php if (empty($champions)): ?>
                <p class="text-muted mb-0">Standings will appear after matches are completed.</p>
                <?php else: ?>
                <ol class="mb-0">
                    <?php foreach ($champions as $c): ?>
                    <li class="mb-2">
                        <span class="fw-semibold" style="color:<?= sanitize($c['color']) ?>"><?= sanitize($c['team_name']) ?></span>
                        <span class="badge bg-primary"><?= $c['total'] ?> pts</span>
                        <small class="text-muted">G<?= $c['gold'] ?> S<?= $c['silver'] ?> B<?= $c['bronze'] ?></small>
                    </li>
                    <?php endforeach; ?>
                </ol>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><i class="bi bi-list-ol"></i> Overall Leaderboard</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead class="table-light"><tr><th>#</th><th>Team</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($overall, 0, 6) as $row): ?>
                        <tr>
                            <td><?= $row['rank'] ?></td>
                            <td><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= sanitize($row['color']) ?>"></span> <?= sanitize($row['team_name']) ?></td>
                            <td><strong><?= $row['total'] ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($overall)): ?>
                        <tr><td colspan="3" class="text-muted">No teams yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
