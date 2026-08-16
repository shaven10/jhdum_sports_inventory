<?php
require_once __DIR__ . '/../../includes/auth.php';
requireIntramuralsAccess();

if (!canViewIntramuralsReports() || (!isAdmin() && !isPublishStaff())) {
    flash('error', 'Certificate of Recognition is available only for administrators, secretariat, and publication.');
    redirect(getHomeUrl());
}

ensureSportCategoryEnum();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token')) && post('action') === 'save_certificate_info') {
    if (!isAdmin()) {
        flash('error', 'Only administrators can save certificate information.');
        redirect(BASE_URL . '/intramurals/reports/certificates.php');
    }

    $savedPlace = trim((string) post('place'));
    $savedCity = trim((string) post('city'));
    $savedIncharge = trim((string) post('sports_incharge'));
    $savedDirector = trim((string) post('sports_director'));
    $savedCampusDirector = trim((string) post('campus_director'));

    updateSetting('cert_place', $savedPlace, (int) $_SESSION['user_id']);
    updateSetting('cert_city', $savedCity, (int) $_SESSION['user_id']);
    updateSetting('cert_sports_incharge', $savedIncharge, (int) $_SESSION['user_id']);
    updateSetting('cert_sports_director', $savedDirector, (int) $_SESSION['user_id']);
    updateSetting('cert_campus_director', $savedCampusDirector, (int) $_SESSION['user_id']);
    auditLog((int) $_SESSION['user_id'], 'update', 'certificate_info', null, null, [
        'place' => $savedPlace,
        'city' => $savedCity,
        'sports_incharge' => $savedIncharge,
        'sports_director' => $savedDirector,
        'campus_director' => $savedCampusDirector,
    ]);
    flash('success', 'Certificate information saved. Place, city, and signatories will be used for all future certificates.');
    $redirectQs = [];
    foreach (['sport', 'team', 'certificate_date'] as $k) {
        if (isset($_POST[$k]) && (string) $_POST[$k] !== '') {
            $redirectQs[$k] = $_POST[$k];
        }
    }
    redirect(BASE_URL . '/intramurals/reports/certificates.php' . ($redirectQs ? '?' . http_build_query($redirectQs) : ''));
}

$db = getDB();
$seasonId = getCurrentSeasonId();
$season = getCurrentSeason();
$sportId = (int) get('sport');
$teamId = get('team') !== '' ? (int) get('team') : 0;

$finishedEvents = getFinishedEventsForCertificates($seasonId ?: null);
$finishedIds = array_map(static fn($s) => (int) $s['id'], $finishedEvents);

if ($sportId && !in_array($sportId, $finishedIds, true)) {
    $sportId = 0;
}

$selectedSport = null;
foreach ($finishedEvents as $s) {
    if ((int) $s['id'] === $sportId) {
        $selectedSport = $s;
        break;
    }
}

$placementByTeam = [];
if ($sportId && $seasonId) {
    $blocks = computeSportStandings($sportId, (int) $seasonId);
    $standings = $blocks[$sportId]['standings'] ?? [];
    foreach ($standings as $standing) {
        $place = (int) ($standing['rank'] ?? 0);
        $hasResult = (int) ($standing['played'] ?? 0) > 0 || !empty($standing['manual_rank']);
        // Include champion through last ranked team (skip unranked sentinel ranks).
        if ($hasResult && $place >= 1 && $place < 1000) {
            $placementByTeam[(int) $standing['team_id']] = $place;
        }
    }
}

if ($teamId > 0 && !isset($placementByTeam[$teamId])) {
    $teamId = 0;
}

$teams = [];
if ($placementByTeam) {
    $teamIds = array_keys($placementByTeam);
    if ($teamIds) {
        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        $stmt = $db->prepare("SELECT id, name, color FROM intramural_teams WHERE id IN ($placeholders) AND is_active = 1 ORDER BY name");
        $stmt->execute($teamIds);
        $teams = $stmt->fetchAll() ?: [];
    }
}

