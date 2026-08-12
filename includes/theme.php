<?php
/**
 * Theme configuration and CSS variable output
 */

function getThemePresets(): array
{
    return [
        'jhcsc_official' => [
            'name'      => 'JHCSC Official',
            'primary'   => '#1b5e20',
            'secondary' => '#2e7d32',
            'accent'    => '#c62828',
            'body_bg'   => '#f3f7f4',
            'card_bg'   => '#ffffff',
            'text'      => '#1b2e1d',
            'login_gradient' => 'linear-gradient(135deg, #1b5e20 0%, #2e7d32 45%, #c62828 100%)',
        ],
        'jhcsc_blue' => [
            'name'      => 'JHCSC Blue',
            'primary'   => '#1a5276',
            'secondary' => '#2e86c1',
            'accent'    => '#f39c12',
            'body_bg'   => '#f4f6f9',
            'card_bg'   => '#ffffff',
            'text'      => '#2c3e50',
            'login_gradient' => 'linear-gradient(135deg, #1a5276 0%, #2e86c1 50%, #3498db 100%)',
        ],
        'forest_green' => [
            'name'      => 'Forest Green',
            'primary'   => '#1e5631',
            'secondary' => '#27ae60',
            'accent'    => '#f1c40f',
            'body_bg'   => '#f0f7f2',
            'card_bg'   => '#ffffff',
            'text'      => '#1a3326',
            'login_gradient' => 'linear-gradient(135deg, #1e5631 0%, #27ae60 50%, #2ecc71 100%)',
        ],
        'crimson_red' => [
            'name'      => 'Crimson Red',
            'primary'   => '#922b21',
            'secondary' => '#c0392b',
            'accent'    => '#f39c12',
            'body_bg'   => '#faf3f2',
            'card_bg'   => '#ffffff',
            'text'      => '#4a1c18',
            'login_gradient' => 'linear-gradient(135deg, #922b21 0%, #c0392b 50%, #e74c3c 100%)',
        ],
        'royal_purple' => [
            'name'      => 'Royal Purple',
            'primary'   => '#4a235a',
            'secondary' => '#7d3c98',
            'accent'    => '#e67e22',
            'body_bg'   => '#f6f2f8',
            'card_bg'   => '#ffffff',
            'text'      => '#341748',
            'login_gradient' => 'linear-gradient(135deg, #4a235a 0%, #7d3c98 50%, #9b59b6 100%)',
        ],
        'sunset_orange' => [
            'name'      => 'Sunset Orange',
            'primary'   => '#ba4a00',
            'secondary' => '#e67e22',
            'accent'    => '#f1c40f',
            'body_bg'   => '#fdf6f0',
            'card_bg'   => '#ffffff',
            'text'      => '#5c3310',
            'login_gradient' => 'linear-gradient(135deg, #ba4a00 0%, #e67e22 50%, #f39c12 100%)',
        ],
        'ocean_teal' => [
            'name'      => 'Ocean Teal',
            'primary'   => '#0e6655',
            'secondary' => '#16a085',
            'accent'    => '#3498db',
            'body_bg'   => '#eef8f6',
            'card_bg'   => '#ffffff',
            'text'      => '#0b4639',
            'login_gradient' => 'linear-gradient(135deg, #0e6655 0%, #16a085 50%, #1abc9c 100%)',
        ],
        'dark_mode' => [
            'name'      => 'Dark Mode',
            'primary'   => '#2c3e50',
            'secondary' => '#34495e',
            'accent'    => '#3498db',
            'body_bg'   => '#1a1a2e',
            'card_bg'   => '#16213e',
            'text'      => '#ecf0f1',
            'login_gradient' => 'linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%)',
        ],
    ];
}

function hexToRgb(string $hex): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return "$r, $g, $b";
}

/** Lighten or darken a hex color by a percentage (-100 to 100). */
function adjustHexBrightness(string $hex, float $percent): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $rgb = [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
    foreach ($rgb as &$channel) {
        $channel = (int) round(max(0, min(255, $channel + ($channel * ($percent / 100)))));
    }
    unset($channel);
    return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
}

