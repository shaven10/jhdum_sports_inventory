<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();
ensureSportGuidelinesColumn();

$db = getDB();
$canEdit = canManageIntramurals();
$focusSportId = (int) get('sport');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    if (!verifyCsrf(post('csrf_token'))) {
        flash('error', 'Invalid request.');
        redirect(BASE_URL . '/intramurals/sports/guidelines.php');
    }

    $action = post('action');
    if ($action === 'save_guidelines') {
        $sportId = (int) post('sport_id');
        $guidelines = trim(post('guidelines'));

        $sport = $db->prepare('SELECT id, name, category FROM intramural_sports WHERE id = ?');
        $sport->execute([$sportId]);
        $sportRow = $sport->fetch();

        if (!$sportRow) {
            flash('error', 'Sport not found.');
            redirect(BASE_URL . '/intramurals/sports/guidelines.php');
        }

        $db->prepare('UPDATE intramural_sports SET guidelines = ? WHERE id = ?')
            ->execute([$guidelines !== '' ? $guidelines : null, $sportId]);

        auditLog($_SESSION['user_id'], 'update', 'intramural_sport_guidelines', $sportId, null, [
            'name' => $sportRow['name'],
            'category' => $sportRow['category'],
        ]);

        flash('success', 'Guidelines saved for ' . sportLabel($sportRow) . '.');
        redirect(BASE_URL . '/intramurals/sports/guidelines.php?sport=' . $sportId);
    }
}

$sports = $db->query('SELECT * FROM intramural_sports ORDER BY name, category')->fetchAll();

$pageTitle = 'Sport Guidelines';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <div>
        <h1 class="mb-1"><i class="bi bi-journal-text"></i> Sport Guidelines</h1>
        <p class="text-muted mb-0">
            <?= $canEdit
                ? 'Manage official guidelines per sport/event for tournament managers, coaches, and participants.'
                : 'Official guidelines per sport/event.' ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2 ms-auto">
        <a href="<?= BASE_URL ?>/intramurals/sports/index.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-trophy"></i> Sports / Events</a>
        <a href="<?= BASE_URL ?>/intramurals/index.php" class="btn btn-sm btn-outline-secondary">Back</a>
    </div>
</div>

<?php if (empty($sports)): ?>
<div class="alert alert-info">No sports/events yet. Add sports under <a href="<?= BASE_URL ?>/intramurals/sports/index.php">Sports / Events</a> first.</div>
<?php else: ?>

