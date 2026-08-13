<style>
.match-roster-trigger { text-underline-offset: .15em; }
.fs-4 .match-roster-trigger { font-size: inherit; line-height: inherit; }
</style>
<div class="modal fade" id="matchRosterModal" tabindex="-1" aria-labelledby="matchRosterModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="matchRosterModalLabel">Official roster</h5>
                    <div class="small text-muted" id="matchRosterModalMeta"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="matchRosterModalBody">
                <div class="text-muted py-3 text-center">Loading players…</div>
            </div>
            <div class="modal-footer">
                <span class="me-auto small text-muted">Only listed players are eligible for this match.</span>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    const endpoint = <?= json_encode(BASE_URL . '/intramurals/matches/roster_json.php') ?>;
    const modalEl = document.getElementById('matchRosterModal');
    if (!modalEl) return;
    const titleEl = document.getElementById('matchRosterModalLabel');
    const metaEl = document.getElementById('matchRosterModalMeta');
    const bodyEl = document.getElementById('matchRosterModalBody');
    let modal;

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderPlayers(data) {
        titleEl.textContent = data.team + ' — official roster';
        const bits = [];
        if (data.sport) bits.push(data.sport);
        if (data.round) bits.push(data.round);
        if (data.scheduled_at) bits.push(data.scheduled_at);
        bits.push((data.count || 0) + ' player' + (data.count === 1 ? '' : 's'));
        metaEl.textContent = bits.join(' · ');

        if (!data.players || !data.players.length) {
            bodyEl.innerHTML = '<div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle"></i> No athletes are listed on the official roster for this team and event. Unlisted players are not eligible.</div>';
            return;
        }

        let html = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr>'
            + '<th>#</th><th>Jersey</th><th>Player</th><th>Student ID</th><th>Year</th><th>Pos.</th></tr></thead><tbody>';
        data.players.forEach(function (p, i) {
            html += '<tr><td>' + (i + 1) + '</td>'
                + '<td><strong>' + esc(p.jersey || '—') + '</strong></td>'
                + '<td>' + esc(p.name)
                + (p.gender || p.division ? '<div class="small text-muted">' + esc([p.gender, p.division].filter(Boolean).join(' · ')) + '</div>' : '')
                + '</td>'
                + '<td>' + esc(p.student_id || '—') + '</td>'
                + '<td>' + esc(p.year_level || '—') + '</td>'
                + '<td>' + esc(p.position || '—') + '</td></tr>';
        });
        html += '</tbody></table></div>';
        bodyEl.innerHTML = html;
    }

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.match-roster-trigger');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        const matchId = btn.getAttribute('data-match-id');
        const teamId = btn.getAttribute('data-team-id');
        if (!matchId || !teamId) return;

        titleEl.textContent = 'Official roster';
        metaEl.textContent = '';
        bodyEl.innerHTML = '<div class="text-muted py-3 text-center">Loading players…</div>';
        modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();

        fetch(endpoint + '?match_id=' + encodeURIComponent(matchId) + '&team_id=' + encodeURIComponent(teamId), {
            credentials: 'same-origin'
        })
            .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
            .then(function (result) {
                if (!result.data || !result.data.ok) {
                    bodyEl.innerHTML = '<div class="alert alert-danger mb-0">' + esc((result.data && result.data.error) || 'Could not load roster.') + '</div>';
                    return;
                }
                renderPlayers(result.data);
            })
            .catch(function () {
                bodyEl.innerHTML = '<div class="alert alert-danger mb-0">Could not load roster. Check your connection and try again.</div>';
            });
    });
})();
</script>