/** Pick readable text color (black/white) for a hex background. */
function contrastTextColor(string $hex): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $luminance > 0.62 ? '#1a1a1a' : '#ffffff';
}

/**
 * Build Bootstrap-compatible solid + outline button CSS for a theme color.
 */
function buildThemeButtonCss(string $name, string $color): string
{
    $hover = adjustHexBrightness($color, -12);
    $active = adjustHexBrightness($color, -20);
    $border = adjustHexBrightness($color, -8);
    $rgb = hexToRgb($color);
    $text = contrastTextColor($color);
    $outlineHoverText = contrastTextColor($color);

    return "
    .btn-{$name} {
        --bs-btn-color: {$text};
        --bs-btn-bg: {$color};
        --bs-btn-border-color: {$border};
        --bs-btn-hover-color: {$text};
        --bs-btn-hover-bg: {$hover};
        --bs-btn-hover-border-color: {$active};
        --bs-btn-focus-shadow-rgb: {$rgb};
        --bs-btn-active-color: {$text};
        --bs-btn-active-bg: {$active};
        --bs-btn-active-border-color: {$active};
        --bs-btn-disabled-color: {$text};
        --bs-btn-disabled-bg: {$color};
        --bs-btn-disabled-border-color: {$border};
        background-color: {$color};
        border-color: {$border};
        color: {$text};
    }
    .btn-{$name}:hover,
    .btn-{$name}:focus {
        background-color: {$hover};
        border-color: {$active};
        color: {$text};
    }
    .btn-{$name}:active,
    .btn-{$name}.active,
    .show > .btn-{$name}.dropdown-toggle {
        background-color: {$active};
        border-color: {$active};
        color: {$text};
    }
    .btn-outline-{$name} {
        --bs-btn-color: {$color};
        --bs-btn-border-color: {$color};
        --bs-btn-hover-color: {$outlineHoverText};
        --bs-btn-hover-bg: {$color};
        --bs-btn-hover-border-color: {$color};
        --bs-btn-focus-shadow-rgb: {$rgb};
        --bs-btn-active-color: {$outlineHoverText};
        --bs-btn-active-bg: {$color};
        --bs-btn-active-border-color: {$color};
        --bs-btn-disabled-color: {$color};
        --bs-btn-disabled-bg: transparent;
        --bs-btn-disabled-border-color: {$color};
        color: {$color};
        border-color: {$color};
        background-color: transparent;
    }
    .btn-outline-{$name}:hover,
    .btn-outline-{$name}:focus {
        background-color: {$color};
        border-color: {$color};
        color: {$outlineHoverText};
    }
    .btn-outline-{$name}:active,
    .btn-outline-{$name}.active,
    .show > .btn-outline-{$name}.dropdown-toggle {
        background-color: {$hover};
        border-color: {$hover};
        color: {$outlineHoverText};
    }
    ";
}

function getActiveTheme(): array
{
    $presets = getThemePresets();
    $presetKey = getSetting('theme_preset', 'jhcsc_official');

    if ($presetKey === 'custom') {
        return [
            'preset'    => 'custom',
            'name'      => 'Custom Theme',
            'primary'   => getSetting('theme_primary', '#1b5e20'),
            'secondary' => getSetting('theme_secondary', '#2e7d32'),
            'accent'    => getSetting('theme_accent', '#c62828'),
            'body_bg'   => getSetting('theme_body_bg', '#f3f7f4'),
            'card_bg'   => getSetting('theme_card_bg', '#ffffff'),
            'text'      => getSetting('theme_text', '#1b2e1d'),
            'login_gradient' => getSetting('theme_login_gradient', 'linear-gradient(135deg, #1b5e20 0%, #2e7d32 45%, #c62828 100%)'),
        ];
    }

    $theme = $presets[$presetKey] ?? $presets['jhcsc_official'];
    $theme['preset'] = $presetKey;
    return $theme;
}

