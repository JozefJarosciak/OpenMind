<?php
/**
 * OpenMind — Configuration Defaults & Loader
 *
 * Single source of truth for every configurable value.
 * config.php overrides only the values the user has customized;
 * get_config() merges defaults underneath so every key is guaranteed.
 *
 * Functions:
 *   get_defaults()          Full defaults array
 *   get_config()            Defaults merged with config.php (cached)
 *   validate_password($pw)  Shared password validation
 */

function get_defaults(): array {
    return [
        // ── User-facing settings (editable via Settings UI) ───────────
        'workspace_path'      => '/root/.openclaw/workspace',
        'backup_path'         => dirname(__DIR__) . '/backups',
        'network_restriction' => 'none',
        'allowed_ips'         => '',
        'session_lifetime'    => 86400,
        'app_title'           => 'OpenMind',

        // ── Security (edit config.php directly) ───────────────────────
        'rate_limit_max_attempts' => 10,
        'rate_limit_window'       => 900,       // seconds (15 minutes)
        'remember_me_lifetime'    => 2592000,   // seconds (30 days)
        'min_session_lifetime'    => 300,       // seconds

        // ── Password policy ───────────────────────────────────────────
        'password_min_length' => 8,
        'password_rules'      => [
            'uppercase' => '/[A-Z]/',
            'lowercase' => '/[a-z]/',
            'number'    => '/[0-9]/',
            'special'   => '/[^A-Za-z0-9]/',
        ],

        // ── Node colors ───────────────────────────────────────────────
        'color_root'            => '#223366',
        'color_file'            => '#444466',
        'color_heading'         => '#555577',
        'color_node_fg'         => '#fff',
        'color_orphan_dir'      => '#446644',
        'color_memory_group'    => '#1a3a5a',
        'color_memory_group_fg' => '#7af',
        'color_memory_entry'    => '#2a4a6a',
        'color_memory_entry_fg' => '#cce',
        'branch_colors'         => [
            '#7c3aed', '#3b82f6', '#10b981', '#f59e0b', '#ef4444',
            '#ec4899', '#06b6d4', '#8b5cf6', '#f97316', '#84cc16',
        ],

        // ── jsMind layout ─────────────────────────────────────────────
        'layout_hspace'   => 200,
        'layout_vspace'   => 50,
        'layout_pspace'   => 20,
        'view_hmargin'    => 100,
        'view_vmargin'    => 50,
        'view_line_width' => 3,
        'view_line_color' => '#555',
        'default_design'  => 'outline',

        // ── Search ────────────────────────────────────────────────────
        'search_min_chars'   => 2,
        'search_max_results' => 25,

        // ── Panel ─────────────────────────────────────────────────────
        'panel_min_width' => 220,
        'panel_max_ratio' => 0.8,
    ];
}

/**
 * Load defaults merged with config.php overrides.
 * Result is cached for the duration of the request.
 */
function get_config(): array {
    static $merged = null;
    if ($merged !== null) return $merged;

    $defaults   = get_defaults();
    $configFile = dirname(__DIR__) . '/config.php';
    $user       = file_exists($configFile) ? (require $configFile) : [];
    $merged     = array_merge($defaults, is_array($user) ? $user : []);
    return $merged;
}

/**
 * Detect the OpenClaw agent name from the directory structure.
 * Lightweight fallback — the real bot name detection (Telegram API)
 * happens in the Docker entrypoint or installer, which write to config.php.
 */
function detectAgentName(array $config): ?string {
    $homes = [];
    if (is_dir('/openclaw-home/agents')) $homes[] = '/openclaw-home';
    $ws = rtrim($config['workspace_path'], '/');
    if (basename($ws) === 'workspace') {
        $parent = dirname($ws);
        if (is_dir($parent . '/agents')) $homes[] = $parent;
    }

    foreach ($homes as $home) {
        $dirs = @glob($home . '/agents/*', GLOB_ONLYDIR);
        if (!$dirs) continue;
        foreach ($dirs as $d) {
            if (basename($d) === 'main') return 'main';
        }
        return basename($dirs[0]);
    }

    return null;
}

/**
 * Get the display title: detected agent name or configured app_title.
 */
function getDisplayTitle(array $config): string {
    static $title = null;
    if ($title !== null) return $title;
    $title = detectAgentName($config) ?: $config['app_title'];
    return $title;
}

/**
 * Validate a password against the configured policy.
 * Returns an array of human-readable error strings (empty = valid).
 */
function validate_password(string $pw): array {
    $defaults = get_defaults();
    $minLen   = $defaults['password_min_length'];
    $rules    = $defaults['password_rules'];

    $labels = [
        'uppercase' => 'one uppercase letter',
        'lowercase' => 'one lowercase letter',
        'number'    => 'one number',
        'special'   => 'one special character',
    ];

    $errors = [];
    if (strlen($pw) < $minLen) {
        $errors[] = "at least $minLen characters";
    }
    foreach ($rules as $key => $regex) {
        if (!preg_match($regex, $pw)) {
            $errors[] = $labels[$key] ?? $key;
        }
    }
    return $errors;
}