$athletes = [];
if ($sportId) {
    $athletes = getCertificateRecognitionAthletes($sportId, $teamId > 0 ? $teamId : null, $seasonId ?: null);
    $athletes = array_values(array_filter($athletes, static function ($athlete) use ($placementByTeam) {
        return isset($placementByTeam[(int) $athlete['team_id']]);
    }));
    foreach ($athletes as &$athlete) {
        $athlete['certificate_place'] = $placementByTeam[(int) $athlete['team_id']];
    }
    unset($athlete);
    usort($athletes, static function ($a, $b) {
        $placeCmp = ((int) $a['certificate_place']) <=> ((int) $b['certificate_place']);
        if ($placeCmp !== 0) {
            return $placeCmp;
        }
        $teamCmp = strcasecmp((string) ($a['team_name'] ?? ''), (string) ($b['team_name'] ?? ''));
        if ($teamCmp !== 0) {
            return $teamCmp;
        }
        $lastCmp = strcasecmp((string) ($a['last_name'] ?? ''), (string) ($b['last_name'] ?? ''));
        if ($lastCmp !== 0) {
            return $lastCmp;
        }
        return strcasecmp((string) ($a['first_name'] ?? ''), (string) ($b['first_name'] ?? ''));
    });
}

$seasonLabel = $season ? seasonLabel($season) : 'No active season';
$eventDates = '';
if ($season && !empty($season['start_date']) && !empty($season['end_date'])) {
    $eventDates = formatDate($season['start_date'], 'F j') . ' - ' . formatDate($season['end_date'], 'F j, Y');
} elseif ($season && !empty($season['start_date'])) {
    $eventDates = formatDate($season['start_date'], 'F j, Y');
} elseif ($season && !empty($season['end_date'])) {
    $eventDates = formatDate($season['end_date'], 'F j, Y');
}
$collegeLogo = BASE_URL . '/assets/img/jhcsc-logo.png';
$sdoLogo = appLogoUrl();
$certificateDateInput = get('certificate_date', date('Y-m-d'));
$certificateDate = strtotime($certificateDateInput) !== false
    ? date('F j, Y', strtotime($certificateDateInput))
    : date('F j, Y');
$certificatePlace = trim((string) getSetting('cert_place', ''));
$certificateCity = trim((string) getSetting('cert_city', ''));
$sportsIncharge = trim((string) getSetting('cert_sports_incharge', ''));
$sportsDirector = trim((string) getSetting('cert_sports_director', ''));
$campusDirector = trim((string) getSetting('cert_campus_director', ''));

$pageTitle = 'Certificate of Recognition';
require_once __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../_season_bar.php';
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
    <div>
        <h1><i class="bi bi-award"></i> Certificate of Recognition</h1>
        <p class="text-muted mb-0">Printable certificates for athletes on the official roster of finished events</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/intramurals/reports/index.php" class="btn btn-outline-primary"><i class="bi bi-printer"></i> All Reports</a>
        <?php if ($athletes): ?>
        <button type="button" class="btn btn-primary" onclick="printReport()"><i class="bi bi-printer"></i> Print / PDF</button>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/intramurals/index.php" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