function renderThemeStyles(): string
{
    $theme = getActiveTheme();
    $primary = $theme['primary'];
    $secondary = $theme['secondary'];
    $accent = $theme['accent'];
    $primaryRgb = hexToRgb($primary);
    $secondaryRgb = hexToRgb($secondary);
    $accentRgb = hexToRgb($accent);
    $isDark = ($theme['preset'] ?? '') === 'dark_mode';

    // Complementary status colors derived from the active theme palette
    $success = adjustHexBrightness($secondary, -5);
    $info = adjustHexBrightness($primary, 18);
    $warning = $accent;
    $danger = adjustHexBrightness($accent, -35);
    // Keep danger readable/reddish when accent is already yellow-ish
    if (strtolower($accent) === '#f39c12' || strtolower($accent) === '#f1c40f') {
        $danger = '#c0392b';
    }
    if (($theme['preset'] ?? '') === 'crimson_red') {
        $danger = adjustHexBrightness($primary, -10);
        $success = '#1e8449';
    }
    if (($theme['preset'] ?? '') === 'forest_green' || ($theme['preset'] ?? '') === 'jhcsc_official') {
        $success = $secondary;
        $danger = '#c62828';
        $warning = '#f9a825';
        $info = adjustHexBrightness($primary, 25);
    }

    $css = ":root {
        --theme-primary: {$primary};
        --theme-secondary: {$secondary};
        --theme-accent: {$accent};
        --theme-body-bg: {$theme['body_bg']};
        --theme-card-bg: {$theme['card_bg']};
        --theme-text: {$theme['text']};
        --theme-login-gradient: {$theme['login_gradient']};
        --theme-success: {$success};
        --theme-info: {$info};
        --theme-warning: {$warning};
        --theme-danger: {$danger};
        --bs-primary: {$primary};
        --bs-primary-rgb: {$primaryRgb};
        --bs-secondary: {$secondary};
        --bs-secondary-rgb: {$secondaryRgb};
        --bs-success: {$success};
        --bs-info: {$info};
        --bs-warning: {$warning};
        --bs-danger: {$danger};
        --bs-link-color: {$primary};
        --bs-link-hover-color: " . adjustHexBrightness($primary, -15) . ";
        --jhcsc-primary: {$primary};
        --jhcsc-secondary: {$secondary};
        --jhcsc-accent: {$accent};
    }";

    $css .= "
    .bg-primary { background-color: {$primary} !important; }
    .bg-secondary { background-color: {$secondary} !important; }
    .bg-success { background-color: {$success} !important; }
    .bg-info { background-color: {$info} !important; }
    .bg-warning { background-color: {$warning} !important; }
    .bg-danger { background-color: {$danger} !important; }
    .text-primary { color: {$primary} !important; }
    .text-secondary { color: {$secondary} !important; }
    .text-success { color: {$success} !important; }
    .text-info { color: {$info} !important; }
    .text-warning { color: {$warning} !important; }
    .text-danger { color: {$danger} !important; }
    .border-primary { border-color: {$primary} !important; }
    .navbar.bg-primary { background-color: {$primary} !important; }
    .page-link { color: {$primary}; }
    .page-item.active .page-link {
        background-color: {$primary};
        border-color: {$primary};
        color: " . contrastTextColor($primary) . ";
    }
    .page-link:hover { color: " . adjustHexBrightness($primary, -15) . "; }
    .form-check-input:checked {
        background-color: {$primary};
        border-color: {$primary};
    }
    .form-control:focus,
    .form-select:focus {
        border-color: {$secondary};
        box-shadow: 0 0 0 0.2rem rgba({$primaryRgb}, 0.2);
    }
    .badge.bg-primary { background-color: {$primary} !important; }
    .badge.bg-secondary { background-color: {$secondary} !important; }
    .badge.bg-success { background-color: {$success} !important; }
    .badge.bg-info { background-color: {$info} !important; color: " . contrastTextColor($info) . " !important; }
    .badge.bg-warning { background-color: {$warning} !important; color: " . contrastTextColor($warning) . " !important; }
    .badge.bg-danger { background-color: {$danger} !important; }
    ";

    $css .= buildThemeButtonCss('primary', $primary);
    $css .= buildThemeButtonCss('secondary', $secondary);
    $css .= buildThemeButtonCss('success', $success);
    $css .= buildThemeButtonCss('info', $info);
    $css .= buildThemeButtonCss('warning', $warning);
    $css .= buildThemeButtonCss('danger', $danger);

    // Soft secondary action button used widely for Print / Back
    $css .= buildThemeButtonCss('light', $isDark ? '#2c3e50' : '#f8f9fa');
    $css .= "
    .btn-outline-secondary {
        --bs-btn-color: {$secondary};
        --bs-btn-border-color: {$secondary};
        --bs-btn-hover-color: " . contrastTextColor($secondary) . ";
        --bs-btn-hover-bg: {$secondary};
        --bs-btn-hover-border-color: {$secondary};
        --bs-btn-focus-shadow-rgb: {$secondaryRgb};
        --bs-btn-active-color: " . contrastTextColor($secondary) . ";
        --bs-btn-active-bg: {$secondary};
        --bs-btn-active-border-color: {$secondary};
        color: {$secondary};
        border-color: {$secondary};
    }
    .btn-outline-secondary:hover,
    .btn-outline-secondary:focus {
        background-color: {$secondary};
        border-color: {$secondary};
        color: " . contrastTextColor($secondary) . ";
    }
    .btn {
        transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
    }
    ";

    if ($isDark) {
        $css .= "
        body { background-color: var(--theme-body-bg); color: var(--theme-text); }
        .card, .card-header, .filter-bar, .stat-card { background-color: var(--theme-card-bg) !important; color: var(--theme-text); border-color: #2c3e50; }
        .page-header h1, .card-header { color: var(--theme-text); }
        .table { color: var(--theme-text); --bs-table-bg: var(--theme-card-bg); }
        .table-light { --bs-table-bg: #1f2b47; color: var(--theme-text); }
        .footer { background-color: var(--theme-card-bg) !important; border-color: #2c3e50 !important; }
        .footer .text-muted { color: #95a5a6 !important; }
        .form-control, .form-select { background-color: #1f2b47; color: var(--theme-text); border-color: #34495e; }
        .dropdown-menu { background-color: var(--theme-card-bg); }
        .dropdown-item { color: var(--theme-text); }
        .dropdown-item:hover { background-color: #1f2b47; }
        .list-group-item { background-color: var(--theme-card-bg); color: var(--theme-text); border-color: #2c3e50; }
        .text-muted { color: #95a5a6 !important; }
        .bg-light { background-color: #1f2b47 !important; }
        ";
    }

    return "<style id=\"system-theme\">{$css}</style>";
}

function ensureThemeSettings(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $db = getDB();
        $defaults = [
            ['theme_preset', 'jhcsc_official', 'string', 'Active theme preset'],
            ['theme_primary', '#1b5e20', 'string', 'Custom primary color'],
            ['theme_secondary', '#2e7d32', 'string', 'Custom secondary color'],
            ['theme_accent', '#c62828', 'string', 'Custom accent color'],
            ['theme_body_bg', '#f3f7f4', 'string', 'Custom body background'],
            ['theme_card_bg', '#ffffff', 'string', 'Custom card background'],
            ['theme_text', '#1b2e1d', 'string', 'Custom text color'],
            ['theme_login_gradient', 'linear-gradient(135deg, #1b5e20 0%, #2e7d32 45%, #c62828 100%)', 'string', 'Login page gradient'],
        ];

        $check = $db->prepare('SELECT id FROM system_settings WHERE setting_key = ?');
        $insert = $db->prepare('INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES (?, ?, ?, ?)');

        foreach ($defaults as [$key, $value, $type, $desc]) {
            $check->execute([$key]);
            if (!$check->fetch()) {
                $insert->execute([$key, $value, $type, $desc]);
            }
        }

        // Prefer official JHCSC palette for existing installs still on the old default blue.
        $preset = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'theme_preset'")->fetchColumn();
        if ($preset === 'jhcsc_blue') {
            $db->prepare("UPDATE system_settings SET setting_value = 'jhcsc_official' WHERE setting_key = 'theme_preset'")->execute();
        }
    } catch (Exception $e) {
        // Database may not be ready during install
    }
}

ensureThemeSettings();
