<?php
/**
 * Public live rankings landing page — overall, medal tally, per-event.
 */
require_once __DIR__ . '/includes/auth.php';
ensureLiveBoardColumns();

if (!isLiveBoardEnabled() && !isAdmin()) {
    redirect(BASE_URL . '/landing.php');
}

if (isLoggedIn()) {
    // Staff still see the board; home is available via CTA.
}

$season = null;
try {
    $season = getCurrentSeason();
} catch (Throwable $e) {
    $season = null;
}

$pageTitle = 'Live Rankings';
$apiUrl = BASE_URL . '/api/live_standings.php';
$refreshSeconds = 600;
$stylePath = __DIR__ . '/assets/css/style.css';
$styleVersion = is_file($stylePath) ? (string) filemtime($stylePath) : APP_VERSION;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="<?= (int) ($refreshSeconds * 30) ?>">
    <title><?= sanitize($pageTitle) ?> — Sports Development Integrated Management Information System (SDIMIS)</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css?v=<?= $styleVersion ?>" rel="stylesheet">
    <?= renderThemeStyles() ?>
</head>
<body class="live-board-page">
<div class="live-board">
    <?php if (isAdmin() && !isLiveBoardEnabled()): ?>
    <div class="live-admin-notice" role="status">
        <i class="bi bi-eye-slash"></i>
        Live standings are hidden from the public.
        <a href="<?= BASE_URL ?>/admin/live_board.php">Enable live standings</a>
    </div>
    <?php endif; ?>
    <header class="live-board-hero">
        <div class="live-board-hero__bg" aria-hidden="true"></div>
        <div class="live-board-hero__inner">
            <div class="live-board-brand">
                <img src="<?= sanitize(appLogoUrl()) ?>" alt="" class="live-board-logo">
                <div>
                    <p class="live-board-campus"><?= sanitize(APP_CAMPUS) ?></p>
                    <h1 class="live-board-title">Sports Development Integrated Management Information System (SDIMIS)</h1>
                </div>
            </div>
            <p class="live-board-lead">Live intramurals overall rankings, medal tally, and per-event standings.</p>
            <div class="live-board-cta">
                <a class="btn btn-light btn-lg" href="#live-panels">View standings</a>
                <a class="btn btn-outline-light btn-lg" href="<?= BASE_URL ?>/committees.php">Working committees</a>
                <?php if (isLoggedIn()): ?>
                <a class="btn btn-outline-light btn-lg" href="<?= sanitize(getHomeUrl()) ?>">Go to dashboard</a>
                <?php else: ?>
                <a class="btn btn-outline-light btn-lg" href="<?= BASE_URL ?>/login.php">Staff login</a>
                <?php endif; ?>
            </div>
            <div class="live-board-meta">
                <span class="live-pill" id="liveStatus"><span class="live-dot"></span> Live</span>
                <span id="seasonLabel"><?= $season ? sanitize(seasonLabel($season)) : 'Current season' ?></span>
                <span class="live-meta-sep" aria-hidden="true">·</span>
                <span>Updated <time id="updatedLabel">—</time></span>
            </div>
        </div>
    </header>

    <main class="live-board-main" id="live-panels">
        <nav class="live-tabs" role="tablist" aria-label="Rankings views">
            <button type="button" class="live-tab active" data-panel="overall" role="tab" aria-selected="true">Overall rankings</button>
            <button type="button" class="live-tab" data-panel="medals" role="tab" aria-selected="false">Medal tally</button>
            <button type="button" class="live-tab" data-panel="events" role="tab" aria-selected="false">Per event</button>
        </nav>

        <div class="live-toolbar">
            <label class="live-toolbar-label" for="divisionFilter">Division</label>
            <select id="divisionFilter" class="form-select live-select">
                <option value="all">All divisions</option>
            </select>
            <div class="live-event-filter d-none" id="eventFilterWrap">
                <label class="live-toolbar-label" for="eventFilter">Event</label>
                <select id="eventFilter" class="form-select live-select">
                    <option value="">Select event</option>
                </select>
            </div>
        </div>

        <div id="liveError" class="live-alert d-none" role="alert"></div>
        <div id="liveLoading" class="live-loading">Loading standings…</div>

        <section id="panel-overall" class="live-panel" role="tabpanel"></section>
        <section id="panel-medals" class="live-panel d-none" role="tabpanel"></section>
        <section id="panel-events" class="live-panel d-none" role="tabpanel"></section>
    </main>

    <footer class="live-board-footer">
        <span><?= sanitize(APP_NAME) ?></span>
        <span>Auto-refreshes every <?= (int) $refreshSeconds ?>s</span>
    </footer>
