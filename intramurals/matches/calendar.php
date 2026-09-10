<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
requireMatchResultsAccess();

$db = getDB();
$seasonId = getCurrentSeasonId();
if ($seasonId) {
    ensureVenueGameNumbersCurrent($seasonId);
}
$month = get('month', date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$start = $month . '-01';
$end = date('Y-m-t', strtotime($start));
$prev = date('Y-m', strtotime($start . ' -1 month'));
$next = date('Y-m', strtotime($start . ' +1 month'));

$sql = "SELECT m.*, s.name as sport_name, s.category as sport_category,
    ta.name as team_a_name, ta.color as team_a_color,
    tb.name as team_b_name, tb.color as team_b_color
    FROM intramural_matches m
    JOIN intramural_sports s ON m.sport_id = s.id
    LEFT JOIN intramural_teams ta ON m.team_a_id = ta.id
    LEFT JOIN intramural_teams tb ON m.team_b_id = tb.id
    WHERE m.scheduled_at IS NOT NULL AND DATE(m.scheduled_at) BETWEEN ? AND ?";
$params = [$start, $end];
if ($seasonId) {
    $sql .= ' AND m.season_id = ?';
    $params[] = $seasonId;
}
if (isTournamentManager() && !canManageIntramurals()) {
    $tmSportIds = getTmSportIds();
    if (empty($tmSportIds)) {
        $sql .= ' AND 0=1';
    } else {
        $placeholders = implode(',', array_fill(0, count($tmSportIds), '?'));
        $sql .= " AND m.sport_id IN ($placeholders)";
        $params = array_merge($params, $tmSportIds);
    }
}
appendUnitManagerMatchFilter($sql, $params);
$sql .= ' ORDER BY m.scheduled_at';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$matches = $stmt->fetchAll();

$byDate = [];
$calendarPayload = [];
foreach ($matches as $m) {
    $d = date('Y-m-d', strtotime($m['scheduled_at']));
    $byDate[$d][] = $m;
    $calendarPayload[$d][] = [
        'id' => (int) $m['id'],
        'time' => date('g:i A', strtotime($m['scheduled_at'])),
        'game_number' => !empty($m['game_number']) ? (int) $m['game_number'] : null,
        'sport' => (string) $m['sport_name'],
        'category' => ucfirst((string) ($m['sport_category'] ?? '')),
        'round' => (string) ($m['round_label'] ?? ''),
        'team_a' => (string) ($m['team_a_name'] ?? 'TBD'),
        'team_b' => (string) ($m['team_b_name'] ?? 'TBD'),
        'team_a_color' => (string) ($m['team_a_color'] ?? '#333'),
        'team_b_color' => (string) ($m['team_b_color'] ?? '#333'),
        'venue' => (string) ($m['venue'] ?? ''),
        'status' => (string) ($m['status'] ?? ''),
        'url' => BASE_URL . '/intramurals/matches/view.php?id=' . (int) $m['id'],
    ];
}

$firstDow = (int) date('N', strtotime($start)); // 1=Mon
$daysInMonth = (int) date('t', strtotime($start));

$pageTitle = 'Match Calendar';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-calendar-week"></i> Match Calendar</h1>
        <p class="text-muted mb-0"><?= date('F Y', strtotime($start)) ?> · Click a day to view all matches</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?month=<?= $prev ?>" class="btn btn-outline-secondary">&laquo; Prev</a>
        <a href="?month=<?= date('Y-m') ?>" class="btn btn-outline-primary">Today</a>
        <a href="?month=<?= $next ?>" class="btn btn-outline-secondary">Next &raquo;</a>
        <a href="<?= BASE_URL ?>/intramurals/matches/index.php" class="btn btn-outline-secondary">List View</a>
    </div>
</div>

<div class="card">
    <div class="card-body p-2 p-md-3">
        <div class="table-responsive">
            <table class="table table-bordered mb-0 calendar-table">
                <thead class="table-light">
                    <tr>
                        <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d): ?>
                        <th class="text-center"><?= $d ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                    <?php
                    $cells = $firstDow - 1;
                    for ($i = 0; $i < $cells; $i++) {
                        echo '<td class="calendar-day calendar-day-empty bg-light"></td>';
                    }
                    for ($day = 1; $day <= $daysInMonth; $day++) {
                        if ($cells > 0 && $cells % 7 === 0) {
                            echo '</tr><tr>';
                        }
                        $date = sprintf('%s-%02d', $month, $day);
                        $isToday = $date === date('Y-m-d');
                        $dayMatches = $byDate[$date] ?? [];
                        $count = count($dayMatches);
                        $classes = 'calendar-day align-top';
                        if ($isToday) {
                            $classes .= ' calendar-day-today';
                        }
                        if ($count > 0) {
                            $classes .= ' calendar-day-has-matches';
                        }

                        echo '<td class="' . $classes . '"';
                        if ($count > 0) {
                            echo ' role="button" tabindex="0" data-calendar-date="' . sanitize($date) . '"';
                            echo ' aria-label="' . $count . ' match' . ($count === 1 ? '' : 'es') . ' on ' . date('F j, Y', strtotime($date)) . '"';
                        }
                        echo '>';
                        echo '<div class="calendar-day-num">' . $day . '</div>';

                        if ($count > 0) {
                            $preview = $dayMatches[0];
                            $previewTime = date('g:i A', strtotime($preview['scheduled_at']));
                            echo '<div class="calendar-day-preview">';
                            echo '<span class="badge bg-primary">' . $count . ' match' . ($count === 1 ? '' : 'es') . '</span>';
                            echo '<div class="calendar-day-preview-line text-truncate">' . sanitize($previewTime . ' · ' . $preview['sport_name']) . '</div>';
                            if ($count > 1) {
                                echo '<div class="calendar-day-more">+' . ($count - 1) . ' more — tap to view</div>';
                            } else {
                                echo '<div class="calendar-day-more">Tap to view</div>';
                            }
                            echo '</div>';
                        }

                        echo '</td>';
                        $cells++;
                    }
                    while ($cells % 7 !== 0) {
                        echo '<td class="calendar-day calendar-day-empty bg-light"></td>';
                        $cells++;
                    }
                    ?>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="calendarDayModal" tabindex="-1" aria-labelledby="calendarDayModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="calendarDayModalLabel"><i class="bi bi-calendar-event"></i> Matches</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Game #</th>
                                <th>Time</th>
                                <th>Event</th>
                                <th>Match</th>
                                <th>Venue</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="calendarDayModalBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.calendar-table {
    table-layout: fixed;
}
.calendar-day {
    height: 110px;
    min-height: 110px;
    vertical-align: top;
    padding: 0.45rem !important;
}
.calendar-day-empty {
    pointer-events: none;
}
.calendar-day-today {
    background-color: rgba(var(--bs-primary-rgb), 0.08);
}
.calendar-day-has-matches {
    cursor: pointer;
    transition: background-color 0.15s ease, box-shadow 0.15s ease;
}
.calendar-day-has-matches:hover,
.calendar-day-has-matches:focus {
    background-color: rgba(var(--bs-primary-rgb), 0.12);
    outline: none;
    box-shadow: inset 0 0 0 2px rgba(var(--bs-primary-rgb), 0.35);
}
.calendar-day-num {
    font-weight: 600;
    font-size: 0.9rem;
    margin-bottom: 0.35rem;
}
.calendar-day-preview-line {
    font-size: 0.75rem;
    margin-top: 0.35rem;
    color: #495057;
}
.calendar-day-more {
    font-size: 0.7rem;
    color: #6c757d;
    margin-top: 0.15rem;
}
@media (max-width: 767.98px) {
    .calendar-day {
        height: 88px;
        min-height: 88px;
    }
    .calendar-day-preview-line {
        display: none;
    }
}
</style>

