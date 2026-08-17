<?php
/**
 * Public working committees listing — grouped by Overall / Sporting Events / Socio-Cultural.
 */
require_once __DIR__ . '/includes/auth.php';

$sections = [];
try {
    $sections = getPublicWorkingCommitteesGrouped();
} catch (Throwable $e) {
    $sections = [];
}

$pageTitle = 'Working Committees';
$stylePath = __DIR__ . '/assets/css/style.css';
$styleVersion = is_file($stylePath) ? (string) filemtime($stylePath) : APP_VERSION;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle) ?> — Sports Development Integrated Management Information System (SDIMIS)</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css?v=<?= $styleVersion ?>" rel="stylesheet">
    <?= renderThemeStyles() ?>
</head>
<body class="live-board-page committees-page">
<div class="live-board">
    <header class="live-board-hero committees-hero">
        <div class="live-board-hero__bg" aria-hidden="true"></div>
        <div class="live-board-hero__inner">
            <div class="live-board-brand">
                <img src="<?= sanitize(appLogoUrl()) ?>" alt="" class="live-board-logo">
                <div>
                    <p class="live-board-campus"><?= sanitize(APP_CAMPUS) ?></p>
                    <h1 class="live-board-title">Working Committees</h1>
                </div>
            </div>
            <p class="live-board-lead">Overall, sporting events, and socio-cultural working committees.</p>
            <div class="live-board-cta">
                <a class="btn btn-light btn-lg" href="<?= BASE_URL ?>/live.php">Back to live rankings</a>
                <?php if (isLoggedIn() && isAdmin()): ?>
                <a class="btn btn-outline-light btn-lg" href="<?= BASE_URL ?>/admin/committees/index.php">Manage committees</a>
                <?php elseif (!isLoggedIn()): ?>
                <a class="btn btn-outline-light btn-lg" href="<?= BASE_URL ?>/login.php">Staff login</a>
                <?php endif; ?>
            </div>
            <?php if ($sections): ?>
            <nav class="committees-jump" aria-label="Committee groups">
                <?php foreach ($sections as $section): ?>
                <a href="#group-<?= sanitize($section['category']) ?>"><?= sanitize($section['label']) ?></a>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>
        </div>
    </header>

    <main class="live-board-main committees-main">
        <?php if (empty($sections)): ?>
        <p class="live-empty">No working committees have been published yet.</p>
        <?php else: ?>
        <?php foreach ($sections as $section): ?>
        <section class="committee-group" id="group-<?= sanitize($section['category']) ?>">
            <header class="committee-group-head">
                <h2><?= sanitize($section['label']) ?></h2>
                <p><?= count($section['committees']) ?> committee<?= count($section['committees']) === 1 ? '' : 's' ?></p>
            </header>
            <div class="committees-grid">
                <?php foreach ($section['committees'] as $group): ?>
                <?php
                $committee = $group['committee'];
                $members = $group['members'];
                ?>
                <article class="committee-block">
                    <header class="committee-block-head">
                        <h3><?= sanitize($committee['name']) ?></h3>
                        <?php if (!empty($committee['description'])): ?>
                        <p><?= nl2br(sanitize($committee['description'])) ?></p>
                        <?php endif; ?>
                    </header>
                    <?php if (empty($members)): ?>
                    <p class="live-empty">Members to be announced.</p>
                    <?php else: ?>
                    <ul class="committee-member-list">
                        <?php foreach ($members as $m): ?>
                        <li class="committee-member">
                            <div class="committee-member-name"><?= sanitize($m['full_name']) ?></div>
                            <?php if (!empty($m['position_title']) || !empty($m['organization'])): ?>
                            <div class="committee-member-meta">
                                <?php if (!empty($m['position_title'])): ?>
                                <span><?= sanitize($m['position_title']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($m['position_title']) && !empty($m['organization'])): ?>
                                <span class="live-meta-sep" aria-hidden="true">·</span>
                                <?php endif; ?>
                                <?php if (!empty($m['organization'])): ?>
                                <span><?= sanitize($m['organization']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>
        <?php endif; ?>
    </main>

    <footer class="live-board-footer">
        <span><?= sanitize(APP_NAME) ?></span>
        <a href="<?= BASE_URL ?>/live.php">Live rankings</a>
    </footer>
</div>
</body>
</html>