</div>

<script>
(function () {
    const API_URL = <?= json_encode($apiUrl) ?>;
    const REFRESH_MS = <?= (int) $refreshSeconds ?> * 1000;
    let data = { divisions: [], events: [] };
    let activePanel = 'overall';

    const els = {
        status: document.getElementById('liveStatus'),
        season: document.getElementById('seasonLabel'),
        updated: document.getElementById('updatedLabel'),
        error: document.getElementById('liveError'),
        loading: document.getElementById('liveLoading'),
        division: document.getElementById('divisionFilter'),
        event: document.getElementById('eventFilter'),
        eventWrap: document.getElementById('eventFilterWrap'),
        overall: document.getElementById('panel-overall'),
        medals: document.getElementById('panel-medals'),
        events: document.getElementById('panel-events'),
    };

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function teamCell(row) {
        const color = esc(row.color || '#888');
        const name = esc(row.team_name || row.short_name || 'Team');
        return `<span class="live-team"><i style="background:${color}"></i>${name}</span>`;
    }

    function medalBadge(medal) {
        if (!medal) return '—';
        const m = String(medal).toLowerCase();
        if (m === 'gold' || m === 'silver' || m === 'bronze') {
            return `<span class="live-medal live-medal--${m}">${esc(m)}</span>`;
        }
        return esc(medal);
    }

    function filteredDivisions() {
        const key = els.division.value;
        if (key === 'all') return data.divisions || [];
        return (data.divisions || []).filter(d => String(d.division_key) === key);
    }

    function fillDivisionOptions(preserve) {
        const prev = preserve ? els.division.value : 'all';
        const opts = ['<option value="all">All divisions</option>'];
        (data.divisions || []).forEach(d => {
            opts.push(`<option value="${esc(d.division_key)}">${esc(d.division_name)}</option>`);
        });
        els.division.innerHTML = opts.join('');
        if ([...els.division.options].some(o => o.value === prev)) {
            els.division.value = prev;
        }
    }

    function fillEventOptions(preserve) {
        const prev = preserve ? els.event.value : '';
        const groups = {};
        const order = [];
        (data.events || []).forEach(ev => {
            const key = ev.event_group || 'sports_competition';
            const label = ev.event_group_label || (key === 'socio_cultural' ? 'Socio-Cultural Events' : 'Sports Competition');
            if (!groups[key]) {
                groups[key] = { label, items: [] };
                order.push(key);
            }
            groups[key].items.push(ev);
        });
        let html = '<option value="">Select event</option>';
        order.forEach(key => {
            const g = groups[key];
            html += `<optgroup label="${esc(g.label)}">`;
            g.items.forEach(ev => {
                html += `<option value="${esc(ev.sport_id)}">${esc(ev.label)}</option>`;
            });
            html += '</optgroup>';
        });
        els.event.innerHTML = html;
        if (prev && [...els.event.options].some(o => o.value === prev)) {
            els.event.value = prev;
        } else if (data.events && data.events.length) {
            els.event.value = String(data.events[0].sport_id);
        }
    }

    function eventGroupSpans(headers) {
        const spans = [];
        (headers || []).forEach(eh => {
            const key = eh.event_group || 'sports_competition';
            const label = key === 'socio_cultural' ? 'Socio-Cultural Events' : 'Sports Competition';
            if (!spans.length || spans[spans.length - 1].key !== key) {
                spans.push({ key, label, count: 1 });
            } else {
                spans[spans.length - 1].count++;
            }
        });
        return spans;
    }

    function renderOverall() {
        const groups = filteredDivisions();
        if (!groups.length) {
            els.overall.innerHTML = '<p class="live-empty">No overall rankings yet.</p>';
            return;
        }
        els.overall.innerHTML = groups.map(g => {
            const labels = g.sport_labels || [];
            const eventHeaders = g.event_headers || [];
            const spans = eventGroupSpans(eventHeaders);
            const nameHeaders = (eventHeaders.length ? eventHeaders : labels.map(l => ({ name: l }))).map(eh => {
                const title = eh.label || eh.name || '';
                return `<th class="live-event-col" title="${esc(title)}">${esc(eh.name || title)}</th>`;
            }).join('');
            const rows = (g.standings || []).map(r => {
                const pts = labels.map(l => `<td class="live-event-col">${esc(r.sports && r.sports[l] != null ? r.sports[l] : 0)}</td>`).join('');
                return `<tr>
                    <td class="live-rank live-sticky live-sticky-1">${esc(r.division_rank)}</td>
                    <td class="live-sticky live-sticky-2">${teamCell(r)}</td>
                    ${pts}
                    <td class="live-strong">${esc(r.total)}</td>
                </tr>`;
            }).join('');
            const leader = g.champion
                ? `<span class="live-leader">Points lead: <strong style="color:${esc(g.champion.color)}">${esc(g.champion.team_name)}</strong> (${esc(g.champion.total)} pts)</span>`
                : '';
            const headRowspan = spans.length ? 2 : 1;
            return `<article class="live-block">
                <header class="live-block-head">
                    <h2>${esc(g.division_name)}</h2>
                    <p>${esc(g.activated_event_count)} activated event${g.activated_event_count === 1 ? '' : 's'} ${leader}</p>
                </header>
                <div class="live-table-wrap">
                    <table class="live-table">
                        <thead>
                            <tr>
                                <th class="live-sticky live-sticky-1" rowspan="${headRowspan}">#</th>
                                <th class="live-sticky live-sticky-2" rowspan="${headRowspan}">Team</th>
                                ${spans.length ? spans.map(s => `<th colspan="${s.count}" class="live-event-col">${esc(s.label)}</th>`).join('') : nameHeaders}
                                <th rowspan="${headRowspan}">Total</th>
                            </tr>
                            ${spans.length ? `<tr>${nameHeaders}</tr>` : ''}
                        </thead>
                        <tbody>${rows || '<tr><td colspan="' + (labels.length + 3) + '" class="live-empty-cell">No teams.</td></tr>'}</tbody>
                    </table>
                </div>
            </article>`;
        }).join('');
    }

    function renderMedals() {
        const groups = filteredDivisions();
        if (!groups.length) {
            els.medals.innerHTML = '<p class="live-empty">No medal tally yet.</p>';
            return;
        }
        els.medals.innerHTML = groups.map(g => {
            const mt = g.medal_totals || {};
            const rows = (g.medal_tally || []).map(r => `<tr>
                <td class="live-rank live-sticky live-sticky-1">${esc(r.medal_rank)}</td>
                <td class="live-sticky live-sticky-2">${teamCell(r)}</td>
                <td class="live-medal-col live-medal-col--gold">${esc(r.gold)}</td>
                <td class="live-medal-col live-medal-col--silver">${esc(r.silver)}</td>
                <td class="live-medal-col live-medal-col--bronze">${esc(r.bronze)}</td>
                <td class="live-strong">${esc(r.medal_total)}</td>
                <td>${esc(r.total)}</td>
            </tr>`).join('');
            const leader = g.medal_leader
                ? `<span class="live-leader">Medal lead: <strong style="color:${esc(g.medal_leader.color)}">${esc(g.medal_leader.team_name)}</strong></span>`
                : '';
            return `<article class="live-block">
                <header class="live-block-head">
                    <h2>${esc(g.division_name)}</h2>
                    <p>${esc(mt.gold || 0)}G / ${esc(mt.silver || 0)}S / ${esc(mt.bronze || 0)}B ${leader}</p>
                </header>
                <div class="live-table-wrap">
                    <table class="live-table">
                        <thead>
                            <tr>
                                <th class="live-sticky live-sticky-1">#</th>
                                <th class="live-sticky live-sticky-2">Team</th>
                                <th>Gold</th>
                                <th>Silver</th>
                                <th>Bronze</th>
                                <th>Total</th>
                                <th>Points</th>
                            </tr>
                        </thead>
                        <tbody>${rows || '<tr><td colspan="7" class="live-empty-cell">No teams.</td></tr>'}
                        ${(g.medal_tally || []).length ? `<tr class="live-totals">
                            <td class="live-sticky live-sticky-1"></td>
                            <td class="live-sticky live-sticky-2">Total</td>
                            <td class="live-medal-col live-medal-col--gold">${esc(mt.gold || 0)}</td>
                            <td class="live-medal-col live-medal-col--silver">${esc(mt.silver || 0)}</td>
                            <td class="live-medal-col live-medal-col--bronze">${esc(mt.bronze || 0)}</td>
                            <td class="live-strong">${esc(mt.all || 0)}</td>
                            <td></td>
                        </tr>` : ''}
                        </tbody>
                    </table>
                </div>
            </article>`;
        }).join('');
    }

    function renderEvents() {
        const sportId = els.event.value;
        const event = (data.events || []).find(e => String(e.sport_id) === String(sportId));
        if (!event) {
            els.events.innerHTML = '<p class="live-empty">No events available.</p>';
            return;
        }
        const divKey = els.division.value;
        let divisions = event.divisions || [];
        if (divKey !== 'all') {
            divisions = divisions.filter(d => String(d.division_key) === divKey);
        }
        if (!divisions.length) {
            els.events.innerHTML = `<article class="live-block"><header class="live-block-head"><h2>${esc(event.label)}</h2></header><p class="live-empty">No standings for this filter.</p></article>`;
            return;
        }
        els.events.innerHTML = `<article class="live-block">
            <header class="live-block-head">
                <h2>${esc(event.label)}</h2>
                <p>${event.manual_ranks ? 'Manual ranks applied · ' : ''}Per-division manual entry of ranks</p>
            </header>
            ${divisions.map(d => {
                const rows = (d.standings || []).map(r => {
                    const rank = r.rank >= 1000 ? '—' : r.rank;
                    return `<tr>
                        <td class="live-rank live-sticky live-sticky-1">${esc(rank)}</td>
                        <td class="live-sticky live-sticky-2">${teamCell(r)}</td>
                        <td>${esc(r.placement_label || '—')}</td>
                        <td>${esc(r.played)}</td>
                        <td>${esc(r.wins)}-${esc(r.losses)}-${esc(r.draws)}</td>
                        <td>${esc(r.points)}</td>
                        <td class="live-strong">${esc(r.placement_points)}</td>
                        <td>${medalBadge(r.medal)}</td>
                    </tr>`;
                }).join('');
                return `<div class="live-subblock">
                    <h3>${esc(d.division_name)}${d.manual_ranks ? ' <span class="live-chip">Manual</span>' : ''}</h3>
                    <div class="live-table-wrap">
                        <table class="live-table">
                            <thead>
                                <tr>
                                    <th class="live-sticky live-sticky-1">#</th>
                                    <th class="live-sticky live-sticky-2">Team</th>
                                    <th>Place</th>
                                    <th>P</th>
                                    <th>W-L-D</th>
                                    <th>Match</th>
                                    <th>Event pts</th>
                                    <th>Medal</th>
                                </tr>
                            </thead>
                            <tbody>${rows || '<tr><td colspan="8" class="live-empty-cell">No ranked teams yet.</td></tr>'}</tbody>
                        </table>
                    </div>
                </div>`;
            }).join('')}
        </article>`;
    }

    function renderAll() {
        renderOverall();
        renderMedals();
        renderEvents();
    }

    function showPanel(name) {
        activePanel = name;
        document.querySelectorAll('.live-tab').forEach(btn => {
            const on = btn.dataset.panel === name;
            btn.classList.toggle('active', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        els.overall.classList.toggle('d-none', name !== 'overall');
        els.medals.classList.toggle('d-none', name !== 'medals');
        els.events.classList.toggle('d-none', name !== 'events');
        els.eventWrap.classList.toggle('d-none', name !== 'events');
    }

    async function refresh() {
        try {
            const res = await fetch(API_URL, { cache: 'no-store' });
            const json = await res.json();
            if (!json.ok) throw new Error(json.error || 'Failed to load');
            data = json;
            if (json.season && json.season.label) {
                els.season.textContent = json.season.label;
            }
            els.updated.textContent = json.updated_label || 'just now';
            els.error.classList.add('d-none');
            els.status.classList.remove('is-stale');
            fillDivisionOptions(true);
            fillEventOptions(true);
            renderAll();
        } catch (err) {
            els.error.textContent = 'Could not refresh live standings. Retrying…';
            els.error.classList.remove('d-none');
            els.status.classList.add('is-stale');
        } finally {
            els.loading.classList.add('d-none');
        }
    }

    document.querySelectorAll('.live-tab').forEach(btn => {
        btn.addEventListener('click', () => showPanel(btn.dataset.panel));
    });
    els.division.addEventListener('change', renderAll);
    els.event.addEventListener('change', renderEvents);

    showPanel('overall');
    refresh();
    setInterval(refresh, REFRESH_MS);
})();
</script>
</body>
</html>
