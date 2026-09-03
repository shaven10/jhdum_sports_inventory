<?php
/**
 * Public landing page — shown when live standings are disabled by admin.
 */
require_once __DIR__ . '/includes/auth.php';

try {
    ensureLiveBoardColumns();
    ensureSeasonGalleryTable();
    ensureSdoAboutSettings();
} catch (Throwable $e) {
    // Public page must remain reachable even if optional migrations fail.
}

if (isLiveBoardEnabled() && !isAdmin()) {
    redirect(BASE_URL . '/live.php');
}

$season = getActiveSeason();
$gallerySeason = null;
$galleryPhotos = [];

try {
    $gallerySeason = getGalleryDisplaySeason();
    $galleryPhotos = $gallerySeason ? getSeasonGalleryPhotos((int) $gallerySeason['id']) : [];
} catch (Throwable $e) {
    $gallerySeason = null;
    $galleryPhotos = [];
}

$sdoTitle = getSetting('sdo_about_title', 'About the Sports Development Office');
$sdoContent = trim((string) getSetting('sdo_about_content', ''));
if ($sdoContent === '') {
    $sdoContent = "The Sports Development Office (SDO) leads the campus sports program at J.H. Cerilles State College — organizing intramurals, supporting varsity teams, managing sports facilities and equipment, and promoting wellness through physical activity.\n\nOur office works with coaches, unit managers, and student-athletes to deliver fair competition, meaningful recreation, and opportunities for leadership on and off the field.";
}
$sections = [];
try {
    $sections = getPublicWorkingCommitteesGrouped();
} catch (Throwable $e) {
    $sections = [];
}


$committeeCount = 0;

foreach ($sections as $section) {

    $committeeCount += count($section['committees'] ?? []);

}



$pageTitle = 'Intramurals';

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

<body class="live-board-page landing-page">