<script>
(function () {
    const byDate = <?= json_encode($calendarPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const modalEl = document.getElementById('calendarDayModal');
    const titleEl = document.getElementById('calendarDayModalLabel');
    const bodyEl = document.getElementById('calendarDayModalBody');
    let modal;

    function esc(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function statusBadge(status) {
        const map = {
            scheduled: 'primary',
            ongoing: 'warning',
            completed: 'success',
            forfeit: 'danger',
            cancelled: 'secondary'
        };
        const cls = map[status] || 'secondary';
        const label = status ? status.charAt(0).toUpperCase() + status.slice(1) : '—';
        return '<span class="badge bg-' + cls + '">' + esc(label) + '</span>';
    }

    function openDay(date) {
        const matches = byDate[date] || [];
        if (!matches.length) {
            return;
        }
        const pretty = new Date(date + 'T12:00:00').toLocaleDateString(undefined, {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
        titleEl.innerHTML = '<i class="bi bi-calendar-event"></i> ' + esc(pretty)
            + ' <span class="badge bg-primary ms-1">' + matches.length + '</span>';

        bodyEl.innerHTML = matches.map(function (m) {
            const eventBits = [m.sport, m.category].filter(Boolean).join(' · ');
            const round = m.round ? '<div class="small text-muted">' + esc(m.round) + '</div>' : '';
            const gameNum = m.game_number ? '<span class="badge bg-dark">' + esc(String(m.game_number)) + '</span>' : '—';
            return '<tr>'
                + '<td class="text-nowrap">' + gameNum + '</td>'
                + '<td class="text-nowrap">' + esc(m.time) + '</td>'
                + '<td>' + esc(eventBits) + round + '</td>'
                + '<td><span style="color:' + esc(m.team_a_color) + '">' + esc(m.team_a) + '</span>'
                + ' vs <span style="color:' + esc(m.team_b_color) + '">' + esc(m.team_b) + '</span></td>'
                + '<td>' + esc(m.venue || '—') + '</td>'
                + '<td>' + statusBadge(m.status) + '</td>'
                + '<td class="text-end"><a class="btn btn-sm btn-primary" href="' + esc(m.url) + '">Open</a></td>'
                + '</tr>';
        }).join('');

        modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }

    document.querySelectorAll('[data-calendar-date]').forEach(function (cell) {
        cell.addEventListener('click', function () {
            openDay(cell.getAttribute('data-calendar-date'));
        });
        cell.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openDay(cell.getAttribute('data-calendar-date'));
            }
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