<div class="card mb-3 no-print">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-pen"></i> Certificate Information</span>
        <small class="text-muted"><?= isAdmin() ? 'Saved once and reused on every certificate' : 'Configured by administrator' ?></small>
    </div>
    <div class="card-body">
        <?php if (isAdmin()): ?>
        <form method="POST" class="row g-2 align-items-end">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_certificate_info">
            <?php if ($sportId): ?><input type="hidden" name="sport" value="<?= (int) $sportId ?>"><?php endif; ?>
            <?php if ($teamId): ?><input type="hidden" name="team" value="<?= (int) $teamId ?>"><?php endif; ?>
            <?php if ($certificateDateInput !== ''): ?><input type="hidden" name="certificate_date" value="<?= sanitize($certificateDateInput) ?>"><?php endif; ?>
            <div class="col-md-4">
                <label class="form-label">Place / Venue</label>
                <input type="text" name="place" class="form-control" value="<?= sanitize($certificatePlace) ?>" placeholder="College gymnasium" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">City</label>
                <input type="text" name="city" class="form-control" value="<?= sanitize($certificateCity) ?>" placeholder="City / Municipality" required>
            </div>
            <div class="col-md-4 d-none d-md-block"></div>
            <div class="col-md-3">
                <label class="form-label">Sports Incharge</label>
                <input type="text" name="sports_incharge" class="form-control" value="<?= sanitize($sportsIncharge) ?>" placeholder="Full name" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Sports Director</label>
                <input type="text" name="sports_director" class="form-control" value="<?= sanitize($sportsDirector) ?>" placeholder="Full name" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Campus Director</label>
                <input type="text" name="campus_director" class="form-control" value="<?= sanitize($campusDirector) ?>" placeholder="Full name" required>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-save"></i> Save Information</button>
            </div>
        </form>
        <?php else: ?>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="text-muted small">Place / Venue</div>
                <div class="fw-semibold"><?= $certificatePlace !== '' ? sanitize($certificatePlace) : '—' ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">City</div>
                <div class="fw-semibold"><?= $certificateCity !== '' ? sanitize($certificateCity) : '—' ?></div>
            </div>
            <div class="col-md-4"></div>
            <div class="col-md-4">
                <div class="text-muted small">Sports Incharge</div>
                <div class="fw-semibold"><?= $sportsIncharge !== '' ? sanitize($sportsIncharge) : '—' ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Sports Director</div>
                <div class="fw-semibold"><?= $sportsDirector !== '' ? sanitize($sportsDirector) : '—' ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Campus Director</div>
                <div class="fw-semibold"><?= $campusDirector !== '' ? sanitize($campusDirector) : '—' ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="filter-bar no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Finished Event</label>
            <select name="sport" class="form-select" required>
                <option value="">Select finished event…</option>
                <?php foreach ($finishedEvents as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= $sportId === (int) $s['id'] ? 'selected' : '' ?>>
                    <?= sanitize(sportLabel($s)) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Team (optional)</label>
            <select name="team" class="form-select" <?= $sportId ? '' : 'disabled' ?>>
                <option value="">All teams</option>
                <?php foreach ($teams as $t): ?>
                <option value="<?= (int) $t['id'] ?>" <?= $teamId === (int) $t['id'] ? 'selected' : '' ?>><?= sanitize($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Certificate Date</label>
            <input type="date" name="certificate_date" class="form-control" value="<?= sanitize($certificateDateInput) ?>">
        </div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Generate</button></div>
    </form>
    <p class="text-muted small mb-0 mt-2">
        An event is finished when all of its matches are completed/forfeit, or when manual rankings are saved and no open fixtures remain.
        Certificates are generated for every ranked team from champion through last place. Athlete names come from their official event rosters.
    </p>
</div>

<?php if (!$seasonId): ?>
<div class="alert alert-warning no-print">Set an active season before generating certificates.</div>
<?php elseif (empty($finishedEvents)): ?>
<div class="alert alert-info no-print">No finished events yet for <?= sanitize($seasonLabel) ?>.</div>
<?php elseif (!$sportId): ?>
<div class="alert alert-info no-print">Select a finished event to generate certificates.</div>
<?php elseif (empty($athletes)): ?>
<div class="alert alert-warning no-print">
    No ranked athletes are on the official roster for <?= sanitize(sportLabel($selectedSport ?? ['name' => 'this event', 'category' => ''])) ?>.
    Confirm the event standings and official rosters first.
</div>
<?php else: ?>

<div class="alert alert-success no-print">
    Ready to print <strong><?= count($athletes) ?></strong> certificate<?= count($athletes) === 1 ? '' : 's' ?>
    for <strong><?= sanitize(sportLabel($selectedSport)) ?></strong>
    <?= $teamId ? ' · filtered team' : '' ?>.
</div>

<div class="cert-print-root">
<?php
$total = count($athletes);
$index = 0;
foreach ($athletes as $athlete):
    $index++;
    $sportName = (string) ($athlete['sport_name'] ?? '');
    $athleteName = athleteFullName($athlete);
    $place = (int) $athlete['certificate_place'];
    $namedPlaces = placementLabels();
    if (isset($namedPlaces[$place])) {
        $placeLabel = $namedPlaces[$place];
    } else {
        $mod100 = $place % 100;
        $mod10 = $place % 10;
        if ($mod100 >= 11 && $mod100 <= 13) {
            $suffix = 'th';
        } else {
            $suffix = [1 => 'st', 2 => 'nd', 3 => 'rd'][$mod10] ?? 'th';
        }
        $placeLabel = $place . $suffix . ' Place';
    }
?>
<section class="cert-sheet<?= $index < $total ? ' cert-sheet-break' : '' ?>" style="--cert-watermark:url('<?= sanitize($collegeLogo) ?>'); --cert-app-logo:url('<?= sanitize($sdoLogo) ?>')">
    <div class="cert-bg-art" aria-hidden="true"></div>
    <header class="entry-form-header cert-header">
        <div class="entry-form-header-top">
            <div class="entry-form-title-cluster">
                <img src="<?= sanitize($collegeLogo) ?>" alt="JHCSC" class="entry-form-logo" onerror="this.src='<?= sanitize($sdoLogo) ?>'">
                <div class="entry-form-title-block">
                    <div class="entry-form-republic">Republic of the Philippines</div>
                    <div class="entry-form-event-title">J.H.CERILLES STATE COLLEGE - DUMINGAG CAMPUS</div>
                    <div class="entry-form-event-dates">Caridad, Dumingag, Zamboanga del Sur</div>
                </div>
                <img src="<?= sanitize($sdoLogo) ?>" alt="Sports Development Office" class="entry-form-sdo-logo">
            </div>
        </div>
    </header>

    <div class="cert-body">
        <div class="cert-main-title">CERTIFICATE OF RECOGNITION</div>
        <div class="cert-kicker">This Certificate of Recognition is proudly presented to</div>
        <div class="cert-athlete-name"><?= sanitize($athleteName) ?></div>
        <p class="cert-statement">
            for having garnered <strong><?= sanitize($placeLabel) ?></strong> in
            <strong><?= sanitize(sportLabel(['name' => $athlete['sport_name'], 'category' => $athlete['sport_category']])) ?></strong>
            representing <strong><?= sanitize($athlete['team_name']) ?></strong>
            during the <strong><?= sanitize($seasonLabel) ?></strong><?php if ($eventDates !== ''): ?> last <strong><?= sanitize($eventDates) ?></strong><?php endif; ?>.
        </p>
        <p class="cert-statement">
            This recognition is awarded in appreciation of the athlete’s outstanding performance,
            dedication, perseverance, and exemplary sportsmanship demonstrated throughout the competition.
        </p>
        <div class="cert-date-line">
            Given this <strong><?= sanitize($certificateDate) ?></strong>
            at <strong><?= sanitize($certificatePlace ?: APP_CAMPUS) ?></strong><?= $certificateCity !== '' ? ', <strong>' . sanitize($certificateCity) . '</strong>' : '' ?>.
        </div>

        <div class="cert-signatures">
            <div class="cert-sign">
                <div class="cert-sign-line"></div>
                <div class="cert-sign-name"><?= sanitize($sportsIncharge) ?></div>
                <div class="cert-sign-title">Sports Incharge</div>
            </div>
            <div class="cert-sign">
                <div class="cert-sign-line"></div>
                <div class="cert-sign-name"><?= sanitize($sportsDirector) ?></div>
                <div class="cert-sign-title">Sports Director</div>
            </div>
            <div class="cert-sign">
                <div class="cert-sign-line"></div>
                <div class="cert-sign-name"><?= sanitize($campusDirector) ?></div>
                <div class="cert-sign-title">Campus Director</div>
            </div>
        </div>
    </div>

    <footer class="cert-footer">
        <span><?= sanitize($seasonLabel) ?> · <?= sanitize($sportName) ?> · <?= sanitize($athlete['team_name']) ?></span>
        <span>Certificate <?= $index ?> of <?= $total ?></span>
    </footer>
</section>
<?php endforeach; ?>
</div>
<?php endif; ?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Great+Vibes&display=swap');

.cert-print-root {
    --ef-border: #222;
    --ef-accent: var(--theme-accent, #c62828);
    --cert-title-font: "Cinzel", "Palatino Linotype", "Book Antiqua", Palatino, serif;
    --cert-script-font: "Great Vibes", "Segoe Script", "Brush Script MT", cursive;
    --cert-theme-primary: var(--theme-primary, #1b5e20);
    --cert-theme-secondary: var(--theme-secondary, #2e7d32);
    --cert-green-deep: #06301a;
    --cert-green-mid: #0d4726;
    --cert-green-soft: #1b6b39;
    --cert-green-pale: #dcebe0;
}

/* Blend the sheet palette with the active app theme when color-mix is available. */
@supports (background: color-mix(in srgb, red 50%, blue)) {
    .cert-print-root {
        --cert-green-deep: color-mix(in srgb, var(--cert-theme-primary) 62%, #001408);
        --cert-green-mid: color-mix(in srgb, var(--cert-theme-primary) 82%, #001408);
        --cert-green-soft: color-mix(in srgb, var(--cert-theme-secondary) 88%, #002a12);
        --cert-green-pale: color-mix(in srgb, var(--cert-theme-secondary) 12%, #ffffff);
    }
}

.cert-sheet {
    width: 8.5in;
    height: 6.5in;
    max-width: 100%;
    box-sizing: border-box;
    color: #111;
    border: 1px solid #c5c5c5;
    border-radius: 6px;
    padding: 0.28in 0.4in 0.22in;
    margin: 0 auto 1.5rem;
    position: relative;
    overflow: hidden;
    font-family: Arial, Helvetica, sans-serif;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    background-color: #fff;
    background-image:
        radial-gradient(120% 85% at -12% -18%, rgba(6, 48, 26, 0.92) 0%, rgba(6, 48, 26, 0.5) 30%, rgba(6, 48, 26, 0) 62%),
        radial-gradient(95% 70% at 112% 118%, rgba(13, 71, 38, 0.88) 0%, rgba(13, 71, 38, 0.42) 32%, rgba(13, 71, 38, 0) 66%),
        radial-gradient(70% 55% at 108% -10%, rgba(27, 107, 57, 0.7) 0%, rgba(27, 107, 57, 0) 58%),
        radial-gradient(80% 60% at -8% 112%, rgba(27, 107, 57, 0.62) 0%, rgba(27, 107, 57, 0) 60%),
        linear-gradient(135deg, rgba(220, 235, 224, 0.95) 0%, rgba(255, 255, 255, 0.96) 45%, rgba(220, 235, 224, 0.95) 100%);
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

/* Abstract green ribbon sweeps across opposite corners. */
.cert-sheet::before,
.cert-sheet::after {
    content: "";
    position: absolute;
    pointer-events: none;
    z-index: 0;
}

.cert-sheet::before {
    top: -1.6in;
    left: -2.1in;
    width: 4.6in;
    height: 4.6in;
    border-radius: 46% 54% 38% 62% / 55% 40% 60% 45%;
    background: linear-gradient(135deg, rgba(6, 48, 26, 0.88) 0%, rgba(13, 71, 38, 0.45) 52%, rgba(27, 107, 57, 0) 100%);
    transform: rotate(-12deg);
}

.cert-sheet::after {
    right: -2in;
    bottom: -1.7in;
    width: 4.4in;
    height: 4.4in;
    border-radius: 58% 42% 60% 40% / 45% 58% 42% 55%;
    background: linear-gradient(315deg, rgba(6, 48, 26, 0.85) 0%, rgba(13, 71, 38, 0.42) 52%, rgba(27, 107, 57, 0) 100%);
    transform: rotate(8deg);
}

@supports (background: color-mix(in srgb, red 50%, blue)) {
    .cert-sheet {
        background-image:
            radial-gradient(120% 85% at -12% -18%,
                color-mix(in srgb, var(--cert-green-deep) 92%, transparent) 0%,
                color-mix(in srgb, var(--cert-green-deep) 50%, transparent) 30%,
                transparent 62%),
            radial-gradient(95% 70% at 112% 118%,
                color-mix(in srgb, var(--cert-green-mid) 88%, transparent) 0%,
                color-mix(in srgb, var(--cert-green-mid) 42%, transparent) 32%,
                transparent 66%),
            radial-gradient(70% 55% at 108% -10%,
                color-mix(in srgb, var(--cert-green-soft) 70%, transparent) 0%,
                transparent 58%),
            radial-gradient(80% 60% at -8% 112%,
                color-mix(in srgb, var(--cert-green-soft) 62%, transparent) 0%,
                transparent 60%),
            linear-gradient(135deg,
                color-mix(in srgb, var(--cert-green-pale) 95%, transparent) 0%,
                rgba(255, 255, 255, 0.96) 45%,
                color-mix(in srgb, var(--cert-green-pale) 95%, transparent) 100%);
    }

    .cert-sheet::before {
        background: linear-gradient(135deg,
            color-mix(in srgb, var(--cert-green-deep) 88%, transparent) 0%,
            color-mix(in srgb, var(--cert-green-mid) 45%, transparent) 52%,
            transparent 100%);
    }

    .cert-sheet::after {
        background: linear-gradient(315deg,
            color-mix(in srgb, var(--cert-green-deep) 85%, transparent) 0%,
            color-mix(in srgb, var(--cert-green-mid) 42%, transparent) 52%,
            transparent 100%);
    }
}

.cert-sheet > * {
    position: relative;
    z-index: 1;
}

/* App logo blended into the green background art. */
.cert-sheet > .cert-bg-art {
    position: absolute;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    background-image: var(--cert-watermark), var(--cert-app-logo), var(--cert-app-logo);
    background-repeat: no-repeat, no-repeat, no-repeat;
    background-position: center 52%, -1.15in 88%, 108% 6%;
    background-size: 3.1in auto, 2.5in auto, 1.9in auto;
    opacity: 0.14;
    mix-blend-mode: multiply;
}

.entry-form-header-top {
    display: flex;
    justify-content: center;
    align-items: center;
    margin-bottom: 0.85rem;
}

.entry-form-title-cluster {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.55rem;
    min-width: 0;
}

.entry-form-logo,
.entry-form-sdo-logo {
    width: 0.82in;
    height: 0.82in;
    object-fit: cover;
    border-radius: 50%;
    background: #fff;
    border: 1px solid #ddd;
    flex-shrink: 0;
}

.entry-form-title-block { text-align: center; }

.entry-form-office-heading,
.entry-form-republic,
.entry-form-event-title,
.entry-form-event-dates {
    font-family: "Times New Roman", Times, Georgia, serif;
}

.entry-form-office-heading {
    font-size: 0.993rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    line-height: 1.15;
    text-transform: uppercase;
    margin-top: 0.1rem;
}

.entry-form-republic {
    font-size: 0.903rem;
    font-weight: 600;
    letter-spacing: 0.03em;
    line-height: 1.15;
    margin-bottom: 0.1rem;
}

.entry-form-event-title {
    font-size: 1.155rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    line-height: 1.2;
    text-transform: uppercase;
    color: var(--cert-green-deep);
}

.entry-form-event-dates {
    font-size: 0.948rem;
    font-weight: 600;
    margin-top: 0.15rem;
}

.cert-body {
    position: relative;
    text-align: center;
    padding: 0.45rem 0.35rem 0.15rem;
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

.cert-main-title,
.cert-kicker,
.cert-athlete-name,
.cert-statement,
.cert-date-line,
.cert-signatures {
    position: relative;
    z-index: 1;
}

.cert-main-title {
    font-family: var(--cert-title-font);
    font-size: 1.85rem;
    font-weight: 700;
    letter-spacing: 0.16em;
    color: var(--ef-accent);
    margin-top: 0.2rem;
    margin-bottom: 0.4rem;
    text-transform: uppercase;
    text-shadow: 0 1px 0 rgba(255, 255, 255, 0.4);
}

.cert-kicker {
    font-family: var(--cert-script-font);
    color: var(--cert-green-deep);
    font-size: 1.534rem;
    font-style: normal;
    font-weight: 400;
    margin-bottom: 0.35rem;
    letter-spacing: 0.02em;
}

.cert-athlete-name {
    font-family: var(--cert-title-font);
    font-size: 1.715rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    color: var(--ef-accent);
    border-bottom: 2px solid var(--cert-green-mid);
    display: inline-block;
    min-width: min(100%, 26rem);
    padding: 0.12rem 0.9rem 0.2rem;
    margin: 0 auto 0.45rem;
}

.cert-statement {
    max-width: 46rem;
    margin: 0 auto 0.55rem;
    font-size: 0.975rem;
    line-height: 1.45;
}

.cert-date-line {
    font-family: "Times New Roman", Times, Georgia, serif;
    font-size: 0.975rem;
    margin-bottom: 0.65rem;
}

.cert-signatures {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.1rem;
    max-width: 48rem;
    margin: 0.35rem auto 0;
    width: 100%;
}

.cert-sign {
    min-width: 0;
    text-align: center;
}

.cert-sign-line {
    border-bottom: 1.5px solid var(--cert-green-mid);
    height: 1.5rem;
    margin: 0 auto 0.35rem;
    width: 100%;
}

.cert-sign-name {
    margin: 0 auto 0.18rem;
    font-weight: 700;
    font-size: 0.948rem;
    line-height: 1.2;
    white-space: nowrap;
    max-width: 100%;
}

.cert-sign-title {
    font-size: 0.83rem;
    font-weight: 700;
    color: var(--cert-green-deep);
}

.cert-footer {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    margin-top: 0.25rem;
    font-size: 0.704rem;
    color: #555;
}

@media (max-width: 991.98px) {
    .cert-sheet {
        width: 100%;
        height: auto;
        min-height: 6.5in;
    }
    .cert-signatures { grid-template-columns: 1fr; gap: 0.85rem; }
    .cert-athlete-name { font-size: 1.309rem; min-width: 0; width: 100%; }
    .cert-main-title { font-size: 1.399rem; letter-spacing: 0.1em; }
    .cert-kicker { font-size: 1.218rem; }
    .cert-statement,
    .cert-date-line { font-size: 0.903rem; }
}

@media print {
    @page {
        size: 8.5in 6.5in;
        margin: 0;
    }

    html, body {
        width: 8.5in;
        height: 6.5in;
    }

    .sidebar, .season-bar, .alert, .page-header, .filter-bar, .no-print, .app-navbar, .footer, .app-nav-offcanvas {
        display: none !important;
    }
    .app-main {
        padding: 0 !important;
        margin: 0 !important;
        max-width: none !important;
        width: 8.5in !important;
    }
    .cert-print-root {
        width: 8.5in;
    }
    .cert-sheet {
        width: 8.5in !important;
        height: 6.5in !important;
        max-width: none !important;
        border: none !important;
        border-radius: 0 !important;
        margin: 0 !important;
        padding: 0.28in 0.4in 0.22in !important;
        box-shadow: none !important;
        overflow: hidden !important;
        page-break-after: always;
        break-after: page;
        page-break-inside: avoid;
        break-inside: avoid;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .cert-sheet::before,
    .cert-sheet::after {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .cert-sheet:last-child {
        page-break-after: auto;
        break-after: auto;
    }
    .cert-sheet-break {
        page-break-after: always;
        break-after: page;
    }
    .entry-form-sdo-logo,
    .entry-form-logo {
        width: 0.78in;
        height: 0.78in;
    }
    .cert-sheet > .cert-bg-art {
        opacity: 0.16;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .cert-athlete-name,
    .cert-main-title {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

<script>
(function () {
    function fitSignatoryNames() {
        document.querySelectorAll('.cert-sign-name').forEach(function (el) {
            var parent = el.parentElement;
            if (!parent) {
                return;
            }
            var maxPx = 15.34;
            var minPx = 8.12;
            el.style.fontSize = maxPx + 'px';
            while (el.scrollWidth > parent.clientWidth && maxPx > minPx) {
                maxPx -= 0.5;
                el.style.fontSize = maxPx + 'px';
            }
        });
    }

    fitSignatoryNames();
    window.addEventListener('resize', fitSignatoryNames);
    window.addEventListener('beforeprint', fitSignatoryNames);
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