<div class="live-board">

    <header class="live-board-hero landing-hero">

        <div class="live-board-hero__bg" aria-hidden="true"></div>

        <div class="live-board-hero__inner">

            <div class="live-board-brand">

                <img src="<?= sanitize(appLogoUrl()) ?>" alt="" class="live-board-logo">

                <div>

                    <p class="live-board-campus"><?= sanitize(APP_CAMPUS) ?></p>

                    <h1 class="live-board-title">Sports Development Integrated Management Information System (SDIMIS)</h1>

                </div>

            </div>

            <?php if ($season): ?>

            <p class="live-board-season-label"><?= sanitize(seasonLabel($season)) ?></p>

            <?php endif; ?>

            <p class="live-board-lead">Welcome to the intramurals portal. Live overall rankings and medal standings will be published here when competition results are ready.</p>

            <div class="live-board-cta">

                <?php if ($committeeCount > 0): ?>

                <a class="btn btn-light btn-lg" href="<?= BASE_URL ?>/committees.php">Working committees</a>

                <?php endif; ?>

                <?php if ($galleryPhotos !== []): ?>

                <a class="btn btn-light btn-lg" href="#activity-gallery">Activity gallery</a>

                <?php endif; ?>

                <?php if (isLoggedIn()): ?>

                <a class="btn btn-outline-light btn-lg" href="<?= sanitize(getHomeUrl()) ?>">Go to dashboard</a>

                <?php else: ?>

                <a class="btn btn-outline-light btn-lg" href="<?= BASE_URL ?>/login.php">Staff login</a>

                <?php endif; ?>

                <?php if (isAdmin() && !isLiveBoardEnabled()): ?>

                <a class="btn btn-outline-light btn-lg" href="<?= BASE_URL ?>/admin/live_board.php">Enable live standings</a>

                <?php endif; ?>

            </div>

            <?php if ($season && ($season['start_date'] || $season['end_date'])): ?>

            <div class="live-board-meta">

                <span><i class="bi bi-calendar3"></i>

                    <?= $season['start_date'] ? formatDate($season['start_date']) : '—' ?>

                    →

                    <?= $season['end_date'] ? formatDate($season['end_date']) : '—' ?>

                </span>

            </div>

            <?php endif; ?>

        </div>

    </header>



    <main class="live-board-main landing-main">

        <?php if ($sdoContent !== ''): ?>

        <section class="landing-about landing-sdo" id="about-sdo">

            <h2><?= sanitize($sdoTitle) ?></h2>

            <div class="landing-sdo-content"><?= nl2br(sanitize($sdoContent)) ?></div>

        </section>

        <?php endif; ?>



        <section class="landing-cards">

            <article class="landing-card">

                <div class="landing-card-icon"><i class="bi bi-trophy"></i></div>

                <h2>Overall Rankings</h2>

                <p>Division standings and placement points across all activated events will appear on the live board when enabled.</p>

            </article>

            <article class="landing-card">

                <div class="landing-card-icon"><i class="bi bi-award"></i></div>

                <h2>Medal Tally</h2>

                <p>Gold, silver, and bronze counts by team will be updated live during the intramurals season.</p>

            </article>

            <article class="landing-card">

                <div class="landing-card-icon"><i class="bi bi-grid-3x3-gap"></i></div>

                <h2>Per-Event Standings</h2>

                <p>Individual sport results, win-loss records, and medals will be available for each competition.</p>

            </article>

        </section>



        <?php if ($galleryPhotos !== [] && $gallerySeason): ?>
        <section class="landing-gallery" id="activity-gallery">
            <header class="landing-section-head">
                <div>
                    <h2>Activity Gallery</h2>
                    <p class="landing-gallery-season"><?= sanitize(seasonLabel($gallerySeason)) ?></p>
                </div>
                <?php if (count($galleryPhotos) > 1): ?>
                <p class="gallery-carousel__counter" id="galleryCounter" aria-live="polite">1 / <?= count($galleryPhotos) ?></p>
                <?php endif; ?>
            </header>

            <div class="gallery-carousel" id="galleryCarousel" data-autoplay="5500">
                <div class="gallery-carousel__viewport">
                    <div class="gallery-carousel__track" id="galleryTrack">
                        <?php foreach ($galleryPhotos as $index => $photo): ?>
                        <figure class="gallery-carousel__slide<?= $index === 0 ? ' is-active' : '' ?>" data-index="<?= (int) $index ?>">
                            <button type="button"
                                class="gallery-carousel__photo"
                                data-lightbox="<?= sanitize(galleryPhotoUrl($photo['filename'])) ?>"
                                data-caption="<?= sanitize($photo['caption'] ?? '') ?>"
                                aria-label="<?= sanitize($photo['caption'] ?: 'View activity photo ' . ($index + 1)) ?>">
                                <img src="<?= sanitize(galleryPhotoUrl($photo['filename'])) ?>"
                                    alt="<?= sanitize($photo['caption'] ?? 'Intramurals activity') ?>"
                                    loading="<?= $index === 0 ? 'eager' : 'lazy' ?>">
                            </button>
                            <?php if (!empty($photo['caption'])): ?>
                            <figcaption class="gallery-carousel__caption"><?= sanitize($photo['caption']) ?></figcaption>
                            <?php endif; ?>
                        </figure>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (count($galleryPhotos) > 1): ?>
                <button type="button" class="gallery-carousel__nav gallery-carousel__nav--prev" id="galleryPrev" aria-label="Previous photo">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <button type="button" class="gallery-carousel__nav gallery-carousel__nav--next" id="galleryNext" aria-label="Next photo">
                    <i class="bi bi-chevron-right"></i>
                </button>
                <div class="gallery-carousel__dots" id="galleryDots" role="tablist" aria-label="Gallery slides">
                    <?php foreach ($galleryPhotos as $index => $photo): ?>
                    <button type="button"
                        class="gallery-carousel__dot<?= $index === 0 ? ' is-active' : '' ?>"
                        role="tab"
                        aria-selected="<?= $index === 0 ? 'true' : 'false' ?>"
                        aria-label="Slide <?= (int) $index + 1 ?>"
                        data-index="<?= (int) $index ?>"></button>
                    <?php endforeach; ?>
                </div>
                <div class="gallery-carousel__thumbs" id="galleryThumbs">
                    <?php foreach ($galleryPhotos as $index => $photo): ?>
                    <button type="button"
                        class="gallery-carousel__thumb<?= $index === 0 ? ' is-active' : '' ?>"
                        data-index="<?= (int) $index ?>"
                        aria-label="Go to slide <?= (int) $index + 1 ?>">
                        <img src="<?= sanitize(galleryPhotoUrl($photo['filename'])) ?>" alt="" loading="lazy">
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>



        <?php if ($committeeCount > 0): ?>

        <section class="landing-committees-preview">

            <header class="landing-section-head">

                <h2>Working Committees</h2>

                <a href="<?= BASE_URL ?>/committees.php">View all <i class="bi bi-arrow-right"></i></a>

            </header>

            <div class="landing-committee-tags">

                <?php foreach ($sections as $section): ?>

                <a class="landing-committee-tag" href="<?= BASE_URL ?>/committees.php#group-<?= sanitize($section['category']) ?>">

                    <?= sanitize($section['label']) ?>

                    <span><?= count($section['committees']) ?></span>

                </a>

                <?php endforeach; ?>

            </div>

        </section>

        <?php endif; ?>



        <?php if (!empty($season['description'])): ?>

        <section class="landing-about">

            <h2>About this season</h2>

            <p><?= nl2br(sanitize($season['description'])) ?></p>

        </section>

        <?php endif; ?>

    </main>



    <footer class="live-board-footer">

        <a href="<?= BASE_URL ?>/login.php">Staff login</a>

        <?php if ($committeeCount > 0): ?>

        <span class="live-meta-sep" aria-hidden="true">·</span>

        <a href="<?= BASE_URL ?>/committees.php">Working committees</a>

        <?php endif; ?>

    </footer>

