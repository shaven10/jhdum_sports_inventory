<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin']);

$db = getDB();
$presets = getThemePresets();
$currentPreset = getSetting('theme_preset', 'jhcsc_blue');
$activeTheme = getActiveTheme();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf(post('csrf_token'))) {
    $preset = post('theme_preset', 'jhcsc_blue');

    updateSetting('theme_preset', $preset, $_SESSION['user_id']);

    if ($preset === 'custom') {
        updateSetting('theme_primary', post('theme_primary', '#1a5276'), $_SESSION['user_id']);
        updateSetting('theme_secondary', post('theme_secondary', '#2e86c1'), $_SESSION['user_id']);
        updateSetting('theme_accent', post('theme_accent', '#f39c12'), $_SESSION['user_id']);
        updateSetting('theme_body_bg', post('theme_body_bg', '#f4f6f9'), $_SESSION['user_id']);
        updateSetting('theme_card_bg', post('theme_card_bg', '#ffffff'), $_SESSION['user_id']);
        updateSetting('theme_text', post('theme_text', '#2c3e50'), $_SESSION['user_id']);
    } elseif (isset($presets[$preset])) {
        $p = $presets[$preset];
        updateSetting('theme_primary', $p['primary'], $_SESSION['user_id']);
        updateSetting('theme_secondary', $p['secondary'], $_SESSION['user_id']);
        updateSetting('theme_accent', $p['accent'], $_SESSION['user_id']);
        updateSetting('theme_body_bg', $p['body_bg'], $_SESSION['user_id']);
        updateSetting('theme_card_bg', $p['card_bg'], $_SESSION['user_id']);
        updateSetting('theme_text', $p['text'], $_SESSION['user_id']);
        updateSetting('theme_login_gradient', $p['login_gradient'], $_SESSION['user_id']);
    }

    auditLog($_SESSION['user_id'], 'update_theme', 'system_settings', null, null, ['preset' => $preset]);
    flash('success', 'System theme updated successfully.');
    redirect(BASE_URL . '/settings/theme.php');
}

$pageTitle = 'Theme Manager';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="bi bi-palette"></i> System Theme Manager</h1>
    <p class="text-muted mb-0">Customize the look and feel of the entire system. Changes apply globally for all users.</p>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Choose Theme Preset</div>
            <div class="card-body">
                <form method="POST" id="themeForm">
                    <?= csrfField() ?>
                    <div class="row g-3 mb-4">
                        <?php foreach ($presets as $key => $preset): ?>
                        <div class="col-md-6 col-lg-4">
                            <label class="theme-preset-card <?= $currentPreset === $key ? 'active' : '' ?>">
                                <input type="radio" name="theme_preset" value="<?= $key ?>" <?= $currentPreset === $key ? 'checked' : '' ?> class="d-none theme-radio">
                                <div class="theme-preview" style="background: <?= sanitize($preset['login_gradient']) ?>;"></div>
                                <div class="theme-swatches">
                                    <span style="background:<?= $preset['primary'] ?>"></span>
                                    <span style="background:<?= $preset['secondary'] ?>"></span>
                                    <span style="background:<?= $preset['accent'] ?>"></span>
                                </div>
                                <div class="theme-name"><?= sanitize($preset['name']) ?></div>
                            </label>
                        </div>
                        <?php endforeach; ?>
                        <div class="col-md-6 col-lg-4">
                            <label class="theme-preset-card <?= $currentPreset === 'custom' ? 'active' : '' ?>">
                                <input type="radio" name="theme_preset" value="custom" <?= $currentPreset === 'custom' ? 'checked' : '' ?> class="d-none theme-radio">
                                <div class="theme-preview theme-preview-custom"><i class="bi bi-sliders"></i></div>
                                <div class="theme-name">Custom Colors</div>
                            </label>
                        </div>
                    </div>

                    <div id="customColors" class="border rounded p-3 mb-4" style="display:<?= $currentPreset === 'custom' ? 'block' : 'none' ?>;">
                        <h6 class="mb-3">Custom Color Palette</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Primary Color</label>
                                <input type="color" name="theme_primary" class="form-control form-control-color w-100" value="<?= sanitize(getSetting('theme_primary', '#1a5276')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Secondary Color</label>
                                <input type="color" name="theme_secondary" class="form-control form-control-color w-100" value="<?= sanitize(getSetting('theme_secondary', '#2e86c1')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Accent Color</label>
                                <input type="color" name="theme_accent" class="form-control form-control-color w-100" value="<?= sanitize(getSetting('theme_accent', '#f39c12')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Body Background</label>
                                <input type="color" name="theme_body_bg" class="form-control form-control-color w-100" value="<?= sanitize(getSetting('theme_body_bg', '#f4f6f9')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Card Background</label>
                                <input type="color" name="theme_card_bg" class="form-control form-control-color w-100" value="<?= sanitize(getSetting('theme_card_bg', '#ffffff')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Text Color</label>
                                <input type="color" name="theme_text" class="form-control form-control-color w-100" value="<?= sanitize(getSetting('theme_text', '#2c3e50')) ?>">
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Apply Theme</button>
                    <a href="<?= BASE_URL ?>/settings/index.php" class="btn btn-outline-secondary">Back to Settings</a>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Live Preview</div>
            <div class="card-body p-0">
                <div class="theme-live-preview">
                    <div class="preview-navbar" style="background:<?= sanitize($activeTheme['primary']) ?>;">
                        <span><img src="<?= sanitize(appLogoUrl()) ?>" alt="" style="height:18px;width:auto;margin-right:6px;vertical-align:middle;background:#fff;border-radius:50%"> <?= sanitize(APP_SHORT_NAME) ?></span>
                    </div>
                    <div class="preview-body" style="background:<?= sanitize($activeTheme['body_bg']) ?>; color:<?= sanitize($activeTheme['text']) ?>;">
                        <div class="preview-card" style="background:<?= sanitize($activeTheme['card_bg']) ?>;">
                            <div class="preview-stat" style="color:<?= sanitize($activeTheme['primary']) ?>;">42</div>
                            <small>Available Equipment</small>
                            <button type="button" class="btn btn-sm mt-2" style="background:<?= sanitize($activeTheme['primary']) ?>; color:#fff; border:none;">Primary Button</button>
                            <button type="button" class="btn btn-sm mt-2" style="background:<?= sanitize($activeTheme['accent']) ?>; color:#fff; border:none;">Accent</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card mt-3">
            <div class="card-body">
                <h6><i class="bi bi-info-circle"></i> Active Theme</h6>
                <p class="mb-1"><strong><?= sanitize($activeTheme['name'] ?? ucfirst(str_replace('_', ' ', $currentPreset))) ?></strong></p>
                <div class="d-flex gap-2 mt-2">
                    <span class="badge" style="background:<?= sanitize($activeTheme['primary']) ?>">Primary</span>
                    <span class="badge" style="background:<?= sanitize($activeTheme['secondary']) ?>">Secondary</span>
                    <span class="badge" style="background:<?= sanitize($activeTheme['accent']) ?>">Accent</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.theme-radio').forEach(function(radio) {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.theme-preset-card').forEach(c => c.classList.remove('active'));
        this.closest('.theme-preset-card').classList.add('active');
        document.getElementById('customColors').style.display = this.value === 'custom' ? 'block' : 'none';
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