<div class="row g-3 mb-3">
    <div class="col-md-6 col-lg-4">
        <label class="form-label" for="guidelineSportFilter">Jump to sport</label>
        <select id="guidelineSportFilter" class="form-select">
            <option value="">All sports</option>
            <?php foreach ($sports as $s): ?>
            <option value="sport-<?= (int) $s['id'] ?>" <?= $focusSportId === (int) $s['id'] ? 'selected' : '' ?>>
                <?= sanitize(sportLabel($s)) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="row g-4" id="guidelinesList">
    <?php foreach ($sports as $s): ?>
    <?php
    $sportId = (int) $s['id'];
    $hasGuidelines = trim((string) ($s['guidelines'] ?? '')) !== '';
    $guidelinePayload = htmlspecialchars(json_encode([
        'id' => $sportId,
        'name' => $s['name'],
        'category' => $s['category'],
        'guidelines' => $s['guidelines'] ?? '',
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
    ?>
    <div class="col-lg-6" id="sport-<?= $sportId ?>">
        <div class="card h-100 guideline-card <?= $focusSportId === $sportId ? 'border-primary shadow-sm' : '' ?>">
            <div class="card-header d-flex justify-content-between align-items-start gap-2">
                <div>
                    <strong><?= sanitize($s['name']) ?></strong>
                    <span class="badge bg-secondary ms-1"><?= ucfirst($s['category']) ?></span>
                    <?php if (!$hasGuidelines): ?>
                    <span class="badge bg-warning text-dark ms-1">No guidelines</span>
                    <?php endif; ?>
                    <div class="small text-muted mt-1">
                        <?= sanitize(tournamentFormatLabel($s['tournament_format'] ?? 'round_robin')) ?>
                        <?php if (!empty($s['players_per_event'])): ?>
                        · <?= (int) $s['players_per_event'] ?> players/event
                        <?php endif; ?>
                        <?php if (!empty($s['venue'])): ?>
                        · <?= sanitize($s['venue']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($canEdit): ?>
                <button type="button"
                        class="btn btn-sm btn-outline-primary btn-edit-guidelines flex-shrink-0"
                        data-bs-toggle="modal"
                        data-bs-target="#guidelineModal"
                        data-sport-id="<?= $sportId ?>"
                        data-guideline="<?= $guidelinePayload ?>">
                    <i class="bi bi-pencil"></i> Edit
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($hasGuidelines): ?>
                <div class="guideline-content"><?= nl2br(sanitize($s['guidelines'])) ?></div>
                <?php else: ?>
                <p class="text-muted mb-0">
                    <?= $canEdit
                        ? 'No guidelines published yet. Click Edit to add eligibility rules, equipment requirements, match conduct, and other event policies.'
                        : 'Guidelines have not been published for this event yet.' ?>
                </p>
                <?php endif; ?>

                <?php if (!empty($s['rules'])): ?>
                <hr>
                <h6 class="text-muted small text-uppercase mb-2">Game Rules</h6>
                <div class="small"><?= nl2br(sanitize($s['rules'])) ?></div>
                <?php endif; ?>

                <?php if (!empty($s['format_notes'])): ?>
                <hr>
                <h6 class="text-muted small text-uppercase mb-2">Format Notes</h6>
                <div class="small"><?= nl2br(sanitize($s['format_notes'])) ?></div>
                <?php endif; ?>

                <?php if (!empty($s['schedule_notes'])): ?>
                <hr>
                <h6 class="text-muted small text-uppercase mb-2">Schedule Notes</h6>
                <div class="small"><?= nl2br(sanitize($s['schedule_notes'])) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="guidelineModal" tabindex="-1" aria-labelledby="guidelineModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" id="guidelineForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_guidelines">
                <input type="hidden" name="sport_id" id="guidelineSportId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="guidelineModalLabel"><i class="bi bi-journal-text"></i> Edit Guidelines</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3" id="guidelineSportTitle"></p>
                    <label class="form-label" for="guidelineText">Event Guidelines</label>
                    <textarea name="guidelines" id="guidelineText" class="form-control font-monospace" rows="14" placeholder="Example sections:

ELIGIBILITY
- Enrolled students only
- Valid ID required on game day

ROSTER
- Maximum 12 players per team
- Jersey numbers 0–99

EQUIPMENT
- Official ball provided by host unit

CONDUCT
- Respect officials and opponents
- Unsporting behavior may result in forfeiture"></textarea>
                    <div class="form-text">
                        These guidelines are shown per sport/event. Game rules and format notes from Sports Management are displayed below the guidelines on the same card.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Guidelines</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const modalEl = document.getElementById('guidelineModal');
    const sportIdInput = document.getElementById('guidelineSportId');
    const sportTitle = document.getElementById('guidelineSportTitle');
    const guidelineText = document.getElementById('guidelineText');
    const modalTitle = document.getElementById('guidelineModalLabel');
    const filter = document.getElementById('guidelineSportFilter');

    function openEditor(data) {
        sportIdInput.value = data.id || '';
        const category = data.category ? data.category.charAt(0).toUpperCase() + data.category.slice(1) : '';
        sportTitle.textContent = (data.name || 'Sport') + (category ? ' (' + category + ')' : '');
        modalTitle.innerHTML = '<i class="bi bi-journal-text"></i> Edit Guidelines — ' + (data.name || 'Sport');
        guidelineText.value = data.guidelines || '';
    }

    document.querySelectorAll('.btn-edit-guidelines').forEach(function (btn) {
        btn.addEventListener('click', function () {
            try {
                openEditor(JSON.parse(this.dataset.guideline || '{}'));
            } catch (e) {
                openEditor({});
            }
        });
    });

    if (filter) {
        filter.addEventListener('change', function () {
            const target = this.value;
            if (!target) {
                document.querySelectorAll('.guideline-card').forEach(function (card) {
                    card.closest('.col-lg-6').classList.remove('d-none');
                });
                return;
            }
            document.querySelectorAll('#guidelinesList > .col-lg-6').forEach(function (col) {
                col.classList.toggle('d-none', col.id !== target);
            });
            const el = document.getElementById(target);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });

        if (filter.value) {
            filter.dispatchEvent(new Event('change'));
        }
    }

    const focusId = <?= (int) $focusSportId ?>;
    if (focusId) {
        const trigger = document.querySelector('.btn-edit-guidelines[data-sport-id="' + focusId + '"]');
        if (trigger) {
            trigger.click();
        } else if (modalEl) {
            const card = document.getElementById('sport-' + focusId);
            if (card) {
                card.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }
})();
</script>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