</div>



<div class="landing-lightbox" id="galleryLightbox" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-label="Photo preview">

    <button type="button" class="landing-lightbox-close" id="lightboxClose" aria-label="Close">&times;</button>

    <figure class="landing-lightbox-inner">

        <img src="" alt="" id="lightboxImage">

        <figcaption id="lightboxCaption"></figcaption>

    </figure>

</div>



<script>
(function () {
    /* —— Gallery carousel —— */
    var carousel = document.getElementById('galleryCarousel');
    if (carousel) {
        var track = document.getElementById('galleryTrack');
        var slides = track ? track.querySelectorAll('.gallery-carousel__slide') : [];
        var dots = carousel.querySelectorAll('.gallery-carousel__dot');
        var thumbs = carousel.querySelectorAll('.gallery-carousel__thumb');
        var prevBtn = document.getElementById('galleryPrev');
        var nextBtn = document.getElementById('galleryNext');
        var counter = document.getElementById('galleryCounter');
        var current = 0;
        var total = slides.length;
        var autoplayMs = parseInt(carousel.getAttribute('data-autoplay') || '5500', 10);
        var timer = null;
        var touchStartX = 0;

        function goTo(index) {
            if (total <= 1) return;
            current = (index + total) % total;

            slides.forEach(function (slide, i) {
                slide.classList.toggle('is-active', i === current);
            });
            dots.forEach(function (dot, i) {
                dot.classList.toggle('is-active', i === current);
                dot.setAttribute('aria-selected', i === current ? 'true' : 'false');
            });
            thumbs.forEach(function (thumb, i) {
                thumb.classList.toggle('is-active', i === current);
            });

            if (track) {
                track.style.transform = 'translateX(-' + (current * 100) + '%)';
            }
            if (counter) {
                counter.textContent = (current + 1) + ' / ' + total;
            }
        }

        function next() { goTo(current + 1); }
        function prev() { goTo(current - 1); }

        function startAutoplay() {
            stopAutoplay();
            if (total > 1 && autoplayMs > 0) {
                timer = window.setInterval(next, autoplayMs);
            }
        }

        function stopAutoplay() {
            if (timer) {
                window.clearInterval(timer);
                timer = null;
            }
        }

        if (prevBtn) prevBtn.addEventListener('click', function () { prev(); startAutoplay(); });
        if (nextBtn) nextBtn.addEventListener('click', function () { next(); startAutoplay(); });

        dots.forEach(function (dot) {
            dot.addEventListener('click', function () {
                goTo(parseInt(dot.getAttribute('data-index') || '0', 10));
                startAutoplay();
            });
        });

        thumbs.forEach(function (thumb) {
            thumb.addEventListener('click', function () {
                goTo(parseInt(thumb.getAttribute('data-index') || '0', 10));
                startAutoplay();
            });
        });

        carousel.addEventListener('mouseenter', stopAutoplay);
        carousel.addEventListener('mouseleave', startAutoplay);
        carousel.addEventListener('focusin', stopAutoplay);
        carousel.addEventListener('focusout', startAutoplay);

        carousel.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft') { prev(); startAutoplay(); }
            if (e.key === 'ArrowRight') { next(); startAutoplay(); }
        });

        carousel.addEventListener('touchstart', function (e) {
            touchStartX = e.changedTouches[0].screenX;
        }, { passive: true });

        carousel.addEventListener('touchend', function (e) {
            var diff = e.changedTouches[0].screenX - touchStartX;
            if (Math.abs(diff) > 50) {
                if (diff < 0) next(); else prev();
                startAutoplay();
            }
        }, { passive: true });

        goTo(0);
        startAutoplay();
    }

    /* —— Lightbox —— */
    var lightbox = document.getElementById('galleryLightbox');
    var lightboxImg = document.getElementById('lightboxImage');
    var lightboxCaption = document.getElementById('lightboxCaption');
    var closeBtn = document.getElementById('lightboxClose');
    if (!lightbox) return;

    function openLightbox(src, caption) {
        lightboxImg.src = src;
        lightboxImg.alt = caption || 'Activity photo';
        lightboxCaption.textContent = caption || '';
        lightbox.hidden = false;
        lightbox.setAttribute('aria-hidden', 'false');
        document.body.classList.add('landing-lightbox-open');
    }

    function closeLightbox() {
        lightbox.hidden = true;
        lightbox.setAttribute('aria-hidden', 'true');
        lightboxImg.src = '';
        document.body.classList.remove('landing-lightbox-open');
    }

    document.querySelectorAll('[data-lightbox]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openLightbox(btn.getAttribute('data-lightbox'), btn.getAttribute('data-caption'));
        });
    });

    closeBtn.addEventListener('click', closeLightbox);
    lightbox.addEventListener('click', function (e) {
        if (e.target === lightbox) closeLightbox();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !lightbox.hidden) closeLightbox();
    });
})();
</script>

</body>

</html>

