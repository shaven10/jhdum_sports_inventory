<?php
/**
 * Theme configuration and CSS variable output
 */

function getThemePresets(): array
{
    return [
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

function getActiveTheme(): array
{
    $presets = getThemePresets();
    $presetKey = getSetting('theme_preset', 'jhcsc_blue');

    if ($presetKey === 'custom') {
        return [
            'preset'    => 'custom',
            'name'      => 'Custom Theme',
            'primary'   => getSetting('theme_primary', '#1a5276'),
            'secondary' => getSetting('theme_secondary', '#2e86c1'),
            'accent'    => getSetting('theme_accent', '#f39c12'),
            'body_bg'   => getSetting('theme_body_bg', '#f4f6f9'),
            'card_bg'   => getSetting('theme_card_bg', '#ffffff'),
            'text'      => getSetting('theme_text', '#2c3e50'),
            'login_gradient' => getSetting('theme_login_gradient', 'linear-gradient(135deg, #1a5276 0%, #2e86c1 50%, #3498db 100%)'),
        ];
    }

    $theme = $presets[$presetKey] ?? $presets['jhcsc_blue'];
    $theme['preset'] = $presetKey;
    return $theme;
}

function renderThemeStyles(): string
{
    $theme = getActiveTheme();
    $primaryRgb = hexToRgb($theme['primary']);
    $secondaryRgb = hexToRgb($theme['secondary']);
    $isDark = ($theme['preset'] ?? '') === 'dark_mode';

    $css = ":root {
        --theme-primary: {$theme['primary']};
        --theme-secondary: {$theme['secondary']};
        --theme-accent: {$theme['accent']};
        --theme-body-bg: {$theme['body_bg']};
        --theme-card-bg: {$theme['card_bg']};
        --theme-text: {$theme['text']};
        --theme-login-gradient: {$theme['login_gradient']};
        --bs-primary: {$theme['primary']};
        --bs-primary-rgb: {$primaryRgb};
        --bs-secondary: {$theme['secondary']};
        --bs-secondary-rgb: {$secondaryRgb};
        --jhcsc-primary: {$theme['primary']};
        --jhcsc-secondary: {$theme['secondary']};
        --jhcsc-accent: {$theme['accent']};
    }";

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
            ['theme_preset', 'jhcsc_blue', 'string', 'Active theme preset'],
            ['theme_primary', '#1a5276', 'string', 'Custom primary color'],
            ['theme_secondary', '#2e86c1', 'string', 'Custom secondary color'],
            ['theme_accent', '#f39c12', 'string', 'Custom accent color'],
            ['theme_body_bg', '#f4f6f9', 'string', 'Custom body background'],
            ['theme_card_bg', '#ffffff', 'string', 'Custom card background'],
            ['theme_text', '#2c3e50', 'string', 'Custom text color'],
            ['theme_login_gradient', 'linear-gradient(135deg, #1a5276 0%, #2e86c1 50%, #3498db 100%)', 'string', 'Login page gradient'],
        ];

        $check = $db->prepare('SELECT id FROM system_settings WHERE setting_key = ?');
        $insert = $db->prepare('INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES (?, ?, ?, ?)');

        foreach ($defaults as [$key, $value, $type, $desc]) {
            $check->execute([$key]);
            if (!$check->fetch()) {
                $insert->execute([$key, $value, $type, $desc]);
            }
        }
    } catch (Exception $e) {
        // Database may not be ready during install
    }
}

ensureThemeSettings();
